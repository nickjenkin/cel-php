<?php

declare(strict_types=1);

namespace CEL\Proto;

use CEL\Bytes;
use CEL\DurationValue;
use CEL\EvaluationException;
use CEL\TimestampValue;
use CEL\UInt;
use Google\Protobuf\Any;
use Google\Protobuf\BoolValue;
use Google\Protobuf\BytesValue;
use Google\Protobuf\DoubleValue;
use Google\Protobuf\FieldMask;
use Google\Protobuf\Duration;
use Google\Protobuf\FloatValue;
use Google\Protobuf\GPBEmpty;
use Google\Protobuf\Internal\DescriptorPool;
use Google\Protobuf\Int32Value;
use Google\Protobuf\Int64Value;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\ListValue;
use Google\Protobuf\NullValue;
use Google\Protobuf\RepeatedField;
use Google\Protobuf\StringValue;
use Google\Protobuf\Struct;
use Google\Protobuf\Timestamp;
use Google\Protobuf\UInt32Value;
use Google\Protobuf\UInt64Value;
use Google\Protobuf\Value;

final class ProtoAdapter
{
    /** @var array<class-string, true> */
    private const WRAPPER_CLASSES = [
        BoolValue::class => true,
        BytesValue::class => true,
        DoubleValue::class => true,
        FloatValue::class => true,
        Int32Value::class => true,
        Int64Value::class => true,
        StringValue::class => true,
        UInt32Value::class => true,
        UInt64Value::class => true,
    ];

    public function __construct(
        private readonly ProtoRegistry $registry,
        private readonly bool $strongEnums = false,
    )
    {
    }

    /** @param array<string, mixed> $fields */
    public function construct(string $typeName, array $fields): mixed
    {
        $className = $this->registry->resolveMessage($typeName);
        if ($className === null) {
            throw new EvaluationException(sprintf('unknown proto3 message type "%s"', $typeName));
        }

        if (isset(self::WRAPPER_CLASSES[$className])) {
            /** @var class-string<Message> $className */
            return new ProtoWrapperValue($className, $this->wrapperLiteralValue($className, $fields['value'] ?? null));
        }

        if ($className === Struct::class) {
            return $this->constructStructLiteral($fields);
        }

        if ($className === ListValue::class) {
            return $this->constructListValueLiteral($fields);
        }

        if ($className === Value::class) {
            return $this->constructGoogleValueLiteral($fields);
        }

        if ($className === Any::class && $fields === []) {
            throw new EvaluationException('cannot construct empty google.protobuf.Any');
        }

        /** @var Message $message */
        $message = new $className();
        foreach ($fields as $field => $value) {
            $this->setField($message, $field, $value);
        }

        return $message;
    }

    public function isProtoValue(mixed $value): bool
    {
        return $value instanceof Message
            || $value instanceof ProtoWrapperValue
            || $value instanceof RepeatedField
            || $value instanceof MapField;
    }

    public function select(mixed $target, string $field): mixed
    {
        if ($target instanceof RepeatedField || $target instanceof MapField) {
            $target = $this->normalize($target);
        }

        if ($target instanceof ProtoWrapperValue) {
            throw new EvaluationException(sprintf('no such field "%s"', $field));
        }

        if ($target instanceof ListValue || $target instanceof Value || $target instanceof Any) {
            throw new EvaluationException(sprintf('no such field "%s"', $field));
        }

        if ($target instanceof Struct) {
            if ($field === 'fields') {
                throw new EvaluationException(sprintf('no such field "%s"', $field));
            }

            $fields = $target->getFields();
            if ($fields instanceof MapField && $fields->offsetExists($field)) {
                return $this->normalize($fields[$field]);
            }
        }

        if (!$target instanceof Message) {
            throw new EvaluationException(sprintf('no such field "%s"', $field));
        }

        $fieldDescriptor = $this->fieldDescriptor($target, $field);
        $suffix = $this->methodSuffix($field);
        $unwrappedGetter = 'get' . $suffix . 'Unwrapped';
        $presence = 'has' . $suffix;
        if (method_exists($target, $unwrappedGetter)) {
            if (method_exists($target, $presence) && !$target->{$presence}()) {
                return null;
            }

            return $this->normalizeFieldValue($field, $target->{$unwrappedGetter}());
        }

        $getter = 'get' . $suffix;
        if (method_exists($target, $getter)) {
            $value = $target->{$getter}();
            if ($value === null && method_exists($target, $presence) && !$target->{$presence}()) {
                return $this->defaultMessageField($target, $field);
            }

            return $this->normalizeFieldValue($field, $value, $fieldDescriptor);
        }

        throw new EvaluationException(sprintf('no such field "%s"', $field));
    }

