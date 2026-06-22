<?php

declare(strict_types=1);

namespace CEL\Internal;

use CEL\Bytes;
use CEL\CheckException;
use CEL\DurationValue;
use CEL\Proto\ProtoRegistry;
use CEL\TimestampValue;
use CEL\Type;
use CEL\UInt;
use Google\Protobuf\Internal\DescriptorPool;
use Google\Protobuf\Internal\GPBType;

final class Checker
{
    private const TYPE_NAMES = [
        'bool' => true,
        'int' => true,
        'uint' => true,
        'double' => true,
        'string' => true,
        'bytes' => true,
        'list' => true,
        'map' => true,
        'optional_type' => true,
        'net.IP' => true,
        'net.CIDR' => true,
        'type' => true,
        'null_type' => true,
        'dyn' => true,
    ];

    /**
     * @param array<string, Type> $variables
     * @param array<string, mixed> $functions
     */
    public function __construct(
        private readonly array $variables,
        private readonly array $functions = [],
        private readonly ?ProtoRegistry $protoRegistry = null,
        private readonly bool $strongEnums = false,
        private readonly string $container = '',
    ) {
    }

    public function check(Expr $expr): Type
    {
        return $this->checkExpr($expr, []);
    }

    /** @param array<string, Type> $locals */
    private function checkExpr(Expr $expr, array $locals): Type
    {
        return match ($expr->kind) {
            'literal' => $this->literalType($expr->value),
            'ident' => $this->identifierType((string) $expr->value, $expr, $locals),
            'unary' => $this->checkUnary($expr, $locals),
            'binary' => $this->checkBinary($expr, $locals),
            'conditional' => $this->checkConditional($expr, $locals),
            'list' => $this->checkList($expr, $locals),
            'map' => $this->checkMap($expr, $locals),
            'select' => $this->checkSelect($expr, $locals),
            'optional_select' => Type::message('optional_type'),
            'index' => $this->checkIndex($expr, $locals),
            'optional_index' => Type::message('optional_type'),
            'optional_element' => Type::message('optional_type'),
            'call' => $this->checkCall($expr, $locals),
            'struct' => $this->checkStruct($expr, $locals),
            'comprehension' => $this->checkLoweredComprehension($expr, $locals),
            default => throw new CheckException(sprintf('unknown expression kind "%s"', $expr->kind)),
        };
    }

    private function literalType(mixed $value): Type
    {
        return match (true) {
            is_bool($value) => Type::bool(),
            is_int($value), $value instanceof IntLiteral => Type::int(),
            $value instanceof UInt => Type::uint(),
            is_float($value) => Type::double(),
            is_string($value) => Type::string(),
            $value instanceof Bytes => Type::bytes(),
            $value instanceof DurationValue => Type::message('google.protobuf.Duration'),
            $value instanceof TimestampValue => Type::message('google.protobuf.Timestamp'),
            $value === null => Type::null(),
            default => Type::dyn(),
        };
    }

    /** @param array<string, Type> $locals */
    private function identifierType(string $name, Expr $expr, array $locals): Type
    {
        if (array_key_exists($name, $locals)) {
            return $locals[$name];
        }

        $absolute = str_starts_with($name, '.');
        $normalized = ltrim($name, '.');
        if (!$absolute) {
            $localRootType = $this->localRootIdentifierType($normalized, $locals);
            if ($localRootType !== null) {
                return $localRootType;
            }
        }

        foreach ($this->candidateNames($name) as $candidate) {
            $type = $this->variableCandidateType($candidate, $locals);
            if ($type !== null) {
                return $type;
            }

            if ($this->protoRegistry?->hasTypeOrConstant($candidate)) {
                if ($this->protoRegistry->resolveEnumConstant($candidate) !== null) {
                    return $this->strongEnums
                        ? Type::message($this->protoRegistry->resolveEnumValue($candidate)?->type() ?? 'dyn')
                        : Type::int();
                }

                return Type::type();
            }
        }

        if (isset(self::TYPE_NAMES[$normalized])) {
            return Type::type();
        }

        throw new CheckException(sprintf('undeclared reference to "%s" at byte %d', $name, $expr->offset));
    }

    /** @param array<string, Type> $locals */
    private function localRootIdentifierType(string $name, array $locals): ?Type
    {
        $parts = explode('.', $name);
        $root = array_shift($parts);
        if ($root === null || !array_key_exists($root, $locals)) {
            return null;
        }

        $type = $locals[$root];
        foreach ($parts as $field) {
            $type = $this->selectType($type, $field);
        }

        return $type;
    }

    /** @param array<string, Type> $locals */
    private function variableCandidateType(string $name, array $locals): ?Type
    {
        if (array_key_exists($name, $locals)) {
            return $locals[$name];
        }
        if (array_key_exists($name, $this->variables)) {
            return $this->variables[$name];
        }

        $segments = explode('.', $name);
        for ($i = count($segments) - 1; $i >= 1; $i--) {
            $prefix = implode('.', array_slice($segments, 0, $i));
            $prefixType = $locals[$prefix] ?? $this->variables[$prefix] ?? null;
            if ($prefixType === null) {
                continue;
            }

            $type = $prefixType;
            foreach (array_slice($segments, $i) as $field) {
                $type = $this->selectType($type, $field);
            }

            return $type;
        }

        return null;
    }

    /** @return list<string> */
    private function candidateNames(string $name): array
    {
        $normalized = ltrim($name, '.');
        if ($normalized === '') {
            return [];
        }
        if (str_starts_with($name, '.') || $this->container === '') {
            return [$normalized];
        }

        $candidates = [];
        $containerParts = explode('.', trim($this->container, '.'));
        for ($i = count($containerParts); $i >= 1; $i--) {
            $candidates[] = implode('.', array_slice($containerParts, 0, $i)) . '.' . $normalized;
        }
        $candidates[] = $normalized;

        return array_values(array_unique($candidates));
    }

    /** @param array<string, Type> $locals */
    private function checkStruct(Expr $expr, array $locals): Type
    {
        $typeName = (string) $expr->value;
        $className = $this->protoRegistry?->resolveMessage($typeName);
        if ($className === null) {
            throw new CheckException(sprintf('unknown proto3 message type "%s" at byte %d', $expr->value, $expr->offset));
        }

        $descriptor = $this->descriptorForClass($className);
        if ($descriptor === null) {
            throw new CheckException(sprintf('missing proto3 descriptor for "%s" at byte %d', $typeName, $expr->offset));
        }

        $seen = [];
        foreach ($expr->entries as $entry) {
            if (!is_array($entry) || !array_key_exists('field', $entry) || !array_key_exists('value', $entry)) {
                $this->checkExpr($entry, $locals);
                continue;
            }

            $fieldName = (string) $entry['field'];
            if (isset($seen[$fieldName])) {
                throw new CheckException(sprintf('duplicate message field "%s" at byte %d', $fieldName, $expr->offset));
            }
            $seen[$fieldName] = true;

            $fieldDescriptor = $descriptor->getFieldByName($fieldName) ?? $descriptor->getFieldByJsonName($fieldName);
            if ($fieldDescriptor === null) {
                throw new CheckException(sprintf('no such field "%s" on %s at byte %d', $fieldName, $typeName, $expr->offset));
            }

            $actual = $this->checkExpr($entry['value'], $locals);
            if (($entry['optional'] ?? false) === true) {
                $this->expectAssignable($actual, Type::message('optional_type'), $entry['value'], sprintf('field "%s"', $fieldName));
                continue;
            }

            $expected = $this->typeForField($fieldDescriptor);
            $this->expectAssignable($actual, $expected, $entry['value'], sprintf('field "%s"', $fieldName));
        }

        return $this->typeForMessageField($this->protoRegistry->resolveMessageType($typeName) ?? $typeName);
    }

