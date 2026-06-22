<?php

declare(strict_types=1);

namespace CEL\Internal;

use CEL\Bytes;
use CEL\DurationValue;
use CEL\FunctionDeclaration;
use CEL\Proto\ProtoRegistry;
use CEL\TimestampValue;
use CEL\Type;
use CEL\UInt;
use CEL\UnsupportedFeatureException;
use Google\Protobuf\GPBEmpty;
use Google\Protobuf\Internal\DescriptorPool;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\NullValue;

final class ProtoConverter
{
    private int $nextId = 1;

    /** @var array<int|string, int> */
    private array $positions = [];

    /** @var array<int, \CEL\Generated\Expr\Type> */
    private array $typeMap = [];

    /** @var array<int, \CEL\Generated\Expr\Reference> */
    private array $referenceMap = [];

    /** @var array<string, Type> */
    private array $variables = [];

    /** @var array<string, FunctionDeclaration> */
    private array $functions = [];

    private string $container = '';

    private ProtoRegistry $protoRegistry;

    /** @var array<int, int> */
    private array $importPositions = [];

    public function toParsedExpr(Expr $expr, string $source, string $sourceDescription = '<input>'): \CEL\Generated\Expr\ParsedExpr
    {
        $this->reset();
        $protoExpr = $this->convertExpr($expr);

        return (new \CEL\Generated\Expr\ParsedExpr())
            ->setExpr($protoExpr)
            ->setSourceInfo($this->sourceInfo($source, $sourceDescription));
    }

    /**
     * @param array<string, Type> $variables
     * @param array<string, FunctionDeclaration> $functions
     */
    public function toCheckedExpr(Expr $expr, string $source, array $variables = [], ?ProtoRegistry $protoRegistry = null, array $functions = [], string $container = '', string $sourceDescription = '<input>'): \CEL\Generated\Expr\CheckedExpr
    {
        $this->reset();
        $this->variables = $variables;
        $this->functions = $functions;
        $this->container = $container;
        $this->protoRegistry = $protoRegistry ?? ProtoRegistry::standard();
        $protoExpr = $this->convertExpr($expr);

        return (new \CEL\Generated\Expr\CheckedExpr())
            ->setExpr($protoExpr)
            ->setExprVersion('1.0')
            ->setReferenceMap($this->referenceMap)
            ->setTypeMap($this->typeMap)
            ->setSourceInfo($this->sourceInfo($source, $sourceDescription));
    }

    public function fromParsedExpr(\CEL\Generated\Expr\ParsedExpr $expr): Expr
    {
        if (!$expr->hasExpr()) {
            throw new UnsupportedFeatureException('ParsedExpr does not contain an expression');
        }

        $this->importPositions = $this->sourcePositions($expr->getSourceInfo());

        return $this->importExpr($expr->getExpr());
    }

    public function fromCheckedExpr(\CEL\Generated\Expr\CheckedExpr $expr): Expr
    {
        if (!$expr->hasExpr()) {
            throw new UnsupportedFeatureException('CheckedExpr does not contain an expression');
        }

        $this->importPositions = $this->sourcePositions($expr->getSourceInfo());

        return $this->importExpr($expr->getExpr());
    }

