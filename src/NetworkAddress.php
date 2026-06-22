<?php

declare(strict_types=1);

namespace CEL;

use CEL\EvaluationException;

final class NetworkAddress
{
    private function __construct(private readonly string $packed)
    {
    }

    public static function parse(string $value): self
    {
        if (str_contains($value, '%')) {
            throw new EvaluationException('IP Address with zone value is not allowed');
        }

        if (str_contains($value, "\0")) {
            throw new EvaluationException("IP Address '{$value}' parse error during conversion from string");
        }

        try {
            $packed = @inet_pton($value);
        } catch (\ValueError $exception) {
            throw new EvaluationException(sprintf("IP Address '%s' parse error during conversion from string", $value), 0, $exception);
        }
        if ($packed === false) {
            throw new EvaluationException(sprintf("IP Address '%s' parse error during conversion from string", $value));
        }

        if (strlen($packed) === 16 && self::isMappedV4Packed($packed)) {
            if (str_contains($value, '.')) {
                throw new EvaluationException('IPv4-mapped IPv6 address is not allowed');
            }

            $packed = substr($packed, 12);
        }

        return new self($packed);
    }

    public static function isValid(string $value): bool
    {
        try {
            self::parse($value);
            return true;
        } catch (EvaluationException) {
            return false;
        }
    }

    public static function isCanonical(string $value): bool
    {
        return (string) self::parse($value) === $value;
    }

    public function packed(): string
    {
        return $this->packed;
    }

    public function family(): int
    {
        return strlen($this->packed) === 4 ? 4 : 6;
    }

    public function equals(self $other): bool
    {
        return $this->packed === $other->packed;
    }

    public function isUnspecified(): bool
    {
        return $this->packed === str_repeat("\x00", strlen($this->packed));
    }

    public function isLoopback(): bool
    {
        if ($this->family() === 4) {
            return ord($this->packed[0]) === 127;
        }

        return $this->packed === str_repeat("\x00", 15) . "\x01";
    }

    public function isGlobalUnicast(): bool
    {
        if ($this->isUnspecified()) {
            return false;
        }

        if ($this->family() === 4) {
            return $this->packed !== "\xff\xff\xff\xff" && !$this->isIpv4Multicast();
        }

        return !$this->isIpv6Multicast();
    }

    public function isLinkLocalMulticast(): bool
    {
        if ($this->family() === 4) {
            return ord($this->packed[0]) === 224
                && ord($this->packed[1]) === 0
                && ord($this->packed[2]) === 0;
        }

        return ord($this->packed[0]) === 0xff && ord($this->packed[1]) === 0x02;
    }

    public function isLinkLocalUnicast(): bool
    {
        if ($this->family() === 4) {
            return ord($this->packed[0]) === 169 && ord($this->packed[1]) === 254;
        }

        return ord($this->packed[0]) === 0xfe && (ord($this->packed[1]) & 0xc0) === 0x80;
    }

    public function __toString(): string
    {
        $text = @inet_ntop($this->packed);
        if ($text === false) {
            throw new \RuntimeException('invalid packed IP address');
        }

        return strtolower($text);
    }

    private function isIpv4Multicast(): bool
    {
        $first = ord($this->packed[0]);

        return $first >= 224 && $first <= 239;
    }

    private function isIpv6Multicast(): bool
    {
        return ord($this->packed[0]) === 0xff;
    }

    private static function isMappedV4Packed(string $packed): bool
    {
        return str_starts_with($packed, str_repeat("\x00", 10) . "\xff\xff");
    }
}
