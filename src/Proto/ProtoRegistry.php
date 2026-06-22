<?php

declare(strict_types=1);

namespace CEL\Proto;

/**
 * Registry for generated proto3 PHP classes and enum constants known to a CEL
 * environment. Names are stored by protobuf full name, with optional aliases
 * for ergonomic tests and examples.
 */
final class ProtoRegistry
{
    /** @var array<string, class-string> */
    private array $messages = [];

    /** @var array<string, string> */
    private array $messageTypes = [];

    /** @var array<string, class-string> */
    private array $enums = [];

    /** @var array<string, string> */
    private array $enumTypes = [];

    /** @var array<class-string, string> */
    private array $enumClassTypes = [];

    /** @var array<string, int> */
    private array $enumConstants = [];

    /** @var array<string, EnumValue> */
    private array $enumConstantValues = [];

    public static function empty(): self
    {
        return new self();
    }

    public static function standard(): self
    {
        $registry = new self();

        foreach (self::wellKnownMessages() as $protoName => $className) {
            $shortName = substr($protoName, strrpos($protoName, '.') + 1);
            $registry->registerMessage($protoName, $className, [$shortName]);
        }

        $registry->registerEnum('google.protobuf.NullValue', \Google\Protobuf\NullValue::class, ['NullValue']);

        return $registry;
    }

    /** @param list<string> $aliases */
    public function registerMessage(string $protoName, string $className, array $aliases = []): self
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException(sprintf('Generated message class "%s" does not exist', $className));
        }

        $this->messages[$this->normalizeName($protoName)] = $className;
        $this->messageTypes[$this->normalizeName($protoName)] = $this->normalizeName($protoName);
        foreach ($aliases as $alias) {
            $this->messages[$this->normalizeName($alias)] = $className;
            $this->messageTypes[$this->normalizeName($alias)] = $this->normalizeName($protoName);
        }

        return $this;
    }

    /** @param list<string> $aliases */
    public function registerEnum(string $protoName, string $className, array $aliases = []): self
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException(sprintf('Generated enum class "%s" does not exist', $className));
        }

        $names = [$this->normalizeName($protoName)];
        foreach ($aliases as $alias) {
            $names[] = $this->normalizeName($alias);
        }

        foreach ($names as $name) {
            $this->enums[$name] = $className;
            $this->enumTypes[$name] = $this->normalizeName($protoName);
        }
        $this->enumClassTypes[$className] = $this->normalizeName($protoName);

        $reflection = new \ReflectionClass($className);
        foreach ($reflection->getConstants() as $constant => $value) {
            if (!is_int($value)) {
                continue;
            }
            foreach ($names as $name) {
                $this->enumConstants[$name . '.' . $constant] = $value;
                $this->enumConstantValues[$name . '.' . $constant] = new EnumValue($this->normalizeName($protoName), $value);
            }
        }

        return $this;
    }

    /** @return class-string|null */
    public function resolveMessage(string $name): ?string
    {
        return $this->messages[$this->normalizeName($name)] ?? null;
    }

    public function resolveMessageType(string $name): ?string
    {
        return $this->messageTypes[$this->normalizeName($name)] ?? null;
    }

    public function resolveEnumConstant(string $name): ?int
    {
        return $this->enumConstants[$this->normalizeName($name)] ?? null;
    }

    public function resolveEnumValue(string $name): ?EnumValue
    {
        return $this->enumConstantValues[$this->normalizeName($name)] ?? null;
    }

    public function resolveEnumType(string $name): ?string
    {
        return $this->enumTypes[$this->normalizeName($name)] ?? null;
    }

    /** @param class-string $className */
    public function enumTypeForClass(string $className): ?string
    {
        return $this->enumClassTypes[$className] ?? null;
    }

    public function resolveEnumSymbol(string $type, string $symbol): ?EnumValue
    {
        return $this->enumConstantValues[$this->normalizeName($type) . '.' . $symbol] ?? null;
    }

    public function hasTypeOrConstant(string $name): bool
    {
        $normalized = $this->normalizeName($name);

        return isset($this->messages[$normalized])
            || isset($this->enums[$normalized])
            || array_key_exists($normalized, $this->enumConstants);
    }

    private function normalizeName(string $name): string
    {
        return ltrim($name, '.');
    }

    /** @return array<string, class-string> */
    private static function wellKnownMessages(): array
    {
        return [
            'google.protobuf.Any' => \Google\Protobuf\Any::class,
            'google.protobuf.BoolValue' => \Google\Protobuf\BoolValue::class,
            'google.protobuf.BytesValue' => \Google\Protobuf\BytesValue::class,
            'google.protobuf.DoubleValue' => \Google\Protobuf\DoubleValue::class,
            'google.protobuf.Duration' => \Google\Protobuf\Duration::class,
            'google.protobuf.Empty' => \Google\Protobuf\GPBEmpty::class,
            'google.protobuf.FieldMask' => \Google\Protobuf\FieldMask::class,
            'google.protobuf.FloatValue' => \Google\Protobuf\FloatValue::class,
            'google.protobuf.Int32Value' => \Google\Protobuf\Int32Value::class,
            'google.protobuf.Int64Value' => \Google\Protobuf\Int64Value::class,
            'google.protobuf.ListValue' => \Google\Protobuf\ListValue::class,
            'google.protobuf.StringValue' => \Google\Protobuf\StringValue::class,
            'google.protobuf.Struct' => \Google\Protobuf\Struct::class,
            'google.protobuf.Timestamp' => \Google\Protobuf\Timestamp::class,
            'google.protobuf.UInt32Value' => \Google\Protobuf\UInt32Value::class,
            'google.protobuf.UInt64Value' => \Google\Protobuf\UInt64Value::class,
            'google.protobuf.Value' => \Google\Protobuf\Value::class,
        ];
    }
}
