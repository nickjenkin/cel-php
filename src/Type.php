<?php

declare(strict_types=1);

namespace CEL;

final class Type
{
    private function __construct(
        private readonly string $name,
        private readonly ?self $keyType = null,
        private readonly ?self $valueType = null,
        private readonly ?string $messageType = null,
    ) {
    }

    public static function dyn(): self
    {
        return new self('dyn');
    }

    public static function bool(): self
    {
        return new self('bool');
    }

    public static function int(): self
    {
        return new self('int');
    }

    public static function uint(): self
    {
        return new self('uint');
    }

    public static function double(): self
    {
        return new self('double');
    }

    public static function string(): self
    {
        return new self('string');
    }

    public static function bytes(): self
    {
        return new self('bytes');
    }

    public static function null(): self
    {
        return new self('null_type');
    }

    public static function type(): self
    {
        return new self('type');
    }

    public static function message(string $messageType): self
    {
        return new self('message', messageType: $messageType);
    }

    public static function list(self $valueType): self
    {
        return new self('list', null, $valueType);
    }

    public static function map(self $keyType, self $valueType): self
    {
        return new self('map', $keyType, $valueType);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function keyType(): ?self
    {
        return $this->keyType;
    }

    public function valueType(): ?self
    {
        return $this->valueType;
    }

    public function messageType(): ?string
    {
        return $this->messageType;
    }

    public function equals(self $other): bool
    {
        return (string) $this === (string) $other;
    }

    public function isNumeric(): bool
    {
        return in_array($this->name, ['int', 'uint', 'double'], true);
    }

    public function __toString(): string
    {
        if ($this->name === 'list') {
            if (($this->valueType ?? self::dyn())->name() === 'dyn') {
                return 'list';
            }

            return sprintf('list(%s)', $this->valueType ?? self::dyn());
        }

        if ($this->name === 'map') {
            if (($this->keyType ?? self::dyn())->name() === 'dyn' && ($this->valueType ?? self::dyn())->name() === 'dyn') {
                return 'map';
            }

            return sprintf('map(%s, %s)', $this->keyType ?? self::dyn(), $this->valueType ?? self::dyn());
        }

        if ($this->name === 'message') {
            return $this->messageType ?? 'dyn';
        }

        return $this->name;
    }
}
