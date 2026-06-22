<?php

declare(strict_types=1);

namespace CEL;

final class UInt implements \Stringable
{
    private const MAX = '18446744073709551615';

    private function __construct(private readonly string $value)
    {
    }

    public static function from(int|string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $string = is_int($value) ? (string) $value : ltrim($value, '+');
        if (!preg_match('/^(0|[1-9][0-9]*)$/', $string)) {
            throw new EvaluationException(sprintf('invalid uint value "%s"', $string));
        }

        $string = ltrim($string, '0');
        $string = $string === '' ? '0' : $string;
        if (bccomp($string, self::MAX, 0) === 1) {
            throw new EvaluationException(sprintf('uint value "%s" exceeds uint64 max', $string));
        }

        return new self($string);
    }

    public function add(self $other): self
    {
        return self::from(bcadd($this->value, $other->value, 0));
    }

    public function subtract(self $other): self
    {
        $result = bcsub($this->value, $other->value, 0);
        if (str_starts_with($result, '-')) {
            throw new EvaluationException('uint subtraction underflow');
        }

        return self::from($result);
    }

    public function multiply(self $other): self
    {
        return self::from(bcmul($this->value, $other->value, 0));
    }

    public function divide(self $other): self
    {
        if ($other->value === '0') {
            throw new EvaluationException('division by zero');
        }

        return self::from(bcdiv($this->value, $other->value, 0));
    }

    public function mod(self $other): self
    {
        if ($other->value === '0') {
            throw new EvaluationException('modulo by zero');
        }

        return self::from(bcmod($this->value, $other->value));
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, 0);
    }

    public function toInt(): int
    {
        if (bccomp($this->value, (string) PHP_INT_MAX, 0) === 1) {
            throw new EvaluationException('uint value cannot fit into a PHP int');
        }

        return (int) $this->value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value . 'u';
    }
}
