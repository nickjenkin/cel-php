<?php

declare(strict_types=1);

namespace CEL;

final class DurationValue implements \Stringable
{
    public function __construct(private readonly float $seconds)
    {
    }

    public static function fromParts(int $seconds, int $nanos = 0): self
    {
        while ($nanos >= 1_000_000_000) {
            $seconds++;
            $nanos -= 1_000_000_000;
        }
        while ($nanos <= -1_000_000_000) {
            $seconds--;
            $nanos += 1_000_000_000;
        }
        if ($seconds > 0 && $nanos < 0) {
            $seconds--;
            $nanos += 1_000_000_000;
        }
        if ($seconds < 0 && $nanos > 0) {
            $seconds++;
            $nanos -= 1_000_000_000;
        }

        return new self($seconds + ($nanos / 1_000_000_000));
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    public function wholeSeconds(): int
    {
        return $this->seconds >= 0 ? (int) floor($this->seconds) : (int) ceil($this->seconds);
    }

    public function nanos(): int
    {
        return (int) round(($this->seconds - $this->wholeSeconds()) * 1_000_000_000);
    }

    public function __toString(): string
    {
        return rtrim(rtrim(sprintf('%.9F', $this->seconds), '0'), '.') . 's';
    }
}