    public function hasField(mixed $target, string $field): ?bool
    {
        if ($target instanceof RepeatedField || $target instanceof MapField) {
            $target = $this->normalize($target);
        }

        if (is_array($target)) {
            return array_key_exists($field, $target);
        }

        if ($target instanceof Struct && $field !== 'fields') {
            $fields = $target->getFields();
            return $fields instanceof MapField && $fields->offsetExists($field);
        }

        if (!$target instanceof Message) {
            return null;
        }

        $suffix = $this->methodSuffix($field);
        $presence = 'has' . $suffix;
        if (method_exists($target, $presence)) {
            return (bool) $target->{$presence}();
        }

        $getter = 'get' . $suffix;
        if (!method_exists($target, $getter)) {
            throw new EvaluationException(sprintf('no such field "%s"', $field));
        }

        $value = $target->{$getter}();
        if ($value instanceof RepeatedField || $value instanceof MapField) {
            return count($value) > 0;
        }

        return !$this->isProto3DefaultValue($value);
    }

    /** @return list<array{key:mixed,value:mixed}> */
    public function iterableItems(mixed $collection): ?array
    {
        if (!$collection instanceof RepeatedField && !$collection instanceof MapField) {
            return null;
        }

        $items = [];
        foreach ($collection as $key => $value) {
            $items[] = ['key' => $key, 'value' => $this->normalize($value)];
        }

        return $items;
    }

    public function normalize(mixed $value): mixed
    {
        if ($value instanceof RepeatedField) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->normalize($item);
            }