    public function sourceLocationFromParsedExpr(\CEL\Generated\Expr\ParsedExpr|\CEL\Generated\Expr\CheckedExpr $expr): string
    {
        if (!$expr->hasSourceInfo()) {
            return '<protobuf>';
        }

        $location = $expr->getSourceInfo()->getLocation();

        return $location === '' ? '<protobuf>' : $location;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function convertExpr(Expr $expr, array $locals = []): \CEL\Generated\Expr\Expr
    {
        $id = $this->nextId++;
        $this->positions[$id] = $expr->offset;
        $proto = (new \CEL\Generated\Expr\Expr())->setId($id);

        $converted = match ($expr->kind) {
            'literal' => $proto->setConstExpr($this->constant($expr->value)),
            'ident' => $proto->setIdentExpr((new \CEL\Generated\Expr\Expr\Ident())->setName((string) $expr->value)),
            'select' => $proto->setSelectExpr(
                (new \CEL\Generated\Expr\Expr\Select())
                    ->setOperand($this->convertExpr($expr->target, $locals))
                    ->setField((string) $expr->value),
            ),
            'optional_select' => $proto->setCallExpr($this->call('_?._', [Expr::literal((string) $expr->value, $expr->offset)], $expr->target, $locals)),
            'index' => $proto->setCallExpr($this->call('_[_]', [$expr->target, $expr->args[0]], locals: $locals)),
            'optional_index' => $proto->setCallExpr($this->call('_[?_]', [$expr->target, $expr->args[0]], locals: $locals)),
            'call' => $proto->setCallExpr($this->convertCall($expr, $locals)),
            'unary' => $this->isUnaryIntLiteral($expr)
                ? $proto->setConstExpr($this->int64Constant($this->unaryIntLiteralValue($expr)))
                : $proto->setCallExpr($this->call($this->operatorFunction((string) $expr->value, 1), $expr->args, locals: $locals)),
            'binary' => $proto->setCallExpr($this->call($this->operatorFunction((string) $expr->value, 2), $expr->args, locals: $locals)),
            'conditional' => $proto->setCallExpr($this->call('_?_:_', $expr->args, locals: $locals)),
            'list' => $proto->setListExpr($this->createList($expr, $locals)),
            'map' => $proto->setStructExpr($this->createMap($expr, $locals)),
            'struct' => $proto->setStructExpr($this->createStruct($expr, $locals)),
            'comprehension' => $proto->setComprehensionExpr($this->createComprehension($expr, $locals)),
            default => throw new UnsupportedFeatureException(sprintf('cannot convert expression kind "%s" to protobuf', $expr->kind)),
        };

        $this->recordCheckedMetadata($id, $expr, $locals);

        return $converted;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function createList(Expr $expr, array $locals = []): \CEL\Generated\Expr\Expr\CreateList
    {
        $elements = [];
        $optionalIndices = [];
        foreach ($expr->args as $index => $arg) {
            if ($arg->kind === 'optional_element') {
                $elements[] = $this->convertExpr($arg->args[0], $locals);
                $optionalIndices[] = $index;
                continue;
            }

            $elements[] = $this->convertExpr($arg, $locals);
        }

        return (new \CEL\Generated\Expr\Expr\CreateList())
            ->setElements($elements)
            ->setOptionalIndices($optionalIndices);
    }

    /** @param list<Expr> $args */
    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function call(string $function, array $args, ?Expr $target = null, array $locals = []): \CEL\Generated\Expr\Expr\Call
    {
        $call = (new \CEL\Generated\Expr\Expr\Call())
            ->setFunction($function)
            ->setArgs(array_map(fn (Expr $arg): \CEL\Generated\Expr\Expr => $this->convertExpr($arg, $locals), $args));

        if ($target !== null) {
            $call->setTarget($this->convertExpr($target, $locals));
        }

        return $call;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function convertCall(Expr $expr, array $locals): \CEL\Generated\Expr\Expr\Call
    {
        if ($this->isCelCall($expr, 'bind')) {
            return $this->convertBindCall($expr, $locals);
        }
        if ($this->isCelCall($expr, 'block')) {
            return $this->convertBlockCall($expr, $locals);
        }
        if ($expr->target !== null && in_array((string) $expr->value, ['all', 'exists', 'exists_one', 'existsOne', 'filter', 'map', 'transformList', 'transformMap'], true)) {
            return $this->convertMacroCall($expr, $locals);
        }
        if ($expr->target !== null && in_array((string) $expr->value, ['optMap', 'optFlatMap'], true)) {
            return $this->convertOptionalMapCall($expr, $locals);
        }

        return $this->call((string) $expr->value, $expr->args, $expr->target, $locals);
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function convertBindCall(Expr $expr, array $locals): \CEL\Generated\Expr\Expr\Call
    {
        $args = [];
        $nextLocals = $this->bindLocals($expr, $locals);
        foreach ($expr->args as $index => $arg) {
            $args[] = $this->convertExpr($arg, $index === 2 ? $nextLocals : $locals);
        }

        $call = (new \CEL\Generated\Expr\Expr\Call())
            ->setFunction((string) $expr->value)
            ->setArgs($args);
        if ($expr->target !== null) {
            $call->setTarget($this->convertExpr($expr->target, $locals));
        }

        return $call;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function convertBlockCall(Expr $expr, array $locals): \CEL\Generated\Expr\Expr\Call
    {
        $args = [];
        $blockLocals = $locals;
        if (($expr->args[0] ?? null) instanceof Expr && $expr->args[0]->kind === 'list') {
            [$args[], $blockLocals] = $this->convertBlockSequence($expr->args[0], $locals);
        } elseif (isset($expr->args[0])) {
            $args[] = $this->convertExpr($expr->args[0], $locals);
        }
        if (isset($expr->args[1])) {
            $args[] = $this->convertExpr($expr->args[1], $blockLocals);
        }

        $call = (new \CEL\Generated\Expr\Expr\Call())
            ->setFunction((string) $expr->value)
            ->setArgs($args);
        if ($expr->target !== null) {
            $call->setTarget($this->convertExpr($expr->target, $locals));
        }

        return $call;
    }

    /**
     * @param array<string, \CEL\Generated\Expr\Type> $locals
     * @return array{0:\CEL\Generated\Expr\Expr,1:array<string, \CEL\Generated\Expr\Type>}
     */
    private function convertBlockSequence(Expr $sequence, array $locals): array
    {
        $id = $this->nextId++;
        $this->positions[$id] = $sequence->offset;
        $blockLocals = $locals;
        $elements = [];
        $optionalIndices = [];
        foreach ($sequence->args as $index => $entry) {
            if ($entry->kind === 'optional_element') {
                $elements[] = $this->convertExpr($entry->args[0], $blockLocals);
                $optionalIndices[] = $index;
            } else {
                $elements[] = $this->convertExpr($entry, $blockLocals);
            }

            $blockLocals[$this->blockIndexName($index)] = $this->exprType($entry, $blockLocals) ?? $this->dynType();
        }

        $proto = (new \CEL\Generated\Expr\Expr())
            ->setId($id)
            ->setListExpr(
                (new \CEL\Generated\Expr\Expr\CreateList())
                    ->setElements($elements)
                    ->setOptionalIndices($optionalIndices),
            );
        $this->recordCheckedMetadata($id, $sequence, $locals);

        return [$proto, $blockLocals];
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function convertMacroCall(Expr $expr, array $locals): \CEL\Generated\Expr\Expr\Call
    {
        $name = (string) $expr->value;
        $targetType = $expr->target !== null ? $this->exprType($expr->target, $locals) : null;
        $macroLocals = $this->macroLocals($name, $expr, $targetType, $locals);
        $last = array_key_last($expr->args);
        $args = [];
        foreach ($expr->args as $index => $arg) {
            $args[] = $this->convertExpr($arg, $this->macroArgumentUsesBodyLocals($name, $index, $last) ? $macroLocals : $locals);
        }

        $call = (new \CEL\Generated\Expr\Expr\Call())
            ->setFunction($name)
            ->setArgs($args);
        if ($expr->target !== null) {
            $call->setTarget($this->convertExpr($expr->target, $locals));
        }

        return $call;
    }

    private function macroArgumentUsesBodyLocals(string $name, int $index, int|string|null $last): bool
    {
        if ($last === null) {
            return false;
        }

        return $index === (int) $last || (in_array($name, ['transformList', 'transformMap'], true) && $index === 2);
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function convertOptionalMapCall(Expr $expr, array $locals): \CEL\Generated\Expr\Expr\Call
    {
        $targetType = $expr->target !== null ? $this->exprType($expr->target, $locals) : null;
        $nextLocals = $locals;
        $var = $expr->args[0] ?? null;
        if ($var instanceof Expr) {
            $name = $this->macroVariableName($var);
            if ($name !== null) {
                $nextLocals[$name] = $targetType !== null ? ($this->optionalParameterType($targetType) ?? $this->dynType()) : $this->dynType();
            }
        }

        $args = [];
        foreach ($expr->args as $index => $arg) {
            $args[] = $this->convertExpr($arg, $index === 1 ? $nextLocals : $locals);
        }

        $call = (new \CEL\Generated\Expr\Expr\Call())
            ->setFunction((string) $expr->value)
            ->setArgs($args);
        if ($expr->target !== null) {
            $call->setTarget($this->convertExpr($expr->target, $locals));
        }

        return $call;
    }

    private function constant(mixed $value): \CEL\Generated\Expr\Constant
    {
        $constant = new \CEL\Generated\Expr\Constant();

        if ($value === null) {
            return $constant->setNullValue(\Google\Protobuf\NullValue::NULL_VALUE);
        }
        if (is_bool($value)) {
            return $constant->setBoolValue($value);
        }
        if ($value instanceof IntLiteral) {
            return $this->int64Constant($value->decimal);
        }
        if (is_int($value)) {
            return $constant->setInt64Value($value);
        }
        if ($value instanceof UInt) {
            return $this->uint64Constant($value->value());
        }
        if (is_float($value)) {
            return $constant->setDoubleValue($value);
        }
        if (is_string($value)) {
            return $constant->setStringValue($value);
        }
        if ($value instanceof Bytes) {
            return $constant->setBytesValue($value->raw());
        }
        if ($value instanceof TimestampValue) {
            @$constant->setTimestampValue(
                (new \Google\Protobuf\Timestamp())
                    ->setSeconds($value->unixSeconds())
                    ->setNanos($value->nanos()),
            );

            return $constant;
        }
        if ($value instanceof DurationValue) {
            @$constant->setDurationValue(
                (new \Google\Protobuf\Duration())
                    ->setSeconds($value->wholeSeconds())
                    ->setNanos($value->nanos()),
            );

            return $constant;
        }

        throw new UnsupportedFeatureException('unsupported literal for protobuf conversion');
    }

    private function isUnaryIntLiteral(Expr $expr): bool
    {
        return $expr->value === '-'
            && count($expr->args) === 1
            && $expr->args[0]->kind === 'literal'
            && (is_int($expr->args[0]->value) || $expr->args[0]->value instanceof IntLiteral);
    }

    private function unaryIntLiteralValue(Expr $expr): string
    {
        $value = $expr->args[0]->value;
        if ($value instanceof IntLiteral) {
            return '-' . $value->decimal;
        }

        return (string) -$value;
    }

    private function int64Constant(int|string $value): \CEL\Generated\Expr\Constant
    {
        if (is_int($value) || (bccomp((string) $value, (string) PHP_INT_MIN, 0) >= 0 && bccomp((string) $value, (string) PHP_INT_MAX, 0) <= 0)) {
            return (new \CEL\Generated\Expr\Constant())->setInt64Value($value);
        }

        $constant = new \CEL\Generated\Expr\Constant();
        $constant->mergeFromJsonString(json_encode(['int64Value' => (string) $value], JSON_THROW_ON_ERROR));

        return $constant;
    }

    private function uint64Constant(string $value): \CEL\Generated\Expr\Constant
    {
        if (bccomp($value, (string) PHP_INT_MAX, 0) <= 0) {
            return (new \CEL\Generated\Expr\Constant())->setUint64Value($value);
        }

        $constant = new \CEL\Generated\Expr\Constant();
        $constant->mergeFromJsonString(json_encode(['uint64Value' => $value], JSON_THROW_ON_ERROR));

        return $constant;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function createMap(Expr $expr, array $locals = []): \CEL\Generated\Expr\Expr\CreateStruct
    {
        $entries = [];
        foreach ($expr->entries as $entry) {
            $id = $this->nextId++;
            $this->positions[$id] = $entry['key']->offset;
            $entries[] = (new \CEL\Generated\Expr\Expr\CreateStruct\Entry())
                ->setId($id)
                ->setMapKey($this->convertExpr($entry['key'], $locals))
                ->setValue($this->convertExpr($entry['value'], $locals))
                ->setOptionalEntry((bool) ($entry['optional'] ?? false));
        }

        return (new \CEL\Generated\Expr\Expr\CreateStruct())->setEntries($entries);
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function createStruct(Expr $expr, array $locals = []): \CEL\Generated\Expr\Expr\CreateStruct
    {
        $entries = [];
        foreach ($expr->entries as $field => $value) {
            $fieldName = (string) $field;
            $fieldValue = $value;
            $optional = false;
            if (is_array($value) && array_key_exists('field', $value)) {
                $fieldName = (string) $value['field'];
                $fieldValue = $value['value'];
                $optional = (bool) ($value['optional'] ?? false);
            }
            $id = $this->nextId++;
            $this->positions[$id] = $fieldValue->offset;
            $entries[] = (new \CEL\Generated\Expr\Expr\CreateStruct\Entry())
                ->setId($id)
                ->setFieldKey($fieldName)
                ->setValue($this->convertExpr($fieldValue, $locals))
                ->setOptionalEntry($optional);
        }

        return (new \CEL\Generated\Expr\Expr\CreateStruct())
            ->setMessageName((string) $expr->value)
            ->setEntries($entries);
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function createComprehension(Expr $expr, array $locals = []): \CEL\Generated\Expr\Expr\Comprehension
    {
        if (count($expr->args) !== 5 || !is_array($expr->value)) {
            throw new UnsupportedFeatureException('invalid internal comprehension expression');
        }

        $rangeType = $this->exprType($expr->args[0], $locals);
        $accuType = $this->exprType($expr->args[1], $locals) ?? $this->dynType();
        [$keyType, $valueType] = $this->iterationProtoTypes($rangeType);
        $iterVar = (string) ($expr->value['iter_var'] ?? '');
        $iterVar2 = (string) ($expr->value['iter_var2'] ?? '');
        $accuVar = (string) ($expr->value['accu_var'] ?? '');

        $loopLocals = $locals;
        $loopLocals[$accuVar] = $accuType;
        if ($iterVar2 === '') {
            $loopLocals[$iterVar] = $rangeType !== null && $rangeType->hasMapType() ? $keyType : $valueType;
        } else {
            $loopLocals[$iterVar] = $keyType;
            $loopLocals[$iterVar2] = $valueType;
        }

        $resultLocals = $locals;
        $resultLocals[$accuVar] = $this->commonProtoType($accuType, $this->exprType($expr->args[3], $loopLocals)) ?? $accuType;

        $comprehension = (new \CEL\Generated\Expr\Expr\Comprehension())
            ->setIterVar($iterVar)
            ->setAccuVar($accuVar)
            ->setIterRange($this->convertExpr($expr->args[0], $locals))
            ->setAccuInit($this->convertExpr($expr->args[1], $locals))
            ->setLoopCondition($this->convertExpr($expr->args[2], $loopLocals))
            ->setLoopStep($this->convertExpr($expr->args[3], $loopLocals))
            ->setResult($this->convertExpr($expr->args[4], $resultLocals));

        if (($expr->value['iter_var2'] ?? '') !== '') {
            $comprehension->setIterVar2((string) $expr->value['iter_var2']);
        }

        return $comprehension;
    }

    private function sourceInfo(string $source, string $sourceDescription): \CEL\Generated\Expr\SourceInfo
    {
        return (new \CEL\Generated\Expr\SourceInfo())
            ->setSyntaxVersion('cel1')
            ->setLocation($sourceDescription === '' ? '<input>' : $sourceDescription)
            ->setLineOffsets($this->lineOffsets($source))
            ->setPositions($this->positions);
    }

    private function reset(): void
    {
        $this->nextId = 1;
        $this->positions = [];
        $this->typeMap = [];
        $this->referenceMap = [];
        $this->variables = [];
        $this->functions = [];
        $this->container = '';
        $this->protoRegistry = ProtoRegistry::standard();
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function recordCheckedMetadata(int $id, Expr $expr, array $locals = []): void
    {
        $type = $this->exprType($expr, $locals);
        if ($type !== null) {
            $this->typeMap[$id] = $this->completeProtoType($type);
        }

        $reference = $this->reference($expr);
        if ($reference !== null) {
            $this->referenceMap[$id] = $reference;
        }
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function exprType(Expr $expr, array $locals = []): ?\CEL\Generated\Expr\Type
    {
        return match ($expr->kind) {
            'literal' => $this->literalProtoType($expr->value),
            'ident' => $this->identifierProtoType((string) $expr->value, $locals),
            'unary' => (string) $expr->value === '!' ? $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL) : $this->exprType($expr->args[0], $locals),
            'binary' => $this->binaryProtoType($expr, $locals),
            'conditional' => $this->commonProtoType($this->exprType($expr->args[1], $locals), $this->exprType($expr->args[2], $locals)),
            'list' => $this->listProtoType($expr, $locals),
            'map' => $this->mapProtoType($expr, $locals),
            'select' => $this->selectProtoType($this->exprType($expr->target, $locals), (string) $expr->value),
            'index' => $this->indexProtoType($this->exprType($expr->target, $locals)),
            'optional_select', 'optional_index', 'optional_element' => $this->optionalProtoType(),
            'call' => $this->callProtoType($expr, $locals),
            'struct' => $this->protoTypeForMessageType($this->protoRegistry->resolveMessageType((string) $expr->value) ?? (string) $expr->value),
            'comprehension' => null,
            default => null,
        };
    }

    private function reference(Expr $expr): ?\CEL\Generated\Expr\Reference
    {
        if ($expr->kind === 'ident') {
            $name = (string) $expr->value;
            $enumValue = $this->protoRegistry->resolveEnumConstant($name);
            $reference = (new \CEL\Generated\Expr\Reference())->setName($name);
            if ($enumValue !== null) {
                $reference->setValue((new \CEL\Generated\Expr\Constant())->setInt64Value($enumValue));
            }

            return array_key_exists($name, $this->variables)
                || $enumValue !== null
                || $this->protoRegistry->resolveMessage($name) !== null
                ? $reference
                : null;
        }

        if ($expr->kind === 'call') {
            return $this->functionReference((string) $expr->value);
        }

        if ($expr->kind === 'unary') {
            return $this->functionReference($this->operatorFunction((string) $expr->value, 1));
        }

        if ($expr->kind === 'binary') {
            return $this->functionReference($this->operatorFunction((string) $expr->value, 2));
        }

        if ($expr->kind === 'conditional') {
            return $this->functionReference('_?_:_');
        }

        if ($expr->kind === 'index') {
            return $this->functionReference('_[_]');
        }

        if ($expr->kind === 'optional_index') {
            return $this->functionReference('_[?_]');
        }

        if ($expr->kind === 'optional_select') {
            return $this->functionReference('_?._');
        }

        if ($expr->kind === 'struct') {
            return (new \CEL\Generated\Expr\Reference())->setName((string) $expr->value);
        }

        return null;
    }

    private function functionReference(string $name): \CEL\Generated\Expr\Reference
    {
        return (new \CEL\Generated\Expr\Reference())->setName($name)->setOverloadId([$name]);
    }

    private function literalProtoType(mixed $value): \CEL\Generated\Expr\Type
    {
        if ($value === null) {
            return (new \CEL\Generated\Expr\Type())->setNull(NullValue::NULL_VALUE);
        }
        if (is_bool($value)) {
            return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL);
        }
        if (is_int($value) || $value instanceof IntLiteral) {
            return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::INT64);
        }
        if ($value instanceof UInt) {
            return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::UINT64);
        }
        if (is_float($value)) {
            return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::DOUBLE);
        }
        if (is_string($value)) {
            return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::STRING);
        }
        if ($value instanceof Bytes) {
            return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BYTES);
        }
        if ($value instanceof TimestampValue) {
            return (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP);
        }
        if ($value instanceof DurationValue) {
            return (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::DURATION);
        }

        return $this->dynType();
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function identifierProtoType(string $name, array $locals = []): ?\CEL\Generated\Expr\Type
    {
        if (isset($locals[$name])) {
            return $locals[$name];
        }

        $absolute = str_starts_with($name, '.');
        $normalized = ltrim($name, '.');
        if (!$absolute) {
            $localRootType = $this->localRootProtoType($normalized, $locals);
            if ($localRootType !== null) {
                return $localRootType;
            }
        }

        foreach ($this->candidateNames($name) as $candidate) {
            $type = $this->variableCandidateProtoType($candidate, $locals);
            if ($type !== null) {
                return $type;
            }

            if ($this->protoRegistry->resolveEnumConstant($candidate) !== null) {
                return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::INT64);
            }

            if ($this->protoRegistry->resolveMessage($candidate) !== null) {
                return (new \CEL\Generated\Expr\Type())->setType($this->dynType());
            }
        }

        if (in_array($normalized, ['dyn', 'bool', 'int', 'uint', 'double', 'string', 'bytes', 'null_type', 'type', 'net.IP', 'net.CIDR'], true)) {
            return (new \CEL\Generated\Expr\Type())->setType($this->dynType());
        }

        return null;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function localRootProtoType(string $name, array $locals): ?\CEL\Generated\Expr\Type
    {
        $parts = explode('.', $name);
        $root = array_shift($parts);
        if ($root === null || !isset($locals[$root])) {
            return null;
        }

        $type = $locals[$root];
        foreach ($parts as $field) {
            $type = $this->selectProtoType($type, $field);
            if ($type === null) {
                return null;
            }
        }

        return $type;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function variableCandidateProtoType(string $name, array $locals): ?\CEL\Generated\Expr\Type
    {
        if (isset($locals[$name])) {
            return $locals[$name];
        }
        if (isset($this->variables[$name])) {
            return $this->celTypeToProto($this->variables[$name]);
        }

        $segments = explode('.', $name);
        for ($i = count($segments) - 1; $i >= 1; $i--) {
            $prefix = implode('.', array_slice($segments, 0, $i));
            $type = isset($locals[$prefix])
                ? $locals[$prefix]
                : (isset($this->variables[$prefix]) ? $this->celTypeToProto($this->variables[$prefix]) : null);
            if ($type === null) {
                continue;
            }

            foreach (array_slice($segments, $i) as $field) {
                $type = $this->selectProtoType($type, $field);
                if ($type === null) {
                    return null;
                }
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

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function callProtoType(Expr $expr, array $locals = []): ?\CEL\Generated\Expr\Type
    {
        $name = (string) $expr->value;

        if ($expr->target !== null && $expr->target->kind === 'ident') {
            $namespace = (string) $expr->target->value;
            if ($namespace === 'optional') {
                return match ($name) {
                    'none' => $this->optionalNoneProtoType(),
                    'of', 'ofNonZeroValue' => $this->optionalProtoType(isset($expr->args[0]) ? $this->exprType($expr->args[0], $locals) : null),
                    default => null,
                };
            }
            if ($namespace === 'cel') {
                return match ($name) {
                    'bind' => $this->bindProtoType($expr, $locals),
                    'block' => $this->blockProtoType($expr, $locals),
                    'index' => $locals[$this->blockIndexName($this->singleLiteralIntArg($expr))] ?? $this->dynType(),
                    'iterVar' => $locals[$this->iterVarName(...$this->twoLiteralIntArgs($expr))] ?? $this->dynType(),
                    'accuVar' => $locals[$this->accuVarName(...$this->twoLiteralIntArgs($expr))] ?? $this->dynType(),
                    default => null,
                };
            }
            if ($namespace === 'base64') {
                return match ($name) {
                    'encode' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::STRING),
                    'decode' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BYTES),
                    default => null,
                };
            }
            if ($namespace === 'math') {
                return match ($name) {
                    'isNaN', 'isInf', 'isFinite' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
                    'ceil', 'floor', 'round', 'trunc' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::DOUBLE),
                    default => null,
                };
            }
            if ($namespace === 'strings' && $name === 'quote') {
                return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::STRING);
            }
            if ($namespace === 'ip' && $name === 'isCanonical') {
                return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL);
            }
        }

        if ($expr->target !== null) {
            $targetType = $this->exprType($expr->target, $locals);
            if ($targetType?->hasMessageType() && $targetType->getMessageType() === 'net.IP') {
                return match ($name) {
                    'family' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::INT64),
                    'isUnspecified', 'isLoopback', 'isGlobalUnicast', 'isLinkLocalMulticast', 'isLinkLocalUnicast' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
                    default => null,
                };
            }
            if ($targetType?->hasMessageType() && $targetType->getMessageType() === 'net.CIDR') {
                return match ($name) {
                    'containsIP', 'containsCIDR' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
                    'ip' => (new \CEL\Generated\Expr\Type())->setMessageType('net.IP'),
                    'masked' => (new \CEL\Generated\Expr\Type())->setMessageType('net.CIDR'),
                    'prefixLength' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::INT64),
                    default => null,
                };
            }
            if (in_array($name, ['all', 'exists', 'exists_one', 'existsOne'], true)) {
                return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL);
            }
            if ($name === 'filter') {
                return $targetType?->hasListType() ? $targetType : (new \CEL\Generated\Expr\Type())->setListType(
                    (new \CEL\Generated\Expr\Type\ListType())->setElemType($this->dynType()),
                );
            }
            if ($name === 'map') {
                return $this->mapMacroProtoType($expr, $targetType, $locals);
            }
            if ($name === 'transformList') {
                return $this->transformListProtoType($expr, $targetType, $locals);
            }
            if ($name === 'transformMap') {
                return $this->transformMapProtoType($expr, $targetType, $locals);
            }
            if (isset($this->functions[$name])) {
                return $this->customFunctionProtoType($expr, true, $locals);
            }

            return match ($name) {
                'startsWith', 'endsWith', 'contains', 'matches', 'hasValue' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
                'charAt', 'lowerAscii', 'upperAscii', 'replace', 'substring', 'trim', 'reverse', 'format', 'join' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::STRING),
                'indexOf', 'lastIndexOf', 'size' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::INT64),
                'split' => (new \CEL\Generated\Expr\Type())->setListType(
                    (new \CEL\Generated\Expr\Type\ListType())->setElemType($this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::STRING)),
                ),
                'or', 'optMap', 'optFlatMap' => $this->optionalProtoType(),
                default => null,
            };
        }

