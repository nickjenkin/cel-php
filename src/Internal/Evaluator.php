<?php

declare(strict_types=1);

namespace CEL\Internal;

use CEL\Bytes;
use CEL\DurationValue;
use CEL\EvaluationContext;
use CEL\EvaluationException;
use CEL\FunctionDeclaration;
use CEL\NetworkAddress;
use CEL\NetworkCidr;
use CEL\OptionalValue;
use CEL\PcreRegexProvider;
use CEL\ProgramOptions;
use CEL\Proto\EnumValue;
use CEL\Proto\ProtoAdapter;
use CEL\Proto\ProtoRegistry;
use CEL\RegexProvider;
use CEL\TimestampValue;
use CEL\Type;
use CEL\UInt;
use CEL\UnknownValue;
use Google\Protobuf\Internal\MapField;

final class Evaluator
{
    /** @var array<string, true> */
    private const COMPREHENSION_MACROS = [
        'all' => true,
        'exists' => true,
        'exists_one' => true,
        'existsOne' => true,
        'filter' => true,
        'map' => true,
        'transformList' => true,
        'transformMap' => true,
    ];

    /** @var array<string, true> */
    private const OPTIONAL_MAP_CALLS = [
        'optMap' => true,
        'optFlatMap' => true,
    ];

    /** @var array<string, true> */
    private const STRING_EXTENSION_RECEIVERS = [
        'charAt' => true,
        'indexOf' => true,
        'lastIndexOf' => true,
        'lowerAscii' => true,
        'upperAscii' => true,
        'replace' => true,
        'split' => true,
        'substring' => true,
        'trim' => true,
        'reverse' => true,
        'format' => true,
    ];

    /** @var array<string, true> */
    private const FORMAT_CLAUSES = [
        's' => true,
        'd' => true,
        'b' => true,
        'o' => true,
        'x' => true,
        'X' => true,
        'e' => true,
        'f' => true,
    ];

    private int $steps = 0;
    private readonly ProtoRegistry $protoRegistry;
    private readonly ProtoAdapter $protoAdapter;
    private readonly RegexProvider $regexProvider;

    /** @var list<string> */
    private readonly array $containerPrefixes;

    /**
     * @param array<string, FunctionDeclaration> $functions
     */
    public function __construct(
        private readonly array $functions,
        private readonly bool $macrosEnabled,
        private readonly ProgramOptions $options,
        ?ProtoRegistry $protoRegistry = null,
        private readonly bool $strongEnums = false,
        private readonly string $container = '',
        ?RegexProvider $regexProvider = null,
    ) {
        $this->protoRegistry = $protoRegistry ?? ProtoRegistry::standard();
        $this->protoAdapter = new ProtoAdapter($this->protoRegistry, $strongEnums);
        $this->regexProvider = $regexProvider ?? new PcreRegexProvider();
        $this->containerPrefixes = $this->containerPrefixes($container);
    }

    public function evaluate(Expr $expr, EvaluationContext $context): mixed
    {
        $previousSteps = $this->steps;
        $this->steps = 0;

        try {
            return $this->evalExpr($expr, $context, 0);
        } finally {
            $this->steps = $previousSteps;
        }
    }

    private function evalExpr(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        $this->step($depth);

        return match ($expr->kind) {
            'literal' => $expr->value instanceof IntLiteral ? $expr->value->toInt() : $expr->value,
            'ident' => $this->resolveIdentifier((string) $expr->value, $context),
            'unary' => $this->evalUnary($expr, $context, $depth),
            'binary' => $this->evalBinary($expr, $context, $depth),
            'conditional' => $this->evalConditional($expr, $context, $depth),
            'list' => $this->evalList($expr, $context, $depth),
            'map' => $this->evalMap($expr, $context, $depth),
            'select' => $this->select($this->evalExpr($expr->target, $context, $depth + 1), (string) $expr->value),
            'optional_select' => $this->optionalSelect($this->evalExpr($expr->target, $context, $depth + 1), (string) $expr->value),
            'index' => $this->index($this->evalExpr($expr->target, $context, $depth + 1), $this->evalExpr($expr->args[0], $context, $depth + 1)),
            'optional_index' => $this->optionalIndex($this->evalExpr($expr->target, $context, $depth + 1), $this->evalExpr($expr->args[0], $context, $depth + 1)),
            'optional_element' => $this->evalExpr($expr->args[0], $context, $depth + 1),
            'call' => $this->evalCall($expr, $context, $depth),
            'struct' => $this->evalStruct($expr, $context, $depth),
            'comprehension' => $this->evalLoweredComprehension($expr, $context, $depth),
            default => throw new EvaluationException(sprintf('unknown expression kind "%s"', $expr->kind)),
        };
    }

    private function evalUnary(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        if ($expr->value === '-' && $expr->args[0]->kind === 'literal' && $expr->args[0]->value instanceof IntLiteral) {
            return $expr->args[0]->value->negate();
        }

        $value = $this->evalExpr($expr->args[0], $context, $depth + 1);
        if ($value instanceof UnknownValue) {
            return $value;
        }

        return match ($expr->value) {
            '!' => !$this->truthy($value),
            '-' => $this->negate($value),
            default => throw new EvaluationException(sprintf('unsupported unary operator "%s"', $expr->value)),
        };
    }

    private function evalConditional(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        $condition = $this->evalExpr($expr->args[0], $context, $depth + 1);
        if ($condition instanceof UnknownValue) {
            return $condition;
        }

        return $this->truthy($condition)
            ? $this->evalExpr($expr->args[1], $context, $depth + 1)
            : $this->evalExpr($expr->args[2], $context, $depth + 1);
    }

    private function evalBinary(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        $operator = (string) $expr->value;
        if ($operator === '&&') {
            return $this->evalLogicalAnd($expr, $context, $depth);
        }

        if ($operator === '||') {
            return $this->evalLogicalOr($expr, $context, $depth);
        }

        $left = $this->evalExpr($expr->args[0], $context, $depth + 1);
        $right = $this->evalExpr($expr->args[1], $context, $depth + 1);
        $unknown = $this->unknownFromValues($left, $right);
        if ($unknown !== null) {
            return $unknown;
        }

        return match ($operator) {
            '+' => $this->add($left, $right),
            '-' => $this->subtract($left, $right),
            '*' => $this->multiply($left, $right),
            '/' => $this->divide($left, $right),
            '%' => $this->mod($left, $right),
            '==' => $this->celEquals($left, $right),
            '!=' => !$this->celEquals($left, $right),
            '<' => $this->compare($left, $right) < 0,
            '<=' => $this->compare($left, $right) <= 0,
            '>' => $this->compare($left, $right) > 0,
            '>=' => $this->compare($left, $right) >= 0,
            'in' => $this->contains($right, $left),
            default => throw new EvaluationException(sprintf('unsupported binary operator "%s"', $operator)),
        };
    }

    private function evalLogicalAnd(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        try {
            $leftValue = $this->evalExpr($expr->args[0], $context, $depth + 1);
            if ($leftValue instanceof UnknownValue) {
                try {
                    $rightValue = $this->evalExpr($expr->args[1], $context, $depth + 1);
                } catch (EvaluationException) {
                    return $leftValue;
                }
                if ($rightValue instanceof UnknownValue) {
                    return UnknownValue::merge([$leftValue, $rightValue]);
                }
                if (!$this->truthy($rightValue)) {
                    return false;
                }

                return $leftValue;
            }

            $left = $this->truthy($leftValue);
            if (!$left) {
                return false;
            }

            $rightValue = $this->evalExpr($expr->args[1], $context, $depth + 1);
            if ($rightValue instanceof UnknownValue) {
                return $rightValue;
            }

            return $this->truthy($rightValue);
        } catch (EvaluationException $leftError) {
            try {
                if (!$this->truthy($this->evalExpr($expr->args[1], $context, $depth + 1))) {
                    return false;
                }
            } catch (EvaluationException) {
                throw $leftError;
            }

            throw $leftError;
        }
    }

    private function evalLogicalOr(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        try {
            $leftValue = $this->evalExpr($expr->args[0], $context, $depth + 1);
            if ($leftValue instanceof UnknownValue) {
                try {
                    $rightValue = $this->evalExpr($expr->args[1], $context, $depth + 1);
                } catch (EvaluationException) {
                    return $leftValue;
                }
                if ($rightValue instanceof UnknownValue) {
                    return UnknownValue::merge([$leftValue, $rightValue]);
                }
                if ($this->truthy($rightValue)) {
                    return true;
                }

                return $leftValue;
            }

            $left = $this->truthy($leftValue);
            if ($left) {
                return true;
            }

            $rightValue = $this->evalExpr($expr->args[1], $context, $depth + 1);
            if ($rightValue instanceof UnknownValue) {
                return $rightValue;
            }

            return $this->truthy($rightValue);
        } catch (EvaluationException $leftError) {
            try {
                if ($this->truthy($this->evalExpr($expr->args[1], $context, $depth + 1))) {
                    return true;
                }
            } catch (EvaluationException) {
                throw $leftError;
            }

            throw $leftError;
        }
    }

    private function evalList(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        $values = [];
        foreach ($expr->args as $arg) {
            if ($arg->kind === 'optional_element') {
                $optional = $this->requireOptional($this->evalExpr($arg->args[0], $context, $depth + 1));
                if ($optional->hasValue()) {
                    if ($optional->value() instanceof UnknownValue) {
                        return $optional->value();
                    }
                    $values[] = $optional->value();
                }
                continue;
            }

            $value = $this->evalExpr($arg, $context, $depth + 1);
            if ($value instanceof UnknownValue) {
                return $value;
            }
            $values[] = $value;
        }

        return $values;
    }

    private function evalMap(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        $map = [];
        foreach ($expr->entries as $entry) {
            $key = $this->evalExpr($entry['key'], $context, $depth + 1);
            $value = $this->evalExpr($entry['value'], $context, $depth + 1);
            $unknown = $this->unknownFromValues($key, $value);
            if ($unknown !== null) {
                return $unknown;
            }
            if (($entry['optional'] ?? false) === true) {
                $optional = $this->requireOptional($value);
                if (!$optional->hasValue()) {
                    continue;
                }
                $value = $optional->value();
            }
            $arrayKey = $this->arrayKey($key);
            if (array_key_exists($arrayKey, $map)) {
                throw new EvaluationException('duplicate map key');
            }
            $map[$arrayKey] = $value;
        }

        return $map;
    }

    private function evalStruct(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        $fields = [];
        foreach ($expr->entries as $field => $value) {
            if (is_array($value) && array_key_exists('field', $value)) {
                $fieldName = (string) $value['field'];
                $fieldValue = $this->evalExpr($value['value'], $context, $depth + 1);
                if ($fieldValue instanceof UnknownValue) {
                    return $fieldValue->select($fieldName);
                }
                if (($value['optional'] ?? false) === true) {
                    $optional = $this->requireOptional($fieldValue);
                    if (!$optional->hasValue()) {
                        continue;
                    }
                    $fieldValue = $optional->value();
                }
                $fields[$fieldName] = $fieldValue;
                continue;
            }

            $fieldValue = $this->evalExpr($value, $context, $depth + 1);
            if ($fieldValue instanceof UnknownValue) {
                return $fieldValue->select((string) $field);
            }
            $fields[(string) $field] = $fieldValue;
        }

        return $this->protoAdapter->construct((string) $expr->value, $fields);
    }

