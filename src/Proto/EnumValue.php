<?php

declare(strict_types=1);

namespace CEL\Proto;

final readonly class EnumValue implements \Stringable
{
    public function __construct(
        private string $type,
        private int $value,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->type . '(' . $this->value . ')';
    }
}
