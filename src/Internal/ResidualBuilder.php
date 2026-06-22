<?php

declare(strict_types=1);

namespace CEL\Internal;

use CEL\Bytes;
use CEL\DurationValue;
use CEL\ErrorValue;
use CEL\EvaluationContext;
use CEL\EvaluationException;
use CEL\FunctionDeclaration;
use CEL\OptionalValue;
use CEL\PcreRegexProvider;
use CEL\ProgramOptions;
use CEL\Proto\EnumValue;
use CEL\Proto\ProtoAdapter;
use CEL\Proto\ProtoRegistry;
use CEL\Proto\ProtoWrapperValue;
use CEL\RegexProvider;
use CEL\TimestampValue;
use CEL\Type;
use CEL\UInt;
use CEL\UnknownValue;
use Google\Protobuf\Internal\DescriptorPool;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\RepeatedField;

final class ResidualBuilder
{
    private readonly ProtoAdapter $protoAdapter;
    private readonly Evaluator $evaluator;

    /**
     * @param array<string, FunctionDeclaration> $functions
     */
    public function __construct(
        private readonly array $functions,
        private readonly bool $macrosEnabled,
        private readonly ProgramOptions $options,
        private readonly ProtoRegistry $protoRegistry,
        private readonly bool $strongEnums,
        private readonly string $container = '',
        ?RegexProvider $regexProvider = null,
    ) {
        $this->protoAdapter = new ProtoAdapter($this->protoRegistry, $this->strongEnums);
        $regexProvider ??= new PcreRegexProvider();
        $this->evaluator = new Evaluator($this->functions, $this->macrosEnabled, $this->options, $this->protoRegistry, $this->strongEnums, $this->container, $regexProvider);
    }

