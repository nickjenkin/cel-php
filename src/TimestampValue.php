<?php

declare(strict_types=1);

namespace CEL;

final class TimestampValue implements \Stringable
{
    public function __construct(
        private readonly \DateTimeImmutable $value,
        private readonly ?int $nanos = null,
    )
    {
    }

    public static function parse(string $value): self
    {
        if (preg_match('/^(?<year>[0-9]+)/', $value, $yearMatch) === 1) {
            $year = (int) $yearMatch['year'];
            if ($year < 1 || $year > 9999) {
                throw new EvaluationException(sprintf('timestamp year %d is outside the CEL timestamp range', $year));
            }
        }

        if (!preg_match('/^(?<date>[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.(?<frac>[0-9]{1,9}))?Z$/', $value, $matches)) {
            return new self(self::parseDateTime($value));
        }

        $frac = $matches['frac'] ?? '';
        $micros = $frac === '' ? '000000' : str_pad(substr($frac, 0, 6), 6, '0');
        $date = \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.u\Z',
            $matches['date'] . '.' . $micros . 'Z',
            new \DateTimeZone('UTC'),
        );

        return new self(
            $date ?: self::parseDateTime($value),
            $frac === '' ? 0 : (int) str_pad($frac, 9, '0'),
        );
    }

    public static function fromUnixSecondsNanos(int $seconds, int $nanos = 0): self
    {
        while ($nanos >= 1_000_000_000) {
            $seconds++;
            $nanos -= 1_000_000_000;
        }
        while ($nanos < 0) {
            $seconds--;
            $nanos += 1_000_000_000;
        }

        $micros = intdiv($nanos, 1000);
        $date = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros), new \DateTimeZone('UTC'));
        if (!$date) {
            $date = (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC'));
        }
        $year = (int) $date->setTimezone(new \DateTimeZone('UTC'))->format('Y');
        if ($year < 1 || $year > 9999) {
            throw new EvaluationException('timestamp is outside the CEL timestamp range');
        }

        return new self($date, $nanos);
    }

    public function value(): \DateTimeImmutable
    {
        return $this->value;
    }

    public function nanos(): int
    {
        return $this->nanos ?? ((int) $this->value->format('u') * 1000);
    }

    public function unixSeconds(): int
    {
        return (int) $this->value->setTimezone(new \DateTimeZone('UTC'))->format('U');
    }

    public function __toString(): string
    {
        $utc = $this->value->setTimezone(new \DateTimeZone('UTC'));
        $base = $utc->format('Y-m-d\TH:i:s');
        $nanos = $this->nanos();
        if ($nanos === 0) {
            return $base . 'Z';
        }

        return $base . '.' . rtrim(sprintf('%09d', $nanos), '0') . 'Z';
    }

    private static function parseDateTime(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable $exception) {
            throw new EvaluationException('timestamp expects a valid timestamp string', 0, $exception);
        }
    }
}
