<?php

declare(strict_types=1);

namespace CEL;

final readonly class OptionalValue implements \Stringable
{
    private function __construct(
        private bool $hasValue,
        private mixed $value = null,
    ) {
    }

    public static function none(): self
    {
        return new self(false);
    }

    public static function of(mixed $value): self
    {
        return new self(true, $value);
    }

    public function hasValue(): bool
    {
        return $this->hasValue;
    }

    public function value(): mixed
    {
        if (!$this->hasValue) {
            throw new EvaluationException('optional has no value');
        }

        return $this->value;
    }

    public function __toString(): string
    {
        return $this->hasValue ? 'optional.of(...)' : 'optional.none()';
    }
}
