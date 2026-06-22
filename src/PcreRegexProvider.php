<?php

declare(strict_types=1);

namespace CEL;

final class PcreRegexProvider implements RegexProvider
{
    public function matches(string $value, string $pattern): bool
    {
        $result = @preg_match('/' . str_replace('/', '\\/', $pattern) . '/u', $value);
        if ($result === false) {
            throw new EvaluationException('invalid regular expression');
        }

        return $result === 1;
    }
}