            return $out;
        }

        if ($value instanceof MapField) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$this->mapArrayKey($key)] = $this->normalize($item);
            }

            return $out;
        }

        if ($value instanceof ProtoWrapperValue) {
            return $this->normalize($value->wrapped());
        }

        if ($this->isWrapper($value)) {
            /** @var object{getValue: callable} $value */
            $wrapped = $value->getValue();
            if ($value instanceof BytesValue) {
                return new Bytes($wrapped);
            }
            if ($value instanceof UInt32Value || $value instanceof UInt64Value) {
                return UInt::from($wrapped);
            }

            return $wrapped;
        }

        if ($value instanceof Timestamp) {
            return $this->timestampToCel($value);
        }

        if ($value instanceof Duration) {
            return $this->durationToCel($value);
        }

        if ($value instanceof Struct) {
            return $this->structToArray($value);
        }

        if ($value instanceof ListValue) {
            return $this->listValueToArray($value);
        }

        if ($value instanceof Value) {
            return $this->googleValueToPhp($value);
        }

        if ($value instanceof Any) {
            try {
                return $this->normalize($value->unpack());
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }

    private function setField(Message $message, string $field, mixed $value): void
    {
        $suffix = $this->methodSuffix($field);
        $setter = 'set' . $suffix;
        if (!method_exists($message, $setter)) {
            throw new EvaluationException(sprintf('no such field "%s" on %s', $field, $message::class));
        }

        $unwrappedSetter = $setter . 'Unwrapped';
        if (method_exists($message, $unwrappedSetter) && $value !== null && !$value instanceof Message && !$value instanceof ProtoWrapperValue) {
            $prepared = $this->prepareUnwrappedSetterValue($message, $field, $value);
            try {
                $message->{$unwrappedSetter}($prepared);
            } catch (\TypeError|\UnexpectedValueException $exception) {
                throw new EvaluationException(
                    sprintf('invalid value for field "%s" on %s: %s', $field, $message::class, $exception->getMessage()),
                );
            }

            return;
        }

        $prepared = $this->prepareSetterValue($message, $field, $setter, $value);

        try {
            $message->{$setter}($prepared);
        } catch (\TypeError|\UnexpectedValueException $exception) {
            throw new EvaluationException(
                sprintf('invalid value for field "%s" on %s: %s', $field, $message::class, $exception->getMessage()),
            );
        }
    }

    private function prepareUnwrappedSetterValue(Message $message, string $field, mixed $value): mixed
    {
        $className = $this->messageSetterClass($message, $field);
        if ($className !== null && isset(self::WRAPPER_CLASSES[$className])) {
            return $this->toWrapperUnwrappedSetterValue($className, $this->wrapperLiteralValue($className, $value));
        }

        return $this->toScalarSetterValue($value);
    }

    private function prepareSetterValue(Message $message, string $field, string $setter, mixed $value): mixed
    {
        $fieldDescriptor = $this->fieldDescriptor($message, $field);
        $getter = 'get' . $this->methodSuffix($field);
        if (method_exists($message, $getter)) {
            $current = $message->{$getter}();
            if ($current instanceof RepeatedField) {
                return $this->prepareRepeatedValues($current, $value);
            }
            if ($current instanceof MapField) {
                return $this->prepareMapValues($current, $value);
            }
        }

        if ($fieldDescriptor !== null && $fieldDescriptor->getType() === GPBType::ENUM) {
            return $this->enumNumber($value);
        }

        $reflection = new \ReflectionMethod($message, $setter);
        $parameter = $reflection->getParameters()[0] ?? null;
        $type = $parameter?->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $className = $type->getName();
            if ($value === null && $type->allowsNull()) {
                if ($className === Value::class) {
                    return $this->googleValueMessage(null);
                }
                if (in_array($className, [Struct::class, ListValue::class], true)) {
                    throw new EvaluationException('unsupported field type');
                }

                return null;
            }

            if (is_a($className, Message::class, true)) {
                /** @var class-string<Message> $className */
                return $this->coerceMessage($className, $value);
            }
        }
        if ($type instanceof \ReflectionNamedType && $type->isBuiltin() && $type->getName() === 'int' && $value instanceof UInt) {
            return $value->toInt();
        }

        return $this->toScalarSetterValue($value);
    }

    /** @param array<string, mixed> $fields */
    private function constructStructLiteral(array $fields): array
    {
        $value = $fields['fields'] ?? [];
        if ($value === []) {
            return [];
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new EvaluationException('invalid google.protobuf.Struct literal');
        }

        return $this->requireStructMap($value);
    }

    /** @param array<string, mixed> $fields */
    private function constructListValueLiteral(array $fields): array
    {
        $value = $fields['values'] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new EvaluationException('invalid google.protobuf.ListValue literal');
        }

        return $value;
    }

    /** @param array<string, mixed> $fields */
    private function constructGoogleValueLiteral(array $fields): mixed
    {
        if ($fields === [] || array_key_exists('null_value', $fields)) {
            return null;
        }
        if (array_key_exists('number_value', $fields)) {
            return $this->requireDoubleWrapperValue($fields['number_value']);
        }
        if (array_key_exists('string_value', $fields)) {
            return $this->requireStringWrapperValue($fields['string_value']);
        }
        if (array_key_exists('bool_value', $fields)) {
            return $this->requireBoolWrapperValue($fields['bool_value']);
        }
        if (array_key_exists('struct_value', $fields)) {
            $value = $fields['struct_value'];
            if ($value === []) {
                return [];
            }
            if (!is_array($value) || array_is_list($value)) {
                throw new EvaluationException('invalid google.protobuf.Value struct literal');
            }

            return $this->requireStructMap($value);
        }
        if (array_key_exists('list_value', $fields)) {
            $value = $fields['list_value'];
            if (!is_array($value) || !array_is_list($value)) {
                throw new EvaluationException('invalid google.protobuf.Value list literal');
            }

            return $value;
        }

        throw new EvaluationException('invalid google.protobuf.Value literal');
    }

    private function prepareRepeatedValues(RepeatedField $field, mixed $value): array
    {
        if (!is_array($value)) {
            throw new EvaluationException('repeated proto field expects a list value');
        }

        $className = $field->getClass();
        if (!is_string($className) || $className === '' || !is_a($className, Message::class, true)) {
            return array_map($this->toScalarSetterValue(...), $value);
        }

        $out = [];
        foreach ($value as $item) {
            if ($item === null && $this->shouldPruneNullMessageValue($className)) {
                continue;
            }
            $out[] = $this->coerceMessage($className, $item);
        }

        return $out;
    }

    private function prepareMapValues(MapField $field, mixed $value): array
    {
        if (!is_array($value)) {
            throw new EvaluationException('map proto field expects a map value');
        }

        $out = [];
        $valueClass = $field->getValueClass();
        foreach ($value as $key => $item) {
            if ($item === null && is_string($valueClass) && $valueClass !== '' && $this->shouldPruneNullMessageValue($valueClass)) {
                continue;
            }
            $preparedKey = $this->prepareMapKey($field->getKeyType(), $key);
            $out[$preparedKey] = is_string($valueClass) && $valueClass !== '' && is_a($valueClass, Message::class, true)
                ? $this->coerceMessage($valueClass, $item)
                : $this->toScalarSetterValue($item);
        }

        return $out;
    }

    private function prepareMapKey(int $type, int|string $key): int|string|bool
    {
        return match ($type) {
            GPBType::BOOL => $key === true || $key === 1 || $key === '1' || $key === 'true',
            GPBType::INT32, GPBType::INT64, GPBType::UINT32, GPBType::UINT64 => (int) $key,
            default => $key,
        };
    }

    /** @param class-string<Message> $className */
    private function coerceMessage(string $className, mixed $value): Message
    {
        if ($value instanceof $className) {
            return $value;
        }

        if ($value instanceof ProtoWrapperValue && $value->className() === $className) {
            return $this->wrapperMessage($className, $value->wrapped());
        }

        if (isset(self::WRAPPER_CLASSES[$className])) {
            return $this->wrapperMessage($className, $value);
        }

        if ($className === Timestamp::class) {
            return $this->timestampMessage($value);
        }

        if ($className === Duration::class) {
            return $this->durationMessage($value);
        }

        if ($className === Any::class) {
            $any = new Any();
            if ($value instanceof ProtoWrapperValue) {
                $any->pack($this->wrapperMessage($value->className(), $value->wrapped()));
                return $any;
            }
            if ($value instanceof Message) {
                $any->pack($value);
                return $any;
            }
            if (is_array($value)) {
                $any->pack(array_is_list($value) ? $this->listValueMessage($value) : $this->structMessage($value));
                return $any;
            }

            $any->pack($this->googleValueMessage($value));
            return $any;
        }

        if ($className === Struct::class && is_array($value)) {
            return $this->structMessage($value);
        }

        if ($className === ListValue::class && is_array($value)) {
            return $this->listValueMessage($value);
        }

        if ($className === Value::class) {
            return $this->googleValueMessage($value);
        }

        throw new EvaluationException(sprintf('cannot coerce CEL value into %s', $className));
    }

    /** @param class-string<Message> $className */
    private function wrapperLiteralValue(string $className, mixed $value): mixed
    {
        if ($value instanceof ProtoWrapperValue) {
            $value = $value->wrapped();
        }

        return match ($className) {
            BoolValue::class => $value === null ? false : $this->requireBoolWrapperValue($value),
            BytesValue::class => $value === null ? new Bytes('') : $this->requireBytesWrapperValue($value),
            DoubleValue::class => $value === null ? 0.0 : $this->requireDoubleWrapperValue($value),
            FloatValue::class => $value === null ? 0.0 : $this->requireFloatWrapperValue($value),
            Int32Value::class => $this->requireIntRangeWrapperValue($value ?? 0, '-2147483648', '2147483647'),
            Int64Value::class => $this->requireIntRangeWrapperValue($value ?? 0, '-9223372036854775808', '9223372036854775807'),
            StringValue::class => $value === null ? '' : $this->requireStringWrapperValue($value),
            UInt32Value::class => UInt::from($this->requireUIntRangeWrapperValue($value ?? 0, '4294967295')),
            UInt64Value::class => UInt::from($value ?? 0),
            default => throw new EvaluationException(sprintf('unsupported wrapper type "%s"', $className)),
        };
    }

    private function requireBoolWrapperValue(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new EvaluationException('invalid wrapper value: expected bool');
        }

        return $value;
    }

    private function requireBytesWrapperValue(mixed $value): Bytes
    {
        if ($value instanceof Bytes) {
            return $value;
        }
        if (is_string($value)) {
            return new Bytes($value);
        }

        throw new EvaluationException('invalid wrapper value: expected bytes');
    }

    private function requireDoubleWrapperValue(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new EvaluationException('invalid wrapper value: expected double');
        }

        return (float) $value;
    }

    private function requireFloatWrapperValue(mixed $value): float
    {
        return $this->toFloat32($this->requireDoubleWrapperValue($value));
    }

    private function requireStringWrapperValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new EvaluationException('invalid wrapper value: expected string');
        }

        return $value;
    }

    private function requireIntRangeWrapperValue(mixed $value, string $min, string $max): int
    {
        if (!is_int($value)) {
            throw new EvaluationException('invalid wrapper value: expected int');
        }

        $string = (string) $value;
        if (bccomp($string, $min, 0) === -1 || bccomp($string, $max, 0) === 1) {
            throw new EvaluationException('invalid wrapper value: range');
        }

        return $value;
    }

    private function requireUIntRangeWrapperValue(mixed $value, string $max): string
    {
        if (!$value instanceof UInt && !is_int($value) && !is_string($value)) {
            throw new EvaluationException('invalid wrapper value: expected uint');
        }

        $uint = UInt::from($value);
        if (bccomp($uint->value(), $max, 0) === 1) {
            throw new EvaluationException('invalid wrapper value: range');
        }

        return $uint->value();
    }

    /** @param class-string<Message> $className */
    private function wrapperMessage(string $className, mixed $value): Message
    {
        $value = $this->wrapperLiteralValue($className, $value);

        /** @var Message $wrapper */
        $wrapper = new $className();
        if ($wrapper instanceof BytesValue && $value instanceof Bytes) {
            $wrapper->setValue($value->raw());
            return $wrapper;
        }
        if ($wrapper instanceof UInt32Value || $wrapper instanceof UInt64Value) {
            $wrapper->setValue($value instanceof UInt && $wrapper instanceof UInt32Value ? $value->toInt() : ($value instanceof UInt ? $value->value() : $value));
            return $wrapper;
        }
        $wrapper->setValue($this->toScalarSetterValue($value));

        return $wrapper;
    }

    /** @param class-string<Message> $className */
    private function toWrapperUnwrappedSetterValue(string $className, mixed $value): mixed
    {
        return match ($className) {
            BytesValue::class => $value instanceof Bytes ? $value->raw() : (string) $value,
            UInt32Value::class => UInt::from($value)->toInt(),
            UInt64Value::class => UInt::from($value)->value(),
            default => $this->toScalarSetterValue($value),
        };
    }

    private function toFloat32(float $value): float
    {
        /** @var array{value: float} $unpacked */
        $unpacked = unpack('Gvalue', pack('G', $value));

        return $unpacked['value'];
    }

    private function timestampMessage(mixed $value): Timestamp
    {
        if ($value instanceof Timestamp) {
            return $value;
        }
        if ($value instanceof TimestampValue) {
            $seconds = (int) $value->value()->format('U');
            $nanos = $value->nanos();

            return (new Timestamp())->setSeconds($seconds)->setNanos($nanos);
        }
        if (is_string($value)) {
            return $this->timestampMessage(new TimestampValue(new \DateTimeImmutable($value)));
        }

        throw new EvaluationException('cannot coerce value into google.protobuf.Timestamp');
    }

    private function durationMessage(mixed $value): Duration
    {
        if ($value instanceof Duration) {
            return $value;
        }
        if ($value instanceof DurationValue) {
            return (new Duration())->setSeconds($value->wholeSeconds())->setNanos($value->nanos());
        }
        if (is_string($value)) {
            $number = rtrim($value, 's');
            if (is_numeric($number)) {
                return $this->durationMessage(new DurationValue((float) $number));
            }
        }

        throw new EvaluationException('cannot coerce value into google.protobuf.Duration');
    }

    private function structMessage(array $value): Struct
    {
        $fields = [];
        foreach ($this->requireStructMap($value) as $key => $item) {
            $fields[$key] = $this->googleValueMessage($item);
        }

        return (new Struct())->setFields($fields);
    }

    /** @param array<array-key, mixed> $value */
    private function requireStructMap(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || str_starts_with($key, 'n:')) {
                throw new EvaluationException('bad key type');
            }

            $out[$key] = $item;
        }

        return $out;
    }

    private function listValueMessage(array $value): ListValue
    {
        return (new ListValue())->setValues(array_map($this->googleValueMessage(...), array_values($value)));
    }

    private function googleValueMessage(mixed $value): Value
    {
        if ($value instanceof Value) {
            return $value;
        }
        if ($value instanceof ProtoWrapperValue) {
            return $this->wrapperJsonValue($value);
        }
        if ($value instanceof Message) {
            if ($value instanceof BytesValue) {
                return (new Value())->setStringValue(base64_encode($value->getValue()));
            }
            if ($value instanceof UInt64Value) {
                $uint = UInt::from($value->getValue());

                return $this->uintJsonValue($uint);
            }
            if ($value instanceof UInt32Value) {
                return (new Value())->setNumberValue((float) UInt::from($value->getValue())->value());
            }
            if ($value instanceof Int64Value) {
                return $this->int64JsonValue((string) $value->getValue());
            }
            if ($value instanceof Timestamp) {
                return (new Value())->setStringValue((string) $this->timestampToCel($value));
            }
            if ($value instanceof Duration) {
                return (new Value())->setStringValue((string) $this->durationToCel($value));
            }
            if ($value instanceof FieldMask) {
                $paths = [];
                foreach ($value->getPaths() ?? [] as $path) {
                    $paths[] = (string) $path;
                }

                return (new Value())->setStringValue(implode(',', $paths));
            }
            if ($value instanceof GPBEmpty) {
                return (new Value())->setStructValue(new Struct());
            }

            $value = $this->normalize($value);
        }
        if ($value instanceof TimestampValue || $value instanceof DurationValue) {
            return (new Value())->setStringValue((string) $value);
        }
        if ($value === null) {
            return (new Value())->setNullValue(NullValue::NULL_VALUE);
        }
        if (is_bool($value)) {
            return (new Value())->setBoolValue($value);
        }
        if (is_int($value) || is_float($value)) {
            return (new Value())->setNumberValue((float) $value);
        }
        if (is_string($value)) {
            return (new Value())->setStringValue($value);
        }
        if (is_array($value)) {
            return array_is_list($value)
                ? (new Value())->setListValue($this->listValueMessage($value))
                : (new Value())->setStructValue($this->structMessage($value));
        }
        if ($value instanceof UInt) {
            return $this->uintJsonValue($value);
        }

        throw new EvaluationException('cannot coerce value into google.protobuf.Value');
    }

    private function wrapperJsonValue(ProtoWrapperValue $value): Value
    {
        $wrapped = $value->wrapped();

        return match ($value->className()) {
            BoolValue::class => (new Value())->setBoolValue((bool) $wrapped),
            BytesValue::class => (new Value())->setStringValue(base64_encode($wrapped instanceof Bytes ? $wrapped->raw() : (string) $wrapped)),
            DoubleValue::class, FloatValue::class => (new Value())->setNumberValue((float) $wrapped),
            Int32Value::class => (new Value())->setNumberValue((float) $wrapped),
            Int64Value::class => $this->int64JsonValue((string) $wrapped),
            StringValue::class => (new Value())->setStringValue((string) $wrapped),
            UInt32Value::class => (new Value())->setNumberValue((float) UInt::from($wrapped)->value()),
            UInt64Value::class => $this->uintJsonValue(UInt::from($wrapped)),
            default => throw new EvaluationException('cannot coerce wrapper into google.protobuf.Value'),
        };
    }

    private function uintJsonValue(UInt $value): Value
    {
        if (bccomp($value->value(), '9007199254740991', 0) === 1) {
            return (new Value())->setStringValue($value->value());
        }

        return (new Value())->setNumberValue((float) $value->value());
    }

    private function int64JsonValue(string $value): Value
    {
        if (bccomp($value, '9007199254740991', 0) === 1 || bccomp($value, '-9007199254740991', 0) === -1) {
            return (new Value())->setStringValue($value);
        }

        return (new Value())->setNumberValue((float) $value);
    }

    private function googleValueToPhp(Value $value): mixed
    {
        if ($value->hasNullValue()) {
            return null;
        }
        if ($value->hasBoolValue()) {
            return $value->getBoolValue();
        }
        if ($value->hasNumberValue()) {
            return $value->getNumberValue();
        }
        if ($value->hasStringValue()) {
            return $value->getStringValue();
        }
        if ($value->hasStructValue()) {
            return $this->structToArray($value->getStructValue());
        }
        if ($value->hasListValue()) {
            return $this->listValueToArray($value->getListValue());
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function structToArray(Struct $struct): array
    {
        $out = [];
        foreach ($struct->getFields() ?? [] as $key => $value) {
            $out[(string) $key] = $this->normalize($value);
        }

        return $out;
    }

    /** @return list<mixed> */
    private function listValueToArray(ListValue $list): array
    {
        $out = [];
        foreach ($list->getValues() ?? [] as $value) {
            $out[] = $this->normalize($value);
        }

        return $out;
    }

    private function timestampToCel(Timestamp $timestamp): TimestampValue
    {
        $seconds = (int) $timestamp->getSeconds();
        $micros = intdiv($timestamp->getNanos(), 1000);
        $date = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros), new \DateTimeZone('UTC'));

        return new TimestampValue($date ?: new \DateTimeImmutable('@' . $seconds), $timestamp->getNanos());
    }

    private function durationToCel(Duration $duration): DurationValue
    {
        return DurationValue::fromParts((int) $duration->getSeconds(), $duration->getNanos());
    }

    private function normalizeFieldValue(string $field, mixed $value, mixed $fieldDescriptor = null): mixed
    {
        if ($value instanceof RepeatedField) {
            $out = [];
            $enumType = is_string($value->getClass()) ? $this->registry->enumTypeForClass($value->getClass()) : null;
            foreach ($value as $item) {
                $out[] = $this->normalizeProtoScalar($value->getType(), $item, $field, $enumType);
            }

            return $out;
        }

        if ($value instanceof MapField) {
            $out = [];
            $enumType = is_string($value->getValueClass()) ? $this->registry->enumTypeForClass($value->getValueClass()) : null;
            foreach ($value as $key => $item) {
                $normalizedKey = $this->normalizeProtoScalar($value->getKeyType(), $key, $field);
                $out[$this->mapArrayKey($normalizedKey)] = $this->normalizeProtoScalar($value->getValueType(), $item, $field, $enumType);
            }

            return $out;
        }

        return $this->normalizeProtoScalar($fieldDescriptor?->getType(), $value, $field, $this->enumTypeForField($fieldDescriptor));
    }

    private function normalizeProtoScalar(?int $type, mixed $value, string $field, ?string $enumType = null): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->strongEnums && $type === GPBType::ENUM && $enumType !== null) {
            return new EnumValue($enumType, (int) $value);
        }

        if ($type === GPBType::BYTES || ($type === null && $this->isBytesField($field) && is_string($value))) {
            return new Bytes($value);
        }

        if (
            in_array($type, [GPBType::UINT32, GPBType::UINT64, GPBType::FIXED32, GPBType::FIXED64], true)
            || ($type === null && $this->isUnsignedField($field) && (is_int($value) || is_string($value)))
        ) {
            return UInt::from($value);
        }

        return $this->normalize($value);
    }

    private function fieldDescriptor(Message $message, string $field): mixed
    {
        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($message::class);
        if ($descriptor === null) {
            return null;
        }

        return $descriptor->getFieldByName($field) ?? $descriptor->getFieldByJsonName($field);
    }

    private function enumTypeForField(mixed $fieldDescriptor): ?string
    {
        if ($fieldDescriptor === null || $fieldDescriptor->getType() !== GPBType::ENUM) {
            return null;
        }

        $enumDescriptor = $fieldDescriptor->getEnumType();
        $className = $enumDescriptor?->getClass();
        if (is_string($className)) {
            return $this->registry->enumTypeForClass($className) ?? $enumDescriptor->getFullName();
        }

        return $enumDescriptor?->getFullName();
    }

    private function enumNumber(mixed $value): int
    {
        if ($value instanceof EnumValue) {
            $value = $value->value();
        }

        if (!is_int($value)) {
            throw new EvaluationException('invalid enum value');
        }
        if ($value < -2147483648 || $value > 2147483647) {
            throw new EvaluationException('enum range error');
        }

        return $value;
    }

    private function defaultMessageField(Message $message, string $field): mixed
    {
        $className = $this->messageSetterClass($message, $field);
        if ($className === null || !$this->shouldDefaultMessageSelect($className)) {
            return null;
        }

        return $this->normalize(new $className());
    }

    /** @return class-string<Message>|null */
    private function messageSetterClass(Message $message, string $field): ?string
    {
        $setter = 'set' . $this->methodSuffix($field);
        if (!method_exists($message, $setter)) {
            return null;
        }

        $reflection = new \ReflectionMethod($message, $setter);
        $parameter = $reflection->getParameters()[0] ?? null;
        $type = $parameter?->getType();
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : ($type !== null ? [$type] : []);
        foreach ($types as $namedType) {
            if (!$namedType instanceof \ReflectionNamedType || $namedType->isBuiltin()) {
                continue;
            }

            $className = $namedType->getName();
            if (is_a($className, Message::class, true)) {
                /** @var class-string<Message> $className */
                return $className;
            }
        }

        return null;
    }

    /** @param class-string<Message> $className */
    private function shouldDefaultMessageSelect(string $className): bool
    {
        return !isset(self::WRAPPER_CLASSES[$className])
            && !in_array($className, [Any::class, Struct::class, Value::class, ListValue::class, FieldMask::class, GPBEmpty::class], true);
    }

    /** @param class-string<Message> $className */
    private function shouldPruneNullMessageValue(string $className): bool
    {
        return !in_array($className, [Any::class, Value::class], true);
    }

    private function isProto3DefaultValue(mixed $value): bool
    {
        if ($value instanceof UInt) {
            return $value->value() === '0';
        }

        return $value === null || $value === false || $value === 0 || $value === 0.0 || $value === '';
    }

    private function isUnsignedField(string $field): bool
    {
        foreach (explode('_', $field) as $part) {
            if (str_starts_with($part, 'uint') || $part === 'fixed32' || $part === 'fixed64') {
                return true;
            }
        }

        return false;
    }

    private function isBytesField(string $field): bool
    {
        foreach (explode('_', $field) as $part) {
            if ($part === 'bytes') {
                return true;
            }
        }

        return false;
    }

    private function isWrapper(mixed $value): bool
    {
        return is_object($value) && isset(self::WRAPPER_CLASSES[$value::class]);
    }

    private function methodSuffix(string $field): string
    {
        if (str_contains($field, '_')) {
            return str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
        }

        return ucfirst($field);
    }

    private function toScalarSetterValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof EnumValue => $value->value(),
            $value instanceof Bytes => $value->raw(),
            $value instanceof UInt => $value->value(),
            $value === null => NullValue::NULL_VALUE,
            default => $value,
        };
    }

    private function mapArrayKey(mixed $key): int|string
    {
        if (is_bool($key)) {
            return $key ? 'true' : 'false';
        }

        return is_int($key) ? $key : (string) $key;
    }
}
