<?php

declare(strict_types=1);

namespace CEL;

final readonly class PartialResult
{
    private function __construct(
        private mixed $value,
        private ?UnknownValue $unknown,
        private ?ErrorValue $error,
        private ?string $residualExpression,
    ) {
    }

    public static function known(mixed $value): self
    {
        return new self($value, null, null, null);
    }

    public static function fromUnknown(UnknownValue $unknown, string $residualExpression): self
    {
        return new self(null, $unknown, null, $residualExpression);
    }

    public static function fromError(ErrorValue $error, string $residualExpression): self
    {
        return new self(null, null, $error, $residualExpression);
    }

    public function isKnown(): bool
    {
        return $this->unknown === null && $this->error === null;
    }

    public function value(): mixed
    {
        if (!$this->isKnown()) {
            throw new EvaluationException('partial result does not contain a known value');
        }

        return $this->value;
    }

    public function unknown(): ?UnknownValue
    {
        return $this->unknown;
    }

    public function error(): ?ErrorValue
    {
        return $this->error;
    }

    public function residualExpression(): ?string
    {
        return $this->residualExpression;
    }
}
