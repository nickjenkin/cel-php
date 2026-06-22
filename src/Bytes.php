<?php

declare(strict_types=1);

namespace CEL;

final class Bytes implements \Stringable
{
    public function __construct(private readonly string $bytes)
    {
    }

    public function raw(): string
    {
        return $this->bytes;
    }

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public function __toString(): string
    {
        return $this->bytes;
    }
}
