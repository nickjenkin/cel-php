<?php

declare(strict_types=1);

namespace CEL;

use CEL\EvaluationException;

final class NetworkCidr
{
    private function __construct(
        private readonly NetworkAddress $address,
        private readonly int $prefixLength,
    ) {
    }

    public static function parse(string $value): self
    {
        if (str_contains($value, '%')) {
            throw new EvaluationException('CIDR with zone value is not allowed');
        }

        $parts = explode('/', $value);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || preg_match('/^[0-9]+$/', $parts[1]) !== 1) {
            throw new EvaluationException('network address parse error during conversion from string');
        }

        try {
            $address = NetworkAddress::parse($parts[0]);
        } catch (EvaluationException $exception) {
            if (str_contains($exception->getMessage(), 'IPv4-mapped IPv6')) {
                throw $exception;
            }
            throw new EvaluationException('network address parse error during conversion from string');
        }

        $prefixLength = (int) $parts[1];
        $max = $address->family() === 4 ? 32 : 128;
        if ($prefixLength < 0 || $prefixLength > $max) {
            throw new EvaluationException('network address parse error during conversion from string');
        }

        return new self($address, $prefixLength);
    }

    public function address(): NetworkAddress
    {
        return $this->address;
    }

    public function prefixLength(): int
    {
        return $this->prefixLength;
    }

    public function equals(self $other): bool
    {
        return $this->prefixLength === $other->prefixLength
            && $this->address->equals($other->address);
    }

    public function containsIP(NetworkAddress|string $address): bool
    {
        $candidate = is_string($address) ? NetworkAddress::parse($address) : $address;
        if ($candidate->family() !== $this->address->family()) {
            return false;
        }

        return $this->prefixMatches($candidate->packed(), $this->maskedAddress()->packed());
    }

    public function containsCIDR(self|string $cidr): bool
    {
        $candidate = is_string($cidr) ? self::parse($cidr) : $cidr;
        if ($candidate->address->family() !== $this->address->family()) {
            return false;
        }
        if ($candidate->prefixLength < $this->prefixLength) {
            return false;
        }

        return $this->containsIP($candidate->maskedAddress());
    }

    public function masked(): self
    {
        return new self($this->maskedAddress(), $this->prefixLength);
    }

    public function __toString(): string
    {
        return (string) $this->address . '/' . $this->prefixLength;
    }

    private function maskedAddress(): NetworkAddress
    {
        $packed = $this->address->packed();
        $remaining = $this->prefixLength;
        $out = '';

        for ($i = 0, $length = strlen($packed); $i < $length; $i++) {
            $byte = ord($packed[$i]);
            if ($remaining >= 8) {
                $out .= chr($byte);
                $remaining -= 8;
                continue;
            }
            if ($remaining <= 0) {
                $out .= "\x00";
                continue;
            }

            $mask = (0xff << (8 - $remaining)) & 0xff;
            $out .= chr($byte & $mask);
            $remaining = 0;
        }

        return NetworkAddress::parse(@inet_ntop($out) ?: '');
    }

    private function prefixMatches(string $candidate, string $network): bool
    {
        $remaining = $this->prefixLength;
        for ($i = 0, $length = strlen($network); $i < $length; $i++) {
            if ($remaining <= 0) {
                return true;
            }

            $left = ord($candidate[$i]);
            $right = ord($network[$i]);
            if ($remaining >= 8) {
                if ($left !== $right) {
                    return false;
                }
                $remaining -= 8;
                continue;
            }

            $mask = (0xff << (8 - $remaining)) & 0xff;
            return ($left & $mask) === ($right & $mask);
        }

        return true;
    }
}