    private function evalCall(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        $name = (string) $expr->value;
        if ($expr->target !== null && $this->isIdentifier($expr->target, 'cel')) {
            return match ($name) {
                'bind' => $this->evalBindMacro($expr, $context, $depth),
                'block' => $this->evalBlockMacro($expr, $context, $depth),
                'index' => $context->getLocal($this->blockIndexName($this->singleLiteralIntArg($expr, 'cel.index'))),
                'iterVar' => $context->getLocal($this->iterVarName(...$this->twoLiteralIntArgs($expr, 'cel.iterVar'))),
                'accuVar' => $context->getLocal($this->accuVarName(...$this->twoLiteralIntArgs($expr, 'cel.accuVar'))),
                default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
            };
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'base64')) {
            $args = $this->evalArgs($expr->args, $context, $depth);
            if (($unknown = $this->unknownFromValues(...$args)) !== null) {
                return $unknown;
            }

            return $this->evalBase64Function($name, $args);
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'math')) {
            $args = $this->evalArgs($expr->args, $context, $depth);
            if (($unknown = $this->unknownFromValues(...$args)) !== null) {
                return $unknown;
            }

            return $this->evalMathFunction($name, $args);
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'strings')) {
            $args = $this->evalArgs($expr->args, $context, $depth);
            if (($unknown = $this->unknownFromValues(...$args)) !== null) {
                return $unknown;
            }

            return $this->evalStringsFunction($name, $args);
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'ip')) {
            $args = $this->evalArgs($expr->args, $context, $depth);
            if (($unknown = $this->unknownFromValues(...$args)) !== null) {
                return $unknown;
            }

            return $this->evalIpNamespaceFunction($name, $args);
        }

        if ($expr->target !== null && $this->isIdentifier($expr->target, 'optional')) {
            $args = $this->evalArgs($expr->args, $context, $depth);

            return $this->evalOptionalNamespaceFunction($name, $args);
        }

        if ($name === 'has') {
            $this->assertMacrosEnabled($name);
            if ($expr->target !== null || count($expr->args) !== 1) {
                throw new EvaluationException('has macro expects one argument');
            }

            return $this->has($expr->args[0], $context);
        }

        if ($expr->target !== null && isset(self::COMPREHENSION_MACROS[$name])) {
            $this->assertMacrosEnabled($name);
            return $this->evalComprehension($name, $expr, $context, $depth);
        }

        if ($expr->target !== null && isset(self::OPTIONAL_MAP_CALLS[$name])) {
            return $this->evalOptionalMapCall($name, $expr, $context, $depth);
        }

        if ($expr->target !== null) {
            $target = $this->evalExpr($expr->target, $context, $depth + 1);
            $args = $this->evalArgs($expr->args, $context, $depth);
            if (($unknown = $this->unknownFromValues($target, ...$args)) !== null) {
                return $unknown;
            }

            return $this->evalReceiverCall($name, $target, $args, $context);
        }

        $args = $this->evalArgs($expr->args, $context, $depth);
        if (($unknown = $this->unknownFromValues(...$args)) !== null) {
            return $unknown;
        }

        return $this->evalGlobalCall($name, $args, $context);
    }

    /**
     * @param list<Expr> $args
     * @return list<mixed>
     */
    private function evalArgs(array $args, EvaluationContext $context, int $depth): array
    {
        $values = [];
        foreach ($args as $arg) {
            $values[] = $this->evalExpr($arg, $context, $depth + 1);
        }

        return $values;
    }

    private function isIdentifier(?Expr $expr, string $name): bool
    {
        return $expr !== null && $expr->kind === 'ident' && (string) $expr->value === $name;
    }

    private function evalBindMacro(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        if (count($expr->args) !== 3) {
            throw new EvaluationException('cel.bind expects variable, value, and expression');
        }

        $var = $expr->args[0];
        if ($var->kind !== 'ident' || str_contains((string) $var->value, '.')) {
            throw new EvaluationException('cel.bind requires identifier variable');
        }

        $value = $this->evalExpr($expr->args[1], $context, $depth + 1);

        return $this->evalExpr($expr->args[2], $context->with((string) $var->value, $value), $depth + 1);
    }

    private function evalBlockMacro(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        if (count($expr->args) !== 2) {
            throw new EvaluationException('cel.block expects a sequence and expression');
        }

        $sequence = $expr->args[0];
        if ($sequence->kind !== 'list') {
            throw new EvaluationException('cel.block requires a list sequence');
        }

        $blockContext = $context;
        foreach ($sequence->args as $index => $entry) {
            $value = $this->evalExpr($entry, $blockContext, $depth + 1);
            if ($value instanceof UnknownValue) {
                return $value;
            }
            $blockContext = $blockContext->with($this->blockIndexName($index), $value);
        }

        return $this->evalExpr($expr->args[1], $blockContext, $depth + 1);
    }

    /** @param list<mixed> $args */
    private function evalBase64Function(string $name, array $args): mixed
    {
        if (count($args) !== 1) {
            throw new EvaluationException(sprintf('base64.%s expects one argument', $name));
        }

        return match ($name) {
            'encode' => base64_encode($this->requireBytes($args[0])->raw()),
            'decode' => new Bytes($this->base64Decode($this->requireString($args[0]))),
            default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
        };
    }

    private function base64Decode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new EvaluationException('invalid base64 input');
        }

        return $decoded;
    }

    /** @param list<mixed> $args */
    private function evalStringsFunction(string $name, array $args): mixed
    {
        if ($name !== 'quote') {
            throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name));
        }
        if (count($args) !== 1) {
            throw new EvaluationException('strings.quote expects one argument');
        }

        return $this->quoteString($this->requireString($args[0]));
    }

    /** @param list<mixed> $args */
    private function evalIpNamespaceFunction(string $name, array $args): mixed
    {
        if ($name !== 'isCanonical') {
            throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name));
        }
        if (count($args) !== 1) {
            throw new EvaluationException('ip.isCanonical expects one argument');
        }

        return NetworkAddress::isCanonical($this->requireString($args[0]));
    }

    /** @param list<mixed> $args */
    private function evalMathFunction(string $name, array $args): mixed
    {
        return match ($name) {
            'greatest' => $this->mathExtremum($args, true),
            'least' => $this->mathExtremum($args, false),
            'ceil' => ceil($this->requireDoubleArg($args, $name)),
            'floor' => floor($this->requireDoubleArg($args, $name)),
            'round' => $this->mathRound($this->requireDoubleArg($args, $name)),
            'trunc' => $this->mathTrunc($this->requireDoubleArg($args, $name)),
            'abs' => $this->mathAbs($this->singleMathArg($args, $name)),
            'sign' => $this->mathSign($this->singleMathArg($args, $name)),
            'isNaN' => is_nan($this->requireDoubleArg($args, $name)),
            'isInf' => is_infinite($this->requireDoubleArg($args, $name)),
            'isFinite' => is_finite($this->requireDoubleArg($args, $name)),
            'bitAnd' => $this->mathBitBinary($args, $name, '&'),
            'bitOr' => $this->mathBitBinary($args, $name, '|'),
            'bitXor' => $this->mathBitBinary($args, $name, '^'),
            'bitNot' => $this->mathBitNot($args),
            'bitShiftLeft' => $this->mathBitShift($args, true),
            'bitShiftRight' => $this->mathBitShift($args, false),
            default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
        };
    }

    /** @param list<mixed> $args */
    private function singleMathArg(array $args, string $name): mixed
    {
        if (count($args) !== 1) {
            throw new EvaluationException(sprintf('math.%s expects one argument', $name));
        }

        return $this->protoAdapter->normalize($args[0]);
    }

    /** @param list<mixed> $args */
    private function requireDoubleArg(array $args, string $name): float
    {
        $value = $this->singleMathArg($args, $name);
        if (!is_float($value)) {
            throw new EvaluationException('no such overload');
        }

        return $value;
    }

    /** @param list<mixed> $args */
    private function mathExtremum(array $args, bool $greatest): mixed
    {
        $values = count($args) === 1 && is_array($args[0]) && array_is_list($args[0])
            ? $args[0]
            : $args;

        if ($values === []) {
            throw new EvaluationException('no such overload');
        }

        $best = $this->requireMathNumeric($values[0]);
        $count = count($values);
        for ($i = 1; $i < $count; $i++) {
            $value = $this->requireMathNumeric($values[$i]);
            $comparison = $this->numericCompare($value, $best);
            if (($greatest && $comparison > 0) || (!$greatest && $comparison < 0)) {
                $best = $value;
            }
        }

        return $best;
    }

    private function requireMathNumeric(mixed $value): int|float|UInt
    {
        $value = $this->protoAdapter->normalize($value);
        if (is_int($value) || is_float($value) || $value instanceof UInt) {
            return $value;
        }

        throw new EvaluationException('no such overload');
    }

    private function mathRound(float $value): float
    {
        if (is_nan($value) || is_infinite($value)) {
            return $value;
        }

        return $value >= 0.0 ? floor($value + 0.5) : ceil($value - 0.5);
    }

    private function mathTrunc(float $value): float
    {
        if (is_nan($value) || is_infinite($value)) {
            return $value;
        }

        return $value < 0.0 ? ceil($value) : floor($value);
    }

    private function mathAbs(mixed $value): mixed
    {
        $value = $this->protoAdapter->normalize($value);
        if ($value instanceof UInt) {
            return $value;
        }
        if (is_int($value)) {
            if ($value === PHP_INT_MIN) {
                throw new EvaluationException('overflow');
            }

            return abs($value);
        }
        if (is_float($value)) {
            return abs($value);
        }

        throw new EvaluationException('no such overload');
    }

    private function mathSign(mixed $value): mixed
    {
        $value = $this->protoAdapter->normalize($value);
        if ($value instanceof UInt) {
            return UInt::from($value->value() === '0' ? '0' : '1');
        }
        if (is_int($value)) {
            return $value <=> 0;
        }
        if (is_float($value)) {
            return $value < 0.0 ? -1.0 : ($value > 0.0 ? 1.0 : 0.0);
        }

        throw new EvaluationException('no such overload');
    }

    /** @param list<mixed> $args */
    private function mathBitBinary(array $args, string $name, string $operator): mixed
    {
        if (count($args) !== 2) {
            throw new EvaluationException(sprintf('math.%s expects two arguments', $name));
        }

        $left = $this->protoAdapter->normalize($args[0]);
        $right = $this->protoAdapter->normalize($args[1]);

        if ($left instanceof UInt && $right instanceof UInt) {
            $result = match ($operator) {
                '&' => $left->toInt() & $right->toInt(),
                '|' => $left->toInt() | $right->toInt(),
                '^' => $left->toInt() ^ $right->toInt(),
                default => throw new EvaluationException('unsupported bit operation'),
            };

            return UInt::from((string) $result);
        }

        if (is_int($left) && is_int($right)) {
            return match ($operator) {
                '&' => $left & $right,
                '|' => $left | $right,
                '^' => $left ^ $right,
                default => throw new EvaluationException('unsupported bit operation'),
            };
        }

        throw new EvaluationException('no such overload');
    }

    /** @param list<mixed> $args */
    private function mathBitNot(array $args): mixed
    {
        $value = $this->singleMathArg($args, 'bitNot');
        if ($value instanceof UInt) {
            return UInt::from(bcsub('18446744073709551615', $value->value(), 0));
        }
        if (is_int($value)) {
            return ~$value;
        }

        throw new EvaluationException('no such overload');
    }

    /** @param list<mixed> $args */
    private function mathBitShift(array $args, bool $leftShift): mixed
    {
        if (count($args) !== 2) {
            throw new EvaluationException($leftShift ? 'math.bitShiftLeft expects two arguments' : 'math.bitShiftRight expects two arguments');
        }

        $value = $this->protoAdapter->normalize($args[0]);
        $offset = $this->protoAdapter->normalize($args[1]);
        if (!is_int($offset)) {
            throw new EvaluationException('no such overload');
        }
        if ($offset < 0) {
            throw new EvaluationException('negative offset');
        }

        if ($value instanceof UInt) {
            return $this->mathUintShift($value, $offset, $leftShift);
        }
        if (is_int($value)) {
            return $this->mathIntShift($value, $offset, $leftShift);
        }

        throw new EvaluationException('no such overload');
    }

    private function mathUintShift(UInt $value, int $offset, bool $leftShift): UInt
    {
        if ($offset >= 64) {
            return UInt::from('0');
        }

        if ($leftShift) {
            return UInt::from(bcmod(bcmul($value->value(), bcpow('2', (string) $offset, 0), 0), '18446744073709551616'));
        }

        return UInt::from(bcdiv($value->value(), bcpow('2', (string) $offset, 0), 0));
    }

    private function mathIntShift(int $value, int $offset, bool $leftShift): int
    {
        if ($offset >= 64) {
            return 0;
        }

        if ($leftShift) {
            $result = bcmul((string) $value, bcpow('2', (string) $offset, 0), 0);
            $result = bcmod($result, '18446744073709551616');
            if (str_starts_with($result, '-')) {
                $result = bcadd($result, '18446744073709551616', 0);
            }
            if (bccomp($result, '9223372036854775807', 0) === 1) {
                $result = bcsub($result, '18446744073709551616', 0);
            }

            return (int) $result;
        }

        $unsigned = $value < 0
            ? bcadd('18446744073709551616', (string) $value, 0)
            : (string) $value;

        return (int) bcdiv($unsigned, bcpow('2', (string) $offset, 0), 0);
    }

    /** @param list<mixed> $args */
    private function evalOptionalNamespaceFunction(string $name, array $args): OptionalValue
    {
        return match ($name) {
            'none' => OptionalValue::none(),
            'of' => OptionalValue::of($args[0] ?? null),
            'ofNonZeroValue' => $this->isZeroValue($args[0] ?? null) ? OptionalValue::none() : OptionalValue::of($args[0] ?? null),
            default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
        };
    }

    private function evalOptionalMapCall(string $name, Expr $expr, EvaluationContext $context, int $depth): OptionalValue
    {
        if (count($expr->args) !== 2) {
            throw new EvaluationException(sprintf('%s expects variable and expression', $name));
        }

        $optional = $this->requireOptional($this->evalExpr($expr->target, $context, $depth + 1));
        if (!$optional->hasValue()) {
            return OptionalValue::none();
        }

        $var = $expr->args[0];
        if ($var->kind !== 'ident' || str_contains((string) $var->value, '.')) {
            throw new EvaluationException(sprintf('%s requires identifier variable', $name));
        }

        $result = $this->evalExpr(
            $expr->args[1],
            $context->with((string) $var->value, $optional->value()),
            $depth + 1,
        );

        if ($name === 'optMap') {
            return OptionalValue::of($result);
        }

        return $this->requireOptional($result);
    }

    private function evalComprehension(string $name, Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        $collection = $this->evalExpr($expr->target, $context, $depth + 1);
        if ($collection instanceof UnknownValue) {
            return $collection;
        }
        $mapCollection = $this->isMapCollection($collection);
        $items = $this->iterableItems($collection);
        $argCount = count($expr->args);
        $valueVar = $this->macroVariable($expr->args[0] ?? null, $name);
        $secondVar = match ($name) {
            'all', 'exists', 'exists_one', 'existsOne', 'filter', 'map' => $argCount === 3 ? $this->macroVariable($expr->args[1], $name) : null,
            'transformList', 'transformMap' => ($argCount === 3 || $argCount === 4) ? $this->macroVariable($expr->args[1] ?? null, $name) : null,
            default => null,
        };
        $body = $expr->args[array_key_last($expr->args)];

        return match ($name) {
            'all' => $this->macroAll($items, $valueVar, $secondVar, $mapCollection, $body, $context, $depth),
            'exists' => $this->macroExists($items, $valueVar, $secondVar, $mapCollection, $body, $context, $depth),
            'exists_one', 'existsOne' => $this->macroExistsOne($items, $valueVar, $secondVar, $mapCollection, $body, $context, $depth),
            'filter' => $this->macroFilter($items, $valueVar, $secondVar, $mapCollection, $body, $context, $depth),
            'map' => $this->macroMap($items, $valueVar, $secondVar, $mapCollection, $body, $context, $depth),
            'transformList' => $this->macroTransformList($items, $valueVar, $secondVar, $mapCollection, $expr->args, $context, $depth),
            'transformMap' => $this->macroTransformMap($items, $valueVar, $secondVar, $mapCollection, $expr->args, $context, $depth),
            default => throw new EvaluationException(sprintf('unsupported macro "%s"', $name)),
        };
    }

    private function evalLoweredComprehension(Expr $expr, EvaluationContext $context, int $depth): mixed
    {
        if (count($expr->args) !== 5 || !is_array($expr->value)) {
            throw new EvaluationException('invalid comprehension expression');
        }

        $collection = $this->evalExpr($expr->args[0], $context, $depth + 1);
        if ($collection instanceof UnknownValue) {
            return $collection;
        }

        $accuVar = (string) ($expr->value['accu_var'] ?? '');
        $iterVar = (string) ($expr->value['iter_var'] ?? '');
        $iterVar2 = (string) ($expr->value['iter_var2'] ?? '');
        if ($accuVar === '' || $iterVar === '') {
            throw new EvaluationException('comprehension requires accumulator and iteration variables');
        }

        $accu = $this->evalExpr($expr->args[1], $context, $depth + 1);
        if ($accu instanceof UnknownValue) {
            return $accu;
        }

        $items = $this->iterableItems($collection);
        $mapCollection = $this->isMapCollection($collection);
        foreach ($items as $item) {
            $next = $this->loweredComprehensionContext($context, $accuVar, $accu, $iterVar, $iterVar2, $mapCollection, $item);
            $condition = $this->evalExpr($expr->args[2], $next, $depth + 1);
            if ($condition instanceof UnknownValue) {
                return $condition;
            }
            if (!$this->truthy($condition)) {
                break;
            }

            $accu = $this->evalExpr($expr->args[3], $next, $depth + 1);
            if ($accu instanceof UnknownValue) {
                return $accu;
            }
        }

        return $this->evalExpr($expr->args[4], $context->with($accuVar, $accu), $depth + 1);
    }

    /** @param array{key:mixed,value:mixed} $item */
    private function loweredComprehensionContext(
        EvaluationContext $context,
        string $accuVar,
        mixed $accu,
        string $iterVar,
        string $iterVar2,
        bool $mapCollection,
        array $item,
    ): EvaluationContext
    {
        if ($iterVar2 === '') {
            return $context->withMany([
                $accuVar => $accu,
                $iterVar => $mapCollection ? $item['key'] : $item['value'],
            ]);
        }

        return $context->withMany([
            $accuVar => $accu,
            $iterVar => $item['key'],
            $iterVar2 => $item['value'],
        ]);
    }

    /** @param list<array{key:mixed,value:mixed}> $items */
    private function macroAll(array $items, string $valueVar, ?string $secondVar, bool $mapCollection, Expr $body, EvaluationContext $context, int $depth): mixed
    {
        $firstError = null;
        $unknowns = [];
        foreach ($items as $item) {
            try {
                $result = $this->evalExpr($body, $this->macroContext($context, $valueVar, $secondVar, $mapCollection, $item), $depth + 1);
                if ($result instanceof UnknownValue) {
                    $unknowns[] = $result;
                    continue;
                }
                $matches = $this->truthy($result);
            } catch (EvaluationException $error) {
                $firstError ??= $error;
                continue;
            }

            if (!$matches) {
                return false;
            }
        }

        if ($unknowns !== []) {
            return UnknownValue::merge($unknowns);
        }

        if ($firstError !== null) {
            throw $firstError;
        }

        return true;
    }

    /** @param list<array{key:mixed,value:mixed}> $items */
    private function macroExists(array $items, string $valueVar, ?string $secondVar, bool $mapCollection, Expr $body, EvaluationContext $context, int $depth): mixed
    {
        $firstError = null;
        $unknowns = [];
        foreach ($items as $item) {
            try {
                $result = $this->evalExpr($body, $this->macroContext($context, $valueVar, $secondVar, $mapCollection, $item), $depth + 1);
                if ($result instanceof UnknownValue) {
                    $unknowns[] = $result;
                    continue;
                }
                $matches = $this->truthy($result);
            } catch (EvaluationException $error) {
                $firstError ??= $error;
                continue;
            }

            if ($matches) {
                return true;
            }
        }

        if ($unknowns !== []) {
            return UnknownValue::merge($unknowns);
        }

        if ($firstError !== null) {
            throw $firstError;
        }

        return false;
    }

    /** @param list<array{key:mixed,value:mixed}> $items */
    private function macroExistsOne(array $items, string $valueVar, ?string $secondVar, bool $mapCollection, Expr $body, EvaluationContext $context, int $depth): mixed
    {
        $count = 0;
        $firstError = null;
        $unknowns = [];
        foreach ($items as $item) {
            try {
                $result = $this->evalExpr($body, $this->macroContext($context, $valueVar, $secondVar, $mapCollection, $item), $depth + 1);
                if ($result instanceof UnknownValue) {
                    $unknowns[] = $result;
                    continue;
                }
                $matches = $this->truthy($result);
            } catch (EvaluationException $error) {
                $firstError ??= $error;
                continue;
            }

            if ($matches) {
                $count++;
            }
        }

        if ($firstError !== null) {
            throw $firstError;
        }

        if ($count > 1) {
            return false;
        }

        if ($unknowns !== []) {
            return UnknownValue::merge($unknowns);
        }

        return $count === 1;
    }

    /** @param list<array{key:mixed,value:mixed}> $items */
    private function macroFilter(array $items, string $valueVar, ?string $secondVar, bool $mapCollection, Expr $body, EvaluationContext $context, int $depth): mixed
    {
        $out = [];
        $unknowns = [];
        foreach ($items as $item) {
            $next = $this->macroContext($context, $valueVar, $secondVar, $mapCollection, $item);
            $result = $this->evalExpr($body, $next, $depth + 1);
            if ($result instanceof UnknownValue) {
                $unknowns[] = $result;
                continue;
            }
            if ($this->truthy($result)) {
                $out[] = $mapCollection && $secondVar === null ? $item['key'] : $item['value'];
            }
        }

        if ($unknowns !== []) {
            return UnknownValue::merge($unknowns);
        }

        return $out;
    }

    /** @param list<array{key:mixed,value:mixed}> $items */
    private function macroMap(array $items, string $valueVar, ?string $secondVar, bool $mapCollection, Expr $body, EvaluationContext $context, int $depth): mixed
    {
        $out = [];
        $unknowns = [];
        foreach ($items as $item) {
            $result = $this->evalExpr($body, $this->macroContext($context, $valueVar, $secondVar, $mapCollection, $item), $depth + 1);
            if ($result instanceof UnknownValue) {
                $unknowns[] = $result;
                continue;
            }
            $out[] = $result;
        }

        if ($unknowns !== []) {
            return UnknownValue::merge($unknowns);
        }

        return $out;
    }

    /** @param list<array{key:mixed,value:mixed}> $items */
    private function macroTransformList(array $items, string $valueVar, ?string $secondVar, bool $mapCollection, array $args, EvaluationContext $context, int $depth): mixed
    {
        if ($secondVar === null || (count($args) !== 3 && count($args) !== 4)) {
            throw new EvaluationException('transformList macro has invalid argument count');
        }

        $filter = count($args) === 4 ? $args[2] : null;
        $transform = $args[array_key_last($args)];
        $out = [];
        foreach ($items as $item) {
            $next = $this->macroContext($context, $valueVar, $secondVar, $mapCollection, $item);
            if ($filter !== null && !$this->truthy($this->evalExpr($filter, $next, $depth + 1))) {
                continue;
            }

            $out[] = $this->evalExpr($transform, $next, $depth + 1);
        }

        return $out;
    }

    /** @param list<array{key:mixed,value:mixed}> $items */
    private function macroTransformMap(array $items, string $valueVar, ?string $secondVar, bool $mapCollection, array $args, EvaluationContext $context, int $depth): mixed
    {
        if ($secondVar === null || (count($args) !== 3 && count($args) !== 4)) {
            throw new EvaluationException('transformMap macro has invalid argument count');
        }

        $filter = count($args) === 4 ? $args[2] : null;
        $transform = $args[array_key_last($args)];
        $out = [];
        foreach ($items as $item) {
            $next = $this->macroContext($context, $valueVar, $secondVar, $mapCollection, $item);
            if ($filter !== null && !$this->truthy($this->evalExpr($filter, $next, $depth + 1))) {
                continue;
            }

            $out[$this->arrayKey($item['key'])] = $this->evalExpr($transform, $next, $depth + 1);
        }

        return $out;
    }

    /** @param array{key:mixed,value:mixed} $item */
    private function macroContext(EvaluationContext $context, string $valueVar, ?string $secondVar, bool $mapCollection, array $item): EvaluationContext
    {
        if ($mapCollection) {
            $locals = [$valueVar => $item['key']];
            if ($secondVar !== null) {
                $locals[$secondVar] = $item['value'];
            }

            return $context->withMany($locals);
        }

        if ($secondVar !== null) {
            return $context->withMany([
                $valueVar => $item['key'],
                $secondVar => $item['value'],
            ]);
        }

        return $context->with($valueVar, $item['value']);
    }

    /** @return list<array{key:mixed,value:mixed}> */
    private function iterableItems(mixed $collection): array
    {
        $protoItems = $this->protoAdapter->iterableItems($collection);
        if ($protoItems !== null) {
            return $protoItems;
        }

        if (!is_array($collection)) {
            throw new EvaluationException('comprehension target must be a list or map');
        }

        $items = [];
        $mapCollection = !array_is_list($collection);
        foreach ($collection as $key => $value) {
            $items[] = ['key' => $mapCollection ? $this->iterableMapKey($key) : $key, 'value' => $value];
        }

        return $items;
    }

    private function iterableMapKey(int|string $key): int|string|UInt
    {
        if (!is_string($key) || !str_starts_with($key, 'n:')) {
            return $key;
        }

        $number = substr($key, 2);
        if (preg_match('/^-?[0-9]+$/', $number) !== 1) {
            return $key;
        }

        if (
            bccomp($number, (string) PHP_INT_MAX, 0) <= 0
            && bccomp($number, (string) PHP_INT_MIN, 0) >= 0
        ) {
            return (int) $number;
        }

        return str_starts_with($number, '-') ? $number : UInt::from($number);
    }

    private function isMapCollection(mixed $collection): bool
    {
        if ($collection instanceof MapField) {
            return true;
        }

        return is_array($collection) && !array_is_list($collection);
    }

    private function macroVariable(?Expr $expr, string $macro): string
    {
        if ($expr === null) {
            throw new EvaluationException(sprintf('%s macro requires identifier variable', $macro));
        }

        if ($expr->kind === 'ident' && !str_contains((string) $expr->value, '.')) {
            return (string) $expr->value;
        }

        $synthetic = $this->syntheticCelVariableName($expr);
        if ($synthetic !== null) {
            return $synthetic;
        }

        throw new EvaluationException(sprintf('%s macro requires identifier variable', $macro));
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
            throw new EvaluationException(sprintf('%s expects one integer argument', $name));
        }

        return $this->literalIntArg($expr->args[0], $name);
    }

    /** @return array{0:int, 1:int} */
    private function twoLiteralIntArgs(Expr $expr, string $name): array
    {
        if (count($expr->args) !== 2) {
            throw new EvaluationException(sprintf('%s expects two integer arguments', $name));
        }

        return [
            $this->literalIntArg($expr->args[0], $name),
            $this->literalIntArg($expr->args[1], $name),
        ];
    }

    private function literalIntArg(Expr $expr, string $name): int
    {
        if ($expr->kind !== 'literal' || (!is_int($expr->value) && !$expr->value instanceof IntLiteral)) {
            throw new EvaluationException(sprintf('%s expects integer literal arguments', $name));
        }

        return $expr->value instanceof IntLiteral ? $expr->value->toInt() : $expr->value;
    }

    /** @param list<mixed> $args */
    private function evalReceiverCall(string $name, mixed $target, array $args, EvaluationContext $context): mixed
    {
        $target = $this->protoAdapter->normalize($target);

        if ($target instanceof OptionalValue) {
            return $this->evalOptionalReceiverFunction($name, $target, $args);
        }

        if ($this->strongEnums && $target instanceof Type) {
            $enumType = $this->protoRegistry->resolveEnumType((string) $target . '.' . $name);
            if ($enumType !== null) {
                return $this->toEnumValue($enumType, $args[0] ?? null);
            }
        }

        if ($target instanceof TimestampValue) {
            return $this->timestampSelector($target, $name, $args);
        }

        if ($target instanceof DurationValue) {
            return $this->durationSelector($target, $name, $args);
        }

        if ($target instanceof NetworkAddress) {
            return $this->networkAddressReceiver($target, $name, $args);
        }

        if ($target instanceof NetworkCidr) {
            return $this->networkCidrReceiver($target, $name, $args);
        }

        if (isset(self::STRING_EXTENSION_RECEIVERS[$name])) {
            return $this->evalStringExtensionReceiver($name, $target, $args);
        }

        if ($name === 'join') {
            return $this->evalJoinReceiver($target, $args);
        }

        return match ($name) {
            'startsWith' => str_starts_with($this->requireString($target), $this->requireString($args[0] ?? null)),
            'endsWith' => str_ends_with($this->requireString($target), $this->requireString($args[0] ?? null)),
            'contains' => str_contains($this->requireString($target), $this->requireString($args[0] ?? null)),
            'matches' => $this->matches($this->requireString($target), $this->requireString($args[0] ?? null)),
            'size' => $this->size($target),
            default => $this->evalCustomFunction($name, array_merge([$target], $args), $context, true),
        };
    }

    /** @param list<mixed> $args */
    private function evalOptionalReceiverFunction(string $name, OptionalValue $target, array $args): mixed
    {
        return match ($name) {
            'hasValue' => $target->hasValue(),
            'value' => $target->value(),
            'or' => $target->hasValue() ? $target : $this->requireOptional($args[0] ?? null),
            'orValue' => $target->hasValue() ? $target->value() : ($args[0] ?? null),
            default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
        };
    }

    /** @param list<mixed> $args */
    private function evalStringExtensionReceiver(string $name, mixed $target, array $args): mixed
    {
        $value = $this->requireString($target);

        return match ($name) {
            'charAt' => $this->stringCharAt($value, $args),
            'indexOf' => $this->stringIndexOf($value, $args, false),
            'lastIndexOf' => $this->stringIndexOf($value, $args, true),
            'lowerAscii' => $this->asciiCase($value, false, $args),
            'upperAscii' => $this->asciiCase($value, true, $args),
            'replace' => $this->stringReplace($value, $args),
            'split' => $this->stringSplit($value, $args),
            'substring' => $this->stringSubstring($value, $args),
            'trim' => $this->stringTrim($value, $args),
            'reverse' => $this->stringReverse($value, $args),
            'format' => $this->stringFormat($value, $args),
            default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
        };
    }

    /** @param list<mixed> $args */
    private function stringCharAt(string $value, array $args): string
    {
        if (count($args) !== 1) {
            throw new EvaluationException('no such overload');
        }

        $index = $this->requireInt($args[0]);
        $length = mb_strlen($value);
        $this->assertStringIndex($index, $length, true);

        return $index === $length ? '' : mb_substr($value, $index, 1);
    }

    /** @param list<mixed> $args */
    private function stringIndexOf(string $value, array $args, bool $last): int
    {
        $argCount = count($args);
        if ($argCount !== 1 && $argCount !== 2) {
            throw new EvaluationException('no such overload');
        }

        $needle = $this->requireString($args[0] ?? null);
        $length = mb_strlen($value);
        $start = $argCount === 2 ? $this->requireInt($args[1]) : ($last ? $length : 0);
        $this->assertStringIndex($start, $length, true);

        if ($needle === '') {
            return $start;
        }

        $needleLength = mb_strlen($needle);
        if ($last) {
            for ($i = min($start, $length - $needleLength); $i >= 0; $i--) {
                if (mb_substr($value, $i, $needleLength) === $needle) {
                    return $i;
                }
            }

            return -1;
        }

        $position = mb_strpos($value, $needle, $start);

        return $position === false ? -1 : $position;
    }

    /** @param list<mixed> $args */
    private function asciiCase(string $value, bool $upper, array $args): string
    {
        if ($args !== []) {
            throw new EvaluationException('no such overload');
        }

        return $upper
            ? strtr($value, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')
            : strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /** @param list<mixed> $args */
    private function stringReplace(string $value, array $args): string
    {
        $argCount = count($args);
        if ($argCount !== 2 && $argCount !== 3) {
            throw new EvaluationException('no such overload');
        }

        $search = $this->requireString($args[0] ?? null);
        $replacement = $this->requireString($args[1] ?? null);
        $limit = $argCount === 3 ? $this->requireInt($args[2]) : -1;

        if ($search === '' || $limit === 0) {
            return $value;
        }
        if ($limit < 0) {
            return str_replace($search, $replacement, $value);
        }

        $out = '';
        $offset = 0;
        $count = 0;
        while ($count < $limit) {
            $position = mb_strpos($value, $search, $offset);
            if ($position === false) {
                break;
            }
            $out .= mb_substr($value, $offset, $position - $offset) . $replacement;
            $offset = $position + mb_strlen($search);
            $count++;
        }

        return $out . mb_substr($value, $offset);
    }

    /** @param list<mixed> $args */
    private function stringSplit(string $value, array $args): array
    {
        $argCount = count($args);
        if ($argCount !== 1 && $argCount !== 2) {
            throw new EvaluationException('no such overload');
        }

        $separator = $this->requireString($args[0] ?? null);
        $limit = $argCount === 2 ? $this->requireInt($args[1]) : -1;
        if ($limit === 0) {
            return [];
        }
        if ($limit === 1) {
            return [$value];
        }
        if ($separator === '') {
            return mb_str_split($value);
        }

        return explode($separator, $value);
    }

    /** @param list<mixed> $args */
    private function stringSubstring(string $value, array $args): string
    {
        $argCount = count($args);
        if ($argCount !== 1 && $argCount !== 2) {
            throw new EvaluationException('no such overload');
        }

        $length = mb_strlen($value);
        $start = $this->requireInt($args[0] ?? null);
        $end = $argCount === 2 ? $this->requireInt($args[1]) : $length;
        $this->assertStringIndex($start, $length, true);
        $this->assertStringIndex($end, $length, true);
        if ($end < $start) {
            throw new EvaluationException(sprintf('invalid substring range. start: %d, end: %d', $start, $end));
        }

        return mb_substr($value, $start, $end - $start);
    }

    /** @param list<mixed> $args */
    private function stringTrim(string $value, array $args): string
    {
        if ($args !== []) {
            throw new EvaluationException('no such overload');
        }

        return preg_replace('/^[\p{Z}\t\n\r\f\x{0B}\x{0085}]+|[\p{Z}\t\n\r\f\x{0B}\x{0085}]+$/u', '', $value) ?? $value;
    }

    /** @param list<mixed> $args */
    private function stringReverse(string $value, array $args): string
    {
        if ($args !== []) {
            throw new EvaluationException('no such overload');
        }

        return implode('', array_reverse(mb_str_split($value)));
    }

    /** @param list<mixed> $args */
    private function stringFormat(string $format, array $args): string
    {
        if (count($args) !== 1) {
            throw new EvaluationException('no such overload');
        }

        $values = $this->protoAdapter->normalize($args[0]);
        if (!is_array($values) || !array_is_list($values)) {
            throw new EvaluationException('no such overload');
        }

        $out = '';
        $index = 0;
        $length = strlen($format);
        $argIndex = 0;
        while ($index < $length) {
            $char = $format[$index];
            if ($char !== '%') {
                $out .= $char;
                $index++;
                continue;
            }

            if (($format[$index + 1] ?? '') === '%') {
                $out .= '%';
                $index += 2;
                continue;
            }

            $index++;
            $precision = null;
            if (($format[$index] ?? '') === '.') {
                $index++;
                $start = $index;
                while ($index < $length && ctype_digit($format[$index])) {
                    $index++;
                }
                if ($start === $index) {
                    throw new EvaluationException('could not parse formatting clause');
                }
                $precision = (int) substr($format, $start, $index - $start);
            }

            $clause = $format[$index] ?? '';
            if (!isset(self::FORMAT_CLAUSES[$clause])) {
                throw new EvaluationException(sprintf('could not parse formatting clause: unrecognized formatting clause "%s"', $clause));
            }
            if (!array_key_exists($argIndex, $values)) {
                throw new EvaluationException(sprintf('index %d out of range', $argIndex));
            }

            $out .= $this->formatCelClause($clause, $values[$argIndex], $precision);
            $argIndex++;
            $index++;
        }

        return $out;
    }

    private function formatCelClause(string $clause, mixed $value, ?int $precision): string
    {
        $value = $this->protoAdapter->normalize($value);

        return match ($clause) {
            's' => $this->formatCelString($value),
            'd' => $this->formatCelDecimal($value),
            'b' => $this->formatCelBinary($value),
            'o' => $this->formatCelOctal($value),
            'x', 'X' => $this->formatCelHex($value, $clause === 'X'),
            'e' => $this->formatCelFloat($value, $precision, true),
            'f' => $this->formatCelFloat($value, $precision, false),
            default => throw new EvaluationException('unsupported formatting clause'),
        };
    }

    private function formatCelString(mixed $value): string
    {
        $value = $this->protoAdapter->normalize($value);
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value instanceof UInt) {
            return $value->value();
        }
        if (is_float($value)) {
            return $this->formatFiniteOrSpecialFloat($value);
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof Bytes) {
            $raw = $value->raw();
            if (!mb_check_encoding($raw, 'UTF-8')) {
                throw new EvaluationException('invalid UTF-8');
            }

            return $raw;
        }
        if ($value instanceof Type || $value instanceof TimestampValue || $value instanceof DurationValue) {
            return (string) $value;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $items = [];
                foreach ($value as $item) {
                    $items[] = $this->formatCelString($item);
                }

                return '[' . implode(', ', $items) . ']';
            }

            $entries = [];
            foreach ($value as $key => $item) {
                $formattedKey = $this->formatCelString($this->iterableMapKey($key));
                $entries[$formattedKey] = $formattedKey . ': ' . $this->formatCelString($item);
            }
            ksort($entries, SORT_STRING);

            return '{' . implode(', ', array_values($entries)) . '}';
        }

        throw new EvaluationException('error during formatting: unsupported string value');
    }

    private function formatCelDecimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value instanceof UInt) {
            return $value->value();
        }
        if (is_float($value) && !is_finite($value)) {
            return $this->formatFiniteOrSpecialFloat($value);
        }

        throw new EvaluationException('error during formatting: decimal clause can only be used on integers');
    }

    private function formatCelBinary(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return $this->decimalStringToBase((string) $value, 2);
        }
        if ($value instanceof UInt) {
            return $this->decimalStringToBase($value->value(), 2);
        }

        throw new EvaluationException('error during formatting: only integers and bools can be formatted as binary');
    }

    private function formatCelOctal(mixed $value): string
    {
        if (is_int($value)) {
            return $this->decimalStringToBase((string) $value, 8);
        }
        if ($value instanceof UInt) {
            return $this->decimalStringToBase($value->value(), 8);
        }

        throw new EvaluationException('error during formatting: octal clause can only be used on integers');
    }

    private function formatCelHex(mixed $value, bool $upper): string
    {
        if (is_string($value)) {
            $hex = bin2hex($value);
            return $upper ? strtoupper($hex) : $hex;
        }
        if ($value instanceof Bytes) {
            $hex = bin2hex($value->raw());
            return $upper ? strtoupper($hex) : $hex;
        }
        if (is_int($value)) {
            $hex = $this->decimalStringToBase((string) $value, 16);
            return $upper ? strtoupper($hex) : $hex;
        }
        if ($value instanceof UInt) {
            $hex = $this->decimalStringToBase($value->value(), 16);
            return $upper ? strtoupper($hex) : $hex;
        }

        throw new EvaluationException('error during formatting: only integers, byte buffers, and strings can be formatted as hex');
    }

    private function formatCelFloat(mixed $value, ?int $precision, bool $scientific): string
    {
        if ($value instanceof UInt) {
            $value = (float) $value->value();
        }
        if (is_int($value)) {
            $value = (float) $value;
        }
        if (!is_float($value)) {
            throw new EvaluationException($scientific
                ? 'error during formatting: scientific clause can only be used on doubles'
                : 'error during formatting: fixed-point clause can only be used on doubles');
        }
        if (!is_finite($value)) {
            return $this->formatFiniteOrSpecialFloat($value);
        }

        return sprintf('%.' . ($precision ?? 6) . ($scientific ? 'e' : 'f'), $value);
    }

    private function formatFiniteOrSpecialFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value < 0.0 ? '-Infinity' : 'Infinity';
        }

        return (string) $value;
    }

    private function decimalStringToBase(string $decimal, int $base): string
    {
        $negative = str_starts_with($decimal, '-');
        if ($negative) {
            $decimal = substr($decimal, 1);
        }
        if ($decimal === '0') {
            return '0';
        }

        $digits = '0123456789abcdef';
        $out = '';
        while (bccomp($decimal, '0', 0) === 1) {
            $remainder = (int) bcmod($decimal, (string) $base);
            $out = $digits[$remainder] . $out;
            $decimal = bcdiv($decimal, (string) $base, 0);
        }

        return $negative ? '-' . $out : $out;
    }

    /** @param list<mixed> $args */
    private function evalJoinReceiver(mixed $target, array $args): string
    {
        $target = $this->protoAdapter->normalize($target);
        if (!is_array($target) || !array_is_list($target) || count($args) > 1) {
            throw new EvaluationException('no such overload');
        }

        $separator = count($args) === 1 ? $this->requireString($args[0]) : '';
        $strings = [];
        foreach ($target as $item) {
            $strings[] = $this->requireString($item);
        }

        return implode($separator, $strings);
    }

    private function assertStringIndex(int $index, int $length, bool $allowEnd): void
    {
        if ($index < 0 || $index > $length || (!$allowEnd && $index === $length)) {
            throw new EvaluationException(sprintf('index out of range: %d', $index));
        }
    }

    private function quoteString(string $value): string
    {
        $out = '"';
        foreach (mb_str_split($value) as $char) {
            $out .= match ($char) {
                "\x07" => '\\a',
                "\x08" => '\\b',
                "\x0c" => '\\f',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                "\x0b" => '\\v',
                '"' => '\\"',
                '\\' => '\\\\',
                default => $char,
            };
        }

        return $out . '"';
    }

    /** @param list<mixed> $args */
    private function timestampSelector(TimestampValue $target, string $name, array $args): int
    {
        if (count($args) > 1) {
            throw new EvaluationException('no such overload');
        }

        $date = $target->value()->setTimezone($this->selectorTimezone($args[0] ?? null));

        return match ($name) {
            'getDate' => (int) $date->format('j'),
            'getDayOfMonth' => (int) $date->format('j') - 1,
            'getDayOfWeek' => (int) $date->format('w'),
            'getDayOfYear' => (int) $date->format('z'),
            'getFullYear' => (int) $date->format('Y'),
            'getHours' => (int) $date->format('G'),
            'getMilliseconds' => intdiv($target->nanos(), 1_000_000),
            'getMinutes' => (int) $date->format('i'),
            'getMonth' => (int) $date->format('n') - 1,
            'getSeconds' => (int) $date->format('s'),
            default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
        };
    }

    /** @param list<mixed> $args */
    private function durationSelector(DurationValue $target, string $name, array $args = []): int
    {
        if ($args !== []) {
            throw new EvaluationException('no such overload');
        }

        $seconds = $target->seconds();

        return match ($name) {
            'getHours' => (int) floor($seconds / 3600),
            'getMilliseconds' => (int) floor(abs($seconds - floor($seconds)) * 1000),
            'getMinutes' => (int) floor($seconds / 60),
            'getSeconds' => (int) floor($seconds),
            default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
        };
    }

    /** @param list<mixed> $args */
    private function networkAddressReceiver(NetworkAddress $target, string $name, array $args): mixed
    {
        if ($args !== []) {
            throw new EvaluationException('no such overload');
        }

        return match ($name) {
            'family' => $target->family(),
            'isUnspecified' => $target->isUnspecified(),
            'isLoopback' => $target->isLoopback(),
            'isGlobalUnicast' => $target->isGlobalUnicast(),
            'isLinkLocalMulticast' => $target->isLinkLocalMulticast(),
            'isLinkLocalUnicast' => $target->isLinkLocalUnicast(),
            default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
        };
    }

    /** @param list<mixed> $args */
    private function networkCidrReceiver(NetworkCidr $target, string $name, array $args): mixed
    {
        return match ($name) {
            'containsIP' => $this->cidrContainsIP($target, $args),
            'containsCIDR' => $this->cidrContainsCIDR($target, $args),
            'ip' => $this->cidrAddress($target, $args),
            'masked' => $this->cidrMasked($target, $args),
            'prefixLength' => $this->cidrPrefixLength($target, $args),
            default => throw new EvaluationException(sprintf('undeclared receiver function "%s"', $name)),
        };
    }

    /** @param list<mixed> $args */
    private function cidrContainsIP(NetworkCidr $target, array $args): bool
    {
        if (count($args) !== 1) {
            throw new EvaluationException('no such overload');
        }

        $arg = $args[0];
        if (!$arg instanceof NetworkAddress && !is_string($arg)) {
            throw new EvaluationException('no such overload');
        }

        return $target->containsIP($arg);
    }

    /** @param list<mixed> $args */
    private function cidrContainsCIDR(NetworkCidr $target, array $args): bool
    {
        if (count($args) !== 1) {
            throw new EvaluationException('no such overload');
        }

        $arg = $args[0];
        if (!$arg instanceof NetworkCidr && !is_string($arg)) {
            throw new EvaluationException('no such overload');
        }

        return $target->containsCIDR($arg);
    }

    /** @param list<mixed> $args */
    private function cidrAddress(NetworkCidr $target, array $args): NetworkAddress
    {
        if ($args !== []) {
            throw new EvaluationException('no such overload');
        }

        return $target->address();
    }

    /** @param list<mixed> $args */
    private function cidrMasked(NetworkCidr $target, array $args): NetworkCidr
    {
        if ($args !== []) {
            throw new EvaluationException('no such overload');
        }

        return $target->masked();
    }

    /** @param list<mixed> $args */
    private function cidrPrefixLength(NetworkCidr $target, array $args): int
    {
        if ($args !== []) {
            throw new EvaluationException('no such overload');
        }

        return $target->prefixLength();
    }

    private function selectorTimezone(mixed $value): \DateTimeZone
    {
        if ($value === null) {
            return new \DateTimeZone('UTC');
        }

        $name = $this->requireString($value);
        if (preg_match('/^[0-9]{2}:[0-9]{2}$/', $name) === 1) {
            $name = '+' . $name;
        }
        if ($name === '-00:00') {
            $name = '+00:00';
        }

        return new \DateTimeZone($name);
    }

    /** @param list<mixed> $args */
    private function evalGlobalCall(string $name, array $args, EvaluationContext $context): mixed
    {
        return match ($name) {
            'size' => $this->size($args[0] ?? null),
            'type' => $this->typeOf($args[0] ?? null),
            'int' => $this->toInt($args[0] ?? null),
            'uint' => $this->toUint($args[0] ?? null),
            'double' => $this->toDouble($args[0] ?? null),
            'string' => $this->toString($args[0] ?? null),
            'bytes' => new Bytes($this->toString($args[0] ?? null)),
            'bool' => $this->toBool($args[0] ?? null),
            'dyn' => $args[0] ?? null,
            'duration' => $this->toDuration($args[0] ?? null),
            'timestamp' => $this->toTimestamp($args[0] ?? null),
            'ip' => NetworkAddress::parse($this->requireString($args[0] ?? null)),
            'cidr' => NetworkCidr::parse($this->requireString($args[0] ?? null)),
            'isIP' => NetworkAddress::isValid($this->requireString($args[0] ?? null)),
            default => $this->evalGlobalOrEnumFunction($name, $args, $context),
        };
    }

    /** @param list<mixed> $args */
    private function evalGlobalOrEnumFunction(string $name, array $args, EvaluationContext $context): mixed
    {
        if ($this->strongEnums) {
            $enumType = $this->protoRegistry->resolveEnumType($name);
            if ($enumType !== null) {
                return $this->toEnumValue($enumType, $args[0] ?? null);
            }
        }

        return $this->evalCustomFunction($name, $args, $context, false);
    }

    private function toEnumValue(string $enumType, mixed $value): EnumValue
    {
        if ($value instanceof EnumValue) {
            return $value->type() === $enumType
                ? $value
                : new EnumValue($enumType, $value->value());
        }

        if (is_string($value)) {
            $enum = $this->protoRegistry->resolveEnumSymbol($enumType, $value);
            if ($enum === null) {
                throw new EvaluationException('invalid enum name');
            }

            return $enum;
        }

        $int = $this->requireInt($value);
        if ($int < -2147483648 || $int > 2147483647) {
            throw new EvaluationException('enum range error');
        }

        return new EnumValue($enumType, $int);
    }

    /** @param list<mixed> $args */
    private function evalCustomFunction(string $name, array $args, EvaluationContext $context, bool $receiverStyle): mixed
    {
        $declaration = $this->functions[$name] ?? null;
        if (!$declaration) {
            throw new EvaluationException(sprintf('undeclared function "%s"', $name));
        }

        foreach ($declaration->overloads as $overload) {
            if ($overload->receiverStyle !== $receiverStyle || count($overload->argumentTypes) !== count($args)) {
                continue;
            }

            return ($overload->implementation)($args, $context);
        }

        throw new EvaluationException(sprintf('no matching overload for "%s"', $name));
    }

    private function resolveIdentifier(string $name, EvaluationContext $context): mixed
    {
        $absolute = $name !== '' && $name[0] === '.';
        $normalized = $absolute ? substr($name, 1) : $name;

        if (!$absolute) {
            if (!str_contains($normalized, '.')) {
                if ($context->hasLocal($normalized)) {
                    return $context->getLocal($normalized);
                }
            } else {
                $localRoot = $this->resolveLocalRootIdentifier($normalized, $context);
                if ($localRoot[0]) {
                    return $localRoot[1];
                }
            }
        }

        if (!str_contains($normalized, '.') && ($absolute || $this->containerPrefixes === [])) {
            $candidateValue = $this->resolveContextCandidate($normalized, $context, !$absolute);
            if ($candidateValue[0]) {
                return $candidateValue[1];
            }

            $protoIdentifier = $this->resolveProtoIdentifier($normalized);
            if ($protoIdentifier[0]) {
                return $protoIdentifier[1];
            }

            return $this->typeIdentifier($normalized, $name);
        }

        foreach ($this->candidateNames($name) as $candidate) {
            $candidateValue = $this->resolveContextCandidate($candidate, $context, !$absolute);
            if ($candidateValue[0]) {
                return $candidateValue[1];
            }

            $protoIdentifier = $this->resolveProtoIdentifier($candidate);
            if ($protoIdentifier[0]) {
                return $protoIdentifier[1];
            }
        }

        return $this->typeIdentifier($normalized, $name);
    }

    /** @return array{0: bool, 1: mixed} */
    private function resolveProtoIdentifier(string $candidate): array
    {
        $enumValue = $this->protoRegistry->resolveEnumConstant($candidate);
        if ($enumValue !== null) {
            return [
                true,
                $this->strongEnums
                    ? $this->protoRegistry->resolveEnumValue($candidate) ?? $enumValue
                    : $enumValue,
            ];
        }

        if ($this->protoRegistry->resolveMessage($candidate) !== null) {
            return [true, Type::message($candidate)];
        }

        return [false, null];
    }

    private function typeIdentifier(string $normalized, string $original): mixed
    {
        return match ($normalized) {
            'bool' => Type::bool(),
            'int' => Type::int(),
            'uint' => Type::uint(),
            'double' => Type::double(),
            'string' => Type::string(),
            'bytes' => Type::bytes(),
            'list' => Type::list(Type::dyn()),
            'map' => Type::map(Type::dyn(), Type::dyn()),
            'net.IP' => Type::message('net.IP'),
            'net.CIDR' => Type::message('net.CIDR'),
            'null_type' => Type::null(),
            'type' => Type::type(),
            'optional_type' => Type::message('optional_type'),
            default => throw new EvaluationException(sprintf('undeclared reference to "%s"', $original)),
        };
    }

    /** @return array{0: bool, 1: mixed} */
    private function resolveLocalRootIdentifier(string $name, EvaluationContext $context): array
    {
        $parts = explode('.', $name);
        $root = array_shift($parts);
        if ($root === null || !$context->hasLocal($root)) {
            return [false, null];
        }

        $value = $context->getLocal($root);
        foreach ($parts as $part) {
            $value = $this->select($value, $part);
        }

        return [true, $value];
    }

    /** @return array{0: bool, 1: mixed} */
    private function resolveContextCandidate(string $name, EvaluationContext $context, bool $includeLocals): array
    {
        if ($includeLocals && $context->hasLocal($name)) {
            return [true, $context->getLocal($name)];
        }
        if ($context->hasActivation($name)) {
            return [true, $context->getActivation($name)];
        }
        if (!str_contains($name, '.')) {
            return [false, null];
        }

        $parts = explode('.', $name);
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $prefix = implode('.', array_slice($parts, 0, $i));
            if ($includeLocals && $context->hasLocal($prefix)) {
                $value = $context->getLocal($prefix);
            } elseif ($context->hasActivation($prefix)) {
                $value = $context->getActivation($prefix);
            } else {
                continue;
            }

            foreach (array_slice($parts, $i) as $part) {
                $value = $this->select($value, $part);
            }

            return [true, $value];
        }

        return [false, null];
    }

    /** @return list<string> */
    private function candidateNames(string $name): array
    {
        $absolute = $name !== '' && $name[0] === '.';
        $normalized = $absolute ? substr($name, 1) : $name;
        if ($normalized === '') {
            return [];
        }
        if ($absolute || $this->containerPrefixes === []) {
            return [$normalized];
        }

        $candidates = [];
        foreach ($this->containerPrefixes as $prefix) {
            $candidates[] = $prefix . '.' . $normalized;
        }
        $candidates[] = $normalized;

        return $candidates;
    }

    /** @return list<string> */
    private function containerPrefixes(string $container): array
    {
        $container = trim($container, '.');
        if ($container === '') {
            return [];
        }

        $prefixes = [];
        $containerParts = explode('.', $container);
        for ($i = count($containerParts); $i >= 1; $i--) {
            $prefixes[] = implode('.', array_slice($containerParts, 0, $i));
        }

        return $prefixes;
    }

    private function select(mixed $target, string $field): mixed
    {
        if ($target instanceof UnknownValue) {
            return $target->select($field);
        }

        if ($target instanceof OptionalValue) {
            return $this->optionalSelect($target, $field);
        }

        if ($this->protoAdapter->isProtoValue($target)) {
            return $this->protoAdapter->select($target, $field);
        }

        if (is_array($target) && array_key_exists($field, $target)) {
            return $target[$field];
        }

        if (is_object($target)) {
            if (isset($target->{$field}) || property_exists($target, $field)) {
                return $target->{$field};
            }

            $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
            if (method_exists($target, $method)) {
                return $target->{$method}();
            }
        }

        throw new EvaluationException(sprintf('no such field "%s"', $field));
    }

    private function optionalSelect(mixed $target, string $field): OptionalValue
    {
        if ($target instanceof OptionalValue) {
            if (!$target->hasValue()) {
                return OptionalValue::none();
            }

            $target = $target->value();
        }

        if ($target instanceof UnknownValue) {
            return OptionalValue::of($target->select($field));
        }

        if ($this->protoAdapter->isProtoValue($target)) {
            $presence = $this->protoAdapter->hasField($target, $field);
            if ($presence === false) {
                return OptionalValue::none();
            }
            if ($presence === true) {
                return OptionalValue::of($this->protoAdapter->select($target, $field));
            }
        }

        if (is_array($target)) {
            return array_key_exists($field, $target)
                ? OptionalValue::of($target[$field])
                : OptionalValue::none();
        }

        try {
            return OptionalValue::of($this->select($target, $field));
        } catch (EvaluationException $exception) {
            if (is_object($target)) {
                return OptionalValue::none();
            }

            throw $exception;
        }
    }

    private function index(mixed $target, mixed $index): mixed
    {
        if ($target instanceof UnknownValue) {
            return $target->index($index);
        }
        if ($index instanceof UnknownValue) {
            return $index;
        }

        if ($target instanceof OptionalValue) {
            return $this->optionalIndex($target, $index);
        }

        if ($this->protoAdapter->isProtoValue($target)) {
            $target = $this->protoAdapter->normalize($target);
        }

        if (is_array($target)) {
            if (array_is_list($target)) {
                $listIndex = $this->listIndex($index);
                if ($listIndex !== null && array_key_exists($listIndex, $target)) {
                    return $target[$listIndex];
                }
            } else {
                $lookup = $this->lookupArrayKey($target, $index);
                if ($lookup[0]) {
                    return $lookup[1];
                }
            }
        }

        $listIndex = $this->listIndex($index);

        if (is_string($target) && $listIndex !== null) {
            if ($listIndex >= 0 && $listIndex < mb_strlen($target)) {
                return mb_substr($target, $listIndex, 1);
            }
        }

        if ($target instanceof Bytes && $listIndex !== null) {
            $raw = $target->raw();
            if ($listIndex >= 0 && $listIndex < strlen($raw)) {
                return ord($raw[$listIndex]);
            }
        }

        throw new EvaluationException('index out of bounds or unsupported target');
    }

    private function optionalIndex(mixed $target, mixed $index): OptionalValue
    {
        if ($target instanceof OptionalValue) {
            if (!$target->hasValue()) {
                return OptionalValue::none();
            }

            $target = $target->value();
        }

        if ($target instanceof UnknownValue) {
            return OptionalValue::of($target->index($index));
        }
        if ($index instanceof UnknownValue) {
            return OptionalValue::of($index);
        }

        try {
            return OptionalValue::of($this->index($target, $index));
        } catch (EvaluationException) {
            return OptionalValue::none();
        }
    }

    private function has(Expr $expr, EvaluationContext $context): mixed
    {
        if ($expr->kind === 'ident') {
            try {
                $value = $this->resolveIdentifier((string) $expr->value, $context);
                if ($value instanceof UnknownValue) {
                    return $value;
                }
                if ($value instanceof OptionalValue) {
                    return $this->optionalPresence($value);
                }

                return true;
            } catch (EvaluationException) {
                return false;
            }
        }

        if ($expr->kind === 'optional_select' || $expr->kind === 'optional_index') {
            try {
                return $this->optionalPresence($this->requireOptional($this->evalExpr($expr, $context, 0)));
            } catch (EvaluationException) {
                return false;
            }
        }

        if ($expr->kind === 'select') {
            try {
                $target = $this->evalExpr($expr->target, $context, 0);
            } catch (EvaluationException) {
                return false;
            }
            if ($target instanceof UnknownValue) {
                return $target->select((string) $expr->value);
            }

            if ($target instanceof OptionalValue) {
                return $this->optionalPresence($this->optionalSelect($target, (string) $expr->value));
            }

            $protoPresence = $this->protoAdapter->hasField($target, (string) $expr->value);
            if ($protoPresence !== null) {
                return $protoPresence;
            }
            if (is_array($target)) {
                return array_key_exists((string) $expr->value, $target);
            }
            if (is_object($target)) {
                return property_exists($target, (string) $expr->value)
                    || isset($target->{(string) $expr->value})
                    || method_exists($target, 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $expr->value))));
            }
        }

        if ($expr->kind === 'index') {
            try {
                $value = $this->index($this->evalExpr($expr->target, $context, 0), $this->evalExpr($expr->args[0], $context, 0));
                if ($value instanceof UnknownValue) {
                    return $value;
                }
                if ($value instanceof OptionalValue) {
                    return $this->optionalPresence($value);
                }

                return true;
            } catch (EvaluationException) {
                return false;
            }
        }

        return false;
    }

    private function optionalPresence(OptionalValue $optional): bool|UnknownValue
    {
        if (!$optional->hasValue()) {
            return false;
        }

        $value = $optional->value();

        return $value instanceof UnknownValue ? $value : true;
    }

    private function add(mixed $left, mixed $right): mixed
    {
        if ($left instanceof TimestampValue && $right instanceof DurationValue) {
            return $this->addDurationToTimestamp($left, $right);
        }
        if ($left instanceof DurationValue && $right instanceof TimestampValue) {
            return $this->addDurationToTimestamp($right, $left);
        }
        if ($left instanceof DurationValue && $right instanceof DurationValue) {
            return $this->addDurations($left, $right);
        }
        if (is_string($left) && is_string($right)) {
            return $left . $right;
        }
        if ($left instanceof Bytes && $right instanceof Bytes) {
            return new Bytes($left->raw() . $right->raw());
        }
        if (is_array($left) && is_array($right) && array_is_list($left) && array_is_list($right)) {
            return array_merge($left, $right);
        }
        if ($left instanceof UInt && $right instanceof UInt) {
            return $left->add($right);
        }
        if (is_float($left) || is_float($right)) {
            return (float) $left + (float) $right;
        }

        return $this->intOp($left, $right, '+');
    }

    private function subtract(mixed $left, mixed $right): mixed
    {
        if ($left instanceof TimestampValue && $right instanceof DurationValue) {
            return $this->addDurationToTimestamp($left, DurationValue::fromParts(-$right->wholeSeconds(), -$right->nanos()));
        }
        if ($left instanceof TimestampValue && $right instanceof TimestampValue) {
            $duration = DurationValue::fromParts(
                $left->unixSeconds() - $right->unixSeconds(),
                $left->nanos() - $right->nanos(),
            );
            $this->assertDurationInRange($duration);

            return $duration;
        }
        if ($left instanceof DurationValue && $right instanceof DurationValue) {
            return $this->addDurations($left, DurationValue::fromParts(-$right->wholeSeconds(), -$right->nanos()));
        }
        if ($left instanceof UInt && $right instanceof UInt) {
            return $left->subtract($right);
        }
        if (is_float($left) || is_float($right)) {
            return (float) $left - (float) $right;
        }

        return $this->intOp($left, $right, '-');
    }

    private function multiply(mixed $left, mixed $right): mixed
    {
        if ($left instanceof UInt && $right instanceof UInt) {
            return $left->multiply($right);
        }
        if (is_float($left) || is_float($right)) {
            return (float) $left * (float) $right;
        }

        return $this->intOp($left, $right, '*');
    }

    private function divide(mixed $left, mixed $right): mixed
    {
        if (is_float($left) || is_float($right)) {
            return fdiv($this->numericToFloat($left), $this->numericToFloat($right));
        }
        if ($this->isZero($right)) {
            throw new EvaluationException('division by zero');
        }
        if ($left instanceof UInt && $right instanceof UInt) {
            return $left->divide($right);
        }

        return intdiv($this->requireInt($left), $this->requireInt($right));
    }

    private function mod(mixed $left, mixed $right): mixed
    {
        if ($this->isZero($right)) {
            throw new EvaluationException('modulo by zero');
        }
        if ($left instanceof UInt && $right instanceof UInt) {
            return $left->mod($right);
        }

        return $this->requireInt($left) % $this->requireInt($right);
    }

    private function negate(mixed $value): mixed
    {
        if ($value instanceof IntLiteral) {
            return $value->negate();
        }
        if (is_float($value)) {
            return -$value;
        }

        return $this->intOp(0, $value, '-');
    }

    private function intOp(mixed $left, mixed $right, string $operator): int
    {
        $l = $this->requireInt($left);
        $r = $this->requireInt($right);

        return match ($operator) {
            '+' => $this->intAdd($l, $r),
            '-' => $this->intSubtract($l, $r),
            '*' => $this->intMultiply($l, $r),
            default => throw new EvaluationException(sprintf('unsupported integer operator "%s"', $operator)),
        };
    }

    private function intAdd(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new EvaluationException('int64 overflow');
        }

        return $left + $right;
    }

    private function intSubtract(int $left, int $right): int
    {
        if (
            ($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new EvaluationException('int64 overflow');
        }

        return $left - $right;
    }

    private function intMultiply(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        if ($left > 0) {
            $overflow = $right > 0
                ? $left > intdiv(PHP_INT_MAX, $right)
                : $right < intdiv(PHP_INT_MIN, $left);
        } elseif ($right > 0) {
            $overflow = $left < intdiv(PHP_INT_MIN, $right);
        } else {
            $overflow = $left < intdiv(PHP_INT_MAX, $right);
        }

        if ($overflow) {
            throw new EvaluationException('int64 overflow');
        }

        return $left * $right;
    }

    private function compare(mixed $left, mixed $right): int
    {
        $left = $this->protoAdapter->normalize($left);
        $right = $this->protoAdapter->normalize($right);

        if ($left instanceof UInt && $right instanceof UInt) {
            return $left->compare($right);
        }

        if ((is_int($left) || is_float($left) || $left instanceof UInt || $left instanceof EnumValue) && (is_int($right) || is_float($right) || $right instanceof UInt || $right instanceof EnumValue)) {
            return $this->numericCompare($left, $right);
        }

        if (is_string($left) && is_string($right)) {
            return $left <=> $right;
        }

        if ($left instanceof Bytes && $right instanceof Bytes) {
            return strcmp($left->raw(), $right->raw());
        }

        if (is_bool($left) && is_bool($right)) {
            return (int) $left <=> (int) $right;
        }

        if ($left instanceof TimestampValue && $right instanceof TimestampValue) {
            return [$left->unixSeconds(), $left->nanos()] <=> [$right->unixSeconds(), $right->nanos()];
        }

        if ($left instanceof DurationValue && $right instanceof DurationValue) {
            return [$left->wholeSeconds(), $left->nanos()] <=> [$right->wholeSeconds(), $right->nanos()];
        }

        throw new EvaluationException('values are not comparable');
    }

    private function celEquals(mixed $left, mixed $right): bool
    {
        $left = $this->protoAdapter->normalize($left);
        $right = $this->protoAdapter->normalize($right);

        if ($left instanceof OptionalValue && $right instanceof OptionalValue) {
            if ($left->hasValue() !== $right->hasValue()) {
                return false;
            }
            if (!$left->hasValue()) {
                return true;
            }

            return $this->celEquals($left->value(), $right->value());
        }

        if ($left instanceof OptionalValue || $right instanceof OptionalValue) {
            return false;
        }

        if ($left instanceof EnumValue && $right instanceof EnumValue) {
            return $left->type() === $right->type() && $left->value() === $right->value();
        }

        if ((is_int($left) || is_float($left) || $left instanceof UInt || $left instanceof EnumValue) && (is_int($right) || is_float($right) || $right instanceof UInt || $right instanceof EnumValue)) {
            if ((is_float($left) && is_nan($left)) || (is_float($right) && is_nan($right))) {
                return false;
            }

            return $this->numericCompare($left, $right) === 0;
        }

        if ($left instanceof Bytes && $right instanceof Bytes) {
            return $left->raw() === $right->raw();
        }

        if ($left instanceof Type && $right instanceof Type) {
            return $left->equals($right);
        }

        if ($left instanceof TimestampValue && $right instanceof TimestampValue) {
            return $left->unixSeconds() === $right->unixSeconds() && $left->nanos() === $right->nanos();
        }

        if ($left instanceof DurationValue && $right instanceof DurationValue) {
            return $left->wholeSeconds() === $right->wholeSeconds() && $left->nanos() === $right->nanos();
        }

        if ($left instanceof NetworkAddress && $right instanceof NetworkAddress) {
            return $left->equals($right);
        }

        if ($left instanceof NetworkCidr && $right instanceof NetworkCidr) {
            return $left->equals($right);
        }

        if ($left instanceof UInt || $right instanceof UInt || $left instanceof EnumValue || $right instanceof EnumValue || $left instanceof Bytes || $right instanceof Bytes) {
            return false;
        }

        if (is_array($left) && is_array($right)) {
            return $this->arraysEqual($left, $right);
        }

        if ($left instanceof \Google\Protobuf\Internal\Message && $right instanceof \Google\Protobuf\Internal\Message) {
            return $left::class === $right::class && $left->serializeToString() === $right->serializeToString();
        }

        return $left === $right;
    }

    private function arraysEqual(array $left, array $right): bool
    {
        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }

        if (array_is_list($left)) {
            foreach ($left as $index => $leftValue) {
                if (!$this->celEquals($leftValue, $right[$index])) {
                    return false;
                }
            }

            return true;
        }

        foreach ($left as $key => $leftValue) {
            if (!array_key_exists($key, $right) || !$this->celEquals($leftValue, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    private function numericCompare(mixed $left, mixed $right): int
    {
        if (is_float($left) || is_float($right)) {
            return $this->numericToFloat($left) <=> $this->numericToFloat($right);
        }

        return bccomp($this->numericToString($left), $this->numericToString($right), 0);
    }

    private function numericToFloat(mixed $value): float
    {
        if ($value instanceof EnumValue) {
            return (float) $value->value();
        }
        if ($value instanceof UInt) {
            return (float) $value->value();
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw new EvaluationException('expected numeric value');
    }

    private function numericToString(mixed $value): string
    {
        if ($value instanceof EnumValue) {
            return (string) $value->value();
        }
        if ($value instanceof UInt) {
            return $value->value();
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new EvaluationException('expected numeric value');
    }

    private function contains(mixed $container, mixed $needle): bool
    {
        $container = $this->protoAdapter->normalize($container);
        $needle = $this->protoAdapter->normalize($needle);

        if (is_array($container)) {
            if (array_is_list($container)) {
                foreach ($container as $value) {
                    if ($this->celEquals($value, $needle)) {
                        return true;
                    }
                }

                return false;
            }

            return $this->lookupArrayKey($container, $needle)[0];
        }

        if (is_string($container) && is_string($needle)) {
            return str_contains($container, $needle);
        }

        throw new EvaluationException('unsupported in operation');
    }

    private function matches(string $value, string $pattern): bool
    {
        return $this->regexProvider->matches($value, $pattern);
    }

    private function size(mixed $value): int
    {
        $value = $this->protoAdapter->normalize($value);

        return match (true) {
            is_string($value) => mb_strlen($value),
            $value instanceof Bytes => $value->length(),
            is_array($value) => count($value),
            default => throw new EvaluationException('size expects string, bytes, list, or map'),
        };
    }

    private function typeOf(mixed $value): Type
    {
        $value = $this->protoAdapter->normalize($value);

        return match (true) {
            is_bool($value) => Type::bool(),
            is_int($value) => Type::int(),
            $value instanceof EnumValue => Type::message($value->type()),
            $value instanceof OptionalValue => Type::message('optional_type'),
            $value instanceof UInt => Type::uint(),
            is_float($value) => Type::double(),
            is_string($value) => Type::string(),
            $value instanceof Bytes => Type::bytes(),
            $value instanceof NetworkAddress => Type::message('net.IP'),
            $value instanceof NetworkCidr => Type::message('net.CIDR'),
            $value instanceof TimestampValue => Type::message('google.protobuf.Timestamp'),
            $value instanceof DurationValue => Type::message('google.protobuf.Duration'),
            $value === null => Type::null(),
            is_array($value) && array_is_list($value) => Type::list(Type::dyn()),
            is_array($value) => Type::map(Type::dyn(), Type::dyn()),
            $value instanceof Type => Type::type(),
            default => Type::dyn(),
        };
    }

    private function addDurationToTimestamp(TimestampValue $timestamp, DurationValue $duration): TimestampValue
    {
        return TimestampValue::fromUnixSecondsNanos(
            $timestamp->unixSeconds() + $duration->wholeSeconds(),
            $timestamp->nanos() + $duration->nanos(),
        );
    }

    private function addDurations(DurationValue $left, DurationValue $right): DurationValue
    {
        $duration = DurationValue::fromParts(
            $left->wholeSeconds() + $right->wholeSeconds(),
            $left->nanos() + $right->nanos(),
        );
        $this->assertDurationInRange($duration);

        return $duration;
    }

    private function assertDurationInRange(DurationValue $duration): void
    {
        if (abs($duration->seconds()) > 315_360_000_000) {
            throw new EvaluationException('duration is outside the CEL duration range');
        }
    }

    private function parseDuration(string $value): DurationValue
    {
        if (!preg_match('/^(?<sign>[+-]?)(?<body>.+)$/u', $value, $outer)) {
            throw new EvaluationException('duration expects a string like "1.5s"');
        }

        $sign = ($outer['sign'] ?? '') === '-' ? -1 : 1;
        $body = $outer['body'];
        $offset = 0;
        $totalSeconds = 0.0;
        while ($offset < strlen($body)) {
            if (preg_match('/\G(?<number>(?:[0-9]+(?:\.[0-9]+)?)|(?:\.[0-9]+))(?<unit>ns|us|µs|ms|h|m|s)/u', $body, $matches, 0, $offset) !== 1) {
                throw new EvaluationException('duration expects a string like "1.5s"');
            }

            $number = (float) $matches['number'];
            $totalSeconds += match ($matches['unit']) {
                'h' => $number * 3600.0,
                'm' => $number * 60.0,
                's' => $number,
                'ms' => $number / 1_000.0,
                'us', 'µs' => $number / 1_000_000.0,
                'ns' => $number / 1_000_000_000.0,
            };
            $offset += strlen($matches[0]);
        }

        $duration = new DurationValue($sign * $totalSeconds);
        $this->assertDurationInRange($duration);

        return $duration;
    }

    private function toDuration(mixed $value): DurationValue
    {
        $value = $this->protoAdapter->normalize($value);
        if ($value instanceof DurationValue) {
            return $value;
        }

        return $this->parseDuration($this->requireString($value));
    }

    private function toTimestamp(mixed $value): TimestampValue
    {
        $value = $this->protoAdapter->normalize($value);
        if ($value instanceof TimestampValue) {
            return $value;
        }
        if (is_int($value)) {
            return TimestampValue::fromUnixSecondsNanos($value, 0);
        }
        if ($value instanceof UInt) {
            return TimestampValue::fromUnixSecondsNanos($value->toInt(), 0);
        }

        return TimestampValue::parse($this->requireString($value));
    }

    private function truthy(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new EvaluationException('expected bool');
        }

        return $value;
    }

    private function unknownFromValues(mixed ...$values): ?UnknownValue
    {
        $unknowns = [];
        foreach ($values as $value) {
            if ($value instanceof UnknownValue) {
                $unknowns[] = $value;
                continue;
            }
            if ($value instanceof OptionalValue && $value->hasValue()) {
                $inner = $value->value();
                if ($inner instanceof UnknownValue) {
                    $unknowns[] = $inner;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof UnknownValue) {
                        $unknowns[] = $item;
                    }
                }
            }
        }

        return $unknowns === [] ? null : UnknownValue::merge($unknowns);
    }

    private function requireString(mixed $value): string
    {
        $value = $this->protoAdapter->normalize($value);

        if (!is_string($value)) {
            throw new EvaluationException('expected string');
        }

        return $value;
    }

    private function requireBytes(mixed $value): Bytes
    {
        $value = $this->protoAdapter->normalize($value);

        if (!$value instanceof Bytes) {
            throw new EvaluationException('expected bytes');
        }

        return $value;
    }

    private function requireInt(mixed $value): int
    {
        $value = $this->protoAdapter->normalize($value);

        if ($value instanceof EnumValue) {
            return $value->value();
        }
        if (!is_int($value)) {
            throw new EvaluationException('expected int');
        }

        return $value;
    }

    private function toInt(mixed $value): int
    {
        $value = $this->protoAdapter->normalize($value);

        if ($value instanceof UInt) {
            return $value->toInt();
        }
        if ($value instanceof EnumValue) {
            return $value->value();
        }
        if ($value instanceof TimestampValue) {
            return (int) $value->value()->setTimezone(new \DateTimeZone('UTC'))->format('U');
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value) || $value >= (float) PHP_INT_MAX || $value <= (float) PHP_INT_MIN) {
                throw new EvaluationException('int64 overflow');
            }

            return (int) $value;
        }
        if (is_string($value)) {
            if (preg_match('/^[+-]?[0-9]+$/', $value) !== 1) {
                $float = (float) $value;
                if (!is_finite($float) || $float >= (float) PHP_INT_MAX || $float <= (float) PHP_INT_MIN) {
                    throw new EvaluationException('int64 overflow');
                }

                return (int) $float;
            }
            if (bccomp($value, (string) PHP_INT_MAX, 0) === 1 || bccomp($value, (string) PHP_INT_MIN, 0) === -1) {
                throw new EvaluationException('int64 overflow');
            }

            return (int) $value;
        }

        throw new EvaluationException('cannot convert value to int');
    }

    private function toUint(mixed $value): UInt
    {
        $value = $this->protoAdapter->normalize($value);

        if ($value instanceof UInt) {
            return $value;
        }
        if (is_int($value) || is_string($value)) {
            return UInt::from($value);
        }
        if (is_float($value)) {
            if (!is_finite($value) || $value < 0.0) {
                throw new EvaluationException('uint64 overflow');
            }

            return UInt::from(sprintf('%.0F', floor($value)));
        }

        throw new EvaluationException('cannot convert value to uint');
    }

    private function toDouble(mixed $value): float
    {
        $value = $this->protoAdapter->normalize($value);

        if ($value instanceof UInt) {
            return (float) $value->value();
        }
        if ($value instanceof EnumValue) {
            return (float) $value->value();
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            return match ($value) {
                'NaN' => NAN,
                'Infinity', '+Infinity' => INF,
                '-Infinity' => -INF,
                default => (float) $value,
            };
        }

        return (float) ($value ?? 0);
    }

    private function toBool(mixed $value): bool
    {
        $value = $this->protoAdapter->normalize($value);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return match ($value) {
                '1', 't', 'true', 'TRUE', 'True' => true,
                '0', 'f', 'false', 'FALSE', 'False' => false,
                default => throw new EvaluationException('Type conversion error'),
            };
        }

        throw new EvaluationException('cannot convert value to bool');
    }

    private function toString(mixed $value): string
    {
        $value = $this->protoAdapter->normalize($value);

        if ($value instanceof Bytes) {
            $raw = $value->raw();
            if (!mb_check_encoding($raw, 'UTF-8')) {
                throw new EvaluationException('invalid UTF-8');
            }

            return $raw;
        }
        if ($value instanceof UInt) {
            return $value->value();
        }
        if ($value instanceof Type) {
            return (string) $value;
        }
        if ($value instanceof TimestampValue || $value instanceof DurationValue) {
            return (string) $value;
        }
        if ($value instanceof NetworkAddress || $value instanceof NetworkCidr) {
            return (string) $value;
        }
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        throw new EvaluationException('cannot convert value to string');
    }

    /** @return array{0:bool,1:mixed} */
    private function lookupArrayKey(array $target, mixed $key): array
    {
        try {
            $arrayKey = $this->arrayKey($key, true);
            if (array_key_exists($arrayKey, $target)) {
                return [true, $target[$arrayKey]];
            }
        } catch (EvaluationException) {
            return [false, null];
        }

        if ((is_int($key) || is_string($key)) && array_key_exists($key, $target)) {
            return [true, $target[$key]];
        }

        return [false, null];
    }

    private function listIndex(mixed $index): ?int
    {
        $index = $this->protoAdapter->normalize($index);
        if (is_int($index)) {
            return $index;
        }
        if ($index instanceof UInt) {
            try {
                return $index->toInt();
            } catch (EvaluationException) {
                return null;
            }
        }
        if (is_float($index) && is_finite($index) && floor($index) === $index) {
            if ($index > (float) PHP_INT_MAX || $index < (float) PHP_INT_MIN) {
                return null;
            }

            return (int) $index;
        }

        return null;
    }

    private function arrayKey(mixed $key, bool $lookup = false): int|string
    {
        if (is_int($key)) {
            return 'n:' . $key;
        }
        if (is_float($key)) {
            if (is_finite($key) && floor($key) === $key) {
                return 'n:' . sprintf('%.0F', $key);
            }
            if ($lookup) {
                return 'n:' . $key;
            }

            throw new EvaluationException('unsupported map key type');
        }
        if (is_string($key)) {
            return $key;
        }
        if (is_bool($key)) {
            return $key ? 'true' : 'false';
        }
        if ($key instanceof UInt) {
            return 'n:' . $key->value();
        }

        throw new EvaluationException('unsupported map key type');
    }

    private function isZero(mixed $value): bool
    {
        return $this->isZeroValue($value);
    }

    private function requireOptional(mixed $value): OptionalValue
    {
        if (!$value instanceof OptionalValue) {
            throw new EvaluationException('expected optional value');
        }

        return $value;
    }

    private function isZeroValue(mixed $value): bool
    {
        $value = $this->protoAdapter->normalize($value);

        if ($value instanceof OptionalValue) {
            return !$value->hasValue() || $this->isZeroValue($value->value());
        }
        if ($value instanceof UInt) {
            return $value->value() === '0';
        }
        if ($value instanceof Bytes) {
            return $value->raw() === '';
        }
        if ($value instanceof \Google\Protobuf\Internal\Message) {
            return $value->serializeToString() === '';
        }
        if (is_array($value)) {
            return count($value) === 0;
        }

        return $value === null || $value === false || $value === 0 || $value === 0.0 || $value === '';
    }

    private function assertMacrosEnabled(string $name): void
    {
        if (!$this->macrosEnabled) {
            throw new EvaluationException(sprintf('macro "%s" is disabled', $name));
        }
    }

    private function step(int $depth): void
    {
        $this->steps++;
        if ($this->steps > $this->options->maxSteps) {
            throw new EvaluationException('evaluation step limit exceeded');
        }
        if ($depth > $this->options->maxDepth) {
            throw new EvaluationException('evaluation depth limit exceeded');
        }
    }
}