    /** @param array<string, Type> $locals */
    private function checkSelect(Expr $expr, array $locals): Type
    {
        $targetType = $this->checkExpr($expr->target, $locals);
        return $this->selectType($targetType, (string) $expr->value);
    }

    private function selectType(Type $targetType, string $field): Type
    {
        if ($targetType->name() !== 'message' || $targetType->messageType() === null) {
            return Type::dyn();
        }

        $className = $this->protoRegistry?->resolveMessage($targetType->messageType());
        if ($className === null) {
            return Type::dyn();
        }

        $descriptor = $this->descriptorForClass($className);
        $fieldDescriptor = $descriptor?->getFieldByName($field) ?? $descriptor?->getFieldByJsonName($field);
        if ($fieldDescriptor === null) {
            return Type::dyn();
        }

        return $this->typeForField($fieldDescriptor);
    }

    /** @param array<string, Type> $locals */
    private function checkIndex(Expr $expr, array $locals): Type
    {
        $targetType = $this->checkExpr($expr->target, $locals);
        $this->checkExpr($expr->args[0], $locals);

        return match ($targetType->name()) {
            'list' => $targetType->valueType() ?? Type::dyn(),
            'map' => $targetType->valueType() ?? Type::dyn(),
            'message' => $targetType->messageType() === 'optional_type' ? Type::message('optional_type') : Type::dyn(),
            default => Type::dyn(),
        };
    }

    /** @param array<string, Type> $locals */
    private function checkUnary(Expr $expr, array $locals): Type
    {
        $type = $this->checkExpr($expr->args[0], $locals);
        if ($expr->value === '!') {
            $this->expectAssignable($type, Type::bool(), $expr, 'operator !');
            return Type::bool();
        }

        if ($expr->value === '-') {
            if (!$type->isNumeric() && $type->name() !== 'dyn') {
                throw new CheckException(sprintf('operator - requires numeric operand at byte %d', $expr->offset));
            }
            if ($type->name() === 'uint') {
                throw new CheckException(sprintf('operator - does not accept uint at byte %d', $expr->offset));
            }

            return $type;
        }

        throw new CheckException(sprintf('unsupported unary operator "%s"', $expr->value));
    }

    /** @param array<string, Type> $locals */
    private function checkBinary(Expr $expr, array $locals): Type
    {
        $left = $this->checkExpr($expr->args[0], $locals);
        $right = $this->checkExpr($expr->args[1], $locals);
        $operator = (string) $expr->value;

        if (in_array($operator, ['&&', '||'], true)) {
            $this->expectAssignable($left, Type::bool(), $expr, sprintf('operator %s', $operator));
            $this->expectAssignable($right, Type::bool(), $expr, sprintf('operator %s', $operator));
            return Type::bool();
        }

        if (in_array($operator, ['==', '!=', '<', '<=', '>', '>=', 'in'], true)) {
            return Type::bool();
        }

        if ($operator === '+') {
            if ($this->isTimestampType($left) && $this->isDurationType($right)) {
                return Type::message('google.protobuf.Timestamp');
            }
            if ($this->isDurationType($left) && $this->isTimestampType($right)) {
                return Type::message('google.protobuf.Timestamp');
            }
            if ($this->isDurationType($left) && $this->isDurationType($right)) {
                return Type::message('google.protobuf.Duration');
            }
            if ($left->name() === 'string' && $right->name() === 'string') {
                return Type::string();
            }
            if ($left->name() === 'bytes' && $right->name() === 'bytes') {
                return Type::bytes();
            }
            if ($left->name() === 'list' && $right->name() === 'list') {
                return Type::list($this->unifyTypes($left->valueType() ?? Type::dyn(), $right->valueType() ?? Type::dyn()));
            }
        }

        if ($operator === '-') {
            if ($this->isTimestampType($left) && $this->isDurationType($right)) {
                return Type::message('google.protobuf.Timestamp');
            }
            if ($this->isTimestampType($left) && $this->isTimestampType($right)) {
                return Type::message('google.protobuf.Duration');
            }
            if ($this->isDurationType($left) && $this->isDurationType($right)) {
                return Type::message('google.protobuf.Duration');
            }
        }

        if (in_array($operator, ['+', '-', '*', '/', '%'], true)) {
            if ($this->wrapperAccepts($left, $right)) {
                return $right;
            }
            if ($this->wrapperAccepts($right, $left)) {
                return $left;
            }
            if ($left->name() !== 'dyn' && $right->name() !== 'dyn' && !$left->equals($right)) {
                throw new CheckException(sprintf('operator %s requires matching operand types at byte %d', $operator, $expr->offset));
            }
            if (!$left->isNumeric() && $left->name() !== 'dyn') {
                throw new CheckException(sprintf('operator %s requires numeric operands at byte %d', $operator, $expr->offset));
            }

            return $left->name() === 'dyn' ? $right : $left;
        }

        throw new CheckException(sprintf('unsupported binary operator "%s"', $operator));
    }

    /** @param array<string, Type> $locals */
    private function checkConditional(Expr $expr, array $locals): Type
    {
        $condition = $this->checkExpr($expr->args[0], $locals);
        $this->expectAssignable($condition, Type::bool(), $expr, 'conditional');
        $then = $this->checkExpr($expr->args[1], $locals);
        $else = $this->checkExpr($expr->args[2], $locals);

        return $this->unifyTypes($then, $else);
    }

    /** @param array<string, Type> $locals */
    private function checkList(Expr $expr, array $locals): Type
    {
        $itemType = null;
        $hasExplicitDyn = false;
        foreach ($expr->args as $arg) {
            if ($arg->kind === 'optional_element') {
                $this->checkExpr($arg->args[0], $locals);
                $itemType = $itemType === null ? Type::dyn() : $this->unifyTypes($itemType, Type::dyn());
                continue;
            }

            $type = $this->checkExpr($arg, $locals);
            $hasExplicitDyn = $hasExplicitDyn || $this->isDynCall($arg);
            $itemType = $itemType === null ? $type : $this->unifyTypes($itemType, $type);
        }

        return Type::list($hasExplicitDyn ? Type::dyn() : ($itemType ?? Type::dyn()));
    }

