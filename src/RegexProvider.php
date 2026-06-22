<?php

declare(strict_types=1);

namespace CEL;

interface RegexProvider
{
    public function matches(string $value, string $pattern): bool;
}
