<?php

declare(strict_types=1);

namespace CEL;

final class ProgramOptions
{
    public function __construct(
        public readonly int $maxSteps = 10000,
        public readonly int $maxDepth = 100,
    ) {
    }
}