    private function isDynCall(Expr $expr): bool
    {
        return $expr->kind === 'call' && $expr->target === null && (string) $expr->value === 'dyn';
    }

    /** @param array<string, Type> $locals */
    private function checkMap(Expr $expr, array $locals): Type
    {
        $seenLiteralKeys = [];
        $keyType = null;
        $valueType = null;
        foreach ($expr->entries as $entry) {
            $key = $entry['key'];
            $value = $entry['value'];
            if ($key->kind === 'literal') {
                $serialized = serialize($key->value);
                if (isset($seenLiteralKeys[$serialized])) {
                    throw new CheckException(sprintf('duplicate map key at byte %d', $key->offset));
                }
                $seenLiteralKeys[$serialized] = true;
            }

            $currentKeyType = $this->checkExpr($key, $locals);
            if (!in_array($currentKeyType->name(), ['int', 'uint', 'bool', 'string', 'dyn'], true)) {
                throw new CheckException(sprintf('unsupported map key type %s at byte %d', $currentKeyType, $key->offset));
            }
            $currentValueType = $this->checkExpr($value, $locals);
            if (($entry['optional'] ?? false) === true) {
                $currentValueType = Type::dyn();
            }
            $keyType = $keyType === null ? $currentKeyType : $this->unifyTypes($keyType, $currentKeyType);
            $valueType = $valueType === null ? $currentValueType : $this->unifyTypes($valueType, $currentValueType);
        }

        return Type::map($keyType ?? Type::dyn(), $valueType ?? Type::dyn());
    }

