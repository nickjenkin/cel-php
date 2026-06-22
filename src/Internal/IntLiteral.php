<?php

declare(strict_types=1);

namespace CEL\Internal;

use CEL\EvaluationException;
use CEL\ParseException;

final class IntLiteral
{
    public function __construct(public readonly string $decimal, private readonly int $offset)
    {
    }

    public function toInt(): int
    {
        if (bccomp($this->decimal, (string) PHP_INT_MAX, 0) === 1) {
            throw new EvaluationException(sprintf('integer literal overflows int64 at byte %d', $this->offset));
        }

        return (int) $this->decimal;
    }

    public function negate(): int
    {
        $negative = '-' . $this->decimal;
        if (bccomp($negative, (string) PHP_INT_MIN, 0) === -1) {
            throw new EvaluationException(sprintf('integer literal underflows int64 at byte %d', $this->offset));
        }

        return (int) $negative;
    }

    public static function fromDecimal(string $decimal, int $offset): int|self
    {
        $decimal = ltrim($decimal, '+');
        if (bccomp($decimal, (string) PHP_INT_MAX, 0) === 1) {
            if (bccomp($decimal, '9223372036854775808', 0) === 1) {
                throw new ParseException(sprintf('integer literal overflows int64 at byte %d', $offset));
            }

            return new self($decimal, $offset);
        }

        return (int) $decimal;
    }
}
