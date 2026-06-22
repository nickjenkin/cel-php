<?php

declare(strict_types=1);

namespace CEL;

final readonly class ErrorValue implements \Stringable
{
    public function __construct(private string $message)
    {
    }

    public static function fromThrowable(\Throwable $throwable): self
    {
        return new self($throwable->getMessage());
    }

    public function message(): string
    {
        return $this->message;
    }

    public function __toString(): string
    {
        return 'error(' . $this->message . ')';
    }
}
