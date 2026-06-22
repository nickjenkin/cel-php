<?php

declare(strict_types=1);

namespace CEL\Internal;

final class Expr
{
    /**
     * @param list<self> $args
     * @param array<string, self>|list<array{key:self,value:self}> $entries
     */
    public function __construct(
        public readonly string $kind,
        public readonly mixed $value = null,
        public readonly array $args = [],
        public readonly int $offset = 0,
        public readonly ?self $target = null,
        public readonly array $entries = [],
    ) {
    }

    public static function literal(mixed $value, int $offset): self
    {
        return new self('literal', $value, offset: $offset);
    }

    public static function ident(string $name, int $offset): self
    {
        return new self('ident', $name, offset: $offset);
    }
}
