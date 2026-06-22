<?php

declare(strict_types=1);

namespace CEL\Tests\Conformance;

use CEL\Bytes;
use CEL\Environment;
use CEL\FunctionDeclaration;
use CEL\Overload;
use CEL\Proto\EnumValue;
use CEL\Proto\ProtoAdapter;
use CEL\Proto\ProtoRegistry;
use CEL\Tests\Support\Proto3ConformanceRegistry;
use CEL\Type;
use CEL\UInt;
use Google\Protobuf\Internal\Message;

final class ConformanceHarness
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const SKIP_PROTO2 = 'skip_proto2';
    public const SKIP_UNSUPPORTED_EXTENSION = 'skip_unsupported_extension';

    /** @var array<class-string<Message>, true> */
    private const WRAPPER_CLASSES = [
        \Google\Protobuf\BoolValue::class => true,
        \Google\Protobuf\BytesValue::class => true,
        \Google\Protobuf\DoubleValue::class => true,
        \Google\Protobuf\FloatValue::class => true,
        \Google\Protobuf\Int32Value::class => true,
        \Google\Protobuf\Int64Value::class => true,
        \Google\Protobuf\StringValue::class => true,
        \Google\Protobuf\UInt32Value::class => true,
        \Google\Protobuf\UInt64Value::class => true,
    ];

    private readonly ProtoRegistry $protoRegistry;
    private readonly ProtoAdapter $protoAdapter;

    public function __construct()
    {
        $this->protoRegistry = Proto3ConformanceRegistry::create();
        $this->protoAdapter = new ProtoAdapter($this->protoRegistry);
    }

    /** @param array<string, mixed> $test */
    public function classify(string $fixture, string $section, array $test): ConformanceResult
    {
        $skipReason = $this->skipReason($fixture, $section, $test);
        if ($skipReason !== null) {
            return new ConformanceResult($skipReason[0], $skipReason[1]);
        }

        try {
            $runtime = $this->environmentForTest($fixture, $section, $test);
            $ast = !empty($test['disableCheck'])
                ? $runtime->parse((string) $test['expr'])
                : $runtime->compile((string) $test['expr']);

            if (isset($test['evalError']) || isset($test['parseError']) || isset($test['checkError'])) {
                $program = $runtime->program($ast);
                $program->eval($this->bindingsFor($test['bindings'] ?? []));

                return new ConformanceResult(self::FAIL, 'expected fixture error, expression evaluated successfully');
            }

            if (isset($test['typedResult'])) {
                return $this->classifyTypedResult($runtime, $ast, $test);
            }

            $program = $runtime->program($ast);
            $actual = $program->eval($this->bindingsFor($test['bindings'] ?? []));

            if (!isset($test['value'])) {
                return $actual === true
                    ? new ConformanceResult(self::PASS)
                    : new ConformanceResult(self::FAIL, sprintf('expected true, got %s', $this->debugValue($actual)));
            }

            $expected = $this->decodeValue($test['value']);

            return $this->valuesEqual($expected, $actual)
                ? new ConformanceResult(self::PASS)
                : new ConformanceResult(self::FAIL, sprintf('expected %s, got %s', $this->debugValue($expected), $this->debugValue($actual)));
        } catch (\Throwable $exception) {
            if (isset($test['evalError']) || isset($test['parseError']) || isset($test['checkError'])) {
                return new ConformanceResult(self::PASS);
            }

            return new ConformanceResult(self::FAIL, $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $test */
    public function environmentForTest(string $fixture, string $section, array $test): Environment
    {
        $env = Environment::builder()->protoRegistry($this->protoRegistry);
        if (isset($test['container'])) {
            $env->container((string) $test['container']);
        }
        if ($fixture === 'enums' && str_starts_with($section, 'strong_')) {
            $env->enableStrongEnums();
        }
        foreach ($test['typeEnv'] ?? [] as $decl) {
            if (!isset($decl['name'])) {
                continue;
            }
            if (isset($decl['ident']['type']) && is_array($decl['ident']['type'])) {
                $env->variable((string) $decl['name'], $this->decodeConformanceType($decl['ident']['type']));
            }
            if (isset($decl['function']['overloads']) && is_array($decl['function']['overloads'])) {
                $env->function($this->decodeFunctionDeclaration((string) $decl['name'], $decl['function']['overloads']));
            }
        }

        return $env->build();
    }

    /** @param array<string, mixed> $test */
    private function skipReason(string $fixture, string $section, array $test): ?array
    {
        $container = (string) ($test['container'] ?? '');
        $expr = (string) ($test['expr'] ?? '');
        $serialized = json_encode($test, JSON_THROW_ON_ERROR);

        if ($fixture === 'proto2' || $container === 'cel.expr.conformance.proto2' || str_contains($expr, 'cel.expr.conformance.proto2') || str_contains($serialized, 'cel.expr.conformance.proto2')) {
            return [self::SKIP_PROTO2, 'proto2 conformance is intentionally out of scope'];
        }

        if (str_contains($section, 'extension')) {
            return [self::SKIP_UNSUPPORTED_EXTENSION, sprintf('extension section "%s" is not implemented yet', $section)];
        }

        return null;
    }

    /** @param array<string, mixed> $bindings */
    public function bindingsFor(array $bindings): array
    {
        $out = [];
        foreach ($bindings as $name => $binding) {
            if (isset($binding['value'])) {
                $out[$name] = $this->decodeValue($binding['value']);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $value */
    public function decodeValue(array $value): mixed
    {
        if (array_key_exists('nullValue', $value)) {
            return null;
        }
        if (array_key_exists('boolValue', $value)) {
            return $value['boolValue'];
        }
        if (array_key_exists('int64Value', $value)) {
            return (int) $value['int64Value'];
        }
        if (array_key_exists('uint64Value', $value)) {
            return UInt::from($value['uint64Value']);
        }
        if (array_key_exists('doubleValue', $value)) {
            return $this->decodeDoubleValue($value['doubleValue']);
        }
        if (array_key_exists('stringValue', $value)) {
            return $value['stringValue'];
        }
        if (array_key_exists('bytesValue', $value)) {
            return new Bytes(base64_decode($value['bytesValue'], true) ?: '');
        }
        if (array_key_exists('enumValue', $value)) {
            $enum = $value['enumValue'];

            return new EnumValue((string) $enum['type'], (int) ($enum['value'] ?? 0));
        }
        if (array_key_exists('typeValue', $value)) {
            return $this->decodeTypeValue((string) $value['typeValue']);
        }
        if (array_key_exists('listValue', $value)) {
            return array_map(
                fn (array $item): mixed => $this->decodeValue($item),
                $value['listValue']['values'] ?? [],
            );
        }
        if (array_key_exists('mapValue', $value)) {
            $map = [];
            foreach ($value['mapValue']['entries'] ?? [] as $entry) {
                $key = $this->decodeValue($entry['key']);
                $map[is_bool($key) ? ($key ? 'true' : 'false') : (string) $key] = $this->decodeValue($entry['value']);
            }

            return $map;
        }
        if (array_key_exists('objectValue', $value)) {
            return $this->decodeObjectValue($value['objectValue']);
        }

        throw new \RuntimeException('Unsupported conformance value: ' . json_encode($value, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $test */
    private function classifyTypedResult(Environment $runtime, \CEL\Ast $ast, array $test): ConformanceResult
    {
        $checked = $ast instanceof \CEL\CheckedAst ? $ast : $runtime->check($ast);
        if (isset($test['typedResult']['deducedType'])) {
            $expectedType = $this->decodeConformanceType($test['typedResult']['deducedType']);
            if (!$expectedType->equals($checked->resultType())) {
                return new ConformanceResult(self::FAIL, sprintf('expected type %s, got %s', $expectedType, $checked->resultType()));
            }

            $expectedProtoType = $this->decodeConformanceProtoType($test['typedResult']['deducedType']);
            $actualProtoType = $this->rootCheckedProtoType($checked->toCheckedExpr());
            if ($actualProtoType === null || $expectedProtoType->serializeToJsonString() !== $actualProtoType->serializeToJsonString()) {
                return new ConformanceResult(
                    self::FAIL,
                    sprintf(
                        'expected checked proto type %s, got %s',
                        $expectedProtoType->serializeToJsonString(),
                        $actualProtoType?->serializeToJsonString() ?? '<missing>',
                    ),
                );
            }
        }

        if (!empty($test['checkOnly'])) {
            return new ConformanceResult(self::PASS);
        }

        if (!isset($test['typedResult']['result'])) {
            return new ConformanceResult(self::PASS);
        }

        $actual = $runtime->program($ast)->eval($this->bindingsFor($test['bindings'] ?? []));
        $expected = $this->decodeValue($test['typedResult']['result']);

        return $this->valuesEqual($expected, $actual)
            ? new ConformanceResult(self::PASS)
            : new ConformanceResult(self::FAIL, sprintf('expected %s, got %s', $this->debugValue($expected), $this->debugValue($actual)));
    }

    private function decodeTypeValue(string $type): Type
    {
        return match ($type) {
            'bool' => Type::bool(),
            'int' => Type::int(),
            'uint' => Type::uint(),
            'double' => Type::double(),
            'string' => Type::string(),
            'bytes' => Type::bytes(),
            'null_type' => Type::null(),
            'type' => Type::type(),
            'list' => Type::list(Type::dyn()),
            'map' => Type::map(Type::dyn(), Type::dyn()),
            default => Type::message($type),
        };
    }

    /** @param array<string, mixed> $type */
    private function decodeConformanceType(array $type): Type
    {
        if (array_key_exists('dyn', $type)) {
            return Type::dyn();
        }
        if (array_key_exists('null', $type)) {
            return Type::null();
        }
        if (isset($type['primitive'])) {
            return match ($type['primitive']) {
                'BOOL' => Type::bool(),
                'INT64' => Type::int(),
                'UINT64' => Type::uint(),
                'DOUBLE' => Type::double(),
                'STRING' => Type::string(),
                'BYTES' => Type::bytes(),
                default => Type::dyn(),
            };
        }
        if (isset($type['messageType'])) {
            return Type::message((string) $type['messageType']);
        }
        if (isset($type['wellKnown'])) {
            return match ($type['wellKnown']) {
                'TIMESTAMP' => Type::message('google.protobuf.Timestamp'),
                'DURATION' => Type::message('google.protobuf.Duration'),
                'ANY' => Type::message('google.protobuf.Any'),
                default => Type::dyn(),
            };
        }
        if (isset($type['listType']['elemType']) && is_array($type['listType']['elemType'])) {
            return Type::list($this->decodeConformanceType($type['listType']['elemType']));
        }
        if (isset($type['mapType']['keyType'], $type['mapType']['valueType']) && is_array($type['mapType']['keyType']) && is_array($type['mapType']['valueType'])) {
            return Type::map($this->decodeConformanceType($type['mapType']['keyType']), $this->decodeConformanceType($type['mapType']['valueType']));
        }
        if (isset($type['wrapper'])) {
            return Type::message('wrapper.' . strtolower((string) $type['wrapper']));
        }
        if (isset($type['abstractType']['name'])) {
            return Type::message((string) $type['abstractType']['name']);
        }

        return Type::dyn();
    }

    private function rootCheckedProtoType(\CEL\Generated\Expr\CheckedExpr $checked): ?\CEL\Generated\Expr\Type
    {
        $rootId = (int) $checked->getExpr()->getId();
        foreach ($checked->getTypeMap() as $id => $type) {
            if ((int) $id === $rootId) {
                return $type;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $type */
    private function decodeConformanceProtoType(array $type): \CEL\Generated\Expr\Type
    {
        if (array_key_exists('dyn', $type)) {
            return (new \CEL\Generated\Expr\Type())->setDyn(new \Google\Protobuf\GPBEmpty());
        }
        if (array_key_exists('null', $type)) {
            return (new \CEL\Generated\Expr\Type())->setNull(\Google\Protobuf\NullValue::NULL_VALUE);
        }
        if (isset($type['primitive'])) {
            return (new \CEL\Generated\Expr\Type())->setPrimitive($this->decodePrimitiveType((string) $type['primitive']));
        }
        if (isset($type['typeParam'])) {
            return (new \CEL\Generated\Expr\Type())->setTypeParam((string) $type['typeParam']);
        }
        if (isset($type['messageType'])) {
            return (new \CEL\Generated\Expr\Type())->setMessageType((string) $type['messageType']);
        }
        if (isset($type['wellKnown'])) {
            return (new \CEL\Generated\Expr\Type())->setWellKnown($this->decodeWellKnownType((string) $type['wellKnown']));
        }
        if (isset($type['listType']['elemType']) && is_array($type['listType']['elemType'])) {
            return (new \CEL\Generated\Expr\Type())->setListType(
                (new \CEL\Generated\Expr\Type\ListType())->setElemType($this->decodeConformanceProtoType($type['listType']['elemType'])),
            );
        }
        if (isset($type['mapType']['keyType'], $type['mapType']['valueType']) && is_array($type['mapType']['keyType']) && is_array($type['mapType']['valueType'])) {
            return (new \CEL\Generated\Expr\Type())->setMapType(
                (new \CEL\Generated\Expr\Type\MapType())
                    ->setKeyType($this->decodeConformanceProtoType($type['mapType']['keyType']))
                    ->setValueType($this->decodeConformanceProtoType($type['mapType']['valueType'])),
            );
        }
        if (isset($type['wrapper'])) {
            return (new \CEL\Generated\Expr\Type())->setWrapper($this->decodePrimitiveType((string) $type['wrapper']));
        }
        if (isset($type['abstractType']['name'])) {
            $abstract = (new \CEL\Generated\Expr\Type\AbstractType())->setName((string) $type['abstractType']['name']);
            $parameters = [];
            foreach ($type['abstractType']['parameterTypes'] ?? [] as $parameterType) {
                if (is_array($parameterType)) {
                    $parameters[] = $this->decodeConformanceProtoType($parameterType);
                }
            }
            $abstract->setParameterTypes($parameters);

            return (new \CEL\Generated\Expr\Type())->setAbstractType($abstract);
        }

        return (new \CEL\Generated\Expr\Type())->setDyn(new \Google\Protobuf\GPBEmpty());
    }

    private function decodePrimitiveType(string $primitive): int
    {
        return match ($primitive) {
            'BOOL' => \CEL\Generated\Expr\Type\PrimitiveType::BOOL,
            'INT64' => \CEL\Generated\Expr\Type\PrimitiveType::INT64,
            'UINT64' => \CEL\Generated\Expr\Type\PrimitiveType::UINT64,
            'DOUBLE' => \CEL\Generated\Expr\Type\PrimitiveType::DOUBLE,
            'STRING' => \CEL\Generated\Expr\Type\PrimitiveType::STRING,
            'BYTES' => \CEL\Generated\Expr\Type\PrimitiveType::BYTES,
            default => \CEL\Generated\Expr\Type\PrimitiveType::PRIMITIVE_TYPE_UNSPECIFIED,
        };
    }

    private function decodeWellKnownType(string $wellKnown): int
    {
        return match ($wellKnown) {
            'ANY' => \CEL\Generated\Expr\Type\WellKnownType::ANY,
            'TIMESTAMP' => \CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP,
            'DURATION' => \CEL\Generated\Expr\Type\WellKnownType::DURATION,
            default => \CEL\Generated\Expr\Type\WellKnownType::WELL_KNOWN_TYPE_UNSPECIFIED,
        };
    }

    /** @param list<array<string, mixed>> $overloads */
    private function decodeFunctionDeclaration(string $name, array $overloads): FunctionDeclaration
    {
        $decoded = [];
        foreach ($overloads as $overload) {
            $params = [];
            foreach ($overload['params'] ?? [] as $param) {
                if (is_array($param)) {
                    $params[] = $this->decodeConformanceType($param);
                }
            }
            $resultType = isset($overload['resultType']) && is_array($overload['resultType'])
                ? $this->decodeConformanceType($overload['resultType'])
                : Type::dyn();
            $decoded[] = new Overload(
                (string) ($overload['overloadId'] ?? $name),
                $params,
                $resultType,
                static fn (): mixed => null,
                false,
                array_values(array_filter(
                    array_map(
                        fn (mixed $param): ?\CEL\Generated\Expr\Type => is_array($param) ? $this->decodeConformanceProtoType($param) : null,
                        $overload['params'] ?? [],
                    ),
                )),
                isset($overload['resultType']) && is_array($overload['resultType'])
                    ? $this->decodeConformanceProtoType($overload['resultType'])
                    : null,
            );
        }

        return new FunctionDeclaration($name, $decoded);
    }

    private function decodeDoubleValue(mixed $value): float
    {
        return match ($value) {
            'Infinity' => INF,
            '-Infinity' => -INF,
            'NaN' => NAN,
            default => (float) $value,
        };
    }

    /** @param array<string, mixed> $object */
    private function decodeObjectValue(array $object): mixed
    {
        $type = (string) ($object['@type'] ?? '');
        $prefix = 'type.googleapis.com/';
        if (!str_starts_with($type, $prefix)) {
            return $object;
        }

        $protoName = substr($type, strlen($prefix));
        if ($protoName === 'google.protobuf.Duration' && isset($object['value']) && is_string($object['value'])) {
            return $this->durationObject($object['value']);
        }
        if ($protoName === 'google.protobuf.Timestamp' && isset($object['value']) && is_string($object['value'])) {
            return $this->timestampObject($object['value']);
        }

        $className = $this->protoRegistry->resolveMessage($protoName);
        if ($className === null) {
            return $object;
        }
        if (isset(self::WRAPPER_CLASSES[$className])) {
            return $this->decodeWrapperObjectValue($className, $object);
        }
        if (in_array($className, [\Google\Protobuf\Struct::class, \Google\Protobuf\ListValue::class, \Google\Protobuf\Value::class], true) && array_key_exists('value', $object)) {
            return $this->decodeJsonFormatMessage($className, $object['value']);
        }

        unset($object['@type']);

        /** @var Message $message */
        $message = new $className();
        $this->mergeJsonObjectValue($message, $object);

        return $message;
    }

    /** @param class-string<Message> $className */
    private function decodeWrapperObjectValue(string $className, array $object): Message
    {
        /** @var Message $message */
        $message = new $className();
        if (array_key_exists('value', $object)) {
            if (in_array($className, [\Google\Protobuf\FloatValue::class, \Google\Protobuf\DoubleValue::class], true) && is_string($object['value']) && $this->isSpecialDoubleString($object['value'])) {
                /** @var object{setValue: callable} $message */
                $message->setValue($this->decodeDoubleValue($object['value']));
            } else {
                $message->mergeFromJsonString(json_encode($object['value'], JSON_THROW_ON_ERROR), true);
            }
        }

        return $message;
    }

    /** @param class-string<Message> $className */
    private function decodeJsonFormatMessage(string $className, mixed $value): Message
    {
        /** @var Message $message */
        $message = new $className();
        $message->mergeFromJsonString(json_encode($value, JSON_THROW_ON_ERROR), true);

        return $message;
    }

    /** @param array<string, mixed> $object */
    private function mergeJsonObjectValue(Message $message, array $object): void
    {
        $specialFloats = [];
        $specialMessages = [];
        foreach ($object as $field => $value) {
            if (is_string($value) && $this->isSpecialDoubleString($value)) {
                $setter = 'set' . ucfirst($field) . 'Unwrapped';
                if (method_exists($message, $setter)) {
                    $specialFloats[$setter] = $value;
                    unset($object[$field]);
                    continue;
                }
            }

            $setter = 'set' . ucfirst($field);
            $className = $this->jsonFieldMessageClass($message, $setter);
            if ($className !== null && in_array($className, [\Google\Protobuf\Struct::class, \Google\Protobuf\ListValue::class, \Google\Protobuf\Value::class], true)) {
                $specialMessages[$setter] = $this->decodeJsonFormatMessage($className, $value);
                unset($object[$field]);
            }
        }

        $message->mergeFromJsonString(json_encode($object, JSON_THROW_ON_ERROR), true);
        foreach ($specialMessages as $setter => $value) {
            $message->{$setter}($value);
        }
        foreach ($specialFloats as $setter => $value) {
            $message->{$setter}($this->decodeDoubleValue($value));
        }
    }

    /** @return class-string<Message>|null */
    private function jsonFieldMessageClass(Message $message, string $setter): ?string
    {
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

    private function isSpecialDoubleString(string $value): bool
    {
        return in_array($value, ['Infinity', '-Infinity', 'NaN'], true);
    }

    private function durationObject(string $value): \Google\Protobuf\Duration
    {
        if (preg_match('/^(?<sign>-?)(?<whole>[0-9]+)(?:\.(?<frac>[0-9]{1,9}))?s$/', $value, $matches) !== 1) {
            throw new \RuntimeException(sprintf('Invalid duration JSON value "%s"', $value));
        }

        $sign = ($matches['sign'] ?? '') === '-' ? -1 : 1;
        $seconds = $sign * (int) $matches['whole'];
        $nanos = isset($matches['frac']) && $matches['frac'] !== ''
            ? $sign * (int) str_pad($matches['frac'], 9, '0')
            : 0;

        return (new \Google\Protobuf\Duration())->setSeconds($seconds)->setNanos($nanos);
    }

    private function timestampObject(string $value): \Google\Protobuf\Timestamp
    {
        $timestamp = \CEL\TimestampValue::parse($value);

        return (new \Google\Protobuf\Timestamp())
            ->setSeconds((int) $timestamp->value()->setTimezone(new \DateTimeZone('UTC'))->format('U'))
            ->setNanos($timestamp->nanos());
    }

    public function valuesEqual(mixed $expected, mixed $actual): bool
    {
        $expected = $this->protoAdapter->normalize($expected);
        $actual = $this->protoAdapter->normalize($actual);

        if ($expected instanceof UInt || $actual instanceof UInt) {
            return $expected instanceof UInt && $actual instanceof UInt && $expected->value() === $actual->value();
        }
        if ($expected instanceof Bytes || $actual instanceof Bytes) {
            return $expected instanceof Bytes && $actual instanceof Bytes && $expected->raw() === $actual->raw();
        }
        if ($expected instanceof EnumValue || $actual instanceof EnumValue) {
            return $expected instanceof EnumValue
                && $actual instanceof EnumValue
                && $expected->type() === $actual->type()
                && $expected->value() === $actual->value();
        }
        if ($expected instanceof Message || $actual instanceof Message) {
            return $expected instanceof Message
                && $actual instanceof Message
                && $expected::class === $actual::class
                && $this->canonicalMessageJson($expected) === $this->canonicalMessageJson($actual);
        }
        if (is_float($expected) && is_float($actual) && is_nan($expected) && is_nan($actual)) {
            return true;
        }

        return $expected == $actual;
    }

    private function canonicalMessageJson(Message $message): mixed
    {
        $decoded = json_decode($message->serializeToJsonString(), true, flags: JSON_THROW_ON_ERROR);

        return $this->canonicalJsonValue($decoded);
    }

    private function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalJsonValue($item);
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    public function debugValue(mixed $value): string
    {
        if ($value instanceof UInt || $value instanceof Bytes || $value instanceof \Stringable) {
            return (string) $value;
        }
        if ($value instanceof Message) {
            return $value::class . ' ' . $value->serializeToJsonString();
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if ($value === INF) {
                return 'Infinity';
            }
            if ($value === -INF) {
                return '-Infinity';
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
