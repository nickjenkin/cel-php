<?php

declare(strict_types=1);

namespace CEL\Tests\Conformance;

final readonly class ConformanceResult
{
    public function __construct(
        public string $status,
        public string $reason = '',
    ) {
    }
}
