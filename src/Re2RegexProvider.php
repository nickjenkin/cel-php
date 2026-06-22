<?php

declare(strict_types=1);

namespace CEL;

final class Re2RegexProvider implements RegexProvider
{
    public function __construct()
    {
        if (!class_exists(\Re2\Regex::class)) {
            throw new \LogicException('RE2 regex provider requires the re2 PHP extension.');
        }
    }

    public function matches(string $value, string $pattern): bool
    {
        try {
            return (new \Re2\Regex($pattern))->isMatch($value);
        } catch (\Re2\CompileException) {
            throw new EvaluationException('invalid regular expression');
        }
    }
}
