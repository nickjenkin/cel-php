<?php

declare(strict_types=1);

namespace CEL;

final class FunctionDeclaration
{
    /** @param list<Overload> $overloads */
    public function __construct(
        public readonly string $name,
        public readonly array $overloads,
    ) {
    }
}