    public function residual(Expr $expr, EvaluationContext $context): string
    {
        return $this->build($expr, $context)['expr'];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function build(Expr $expr, EvaluationContext $context, int $parentPrecedence = 0): array
    {
        $value = $this->tryEvaluate($expr, $context);
        $unknown = $this->unknownFromValue($value);
        if ($unknown === null && !$value instanceof ErrorValue) {
            return [
                'expr' => $this->formatValue($value),
                'value' => $value,
                'known' => true,
                'precedence' => 100,
            ];
        }
        $partialValue = $unknown ?? $value;

        $part = match ($expr->kind) {
            'literal' => $this->literalPart($expr),
            'ident' => $this->unknownPart($partialValue, (string) $expr->value, 100),
            'select', 'optional_select' => $this->selectPart($expr, $context, $partialValue),
            'index', 'optional_index' => $this->indexPart($expr, $context, $partialValue),
            'unary' => $this->unaryPart($expr, $context),
            'binary' => $this->binaryPart($expr, $context),
            'conditional' => $this->conditionalPart($expr, $context),
            'call' => $this->callPart($expr, $context),
            'list' => $this->listPart($expr, $context),
            'map' => $this->mapPart($expr, $context),
            'struct' => $this->structPart($expr, $context),
            'comprehension' => $this->comprehensionPart($expr, $context, $partialValue),
            default => [
                'expr' => $value instanceof UnknownValue ? $this->unknownExpression($value) : 'error',
                'value' => $value,
                'known' => false,
                'precedence' => 100,
            ],
        };

        if ($part['precedence'] < $parentPrecedence) {
            $part['expr'] = '(' . $part['expr'] . ')';
        }

        return $part;
    }

    private function tryEvaluate(Expr $expr, EvaluationContext $context): mixed
    {
        try {
            return $this->evaluator->evaluate($expr, $context);
        } catch (EvaluationException $exception) {
            return ErrorValue::fromThrowable($exception);
        }
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function literalPart(Expr $expr): array
    {
        return [
            'expr' => $this->formatValue($expr->value instanceof IntLiteral ? $expr->value->toInt() : $expr->value),
            'value' => $expr->value,
            'known' => true,
            'precedence' => 100,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function comprehensionPart(Expr $expr, EvaluationContext $context, mixed $value): array
    {
        if (count($expr->args) !== 5 || !is_array($expr->value)) {
            return [
                'expr' => $value instanceof UnknownValue ? $this->unknownExpression($value) : 'error',
                'value' => $value,
                'known' => false,
                'precedence' => 100,
            ];
        }

        $iterVar = (string) ($expr->value['iter_var'] ?? '');
        $iterVar2 = (string) ($expr->value['iter_var2'] ?? '');
        $accuVar = (string) ($expr->value['accu_var'] ?? '');

        $loopLocals = [
            $iterVar => UnknownValue::attribute($iterVar),
            $accuVar => UnknownValue::attribute($accuVar),
        ];
        if ($iterVar2 !== '') {
            $loopLocals[$iterVar2] = UnknownValue::attribute($iterVar2);
        }
        $loopContext = $context->withMany($loopLocals);

        $resultContext = $context->with($accuVar, UnknownValue::attribute($accuVar));
        $vars = $iterVar2 === '' ? [$iterVar, $accuVar] : [$iterVar, $iterVar2, $accuVar];

        return [
            'expr' => '__comprehension__(' . implode(', ', [
                $this->build($expr->args[0], $context)['expr'],
                ...$vars,
                $this->build($expr->args[1], $context)['expr'],
                $this->build($expr->args[2], $loopContext)['expr'],
                $this->build($expr->args[3], $loopContext)['expr'],
                $this->build($expr->args[4], $resultContext)['expr'],
            ]) . ')',
            'value' => $value,
            'known' => false,
            'precedence' => 100,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function unknownPart(mixed $value, string $fallback, int $precedence): array
    {
        return [
            'expr' => $value instanceof UnknownValue ? $this->unknownExpression($value) : $fallback,
            'value' => $value,
            'known' => false,
            'precedence' => $precedence,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function selectPart(Expr $expr, EvaluationContext $context, mixed $value): array
    {
        $operator = $expr->kind === 'optional_select' ? '.?' : '.';
        $rendered = $this->build($expr->target, $context, 90)['expr'] . $operator . $expr->value;
        if ($value instanceof UnknownValue) {
            return [
                'expr' => $rendered,
                'value' => $value,
                'known' => false,
                'precedence' => 100,
            ];
        }

        return [
            'expr' => $rendered,
            'value' => $value,
            'known' => false,
            'precedence' => 100,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function indexPart(Expr $expr, EvaluationContext $context, mixed $value): array
    {
        $target = $this->build($expr->target, $context, 90)['expr'];
        $index = $this->build($expr->args[0], $context)['expr'];
        $rendered = $target . ($expr->kind === 'optional_index' ? '[?' : '[') . $index . ']';
        if ($value instanceof UnknownValue) {
            return [
                'expr' => $rendered,
                'value' => $value,
                'known' => false,
                'precedence' => 100,
            ];
        }

        return [
            'expr' => $rendered,
            'value' => $value,
            'known' => false,
            'precedence' => 100,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function unaryPart(Expr $expr, EvaluationContext $context): array
    {
        $operand = $this->build($expr->args[0], $context, 80);

        return [
            'expr' => (string) $expr->value . $operand['expr'],
            'value' => null,
            'known' => false,
            'precedence' => 80,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function binaryPart(Expr $expr, EvaluationContext $context): array
    {
        $operator = (string) $expr->value;
        $precedence = $this->binaryPrecedence($operator);
        $left = $this->build($expr->args[0], $context, $precedence);
        $right = $this->build($expr->args[1], $context, $precedence + 1);

        if ($operator === '&&') {
            if ($this->isKnownBool($left, true)) {
                return $right;
            }
            if ($this->isKnownBool($right, true)) {
                return $left;
            }
        }

        if ($operator === '||') {
            if ($this->isKnownBool($left, false)) {
                return $right;
            }
            if ($this->isKnownBool($right, false)) {
                return $left;
            }
        }

        return [
            'expr' => $left['expr'] . ' ' . $operator . ' ' . $right['expr'],
            'value' => null,
            'known' => false,
            'precedence' => $precedence,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function conditionalPart(Expr $expr, EvaluationContext $context): array
    {
        $condition = $this->build($expr->args[0], $context, 10);
        if ($this->isKnownBool($condition, true)) {
            return $this->build($expr->args[1], $context);
        }
        if ($this->isKnownBool($condition, false)) {
            return $this->build($expr->args[2], $context);
        }

        $then = $this->build($expr->args[1], $context);
        $else = $this->build($expr->args[2], $context);

        return [
            'expr' => $condition['expr'] . ' ? ' . $then['expr'] . ' : ' . $else['expr'],
            'value' => null,
            'known' => false,
            'precedence' => 5,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function callPart(Expr $expr, EvaluationContext $context): array
    {
        $args = array_map(fn (Expr $arg): string => $this->build($arg, $context)['expr'], $expr->args);
        if ($expr->target !== null) {
            $target = $this->build($expr->target, $context, 90)['expr'];

            return [
                'expr' => $target . '.' . $expr->value . '(' . implode(', ', $args) . ')',
                'value' => null,
                'known' => false,
                'precedence' => 100,
            ];
        }

        return [
            'expr' => $expr->value . '(' . implode(', ', $args) . ')',
            'value' => null,
            'known' => false,
            'precedence' => 100,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function listPart(Expr $expr, EvaluationContext $context): array
    {
        $elements = [];
        foreach ($expr->args as $arg) {
            if ($arg->kind === 'optional_element') {
                $elements[] = '?' . $this->build($arg->args[0], $context)['expr'];
                continue;
            }

            $elements[] = $this->build($arg, $context)['expr'];
        }

        return [
            'expr' => '[' . implode(', ', $elements) . ']',
            'value' => null,
            'known' => false,
            'precedence' => 100,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function mapPart(Expr $expr, EvaluationContext $context): array
    {
        $entries = [];
        foreach ($expr->entries as $entry) {
            $prefix = ($entry['optional'] ?? false) ? '?' : '';
            $entries[] = $prefix . $this->build($entry['key'], $context)['expr'] . ': ' . $this->build($entry['value'], $context)['expr'];
        }

        return [
            'expr' => '{' . implode(', ', $entries) . '}',
            'value' => null,
            'known' => false,
            'precedence' => 100,
        ];
    }

    /** @return array{expr:string,value:mixed,known:bool,precedence:int} */
    private function structPart(Expr $expr, EvaluationContext $context): array
    {
        $fields = [];
        foreach ($expr->entries as $field => $value) {
            if (is_array($value) && array_key_exists('field', $value)) {
                $prefix = ($value['optional'] ?? false) ? '?' : '';
                $fields[] = $prefix . $value['field'] . ': ' . $this->build($value['value'], $context)['expr'];
                continue;
            }
            $fields[] = $field . ': ' . $this->build($value, $context)['expr'];
        }

        return [
            'expr' => $expr->value . '{' . implode(', ', $fields) . '}',
            'value' => null,
            'known' => false,
            'precedence' => 100,
        ];
    }

    /** @param array{value:mixed,known:bool} $part */
    private function isKnownBool(array $part, bool $value): bool
    {
        return $part['known'] && $part['value'] === $value;
    }

    private function binaryPrecedence(string $operator): int
    {
        return match ($operator) {
            '||' => 20,
            '&&' => 30,
            '==', '!=', '<', '<=', '>', '>=', 'in' => 40,
            '+', '-' => 50,
            '*', '/', '%' => 60,
            default => 10,
        };
    }

    private function unknownExpression(UnknownValue $unknown): string
    {
        return implode(' || ', $unknown->attributes());
    }

    private function unknownFromValue(mixed $value): ?UnknownValue
    {
        if ($value instanceof UnknownValue) {
            return $value;
        }

        if ($value instanceof OptionalValue && $value->hasValue()) {
            return $this->unknownFromValue($value->value());
        }

        if (is_array($value)) {
            $unknowns = [];
            foreach ($value as $item) {
                $unknown = $this->unknownFromValue($item);
                if ($unknown !== null) {
                    $unknowns[] = $unknown;
                }
            }

            return $unknowns === [] ? null : UnknownValue::merge($unknowns);
        }

        return null;
    }

    private function formatValue(mixed $value): string
    {
        $value = $this->normalizeValue($value);

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
            return $value->value() . 'u';
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'double("NaN")';
            }
            if (is_infinite($value)) {
                return $value < 0.0 ? 'double("-Infinity")' : 'double("Infinity")';
            }

            return (string) $value;
        }
        if (is_string($value)) {
            return $this->quote($value);
        }
        if ($value instanceof Bytes) {
            return 'b' . $this->quote($value->raw());
        }
        if ($value instanceof TimestampValue) {
            return 'timestamp(' . $this->quote((string) $value) . ')';
        }
        if ($value instanceof DurationValue) {
            return 'duration(' . $this->quote((string) $value) . ')';
        }
        if ($value instanceof Type) {
            return (string) $value;
        }
        if ($value instanceof EnumValue) {
            return (string) $value->value();
        }
        if ($value instanceof OptionalValue) {
            return $value->hasValue()
                ? 'optional.of(' . $this->formatValue($value->value()) . ')'
                : 'optional.none()';
        }
        if ($value instanceof ProtoWrapperValue) {
            return $this->formatProtoWrapper($value);
        }
        if ($value instanceof RepeatedField) {
            return $this->formatRepeatedField($value);
        }
        if ($value instanceof MapField) {
            return $this->formatMapField($value);
        }
        if ($value instanceof Message) {
            return $this->formatMessage($value);
        }
        if ($value instanceof UnknownValue) {
            return $this->unknownExpression($value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(', ', array_map($this->formatValue(...), $value)) . ']';
            }

            $entries = [];
            foreach ($value as $key => $item) {
                $entries[] = $this->quote((string) $key) . ': ' . $this->formatValue($item);
            }

            return '{' . implode(', ', $entries) . '}';
        }

        return 'dyn(' . $this->quote((string) $value) . ')';
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof IntLiteral) {
            return $value->toInt();
        }

        return $value;
    }

    private function formatProtoWrapper(ProtoWrapperValue $value): string
    {
        $descriptor = $this->descriptorForClass($value->className());
        $typeName = $descriptor?->getFullName() ?? $value->className();

        return $typeName . '{value: ' . $this->formatValue($value->wrapped()) . '}';
    }

    private function formatMessage(Message $message): string
    {
        $normalized = $this->protoAdapter->normalize($message);
        if (!$normalized instanceof Message) {
            return $this->formatValue($normalized);
        }

        $descriptor = $this->descriptorForClass($message::class);
        if ($descriptor === null) {
            return 'dyn(' . $this->quote($message->serializeToJsonString()) . ')';
        }

        $fields = [];
        foreach ($descriptor->getField() as $field) {
            $getter = 'get' . $this->methodSuffix($field->getName());
            if (!method_exists($message, $getter)) {
                continue;
            }

            $fieldValue = $message->{$getter}();
            if (!$this->shouldRenderField($message, $field, $fieldValue)) {
                continue;
            }

            $fields[] = $field->getName() . ': ' . $this->formatProtoFieldValue($fieldValue, $field);
        }

        return $descriptor->getFullName() . '{' . implode(', ', $fields) . '}';
    }

    private function shouldRenderField(Message $message, mixed $field, mixed $value): bool
    {
        $presence = 'has' . $this->methodSuffix($field->getName());
        if (method_exists($message, $presence)) {
            return (bool) $message->{$presence}();
        }

        if ($value instanceof RepeatedField || $value instanceof MapField) {
            return count($value) > 0;
        }

        return !$this->isProtoDefaultValue($value);
    }

    private function formatProtoFieldValue(mixed $value, mixed $field): string
    {
        if ($value instanceof RepeatedField) {
            return $this->formatRepeatedField($value);
        }

        if ($value instanceof MapField) {
            return $this->formatMapField($value);
        }

        return $this->formatScalarForProtoType($value, $field->getType());
    }

    private function formatRepeatedField(RepeatedField $field): string
    {
        $values = [];
        foreach ($field as $item) {
            $values[] = $this->formatScalarForProtoType($item, $field->getType());
        }

        return '[' . implode(', ', $values) . ']';
    }

    private function formatMapField(MapField $field): string
    {
        $entries = [];
        foreach ($field as $key => $value) {
            $entries[] = $this->formatScalarForProtoType($key, $field->getKeyType())
                . ': '
                . $this->formatScalarForProtoType($value, $field->getValueType());
        }

        return '{' . implode(', ', $entries) . '}';
    }

    private function formatScalarForProtoType(mixed $value, ?int $type): string
    {
        if ($type === GPBType::BYTES && is_string($value)) {
            return 'b' . $this->quote($value);
        }

        if (in_array($type, [GPBType::UINT32, GPBType::UINT64, GPBType::FIXED32, GPBType::FIXED64], true)) {
            return UInt::from($value)->value() . 'u';
        }

        return $this->formatValue($value);
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

    private function methodSuffix(string $field): string
    {
        if (str_contains($field, '_')) {
            return str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
        }

        return ucfirst($field);
    }

    private function isProtoDefaultValue(mixed $value): bool
    {
        if ($value instanceof UInt) {
            return $value->value() === '0';
        }

        return $value === null || $value === false || $value === 0 || $value === 0.0 || $value === '';
    }

    private function quote(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