        if (isset($this->functions[$name])) {
            return $this->customFunctionProtoType($expr, false, $locals);
        }

        return match ($name) {
            'size', 'int' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::INT64),
            'uint' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::UINT64),
            'double' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::DOUBLE),
            'string' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::STRING),
            'bytes' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BYTES),
            'bool', 'has' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
            'type' => (new \CEL\Generated\Expr\Type())->setType($this->dynType()),
            'duration' => (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::DURATION),
            'timestamp' => (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP),
            'ip' => (new \CEL\Generated\Expr\Type())->setMessageType('net.IP'),
            'cidr' => (new \CEL\Generated\Expr\Type())->setMessageType('net.CIDR'),
            'isIP' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
            'dyn' => $this->dynType(),
            default => null,
        };
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function bindProtoType(Expr $expr, array $locals): ?\CEL\Generated\Expr\Type
    {
        return isset($expr->args[2]) ? $this->exprType($expr->args[2], $this->bindLocals($expr, $locals)) : null;
    }

    /**
     * @param array<string, \CEL\Generated\Expr\Type> $locals
     * @return array<string, \CEL\Generated\Expr\Type>
     */
    private function bindLocals(Expr $expr, array $locals): array
    {
        if (count($expr->args) !== 3) {
            return $locals;
        }

        $var = $expr->args[0];
        if ($var->kind !== 'ident') {
            return $locals;
        }

        $nextLocals = $locals;
        $nextLocals[(string) $var->value] = $this->exprType($expr->args[1], $locals) ?? $this->dynType();

        return $nextLocals;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function blockProtoType(Expr $expr, array $locals): ?\CEL\Generated\Expr\Type
    {
        if (count($expr->args) !== 2 || $expr->args[0]->kind !== 'list') {
            return null;
        }

        return $this->exprType($expr->args[1], $this->blockLocals($expr->args[0], $locals));
    }

    /**
     * @param array<string, \CEL\Generated\Expr\Type> $locals
     * @return array<string, \CEL\Generated\Expr\Type>
     */
    private function blockLocals(Expr $sequence, array $locals): array
    {
        $blockLocals = $locals;
        foreach ($sequence->args as $index => $entry) {
            $blockLocals[$this->blockIndexName($index)] = $this->exprType($entry, $blockLocals) ?? $this->dynType();
        }

        return $blockLocals;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function listProtoType(Expr $expr, array $locals = []): \CEL\Generated\Expr\Type
    {
        $elemType = null;
        foreach ($expr->args as $arg) {
            $type = $arg->kind === 'optional_element' ? $this->dynType() : $this->exprType($arg, $locals);
            $elemType = $this->commonProtoType($elemType, $type);
        }

        $listType = new \CEL\Generated\Expr\Type\ListType();
        if ($elemType !== null) {
            $listType->setElemType($elemType);
        }

        return (new \CEL\Generated\Expr\Type())->setListType($listType);
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function mapProtoType(Expr $expr, array $locals = []): \CEL\Generated\Expr\Type
    {
        $keyType = null;
        $valueType = null;
        foreach ($expr->entries as $entry) {
            $keyType = $this->commonProtoType($keyType, $this->exprType($entry['key'], $locals));
            $valueType = $this->commonProtoType($valueType, ($entry['optional'] ?? false) ? $this->dynType() : $this->exprType($entry['value'], $locals));
        }

        return (new \CEL\Generated\Expr\Type())->setMapType(
            (new \CEL\Generated\Expr\Type\MapType())
                ->setKeyType($keyType ?? $this->dynType())
                ->setValueType($valueType ?? $this->dynType()),
        );
    }

    private function selectProtoType(?\CEL\Generated\Expr\Type $targetType, string $field): ?\CEL\Generated\Expr\Type
    {
        if ($targetType === null) {
            return null;
        }
        if ($targetType->hasDyn()) {
            return $this->dynType();
        }
        if (!$targetType->hasMessageType()) {
            return null;
        }

        $className = $this->protoRegistry->resolveMessage($targetType->getMessageType());
        if ($className === null) {
            return null;
        }

        $descriptor = $this->descriptorForClass($className);
        $fieldDescriptor = $descriptor?->getFieldByName($field) ?? $descriptor?->getFieldByJsonName($field);
        if ($fieldDescriptor === null) {
            return null;
        }

        return $this->protoTypeForField($fieldDescriptor);
    }

    private function indexProtoType(?\CEL\Generated\Expr\Type $targetType): ?\CEL\Generated\Expr\Type
    {
        if ($targetType === null) {
            return null;
        }

        if ($targetType->hasListType() && $targetType->getListType()->hasElemType()) {
            return $targetType->getListType()->getElemType();
        }

        if ($targetType->hasMapType() && $targetType->getMapType()->hasValueType()) {
            return $targetType->getMapType()->getValueType();
        }

        return null;
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function binaryProtoType(Expr $expr, array $locals): ?\CEL\Generated\Expr\Type
    {
        $operator = (string) $expr->value;
        $left = $this->exprType($expr->args[0], $locals);
        $right = $this->exprType($expr->args[1], $locals);

        if (in_array($operator, ['==', '!=', '<', '<=', '>', '>=', 'in', '&&', '||'], true)) {
            return $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL);
        }
        if ($left === null || $right === null) {
            return null;
        }

        if ($operator === '+') {
            if (($this->isTimestampProtoType($left) && $this->isDurationProtoType($right)) || ($this->isDurationProtoType($left) && $this->isTimestampProtoType($right))) {
                return (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP);
            }
            if ($this->isDurationProtoType($left) && $this->isDurationProtoType($right)) {
                return (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::DURATION);
            }
            if ($left->hasPrimitive() && $right->hasPrimitive() && $left->getPrimitive() === $right->getPrimitive()) {
                return $left;
            }
            if ($left->hasListType() && $right->hasListType()) {
                $listType = new \CEL\Generated\Expr\Type\ListType();
                $elemType = $this->commonProtoType(
                    $left->getListType()->hasElemType() ? $left->getListType()->getElemType() : null,
                    $right->getListType()->hasElemType() ? $right->getListType()->getElemType() : null,
                );
                if ($elemType !== null) {
                    $listType->setElemType($elemType);
                }

                return (new \CEL\Generated\Expr\Type())->setListType($listType);
            }
        }

        if ($operator === '-') {
            if ($this->isTimestampProtoType($left) && $this->isDurationProtoType($right)) {
                return (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP);
            }
            if (($this->isTimestampProtoType($left) && $this->isTimestampProtoType($right)) || ($this->isDurationProtoType($left) && $this->isDurationProtoType($right))) {
                return (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::DURATION);
            }
        }

        if (in_array($operator, ['+', '-', '*', '/', '%'], true)) {
            if ($left->hasWrapper() && $right->hasPrimitive() && $left->getWrapper() === $right->getPrimitive()) {
                return $right;
            }
            if ($right->hasWrapper() && $left->hasPrimitive() && $right->getWrapper() === $left->getPrimitive()) {
                return $left;
            }
            if ($left->hasDyn() || $right->hasDyn()) {
                return $this->dynType();
            }
            if ($left->hasPrimitive() && $right->hasPrimitive() && $left->getPrimitive() === $right->getPrimitive()) {
                return $left;
            }
        }

        return $this->protoTypesEqual($left, $right) ? $left : $this->dynType();
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function mapMacroProtoType(Expr $expr, ?\CEL\Generated\Expr\Type $targetType, array $locals): \CEL\Generated\Expr\Type
    {
        $nextLocals = $this->macroLocals('map', $expr, $targetType, $locals);
        $body = $expr->args[array_key_last($expr->args)] ?? null;
        $bodyType = $body instanceof Expr ? $this->exprType($body, $nextLocals) : null;

        return (new \CEL\Generated\Expr\Type())->setListType(
            (new \CEL\Generated\Expr\Type\ListType())->setElemType($bodyType ?? $this->dynType()),
        );
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function transformListProtoType(Expr $expr, ?\CEL\Generated\Expr\Type $targetType, array $locals): \CEL\Generated\Expr\Type
    {
        $bodyType = $this->transformMacroBodyType($expr, $targetType, $locals);

        return (new \CEL\Generated\Expr\Type())->setListType(
            (new \CEL\Generated\Expr\Type\ListType())->setElemType($bodyType ?? $this->dynType()),
        );
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function transformMapProtoType(Expr $expr, ?\CEL\Generated\Expr\Type $targetType, array $locals): \CEL\Generated\Expr\Type
    {
        [$keyType] = $this->iterationProtoTypes($targetType);
        $bodyType = $this->transformMacroBodyType($expr, $targetType, $locals);

        return (new \CEL\Generated\Expr\Type())->setMapType(
            (new \CEL\Generated\Expr\Type\MapType())
                ->setKeyType($keyType)
                ->setValueType($bodyType ?? $this->dynType()),
        );
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function transformMacroBodyType(Expr $expr, ?\CEL\Generated\Expr\Type $targetType, array $locals): ?\CEL\Generated\Expr\Type
    {
        $nextLocals = $this->macroLocals((string) $expr->value, $expr, $targetType, $locals);
        $body = $expr->args[array_key_last($expr->args)] ?? null;

        return $body instanceof Expr ? $this->exprType($body, $nextLocals) : null;
    }

    /**
     * @param array<string, \CEL\Generated\Expr\Type> $locals
     * @return array<string, \CEL\Generated\Expr\Type>
     */
    private function macroLocals(string $name, Expr $expr, ?\CEL\Generated\Expr\Type $targetType, array $locals): array
    {
        [$keyType, $valueType] = $this->iterationProtoTypes($targetType);
        $nextLocals = $locals;
        $hasSecondVar = in_array($name, ['transformList', 'transformMap'], true)
            ? in_array(count($expr->args), [3, 4], true)
            : count($expr->args) === 3;

        $first = $expr->args[0] ?? null;
        if ($first instanceof Expr) {
            $var = $this->macroVariableName($first);
            if ($var !== null) {
                $nextLocals[$var] = $hasSecondVar
                    ? $keyType
                    : ($targetType !== null && $targetType->hasMapType() ? $keyType : $valueType);
            }
        }

        if ($hasSecondVar) {
            $second = $expr->args[1] ?? null;
            if ($second instanceof Expr) {
                $var = $this->macroVariableName($second);
                if ($var !== null) {
                    $nextLocals[$var] = $valueType;
                }
            }
        }

        return $nextLocals;
    }

    private function macroVariableName(Expr $expr): ?string
    {
        if ($expr->kind === 'ident' && !str_contains((string) $expr->value, '.')) {
            return (string) $expr->value;
        }

        return $this->syntheticCelVariableName($expr);
    }

    private function syntheticCelVariableName(Expr $expr): ?string
    {
        if ($expr->kind !== 'call' || $expr->target === null || !$this->isCelTarget($expr)) {
            return null;
        }

        return match ((string) $expr->value) {
            'iterVar' => $this->iterVarName(...$this->twoLiteralIntArgs($expr)),
            'accuVar' => $this->accuVarName(...$this->twoLiteralIntArgs($expr)),
            default => null,
        };
    }

    private function isCelCall(Expr $expr, string $name): bool
    {
        return $expr->kind === 'call' && (string) $expr->value === $name && $this->isCelTarget($expr);
    }

    private function isCelTarget(Expr $expr): bool
    {
        return $expr->target !== null && $expr->target->kind === 'ident' && (string) $expr->target->value === 'cel';
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

    private function singleLiteralIntArg(Expr $expr): int
    {
        if (count($expr->args) !== 1) {
            return 0;
        }

        return $this->literalIntArg($expr->args[0]);
    }

    /** @return array{0:int, 1:int} */
    private function twoLiteralIntArgs(Expr $expr): array
    {
        if (count($expr->args) !== 2) {
            return [0, 0];
        }

        return [$this->literalIntArg($expr->args[0]), $this->literalIntArg($expr->args[1])];
    }

    private function literalIntArg(Expr $expr): int
    {
        if ($expr->kind !== 'literal') {
            return 0;
        }

        if ($expr->value instanceof IntLiteral) {
            return $expr->value->toInt();
        }

        return is_int($expr->value) ? $expr->value : 0;
    }

    /** @return array{0: \CEL\Generated\Expr\Type, 1: \CEL\Generated\Expr\Type} */
    private function iterationProtoTypes(?\CEL\Generated\Expr\Type $targetType): array
    {
        if ($targetType !== null && $targetType->hasListType()) {
            return [
                $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::INT64),
                $targetType->getListType()->hasElemType() ? $targetType->getListType()->getElemType() : $this->dynType(),
            ];
        }

        if ($targetType !== null && $targetType->hasMapType()) {
            return [
                $targetType->getMapType()->hasKeyType() ? $targetType->getMapType()->getKeyType() : $this->dynType(),
                $targetType->getMapType()->hasValueType() ? $targetType->getMapType()->getValueType() : $this->dynType(),
            ];
        }

        return [$this->dynType(), $this->dynType()];
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $locals */
    private function customFunctionProtoType(Expr $expr, bool $receiverStyle, array $locals): ?\CEL\Generated\Expr\Type
    {
        $name = (string) $expr->value;
        $declaration = $this->functions[$name] ?? null;
        if (!$declaration instanceof FunctionDeclaration) {
            return null;
        }

        $actualTypes = [];
        if ($receiverStyle && $expr->target !== null) {
            $targetType = $this->exprType($expr->target, $locals);
            if ($targetType === null) {
                return null;
            }
            $actualTypes[] = $targetType;
        }
        foreach ($expr->args as $arg) {
            $argType = $this->exprType($arg, $locals);
            if ($argType === null) {
                return null;
            }
            $actualTypes[] = $argType;
        }

        foreach ($declaration->overloads as $overload) {
            if ($overload->receiverStyle !== $receiverStyle || count($overload->argumentTypes) !== count($actualTypes)) {
                continue;
            }

            $paramTypes = $overload->protoArgumentTypes !== []
                ? $overload->protoArgumentTypes
                : array_map(fn (Type $type): \CEL\Generated\Expr\Type => $this->celTypeToProto($type), $overload->argumentTypes);

            $bindings = [];
            foreach ($paramTypes as $index => $paramType) {
                if (!$paramType instanceof \CEL\Generated\Expr\Type || !$this->bindTypeParams($paramType, $actualTypes[$index], $bindings)) {
                    continue 2;
                }
            }

            if ($overload->protoResultType instanceof \CEL\Generated\Expr\Type) {
                return $this->substituteTypeParams($overload->protoResultType, $bindings);
            }

            return $this->celTypeToProto($overload->resultType);
        }

        return null;
    }

    private function commonProtoType(?\CEL\Generated\Expr\Type $left, ?\CEL\Generated\Expr\Type $right): ?\CEL\Generated\Expr\Type
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }
        if ($this->isOptionalProtoType($left) && $this->isOptionalProtoType($right)) {
            $leftParam = $this->optionalParameterType($left);
            $rightParam = $this->optionalParameterType($right);
            if ($leftParam === null && $rightParam === null) {
                return $this->optionalProtoType();
            }

            return $this->optionalProtoType($this->commonProtoType($leftParam, $rightParam) ?? $this->dynType());
        }
        if ($left->hasAbstractType() && $right->hasAbstractType() && $left->getAbstractType()->getName() === $right->getAbstractType()->getName()) {
            $leftParams = iterator_to_array($left->getAbstractType()->getParameterTypes());
            $rightParams = iterator_to_array($right->getAbstractType()->getParameterTypes());
            if (count($leftParams) === count($rightParams)) {
                $params = [];
                foreach ($leftParams as $index => $leftParam) {
                    $rightParam = $rightParams[$index] ?? null;
                    $params[] = $this->commonProtoType(
                        $leftParam instanceof \CEL\Generated\Expr\Type ? $leftParam : $this->dynType(),
                        $rightParam instanceof \CEL\Generated\Expr\Type ? $rightParam : $this->dynType(),
                    ) ?? $this->dynType();
                }

                return (new \CEL\Generated\Expr\Type())->setAbstractType(
                    (new \CEL\Generated\Expr\Type\AbstractType())
                        ->setName($left->getAbstractType()->getName())
                        ->setParameterTypes($params),
                );
            }
        }
        if ($left->hasDyn() || $right->hasDyn()) {
            return $this->dynType();
        }
        if ($left->hasNull() && $this->isNullableProtoType($right)) {
            return $right;
        }
        if ($right->hasNull() && $this->isNullableProtoType($left)) {
            return $left;
        }
        if ($left->hasWrapper() && $right->hasPrimitive() && $left->getWrapper() === $right->getPrimitive()) {
            return $left;
        }
        if ($right->hasWrapper() && $left->hasPrimitive() && $right->getWrapper() === $left->getPrimitive()) {
            return $right;
        }
        if ($left->hasListType() && $right->hasListType()) {
            $listType = new \CEL\Generated\Expr\Type\ListType();
            $elemType = $this->commonProtoType(
                $left->getListType()->hasElemType() ? $left->getListType()->getElemType() : null,
                $right->getListType()->hasElemType() ? $right->getListType()->getElemType() : null,
            );
            if ($elemType !== null) {
                $listType->setElemType($elemType);
            }

            return (new \CEL\Generated\Expr\Type())->setListType($listType);
        }
        if ($left->hasMapType() && $right->hasMapType()) {
            return (new \CEL\Generated\Expr\Type())->setMapType(
                (new \CEL\Generated\Expr\Type\MapType())
                    ->setKeyType(
                        $this->commonProtoType(
                            $left->getMapType()->hasKeyType() ? $left->getMapType()->getKeyType() : $this->dynType(),
                            $right->getMapType()->hasKeyType() ? $right->getMapType()->getKeyType() : $this->dynType(),
                        ) ?? $this->dynType(),
                    )
                    ->setValueType(
                        $this->commonProtoType(
                            $left->getMapType()->hasValueType() ? $left->getMapType()->getValueType() : $this->dynType(),
                            $right->getMapType()->hasValueType() ? $right->getMapType()->getValueType() : $this->dynType(),
                        ) ?? $this->dynType(),
                    ),
            );
        }

        return $this->protoTypesEqual($left, $right) ? $left : $this->dynType();
    }

    private function isNullableProtoType(\CEL\Generated\Expr\Type $type): bool
    {
        return $type->hasWrapper()
            || $type->hasMessageType()
            || $type->hasWellKnown()
            || $type->hasAbstractType();
    }

    private function protoTypesEqual(\CEL\Generated\Expr\Type $left, \CEL\Generated\Expr\Type $right): bool
    {
        return $left->serializeToJsonString() === $right->serializeToJsonString();
    }

    private function optionalProtoType(?\CEL\Generated\Expr\Type $parameterType = null): \CEL\Generated\Expr\Type
    {
        return (new \CEL\Generated\Expr\Type())->setAbstractType(
            (new \CEL\Generated\Expr\Type\AbstractType())
                ->setName('optional_type')
                ->setParameterTypes([$parameterType ?? $this->dynType()]),
        );
    }

    private function optionalNoneProtoType(): \CEL\Generated\Expr\Type
    {
        return (new \CEL\Generated\Expr\Type())->setAbstractType(
            (new \CEL\Generated\Expr\Type\AbstractType())->setName('optional_type'),
        );
    }

    private function isOptionalProtoType(\CEL\Generated\Expr\Type $type): bool
    {
        return $type->hasAbstractType() && $type->getAbstractType()->getName() === 'optional_type';
    }

    private function optionalParameterType(\CEL\Generated\Expr\Type $type): ?\CEL\Generated\Expr\Type
    {
        if (!$this->isOptionalProtoType($type)) {
            return null;
        }

        foreach ($type->getAbstractType()->getParameterTypes() as $parameterType) {
            return $parameterType instanceof \CEL\Generated\Expr\Type ? $parameterType : null;
        }

        return null;
    }

    private function completeProtoType(\CEL\Generated\Expr\Type $type): \CEL\Generated\Expr\Type
    {
        if ($type->hasListType()) {
            return (new \CEL\Generated\Expr\Type())->setListType(
                (new \CEL\Generated\Expr\Type\ListType())->setElemType(
                    $type->getListType()->hasElemType()
                        ? $this->completeProtoType($type->getListType()->getElemType())
                        : $this->dynType(),
                ),
            );
        }
        if ($type->hasMapType()) {
            return (new \CEL\Generated\Expr\Type())->setMapType(
                (new \CEL\Generated\Expr\Type\MapType())
                    ->setKeyType(
                        $type->getMapType()->hasKeyType()
                            ? $this->completeProtoType($type->getMapType()->getKeyType())
                            : $this->dynType(),
                    )
                    ->setValueType(
                        $type->getMapType()->hasValueType()
                            ? $this->completeProtoType($type->getMapType()->getValueType())
                            : $this->dynType(),
                    ),
            );
        }
        if ($type->hasAbstractType()) {
            $parameters = [];
            foreach ($type->getAbstractType()->getParameterTypes() as $parameterType) {
                if ($parameterType instanceof \CEL\Generated\Expr\Type) {
                    $parameters[] = $this->completeProtoType($parameterType);
                }
            }
            if ($type->getAbstractType()->getName() === 'optional_type' && $parameters === []) {
                $parameters[] = $this->dynType();
            }

            return (new \CEL\Generated\Expr\Type())->setAbstractType(
                (new \CEL\Generated\Expr\Type\AbstractType())
                    ->setName($type->getAbstractType()->getName())
                    ->setParameterTypes($parameters),
            );
        }
        if ($type->hasType()) {
            return (new \CEL\Generated\Expr\Type())->setType($this->completeProtoType($type->getType()));
        }

        return $this->copyProtoType($type);
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $bindings */
    private function substituteTypeParams(\CEL\Generated\Expr\Type $type, array $bindings): \CEL\Generated\Expr\Type
    {
        if ($type->hasTypeParam()) {
            return isset($bindings[$type->getTypeParam()]) ? $this->copyProtoType($bindings[$type->getTypeParam()]) : $this->dynType();
        }
        if ($type->hasListType()) {
            return (new \CEL\Generated\Expr\Type())->setListType(
                (new \CEL\Generated\Expr\Type\ListType())->setElemType(
                    $type->getListType()->hasElemType()
                        ? $this->substituteTypeParams($type->getListType()->getElemType(), $bindings)
                        : $this->dynType(),
                ),
            );
        }
        if ($type->hasMapType()) {
            return (new \CEL\Generated\Expr\Type())->setMapType(
                (new \CEL\Generated\Expr\Type\MapType())
                    ->setKeyType(
                        $type->getMapType()->hasKeyType()
                            ? $this->substituteTypeParams($type->getMapType()->getKeyType(), $bindings)
                            : $this->dynType(),
                    )
                    ->setValueType(
                        $type->getMapType()->hasValueType()
                            ? $this->substituteTypeParams($type->getMapType()->getValueType(), $bindings)
                            : $this->dynType(),
                    ),
            );
        }
        if ($type->hasAbstractType()) {
            $parameters = [];
            foreach ($type->getAbstractType()->getParameterTypes() as $parameterType) {
                if ($parameterType instanceof \CEL\Generated\Expr\Type) {
                    $parameters[] = $this->substituteTypeParams($parameterType, $bindings);
                }
            }

            return (new \CEL\Generated\Expr\Type())->setAbstractType(
                (new \CEL\Generated\Expr\Type\AbstractType())
                    ->setName($type->getAbstractType()->getName())
                    ->setParameterTypes($parameters),
            );
        }
        if ($type->hasType()) {
            return (new \CEL\Generated\Expr\Type())->setType($this->substituteTypeParams($type->getType(), $bindings));
        }

        return $this->copyProtoType($type);
    }

    /** @param array<string, \CEL\Generated\Expr\Type> $bindings */
    private function bindTypeParams(\CEL\Generated\Expr\Type $parameterType, \CEL\Generated\Expr\Type $actualType, array &$bindings): bool
    {
        if ($parameterType->hasTypeParam()) {
            $name = $parameterType->getTypeParam();
            $bindings[$name] = isset($bindings[$name])
                ? ($this->commonProtoType($bindings[$name], $actualType) ?? $this->dynType())
                : $actualType;

            return true;
        }
        if ($parameterType->hasDyn()) {
            return true;
        }
        if ($parameterType->hasListType() && $actualType->hasListType()) {
            return !$parameterType->getListType()->hasElemType()
                || ($actualType->getListType()->hasElemType() && $this->bindTypeParams($parameterType->getListType()->getElemType(), $actualType->getListType()->getElemType(), $bindings));
        }
        if ($parameterType->hasMapType() && $actualType->hasMapType()) {
            $keyMatches = !$parameterType->getMapType()->hasKeyType()
                || ($actualType->getMapType()->hasKeyType() && $this->bindTypeParams($parameterType->getMapType()->getKeyType(), $actualType->getMapType()->getKeyType(), $bindings));
            $valueMatches = !$parameterType->getMapType()->hasValueType()
                || ($actualType->getMapType()->hasValueType() && $this->bindTypeParams($parameterType->getMapType()->getValueType(), $actualType->getMapType()->getValueType(), $bindings));

            return $keyMatches && $valueMatches;
        }
        if ($parameterType->hasAbstractType() && $actualType->hasAbstractType() && $parameterType->getAbstractType()->getName() === $actualType->getAbstractType()->getName()) {
            $params = iterator_to_array($parameterType->getAbstractType()->getParameterTypes());
            $actuals = iterator_to_array($actualType->getAbstractType()->getParameterTypes());
            if (count($params) !== count($actuals)) {
                return false;
            }
            foreach ($params as $index => $param) {
                $actual = $actuals[$index] ?? null;
                if (!$param instanceof \CEL\Generated\Expr\Type || !$actual instanceof \CEL\Generated\Expr\Type || !$this->bindTypeParams($param, $actual, $bindings)) {
                    return false;
                }
            }

            return true;
        }
        if ($parameterType->hasWrapper() && $actualType->hasPrimitive() && $parameterType->getWrapper() === $actualType->getPrimitive()) {
            return true;
        }
        if ($parameterType->hasPrimitive() && $actualType->hasWrapper() && $parameterType->getPrimitive() === $actualType->getWrapper()) {
            return true;
        }

        return $this->protoTypesEqual($parameterType, $actualType);
    }

    private function copyProtoType(\CEL\Generated\Expr\Type $type): \CEL\Generated\Expr\Type
    {
        if ($type->hasDyn()) {
            return $this->dynType();
        }
        if ($type->hasNull()) {
            return (new \CEL\Generated\Expr\Type())->setNull($type->getNull());
        }
        if ($type->hasPrimitive()) {
            return $this->primitiveType($type->getPrimitive());
        }
        if ($type->hasWrapper()) {
            return (new \CEL\Generated\Expr\Type())->setWrapper($type->getWrapper());
        }
        if ($type->hasWellKnown()) {
            return (new \CEL\Generated\Expr\Type())->setWellKnown($type->getWellKnown());
        }
        if ($type->hasMessageType()) {
            return (new \CEL\Generated\Expr\Type())->setMessageType($type->getMessageType());
        }
        if ($type->hasTypeParam()) {
            return (new \CEL\Generated\Expr\Type())->setTypeParam($type->getTypeParam());
        }
        if ($type->hasError()) {
            return (new \CEL\Generated\Expr\Type())->setError(new GPBEmpty());
        }

        return $this->dynType();
    }

    private function isTimestampProtoType(\CEL\Generated\Expr\Type $type): bool
    {
        return $type->hasWellKnown() && $type->getWellKnown() === \CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP;
    }

    private function isDurationProtoType(\CEL\Generated\Expr\Type $type): bool
    {
        return $type->hasWellKnown() && $type->getWellKnown() === \CEL\Generated\Expr\Type\WellKnownType::DURATION;
    }

    private function protoTypeForField(mixed $field): \CEL\Generated\Expr\Type
    {
        if ($field->isRepeated()) {
            if ($field->getType() === GPBType::MESSAGE && $field->isMap()) {
                $keyField = $field->getMessageType()->getFieldByNumber(1);
                $valueField = $field->getMessageType()->getFieldByNumber(2);

                return (new \CEL\Generated\Expr\Type())->setMapType(
                    (new \CEL\Generated\Expr\Type\MapType())
                        ->setKeyType($this->protoTypeForField($keyField))
                        ->setValueType($this->protoTypeForField($valueField)),
                );
            }

            return (new \CEL\Generated\Expr\Type())->setListType(
                (new \CEL\Generated\Expr\Type\ListType())->setElemType($this->protoTypeForScalarField($field)),
            );
        }

        return $this->protoTypeForScalarField($field);
    }

    private function protoTypeForScalarField(mixed $field): \CEL\Generated\Expr\Type
    {
        return match ($field->getType()) {
            GPBType::BOOL => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
            GPBType::INT32, GPBType::INT64, GPBType::SINT32, GPBType::SINT64, GPBType::SFIXED32, GPBType::SFIXED64, GPBType::ENUM => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::INT64),
            GPBType::UINT32, GPBType::UINT64, GPBType::FIXED32, GPBType::FIXED64 => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::UINT64),
            GPBType::FLOAT, GPBType::DOUBLE => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::DOUBLE),
            GPBType::STRING => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::STRING),
            GPBType::BYTES => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BYTES),
            GPBType::MESSAGE => $this->protoTypeForMessageField($field->getMessageType()->getFullName()),
            default => $this->dynType(),
        };
    }

    private function protoTypeForMessageField(string $messageType): \CEL\Generated\Expr\Type
    {
        return match ($messageType) {
            'google.protobuf.BoolValue' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
            'google.protobuf.BytesValue' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::BYTES),
            'google.protobuf.DoubleValue', 'google.protobuf.FloatValue' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::DOUBLE),
            'google.protobuf.Int32Value', 'google.protobuf.Int64Value' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::INT64),
            'google.protobuf.StringValue' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::STRING),
            'google.protobuf.UInt32Value', 'google.protobuf.UInt64Value' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::UINT64),
            'google.protobuf.Timestamp' => (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP),
            'google.protobuf.Duration' => (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::DURATION),
            'google.protobuf.Any' => (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::ANY),
            default => (new \CEL\Generated\Expr\Type())->setMessageType($messageType),
        };
    }

    private function celTypeToProto(Type $type): \CEL\Generated\Expr\Type
    {
        return match ($type->name()) {
            'bool' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
            'int' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::INT64),
            'uint' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::UINT64),
            'double' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::DOUBLE),
            'string' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::STRING),
            'bytes' => $this->primitiveType(\CEL\Generated\Expr\Type\PrimitiveType::BYTES),
            'null_type' => (new \CEL\Generated\Expr\Type())->setNull(NullValue::NULL_VALUE),
            'list' => (new \CEL\Generated\Expr\Type())->setListType(
                (new \CEL\Generated\Expr\Type\ListType())->setElemType($type->valueType() ? $this->celTypeToProto($type->valueType()) : $this->dynType()),
            ),
            'map' => (new \CEL\Generated\Expr\Type())->setMapType(
                (new \CEL\Generated\Expr\Type\MapType())
                    ->setKeyType($type->keyType() ? $this->celTypeToProto($type->keyType()) : $this->dynType())
                    ->setValueType($type->valueType() ? $this->celTypeToProto($type->valueType()) : $this->dynType()),
            ),
            'message' => $this->protoTypeForMessageType($type->messageType() ?? ''),
            'type' => (new \CEL\Generated\Expr\Type())->setType($this->dynType()),
            default => $this->dynType(),
        };
    }

    private function protoTypeForMessageType(string $messageType): \CEL\Generated\Expr\Type
    {
        return match ($messageType) {
            'wrapper.bool', 'google.protobuf.BoolValue' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::BOOL),
            'wrapper.bytes', 'google.protobuf.BytesValue' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::BYTES),
            'wrapper.double', 'google.protobuf.DoubleValue', 'google.protobuf.FloatValue' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::DOUBLE),
            'wrapper.int64', 'google.protobuf.Int32Value', 'google.protobuf.Int64Value' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::INT64),
            'wrapper.string', 'google.protobuf.StringValue' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::STRING),
            'wrapper.uint64', 'google.protobuf.UInt32Value', 'google.protobuf.UInt64Value' => (new \CEL\Generated\Expr\Type())->setWrapper(\CEL\Generated\Expr\Type\PrimitiveType::UINT64),
            'optional_type' => $this->optionalProtoType(),
            'google.protobuf.Timestamp' => (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP),
            'google.protobuf.Duration' => (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::DURATION),
            'google.protobuf.Any' => (new \CEL\Generated\Expr\Type())->setWellKnown(\CEL\Generated\Expr\Type\WellKnownType::ANY),
            '' => $this->dynType(),
            default => (new \CEL\Generated\Expr\Type())->setMessageType($messageType),
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

    private function importExpr(\CEL\Generated\Expr\Expr $expr): Expr
    {
        $offset = $this->importOffset($expr);

        if ($expr->hasConstExpr()) {
            return Expr::literal($this->importConstant($expr->getConstExpr(), $offset), $offset);
        }

        if ($expr->hasIdentExpr()) {
            return Expr::ident($expr->getIdentExpr()->getName(), $offset);
        }

        if ($expr->hasSelectExpr()) {
            $select = $expr->getSelectExpr();
            if (!$select->hasOperand()) {
                throw new UnsupportedFeatureException('Select expression does not contain an operand');
            }

            $selection = new Expr(
                'select',
                $select->getField(),
                [],
                $offset,
                $this->importExpr($select->getOperand()),
            );

            return $select->getTestOnly()
                ? new Expr('call', 'has', [$selection], $offset)
                : $selection;
        }

        if ($expr->hasCallExpr()) {
            return $this->importCall($expr->getCallExpr(), $offset);
        }

        if ($expr->hasListExpr()) {
            return $this->importList($expr->getListExpr(), $offset);
        }

        if ($expr->hasStructExpr()) {
            return $this->importStruct($expr->getStructExpr(), $offset);
        }

        if ($expr->hasComprehensionExpr()) {
            return $this->importComprehension($expr->getComprehensionExpr(), $offset);
        }

        throw new UnsupportedFeatureException('protobuf expression has no supported expression kind');
    }

    private function importCall(\CEL\Generated\Expr\Expr\Call $call, int $offset): Expr
    {
        $args = [];
        foreach ($call->getArgs() ?? [] as $arg) {
            $args[] = $this->importExpr($arg);
        }

        $target = $call->hasTarget() ? $this->importExpr($call->getTarget()) : null;
        $function = $call->getFunction();

        if ($target !== null && $function === '_?._' && count($args) === 1 && $args[0]->kind === 'literal' && is_string($args[0]->value)) {
            return new Expr('optional_select', $args[0]->value, [], $offset, $target);
        }

        if ($target === null && in_array($function, ['_[_]', '_[?_]'], true) && count($args) === 2) {
            return new Expr($function === '_[?_]' ? 'optional_index' : 'index', null, [$args[1]], $offset, $args[0]);
        }

        $unaryOperator = $this->unaryOperator($function);
        if ($target === null && $unaryOperator !== null && count($args) === 1) {
            return new Expr('unary', $unaryOperator, $args, $offset);
        }

        $binaryOperator = $this->binaryOperator($function);
        if ($target === null && $binaryOperator !== null && count($args) === 2) {
            return new Expr('binary', $binaryOperator, $args, $offset);
        }

        if ($target === null && $function === '_?_:_' && count($args) === 3) {
            return new Expr('conditional', null, $args, $offset);
        }

        return new Expr('call', $function, $args, $offset, $target);
    }

    private function importList(\CEL\Generated\Expr\Expr\CreateList $list, int $offset): Expr
    {
        $optionalIndices = [];
        foreach ($list->getOptionalIndices() ?? [] as $index) {
            $optionalIndices[(int) $index] = true;
        }

        $args = [];
        foreach ($list->getElements() ?? [] as $index => $element) {
            $arg = $this->importExpr($element);
            $args[] = isset($optionalIndices[(int) $index])
                ? new Expr('optional_element', null, [$arg], $arg->offset)
                : $arg;
        }

        return new Expr('list', null, $args, $offset);
    }

    private function importStruct(\CEL\Generated\Expr\Expr\CreateStruct $struct, int $offset): Expr
    {
        $messageName = $struct->getMessageName();
        if ($messageName === '') {
            $entries = [];
            foreach ($struct->getEntries() ?? [] as $entry) {
                if (!$entry->hasMapKey() || !$entry->hasValue()) {
                    throw new UnsupportedFeatureException('map entry must contain a map key and value');
                }

                $entries[] = [
                    'key' => $this->importExpr($entry->getMapKey()),
                    'value' => $this->importExpr($entry->getValue()),
                    'optional' => $entry->getOptionalEntry(),
                ];
            }

            return new Expr('map', null, entries: $entries, offset: $offset);
        }

        $entries = [];
        foreach ($struct->getEntries() ?? [] as $entry) {
            if (!$entry->hasFieldKey() || !$entry->hasValue()) {
                throw new UnsupportedFeatureException('message entry must contain a field key and value');
            }

            $entries[] = [
                'field' => $entry->getFieldKey(),
                'value' => $this->importExpr($entry->getValue()),
                'optional' => $entry->getOptionalEntry(),
            ];
        }

        return new Expr('struct', $messageName, entries: $entries, offset: $offset);
    }

    private function importComprehension(\CEL\Generated\Expr\Expr\Comprehension $comprehension, int $offset): Expr
    {
        foreach ([
            'iterRange' => $comprehension->hasIterRange(),
            'accuInit' => $comprehension->hasAccuInit(),
            'loopCondition' => $comprehension->hasLoopCondition(),
            'loopStep' => $comprehension->hasLoopStep(),
            'result' => $comprehension->hasResult(),
        ] as $field => $present) {
            if (!$present) {
                throw new UnsupportedFeatureException(sprintf('Comprehension expression does not contain %s', $field));
            }
        }

        return new Expr(
            'comprehension',
            [
                'iter_var' => $comprehension->getIterVar(),
                'iter_var2' => $comprehension->getIterVar2(),
                'accu_var' => $comprehension->getAccuVar(),
            ],
            [
                $this->importExpr($comprehension->getIterRange()),
                $this->importExpr($comprehension->getAccuInit()),
                $this->importExpr($comprehension->getLoopCondition()),
                $this->importExpr($comprehension->getLoopStep()),
                $this->importExpr($comprehension->getResult()),
            ],
            $offset,
        );
    }

    private function importConstant(\CEL\Generated\Expr\Constant $constant, int $offset): mixed
    {
        if ($constant->hasNullValue()) {
            return null;
        }
        if ($constant->hasBoolValue()) {
            return $constant->getBoolValue();
        }
        if ($constant->hasInt64Value()) {
            return $this->importInt64($this->constantJsonScalar($constant, 'int64Value') ?? $constant->getInt64Value(), $offset);
        }
        if ($constant->hasUint64Value()) {
            return UInt::from($this->constantJsonScalar($constant, 'uint64Value') ?? $constant->getUint64Value());
        }
        if ($constant->hasDoubleValue()) {
            return $constant->getDoubleValue();
        }
        if ($constant->hasStringValue()) {
            return $constant->getStringValue();
        }
        if ($constant->hasBytesValue()) {
            return new Bytes($constant->getBytesValue());
        }
        if (@$constant->hasTimestampValue()) {
            $timestamp = @$constant->getTimestampValue();

            return TimestampValue::fromUnixSecondsNanos((int) $timestamp->getSeconds(), (int) $timestamp->getNanos());
        }
        if (@$constant->hasDurationValue()) {
            $duration = @$constant->getDurationValue();

            return DurationValue::fromParts((int) $duration->getSeconds(), (int) $duration->getNanos());
        }

        throw new UnsupportedFeatureException('protobuf constant has no supported value kind');
    }

    private function constantJsonScalar(\CEL\Generated\Expr\Constant $constant, string $field): int|string|float|bool|null
    {
        $json = json_decode($constant->serializeToJsonString(), true, flags: JSON_THROW_ON_ERROR);

        return is_array($json) && array_key_exists($field, $json) ? $json[$field] : null;
    }

    private function importInt64(int|string $value, int $offset): int|IntLiteral
    {
        if (is_int($value)) {
            return $value;
        }

        if (bccomp($value, (string) PHP_INT_MIN, 0) >= 0 && bccomp($value, (string) PHP_INT_MAX, 0) <= 0) {
            return (int) $value;
        }

        return IntLiteral::fromDecimal($value, $offset);
    }

    /** @return array<int, int> */
    private function sourcePositions(?\CEL\Generated\Expr\SourceInfo $sourceInfo): array
    {
        if ($sourceInfo === null) {
            return [];
        }

        $positions = [];
        foreach ($sourceInfo->getPositions() ?? [] as $id => $offset) {
            $positions[(int) $id] = (int) $offset;
        }

        return $positions;
    }

    private function importOffset(\CEL\Generated\Expr\Expr $expr): int
    {
        return $this->importPositions[(int) $expr->getId()] ?? 0;
    }

    private function unaryOperator(string $function): ?string
    {
        return match ($function) {
            '!_' => '!',
            '-_' => '-',
            default => null,
        };
    }

    private function binaryOperator(string $function): ?string
    {
        return match ($function) {
            '_+_' => '+',
            '_-_' => '-',
            '_*_' => '*',
            '_/_' => '/',
            '_%_' => '%',
            '_==_' => '==',
            '_!=_' => '!=',
            '_<_' => '<',
            '_<=_' => '<=',
            '_>_' => '>',
            '_>=_' => '>=',
            '_in_' => 'in',
            '_&&_' => '&&',
            '_||_' => '||',
            default => null,
        };
    }

    private function primitiveType(int $primitive): \CEL\Generated\Expr\Type
    {
        return (new \CEL\Generated\Expr\Type())->setPrimitive($primitive);
    }

    private function dynType(): \CEL\Generated\Expr\Type
    {
        return (new \CEL\Generated\Expr\Type())->setDyn(new GPBEmpty());
    }

    /** @return list<int> */
    private function lineOffsets(string $source): array
    {
        $offsets = [];
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] === "\n") {
                $offsets[] = $i + 1;
            }
        }

        return $offsets;
    }

    private function operatorFunction(string $operator, int $arity): string
    {
        if ($arity === 1) {
            return match ($operator) {
                '!' => '!_',
                '-' => '-_',
                default => throw new UnsupportedFeatureException(sprintf('unsupported unary operator "%s"', $operator)),
            };
        }

        return match ($operator) {
            '+', '-', '*', '/', '%', '==', '!=', '<', '<=', '>', '>=', 'in' => '_' . $operator . '_',
            '&&' => '_&&_',
            '||' => '_||_',
            default => throw new UnsupportedFeatureException(sprintf('unsupported binary operator "%s"', $operator)),
        };
    }
}
