<?php

declare(strict_types=1);

namespace CEL\Internal;

final class Token
{
    public function __construct(
        public readonly string $type,
        public readonly string $text,
        public readonly int $offset,
        public readonly mixed $value = null,
        public readonly bool $escapedIdentifier = false,
    ) {
    }
}