    /** @param array<string, Type> $locals */
    private function checkCall(Expr $expr, array $locals): Type
    {
        $name = (string) $expr->value;

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'cel')) {
            return match ($name) {
                'bind' => $this->checkBindMacro($expr, $locals),
                'block' => $this->checkBlockMacro($expr, $locals),
                'index' => $locals[$this->blockIndexName($this->singleLiteralIntArg($expr, 'cel.index'))] ?? Type::dyn(),
                'iterVar' => $locals[$this->iterVarName(...$this->twoLiteralIntArgs($expr, 'cel.iterVar'))] ?? Type::dyn(),
                'accuVar' => $locals[$this->accuVarName(...$this->twoLiteralIntArgs($expr, 'cel.accuVar'))] ?? Type::dyn(),
                default => throw new CheckException(sprintf('undeclared receiver function "%s" at byte %d', $name, $expr->offset)),
            };
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'base64')) {
            return $this->checkBase64Function($name, $expr, $locals);
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'math')) {
            return $this->checkMathFunction($name, $expr, $locals);
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'strings')) {
            return $this->checkStringsFunction($name, $expr, $locals);
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'ip')) {
            return $this->checkIpNamespaceFunction($name, $expr, $locals);
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'optional')) {
            $argTypes = $this->checkArgs($expr->args, $locals);

            return $this->optionalNamespaceFunctionType($name, $argTypes, $expr);
        }

        if ($name === 'has') {
            if ($expr->target !== null || count($expr->args) !== 1) {
                throw new CheckException(sprintf('has macro expects one argument at byte %d', $expr->offset));
            }

            return Type::bool();
        }

        if ($expr->target !== null && in_array($name, ['all', 'exists', 'exists_one', 'existsOne', 'filter', 'map', 'transformList', 'transformMap'], true)) {
            return $this->checkComprehensionMacro($name, $expr, $locals);
        }

        if ($expr->target !== null && in_array($name, ['optMap', 'optFlatMap'], true)) {
            return $this->checkOptionalMapCall($name, $expr, $locals);
        }

        if ($expr->target !== null) {
            $targetType = $this->checkExpr($expr->target, $locals);
            $argTypes = $this->checkArgs($expr->args, $locals);

            return $this->receiverFunctionType($name, $targetType, $argTypes, $expr);
        }

        $argTypes = $this->checkArgs($expr->args, $locals);

        return $this->globalFunctionType($name, $argTypes, $expr);
    }

    private function isIdentifier(?Expr $expr, string $name): bool
    {
        return $expr !== null && $expr->kind === 'ident' && (string) $expr->value === $name;
    }

    /** @param list<Expr> $args */
    private function checkArgs(array $args, array $locals): array
    {
        return array_map(fn (Expr $arg): Type => $this->checkExpr($arg, $locals), $args);
    }

    /** @param list<Type> $argTypes */
    private function optionalNamespaceFunctionType(string $name, array $argTypes, Expr $expr): Type
    {
        match ($name) {
            'none' => $this->expectArity('optional.none', $argTypes, $expr, 0),
            'of', 'ofNonZeroValue' => $this->expectArity('optional.' . $name, $argTypes, $expr, 1),
            default => throw new CheckException(sprintf('undeclared receiver function "%s"', $name)),
        };

        return Type::message('optional_type');
    }

    /** @param array<string, Type> $locals */
    private function checkIpNamespaceFunction(string $name, Expr $expr, array $locals): Type
    {
        if ($name !== 'isCanonical') {
            throw new CheckException(sprintf('undeclared receiver function "%s" at byte %d', $name, $expr->offset));
        }

        $argTypes = $this->checkArgs($expr->args, $locals);
        $this->expectArity('ip.isCanonical', $argTypes, $expr, 1);
        $this->expectAssignable($argTypes[0], Type::string(), $expr, 'ip.isCanonical');

        return Type::bool();
    }

    /** @param array<string, Type> $locals */
    private function checkBindMacro(Expr $expr, array $locals): Type
    {
        if (count($expr->args) !== 3) {
            throw new CheckException(sprintf('cel.bind expects variable, value, and expression at byte %d', $expr->offset));
        }

        $var = $expr->args[0];
        if ($var->kind !== 'ident' || str_contains((string) $var->value, '.')) {
            throw new CheckException(sprintf('cel.bind requires an identifier variable at byte %d', $var->offset));
        }

        $valueType = $this->checkExpr($expr->args[1], $locals);
        $nextLocals = $locals;
        $nextLocals[(string) $var->value] = $valueType;

        return $this->checkExpr($expr->args[2], $nextLocals);
    }

    /** @param array<string, Type> $locals */
    private function checkBlockMacro(Expr $expr, array $locals): Type
    {
        if (count($expr->args) !== 2) {
            throw new CheckException(sprintf('cel.block expects a sequence and expression at byte %d', $expr->offset));
        }

        $sequence = $expr->args[0];
        if ($sequence->kind !== 'list') {
            throw new CheckException(sprintf('cel.block requires a list sequence at byte %d', $sequence->offset));
        }

        $blockLocals = $locals;
        foreach ($sequence->args as $index => $entry) {
            $blockLocals[$this->blockIndexName($index)] = $this->checkExpr($entry, $blockLocals);
        }

        return $this->checkExpr($expr->args[1], $blockLocals);
    }

    /** @param array<string, Type> $locals */
    private function checkBase64Function(string $name, Expr $expr, array $locals): Type
    {
        if (count($expr->args) !== 1) {
            throw new CheckException(sprintf('base64.%s expects one argument at byte %d', $name, $expr->offset));
        }

        $argType = $this->checkExpr($expr->args[0], $locals);

        return match ($name) {
            'encode' => $this->base64FunctionType($argType, Type::bytes(), Type::string(), $expr, 'base64.encode'),
            'decode' => $this->base64FunctionType($argType, Type::string(), Type::bytes(), $expr, 'base64.decode'),
            default => throw new CheckException(sprintf('undeclared receiver function "%s" at byte %d', $name, $expr->offset)),
        };
    }

    private function base64FunctionType(Type $argType, Type $expected, Type $result, Expr $expr, string $name): Type
    {
        $this->expectAssignable($argType, $expected, $expr, $name);

        return $result;
    }

    /** @param array<string, Type> $locals */
    private function checkMathFunction(string $name, Expr $expr, array $locals): Type
    {
        $argTypes = $this->checkArgs($expr->args, $locals);

        if (in_array($name, ['ceil', 'floor', 'round', 'trunc', 'isNaN', 'isInf', 'isFinite'], true)) {
            $this->expectArity('math.' . $name, $argTypes, $expr, 1);
            $this->expectAssignable($argTypes[0], Type::double(), $expr, 'math.' . $name);

            return in_array($name, ['isNaN', 'isInf', 'isFinite'], true) ? Type::bool() : Type::double();
        }

        if (in_array($name, ['abs', 'sign'], true)) {
            $this->expectArity('math.' . $name, $argTypes, $expr, 1);
            $this->expectNumeric($argTypes[0], $expr, 'math.' . $name);

            return $argTypes[0]->name() === 'dyn' ? Type::dyn() : $argTypes[0];
        }

        if (in_array($name, ['bitAnd', 'bitOr', 'bitXor'], true)) {
            $this->expectArity('math.' . $name, $argTypes, $expr, 2);
            if ($argTypes[0]->name() === 'dyn' || $argTypes[1]->name() === 'dyn') {
                return Type::dyn();
            }
            if (!$argTypes[0]->equals($argTypes[1]) || !in_array($argTypes[0]->name(), ['int', 'uint'], true)) {
                throw new CheckException(sprintf('math.%s expects matching int or uint operands at byte %d', $name, $expr->offset));
            }

            return $argTypes[0];
        }

        if ($name === 'bitNot') {
            $this->expectArity('math.bitNot', $argTypes, $expr, 1);
            if ($argTypes[0]->name() === 'dyn') {
                return Type::dyn();
            }
            if (!in_array($argTypes[0]->name(), ['int', 'uint'], true)) {
                throw new CheckException(sprintf('math.bitNot expects int or uint operand at byte %d', $expr->offset));
            }

            return $argTypes[0];
        }

        if (in_array($name, ['bitShiftLeft', 'bitShiftRight'], true)) {
            $this->expectArity('math.' . $name, $argTypes, $expr, 2);
            if ($argTypes[0]->name() === 'dyn' || $argTypes[1]->name() === 'dyn') {
                return Type::dyn();
            }
            if (!in_array($argTypes[0]->name(), ['int', 'uint'], true) || $argTypes[1]->name() !== 'int') {
                throw new CheckException(sprintf('math.%s expects int or uint value and int offset at byte %d', $name, $expr->offset));
            }

            return $argTypes[0];
        }

        return match ($name) {
            'greatest', 'least' => $this->mathExtremumType($name, $argTypes, $expr),
            'isNaN', 'isInf', 'isFinite' => Type::bool(),
            default => throw new CheckException(sprintf('undeclared receiver function "%s" at byte %d', $name, $expr->offset)),
        };
    }

    /** @param array<string, Type> $locals */
    private function checkStringsFunction(string $name, Expr $expr, array $locals): Type
    {
        $argTypes = $this->checkArgs($expr->args, $locals);

        if ($name === 'quote') {
            $this->expectArity('strings.quote', $argTypes, $expr, 1);
            $this->expectAssignable($argTypes[0], Type::string(), $expr, 'strings.quote');

            return Type::string();
        }

        return match ($name) {
            default => throw new CheckException(sprintf('undeclared receiver function "%s" at byte %d', $name, $expr->offset)),
        };
    }

    /** @param array<string, Type> $locals */
    private function checkOptionalMapCall(string $name, Expr $expr, array $locals): Type
    {
        $this->checkExpr($expr->target, $locals);
        if (count($expr->args) !== 2) {
            throw new CheckException(sprintf('%s expects variable and expression at byte %d', $name, $expr->offset));
        }

        $var = $expr->args[0];
        if ($var->kind !== 'ident' || str_contains((string) $var->value, '.')) {
            throw new CheckException(sprintf('%s requires an identifier variable at byte %d', $name, $var->offset));
        }

        $nextLocals = $locals;
        $nextLocals[(string) $var->value] = Type::dyn();
        $this->checkExpr($expr->args[1], $nextLocals);

        return Type::message('optional_type');
    }

    /** @param array<string, Type> $locals */
    private function checkComprehensionMacro(string $name, Expr $expr, array $locals): Type
    {
        $targetType = $this->checkExpr($expr->target, $locals);
        $expected = in_array($name, ['transformList', 'transformMap'], true)
            ? [3, 4]
            : (in_array($name, ['map', 'filter', 'all', 'exists', 'exists_one', 'existsOne'], true) ? [2, 3] : [2]);
        if (!in_array(count($expr->args), $expected, true)) {
            throw new CheckException(sprintf('%s macro has invalid argument count at byte %d', $name, $expr->offset));
        }

        $var = $this->macroVariableName($expr->args[0], $name);

        [$iterKeyType, $iterValueType] = $this->iterationTypes($targetType);
        $nextLocals = $locals;
        $hasSecondVar = in_array($name, ['transformList', 'transformMap'], true)
            ? in_array(count($expr->args), [3, 4], true)
            : count($expr->args) === 3;
        $nextLocals[$var] = $hasSecondVar
            ? $iterKeyType
            : ($targetType->name() === 'map' ? $iterKeyType : $iterValueType);

        if ($hasSecondVar) {
            $index = $expr->args[1];
            $nextLocals[$this->macroVariableName($index, $name, 'index ')] = $iterValueType;
        }

        if (in_array($name, ['transformList', 'transformMap'], true) && count($expr->args) === 4) {
            $filterType = $this->checkExpr($expr->args[2], $nextLocals);
            $this->expectAssignable($filterType, Type::bool(), $expr->args[2], $name);
        }

        $body = $expr->args[array_key_last($expr->args)];
        $bodyType = $this->checkExpr($body, $nextLocals);

        if (in_array($name, ['all', 'exists', 'exists_one', 'existsOne', 'filter'], true)) {
            $this->expectAssignable($bodyType, Type::bool(), $body, $name);
        }

        return match ($name) {
            'all', 'exists', 'exists_one', 'existsOne' => Type::bool(),
            'filter' => Type::list(Type::dyn()),
            'map' => Type::list($bodyType),
            'transformList' => Type::list($bodyType),
            'transformMap' => Type::map($iterKeyType, $bodyType),
        };
    }

    private function macroVariableName(Expr $expr, string $macro, string $label = ''): string
    {
        if ($expr->kind === 'ident' && !str_contains((string) $expr->value, '.')) {
            return (string) $expr->value;
        }

        $synthetic = $this->syntheticCelVariableName($expr);
        if ($synthetic !== null) {
            return $synthetic;
        }

        throw new CheckException(sprintf('%s macro requires an identifier %svariable at byte %d', $macro, $label, $expr->offset));
    }

    private function syntheticCelVariableName(Expr $expr): ?string
    {
        if ($expr->kind !== 'call' || $expr->target === null || !$this->isIdentifier($expr->target, 'cel')) {
            return null;
        }

        return match ((string) $expr->value) {
            'iterVar' => $this->iterVarName(...$this->twoLiteralIntArgs($expr, 'cel.iterVar')),
            'accuVar' => $this->accuVarName(...$this->twoLiteralIntArgs($expr, 'cel.accuVar')),
            default => null,
        };
    }

    private function blockIndexName(int $index): string
    {
        return '@index' . $index;
    }

    private function iterVarName(int $scope, int $slot): string
    {
        return '@it:' . $scope . ':' . $slot;
    }

    private function accuVarName(int $scope, int $slot): string
    {
        return '@ac:' . $scope . ':' . $slot;
    }

    private function singleLiteralIntArg(Expr $expr, string $name): int
    {
        if (count($expr->args) !== 1) {
            throw new CheckException(sprintf('%s expects one integer argument at byte %d', $name, $expr->offset));
        }

        return $this->literalIntArg($expr->args[0], $name);
    }

    /** @return array{0:int, 1:int} */
    private function twoLiteralIntArgs(Expr $expr, string $name): array
    {
        if (count($expr->args) !== 2) {
            throw new CheckException(sprintf('%s expects two integer arguments at byte %d', $name, $expr->offset));
        }

        return [
            $this->literalIntArg($expr->args[0], $name),
            $this->literalIntArg($expr->args[1], $name),
        ];
    }

    private function literalIntArg(Expr $expr, string $name): int
    {
        if ($expr->kind !== 'literal' || (!is_int($expr->value) && !$expr->value instanceof IntLiteral)) {
            throw new CheckException(sprintf('%s expects integer literal arguments at byte %d', $name, $expr->offset));
        }

        return $expr->value instanceof IntLiteral ? $expr->value->toInt() : $expr->value;
    }

    /** @param array<string, Type> $locals */
    private function checkLoweredComprehension(Expr $expr, array $locals): Type
    {
        if (count($expr->args) !== 5 || !is_array($expr->value)) {
            throw new CheckException(sprintf('invalid comprehension expression at byte %d', $expr->offset));
        }

        $iterVar = (string) ($expr->value['iter_var'] ?? '');
        $iterVar2 = (string) ($expr->value['iter_var2'] ?? '');
        $accuVar = (string) ($expr->value['accu_var'] ?? '');
        if ($iterVar === '' || $accuVar === '') {
            throw new CheckException(sprintf('comprehension requires iteration and accumulator variables at byte %d', $expr->offset));
        }

        $rangeType = $this->checkExpr($expr->args[0], $locals);
        $accuType = $this->checkExpr($expr->args[1], $locals);
        [$iterKeyType, $iterValueType] = $this->iterationTypes($rangeType);

        $loopLocals = $locals;
        $loopLocals[$accuVar] = $accuType;
        if ($iterVar2 === '') {
            $loopLocals[$iterVar] = $rangeType->name() === 'map' ? $iterKeyType : $iterValueType;
        } else {
            $loopLocals[$iterVar] = $iterKeyType;
            $loopLocals[$iterVar2] = $iterValueType;
        }

        $conditionType = $this->checkExpr($expr->args[2], $loopLocals);
        $this->expectAssignable($conditionType, Type::bool(), $expr->args[2], 'comprehension loop condition');

        $stepType = $this->checkExpr($expr->args[3], $loopLocals);
        $resultLocals = $locals;
        $resultLocals[$accuVar] = $this->unifyTypes($accuType, $stepType);

        return $this->checkExpr($expr->args[4], $resultLocals);
    }

    /** @return array{0: Type, 1: Type} */
    private function iterationTypes(Type $rangeType): array
    {
        if ($rangeType->name() === 'list') {
            return [Type::int(), $rangeType->valueType() ?? Type::dyn()];
        }

        if ($rangeType->name() === 'map') {
            return [$rangeType->keyType() ?? Type::dyn(), $rangeType->valueType() ?? Type::dyn()];
        }

        return [Type::dyn(), Type::dyn()];
    }

    /** @param list<Type> $argTypes */
    private function receiverFunctionType(string $name, Type $targetType, array $argTypes, Expr $expr): Type
    {
        if ($this->strongEnums && in_array($targetType->name(), ['message', 'type'], true)) {
            $enumType = $this->protoRegistry?->resolveEnumType((string) $targetType . '.' . $name)
                ?? $this->protoRegistry?->resolveEnumType($name);
            if ($enumType !== null) {
                return Type::message($enumType);
            }
        }

        $timestampSelectors = [
            'getDate',
            'getDayOfMonth',
            'getDayOfWeek',
            'getDayOfYear',
            'getFullYear',
            'getHours',
            'getMilliseconds',
            'getMinutes',
            'getMonth',
            'getSeconds',
        ];
        $durationSelectors = ['getHours', 'getMilliseconds', 'getMinutes', 'getSeconds'];
        if (in_array($name, $timestampSelectors, true)) {
            if ($this->isTimestampType($targetType) || $targetType->name() === 'dyn') {
                $this->expectArity($name, $argTypes, $expr, 0, 1);
                if (isset($argTypes[0])) {
                    $this->expectAssignable($argTypes[0], Type::string(), $expr, $name);
                }

                return Type::int();
            }

            if ($this->isDurationType($targetType) && in_array($name, $durationSelectors, true)) {
                $this->expectArity($name, $argTypes, $expr, 0);

                return Type::int();
            }

            throw new CheckException(sprintf('%s receiver requires timestamp%s target at byte %d', $name, in_array($name, $durationSelectors, true) ? ' or duration' : '', $expr->offset));
        }

        if (in_array($name, ['startsWith', 'endsWith', 'contains', 'matches'], true)) {
            if (!in_array($targetType->name(), ['string', 'dyn'], true)) {
                throw new CheckException(sprintf('%s receiver requires string target at byte %d', $name, $expr->offset));
            }
            $this->expectArity($name, $argTypes, $expr, 1);
            $this->expectAssignable($argTypes[0], Type::string(), $expr, $name);

            return Type::bool();
        }

        if (in_array($name, ['lowerAscii', 'upperAscii', 'trim', 'reverse'], true)) {
            if (!in_array($targetType->name(), ['string', 'dyn'], true)) {
                throw new CheckException(sprintf('%s receiver requires string target at byte %d', $name, $expr->offset));
            }
            $this->expectArity($name, $argTypes, $expr, 0);

            return Type::string();
        }

        if ($name === 'charAt') {
            $this->expectStringReceiver($name, $targetType, $expr);
            $this->expectArity($name, $argTypes, $expr, 1);
            $this->expectAssignable($argTypes[0], Type::int(), $expr, $name);

            return Type::string();
        }

        if ($name === 'replace') {
            $this->expectStringReceiver($name, $targetType, $expr);
            $this->expectArity($name, $argTypes, $expr, 2, 3);
            $this->expectAssignable($argTypes[0], Type::string(), $expr, $name);
            $this->expectAssignable($argTypes[1], Type::string(), $expr, $name);
            if (isset($argTypes[2])) {
                $this->expectAssignable($argTypes[2], Type::int(), $expr, $name);
            }

            return Type::string();
        }

        if ($name === 'substring') {
            $this->expectStringReceiver($name, $targetType, $expr);
            $this->expectArity($name, $argTypes, $expr, 1, 2);
            $this->expectAssignable($argTypes[0], Type::int(), $expr, $name);
            if (isset($argTypes[1])) {
                $this->expectAssignable($argTypes[1], Type::int(), $expr, $name);
            }

            return Type::string();
        }

        if ($name === 'format') {
            $this->expectStringReceiver($name, $targetType, $expr);
            $this->expectArity($name, $argTypes, $expr, 1);
            if (!in_array($argTypes[0]->name(), ['list', 'dyn'], true)) {
                throw new CheckException(sprintf('format expects list argument at byte %d', $expr->offset));
            }

            return Type::string();
        }

        if (in_array($name, ['indexOf', 'lastIndexOf'], true)) {
            $this->expectStringReceiver($name, $targetType, $expr);
            $this->expectArity($name, $argTypes, $expr, 1, 2);
            $this->expectAssignable($argTypes[0], Type::string(), $expr, $name);
            if (isset($argTypes[1])) {
                $this->expectAssignable($argTypes[1], Type::int(), $expr, $name);
            }

            return Type::int();
        }

        if ($name === 'split') {
            $this->expectStringReceiver($name, $targetType, $expr);
            $this->expectArity('split', $argTypes, $expr, 1, 2);
            $this->expectAssignable($argTypes[0], Type::string(), $expr, 'split');
            if (isset($argTypes[1])) {
                $this->expectAssignable($argTypes[1], Type::int(), $expr, 'split');
            }

            return Type::list(Type::string());
        }

        if ($name === 'join') {
            if (!in_array($targetType->name(), ['list', 'dyn'], true)) {
                throw new CheckException(sprintf('%s receiver requires list target at byte %d', $name, $expr->offset));
            }
            $this->expectArity('join', $argTypes, $expr, 0, 1);
            if (isset($argTypes[0])) {
                $this->expectAssignable($argTypes[0], Type::string(), $expr, 'join');
            }
            if ($targetType->name() === 'list') {
                $elemType = $targetType->valueType() ?? Type::dyn();
                $this->expectAssignable($elemType, Type::string(), $expr, 'join receiver');
            }

            return Type::string();
        }

        if ($name === 'size') {
            $this->expectArity('size', $argTypes, $expr, 0);
            $this->expectSizedType($targetType, $expr, 'size receiver');

            return Type::int();
        }

        if (in_array($name, ['hasValue'], true) && ($targetType->messageType() === 'optional_type' || $targetType->name() === 'dyn')) {
            $this->expectArity($name, $argTypes, $expr, 0);

            return Type::bool();
        }

        if ($name === 'value' && ($targetType->messageType() === 'optional_type' || $targetType->name() === 'dyn')) {
            $this->expectArity($name, $argTypes, $expr, 0);

            return Type::dyn();
        }

        if ($name === 'orValue' && ($targetType->messageType() === 'optional_type' || $targetType->name() === 'dyn')) {
            $this->expectArity($name, $argTypes, $expr, 1);

            return $argTypes[0] ?? Type::dyn();
        }

        if ($name === 'or' && ($targetType->messageType() === 'optional_type' || $targetType->name() === 'dyn')) {
            $this->expectArity($name, $argTypes, $expr, 1);
            $this->expectAssignable($argTypes[0], Type::message('optional_type'), $expr, $name);

            return Type::message('optional_type');
        }

        if ($targetType->messageType() === 'net.IP' || $targetType->name() === 'dyn') {
            if (in_array($name, ['isUnspecified', 'isLoopback', 'isGlobalUnicast', 'isLinkLocalMulticast', 'isLinkLocalUnicast'], true)) {
                $this->expectArity($name, $argTypes, $expr, 0);

                return Type::bool();
            }
            if ($name === 'family') {
                $this->expectArity($name, $argTypes, $expr, 0);

                return Type::int();
            }
        }

        if ($targetType->messageType() === 'net.CIDR' || $targetType->name() === 'dyn') {
            if ($name === 'containsIP') {
                $this->expectArity($name, $argTypes, $expr, 1);
                $this->expectAssignableToAny($argTypes[0], [Type::string(), Type::message('net.IP'), Type::dyn()], $expr, $name);

                return Type::bool();
            }
            if ($name === 'containsCIDR') {
                $this->expectArity($name, $argTypes, $expr, 1);
                $this->expectAssignableToAny($argTypes[0], [Type::string(), Type::message('net.CIDR'), Type::dyn()], $expr, $name);

                return Type::bool();
            }
            if ($name === 'ip') {
                $this->expectArity($name, $argTypes, $expr, 0);

                return Type::message('net.IP');
            }
            if ($name === 'masked') {
                $this->expectArity($name, $argTypes, $expr, 0);

                return Type::message('net.CIDR');
            }
            if ($name === 'prefixLength') {
                $this->expectArity($name, $argTypes, $expr, 0);

                return Type::int();
            }
        }

        if (isset($this->functions[$name]) && $this->functions[$name] instanceof \CEL\FunctionDeclaration) {
            return $this->customFunctionType($name, array_merge([$targetType], $argTypes), true, $expr);
        }

        throw new CheckException(sprintf('undeclared receiver function "%s" at byte %d', $name, $expr->offset));
    }

    /** @param list<Type> $argTypes */
    private function globalFunctionType(string $name, array $argTypes, Expr $expr): Type
    {
        if ($this->strongEnums && $this->protoRegistry?->resolveEnumType($name) !== null) {
            $this->expectArity($name, $argTypes, $expr, 1);

            return Type::message($this->protoRegistry->resolveEnumType($name));
        }

        if (isset($this->functions[$name]) && $this->functions[$name] instanceof \CEL\FunctionDeclaration) {
            return $this->customFunctionType($name, $argTypes, false, $expr);
        }

        if ($name === 'size') {
            $this->expectArity('size', $argTypes, $expr, 1);
            $this->expectSizedType($argTypes[0], $expr, 'size');

            return Type::int();
        }

        if ($name === 'type') {
            $this->expectArity('type', $argTypes, $expr, 1);

            return Type::type();
        }

        if ($name === 'int') {
            $this->expectArity('int', $argTypes, $expr, 1);
            if (!$this->isEnumType($argTypes[0])) {
                $this->expectAssignableToAny($argTypes[0], [Type::int(), Type::uint(), Type::double(), Type::string(), Type::message('google.protobuf.Timestamp')], $expr, 'int');
            }

            return Type::int();
        }

        if ($name === 'uint') {
            $this->expectArity('uint', $argTypes, $expr, 1);
            $this->expectAssignableToAny($argTypes[0], [Type::int(), Type::uint(), Type::double(), Type::string()], $expr, 'uint');

            return Type::uint();
        }

        if ($name === 'double') {
            $this->expectArity('double', $argTypes, $expr, 1);
            $this->expectAssignableToAny($argTypes[0], [Type::int(), Type::uint(), Type::double(), Type::string()], $expr, 'double');

            return Type::double();
        }

        if ($name === 'string') {
            $this->expectArity('string', $argTypes, $expr, 1);

            return Type::string();
        }

        if ($name === 'bytes') {
            $this->expectArity('bytes', $argTypes, $expr, 1);
            $this->expectAssignableToAny($argTypes[0], [Type::string(), Type::bytes()], $expr, 'bytes');

            return Type::bytes();
        }

        if ($name === 'bool') {
            $this->expectArity('bool', $argTypes, $expr, 1);
            $this->expectAssignableToAny($argTypes[0], [Type::bool(), Type::string()], $expr, 'bool');

            return Type::bool();
        }

        if ($name === 'dyn') {
            $this->expectArity('dyn', $argTypes, $expr, 1);

            return Type::dyn();
        }

        if ($name === 'duration') {
            $this->expectArity('duration', $argTypes, $expr, 1);
            $this->expectAssignableToAny($argTypes[0], [Type::string(), Type::message('google.protobuf.Duration')], $expr, 'duration');

            return Type::message('google.protobuf.Duration');
        }

        if ($name === 'timestamp') {
            $this->expectArity('timestamp', $argTypes, $expr, 1);
            $this->expectAssignableToAny($argTypes[0], [Type::string(), Type::int(), Type::uint(), Type::message('google.protobuf.Timestamp')], $expr, 'timestamp');

            return Type::message('google.protobuf.Timestamp');
        }

        if ($name === 'ip') {
            $this->expectArity('ip', $argTypes, $expr, 1);
            $this->expectAssignable($argTypes[0], Type::string(), $expr, 'ip');

            return Type::message('net.IP');
        }

        if ($name === 'cidr') {
            $this->expectArity('cidr', $argTypes, $expr, 1);
            $this->expectAssignable($argTypes[0], Type::string(), $expr, 'cidr');

            return Type::message('net.CIDR');
        }

        if ($name === 'isIP') {
            $this->expectArity('isIP', $argTypes, $expr, 1);
            $this->expectAssignable($argTypes[0], Type::string(), $expr, 'isIP');

            return Type::bool();
        }

        return match ($name) {
            default => array_key_exists($name, $this->functions)
                ? Type::dyn()
                : throw new CheckException(sprintf('undeclared function "%s" at byte %d', $name, $expr->offset)),
        };
    }

    /** @param list<Type> $argTypes */
    private function customFunctionType(string $name, array $argTypes, bool $receiverStyle, Expr $expr): Type
    {
        $declaration = $this->functions[$name] ?? null;
        if (!$declaration instanceof \CEL\FunctionDeclaration) {
            throw new CheckException(sprintf('undeclared function "%s" at byte %d', $name, $expr->offset));
        }

        foreach ($declaration->overloads as $overload) {
            if ($overload->receiverStyle !== $receiverStyle || count($overload->argumentTypes) !== count($argTypes)) {
                continue;
            }

            foreach ($argTypes as $index => $argType) {
                if (!$this->isAssignable($argType, $overload->argumentTypes[$index])) {
                    continue 2;
                }
            }

            return $overload->resultType;
        }

        throw new CheckException(sprintf('no matching overload for "%s" at byte %d', $name, $expr->offset));
    }

    /** @param list<Type> $argTypes */
    private function mathExtremumType(string $name, array $argTypes, Expr $expr): Type
    {
        if ($argTypes === []) {
            throw new CheckException(sprintf('math.%s expects at least one argument at byte %d', $name, $expr->offset));
        }

        if (count($argTypes) === 1 && $argTypes[0]->name() === 'list') {
            $elemType = $argTypes[0]->valueType() ?? Type::dyn();
            $this->expectNumeric($elemType, $expr, 'math.' . $name);

            return $elemType->name() === 'dyn' ? Type::dyn() : $elemType;
        }

        $result = null;
        foreach ($argTypes as $argType) {
            $this->expectNumeric($argType, $expr, 'math.' . $name);
            $result = $result === null ? $argType : $this->unifyTypes($result, $argType);
        }

        return $result ?? Type::dyn();
    }

    /** @param list<Type> $argTypes */
    private function expectArity(string $name, array $argTypes, Expr $expr, int ...$allowed): void
    {
        if (in_array(count($argTypes), $allowed, true)) {
            return;
        }

        $expected = count($allowed) === 1
            ? (string) $allowed[0]
            : implode(' or ', array_map('strval', $allowed));

        throw new CheckException(sprintf('%s expects %s argument(s) at byte %d', $name, $expected, $expr->offset));
    }

    private function expectStringReceiver(string $name, Type $targetType, Expr $expr): void
    {
        if (!in_array($targetType->name(), ['string', 'dyn'], true)) {
            throw new CheckException(sprintf('%s receiver requires string target at byte %d', $name, $expr->offset));
        }
    }

    private function expectSizedType(Type $type, Expr $expr, string $context): void
    {
        if (in_array($type->name(), ['string', 'bytes', 'list', 'map', 'dyn'], true)) {
            return;
        }

        throw new CheckException(sprintf('%s expects string, bytes, list, or map at byte %d', $context, $expr->offset));
    }

    private function expectNumeric(Type $type, Expr $expr, string $context): void
    {
        if ($type->isNumeric() || $type->name() === 'dyn') {
            return;
        }

        throw new CheckException(sprintf('%s expects numeric argument at byte %d', $context, $expr->offset));
    }

    /** @param list<Type> $expected */
    private function expectAssignableToAny(Type $actual, array $expected, Expr $expr, string $context): void
    {
        foreach ($expected as $type) {
            if ($this->isAssignable($actual, $type)) {
                return;
            }
        }

        throw new CheckException(sprintf('%s has no matching overload for %s at byte %d', $context, $actual, $expr->offset));
    }

    private function typeForField(mixed $field): Type
    {
        if ($field->isRepeated()) {
            if ($field->getType() === GPBType::MESSAGE && $field->isMap()) {
                $keyField = $field->getMessageType()->getFieldByNumber(1);
                $valueField = $field->getMessageType()->getFieldByNumber(2);

                return Type::map($this->typeForField($keyField), $this->typeForField($valueField));
            }

            return Type::list($this->typeForScalarField($field));
        }

        return $this->typeForScalarField($field);
    }

    private function typeForScalarField(mixed $field): Type
    {
        return match ($field->getType()) {
            GPBType::BOOL => Type::bool(),
            GPBType::INT32, GPBType::INT64, GPBType::SINT32, GPBType::SINT64, GPBType::SFIXED32, GPBType::SFIXED64, GPBType::ENUM => Type::int(),
            GPBType::UINT32, GPBType::UINT64, GPBType::FIXED32, GPBType::FIXED64 => Type::uint(),
            GPBType::FLOAT, GPBType::DOUBLE => Type::double(),
            GPBType::STRING => Type::string(),
            GPBType::BYTES => Type::bytes(),
            GPBType::MESSAGE => $this->typeForMessageField($field->getMessageType()->getFullName()),
            default => Type::dyn(),
        };
    }

    private function typeForMessageField(string $messageType): Type
    {
        return match ($messageType) {
            'google.protobuf.BoolValue' => Type::message('wrapper.bool'),
            'google.protobuf.BytesValue' => Type::message('wrapper.bytes'),
            'google.protobuf.DoubleValue' => Type::message('wrapper.double'),
            'google.protobuf.FloatValue' => Type::message('wrapper.double'),
            'google.protobuf.Int32Value', 'google.protobuf.Int64Value' => Type::message('wrapper.int64'),
            'google.protobuf.StringValue' => Type::message('wrapper.string'),
            'google.protobuf.UInt32Value', 'google.protobuf.UInt64Value' => Type::message('wrapper.uint64'),
            default => Type::message($messageType),
        };
    }

    private function unifyTypes(Type $left, Type $right): Type
    {
        if ($left->equals($right)) {
            return $left;
        }
        if ($left->name() === 'dyn') {
            return $right;
        }
        if ($right->name() === 'dyn') {
            return $left;
        }
        if ($left->name() === 'null_type') {
            return $right;
        }
        if ($right->name() === 'null_type') {
            return $left;
        }
        if ($left->messageType() !== null && str_starts_with($left->messageType(), 'wrapper.') && $this->wrapperAccepts($left, $right)) {
            return $left;
        }
        if ($right->messageType() !== null && str_starts_with($right->messageType(), 'wrapper.') && $this->wrapperAccepts($right, $left)) {
            return $right;
        }
        if ($left->name() === 'list' && $right->name() === 'list') {
            return Type::list($this->unifyTypes($left->valueType() ?? Type::dyn(), $right->valueType() ?? Type::dyn()));
        }
        if ($left->name() === 'map' && $right->name() === 'map') {
            return Type::map(
                $this->unifyTypes($left->keyType() ?? Type::dyn(), $right->keyType() ?? Type::dyn()),
                $this->unifyTypes($left->valueType() ?? Type::dyn(), $right->valueType() ?? Type::dyn()),
            );
        }

        return Type::dyn();
    }

    private function wrapperAccepts(Type $wrapper, Type $other): bool
    {
        return match ($wrapper->messageType()) {
            'wrapper.bool' => $other->name() === 'bool',
            'wrapper.bytes' => $other->name() === 'bytes',
            'wrapper.double' => $other->name() === 'double',
            'wrapper.int64' => $other->name() === 'int',
            'wrapper.string' => $other->name() === 'string',
            'wrapper.uint64' => $other->name() === 'uint',
            default => false,
        };
    }

    private function expectAssignable(Type $actual, Type $expected, Expr $expr, string $context): void
    {
        if ($this->isAssignable($actual, $expected)) {
            return;
        }

        throw new CheckException(sprintf('%s expects %s, got %s at byte %d', $context, $expected, $actual, $expr->offset));
    }

    private function isAssignable(Type $actual, Type $expected): bool
    {
        if ($actual->name() === 'dyn' || $expected->name() === 'dyn' || $actual->equals($expected)) {
            return true;
        }

        if ($actual->name() === 'null_type' && $expected->name() === 'message') {
            return true;
        }

        if ($expected->name() === 'int' && $this->isEnumType($actual)) {
            return true;
        }

        if ($this->wrapperAccepts($actual, $expected) || $this->wrapperAccepts($expected, $actual)) {
            return true;
        }

        if ($actual->name() === 'list' && $expected->name() === 'list') {
            return $this->isAssignable($actual->valueType() ?? Type::dyn(), $expected->valueType() ?? Type::dyn());
        }

        if ($actual->name() === 'map' && $expected->name() === 'map') {
            return $this->isAssignable($actual->keyType() ?? Type::dyn(), $expected->keyType() ?? Type::dyn())
                && $this->isAssignable($actual->valueType() ?? Type::dyn(), $expected->valueType() ?? Type::dyn());
        }

        if ($expected->name() === 'message' && $this->wellKnownMessageAccepts($actual, $expected->messageType())) {
            return true;
        }

        return false;
    }

    private function wellKnownMessageAccepts(Type $actual, ?string $expectedMessage): bool
    {
        return match ($expectedMessage) {
            'google.protobuf.Any',
            'google.protobuf.Value' => true,
            'google.protobuf.Struct' => $actual->name() === 'map',
            'google.protobuf.ListValue' => $actual->name() === 'list',
            'google.protobuf.Timestamp',
            'google.protobuf.Duration' => $actual->name() === 'string',
            default => false,
        };
    }

    private function descriptorForClass(string $className): mixed
    {
        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($className);
        if ($descriptor !== null) {
            return $descriptor;
        }

        if (class_exists($className)) {
            new $className();
        }

        return DescriptorPool::getGeneratedPool()->getDescriptorByClassName($className);
    }

    private function isTimestampType(Type $type): bool
    {
        return $type->messageType() === 'google.protobuf.Timestamp';
    }

    private function isDurationType(Type $type): bool
    {
        return $type->messageType() === 'google.protobuf.Duration';
    }

    private function isEnumType(Type $type): bool
    {
        return $type->messageType() !== null && $this->protoRegistry?->resolveEnumType($type->messageType()) !== null;
    }
}
