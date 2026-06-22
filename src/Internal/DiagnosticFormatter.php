<?php

declare(strict_types=1);

namespace CEL\Internal;

final class DiagnosticFormatter
{
    private const MAX_SNIPPET_LENGTH = 16384;

    public static function format(string $message, string $source, int $offset, string $sourceDescription = '<input>', bool $syntax = false): string
    {
        if (str_starts_with($message, 'ERROR: ')) {
            return $message;
        }

        if (!mb_check_encoding($message, 'UTF-8')) {
            $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
        }

        $description = $sourceDescription === '' ? '<input>' : $sourceDescription;
        $displayMessage = $syntax && !str_starts_with($message, 'Syntax error: ')
            ? 'Syntax error: ' . $message
            : $message;

        if ($offset < 0) {
            return sprintf('ERROR: %s:-1:0: %s', $description, $displayMessage);
        }

        $location = self::locationForOffset($source, $offset);
        $result = sprintf('ERROR: %s:%d:%d: %s', $description, $location['line'], $location['column'], $displayMessage);

        if ($location['snippet'] !== null && strlen($location['snippet']) <= self::MAX_SNIPPET_LENGTH) {
            $snippet = str_replace("\t", ' ', $location['snippet']);
            $result .= "\n | " . $snippet;
            $result .= "\n | " . self::indicator($location['before'], $location['target']);
        }

        return $result;
    }

    public static function formatExceptionMessage(string $message, string $source, string $sourceDescription = '<input>', bool $syntax = false): string
    {
        if (str_starts_with($message, 'ERROR: ')) {
            return $message;
        }

        if (preg_match('/^(?<message>.*) at byte (?<offset>-?\d+)$/s', $message, $matches) === 1) {
            return self::format($matches['message'], $source, (int) $matches['offset'], $sourceDescription, $syntax);
        }

        return self::format($message, $source, -1, $sourceDescription, $syntax);
    }

    /**
     * @return array{line:int,column:int,snippet:?string,before:string,target:string}
     */
    private static function locationForOffset(string $source, int $offset): array
    {
        $length = strlen($source);
        $boundedOffset = min($offset, $length);
        $line = 1;
        $lineStart = 0;

        for ($i = 0; $i < $boundedOffset; $i++) {
            if ($source[$i] === "\n") {
                $line++;
                $lineStart = $i + 1;
            }
        }

        $lineEnd = strpos($source, "\n", $lineStart);
        if ($lineEnd === false) {
            $lineEnd = $length;
        }

        $snippet = null;
        $before = '';
        $target = '';
        $column = 1;

        if ($length > 0 && $lineStart <= $length) {
            $snippet = substr($source, $lineStart, max(0, $lineEnd - $lineStart));
            if (str_ends_with($snippet, "\r")) {
                $snippet = substr($snippet, 0, -1);
            }

            [$before, $target, $column] = self::lineIndicatorParts($snippet, max(0, $boundedOffset - $lineStart));
        }

        return [
            'line' => $line,
            'column' => $column,
            'snippet' => $snippet,
            'before' => $before,
            'target' => $target,
        ];
    }

    /** @return array{0:string,1:string,2:int} */
    private static function lineIndicatorParts(string $line, int $relativeOffset): array
    {
        $chars = self::characters($line);
        if ($chars === []) {
            return ['', '', 1];
        }

        $byteOffset = 0;
        $before = '';
        foreach ($chars as $index => $char) {
            $length = strlen($char);
            if ($relativeOffset < $byteOffset + $length) {
                return [str_replace("\t", ' ', $before), $char, $index + 1];
            }

            $before .= $char;
            $byteOffset += $length;
        }

        return [str_replace("\t", ' ', $before), '', count($chars) + 1];
    }

    private static function indicator(string $before, string $target): string
    {
        $indicator = '';
        foreach (self::characters($before) as $char) {
            $indicator .= strlen($char) > 1 ? "\u{FF0E}" : '.';
        }

        return $indicator . ($target !== '' && strlen($target) > 1 ? "\u{FF3E}" : '^');
    }

    /** @return list<string> */
    private static function characters(string $value): array
    {
        if ($value === '') {
            return [];
        }

        if (preg_match_all('/./us', $value, $matches) !== false) {
            return $matches[0];
        }

        return str_split($value);
    }
}
