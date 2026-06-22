<?php

declare(strict_types=1);

namespace CEL\Internal;

use CEL\Bytes;
use CEL\ParseException;
use CEL\UInt;

final class Lexer
{
    private int $offset = 0;
    private readonly int $length;

    public function __construct(
        private readonly string $source,
        private readonly string $sourceDescription = '<input>',
    )
    {
        $this->length = strlen($source);
    }

    /** @return list<Token> */
    public function scan(): array
    {
        $tokens = [];
        while (!$this->isAtEnd()) {
            $this->skipWhitespaceAndComments();
            if ($this->isAtEnd()) {
                break;
            }

            $start = $this->offset;
            $char = $this->peek();

            if ($this->isIdentifierStart($char)) {
                $tokens[] = $this->scanIdentifierOrPrefixedString();
                continue;
            }

            if ($this->isDigit($char) || ($char === '.' && $this->isDigit($this->peek(1)))) {
                $tokens[] = $this->scanNumber();
                continue;
            }

            if ($char === '"' || $char === "'") {
                $tokens[] = $this->scanString(false, false, $start);
                continue;
            }

            if ($char === '`') {
                $tokens[] = $this->scanEscapedIdentifier();
                continue;
            }

            $two = $char . $this->peek(1);
            if (in_array($two, ['==', '!=', '<=', '>=', '&&', '||'], true)) {
                $this->offset += 2;
                $tokens[] = new Token('op', $two, $start);
                continue;
            }

            if (str_contains('+-*/%!<>()[]{}.,?:', $char)) {
                $this->offset++;
                $tokens[] = new Token($char, $char, $start);
                continue;
            }

            throw $this->error(sprintf('unexpected character "%s"', $char), $start);
        }

        $tokens[] = new Token('eof', '', $this->offset);

        return $tokens;
    }

    private function scanIdentifierOrPrefixedString(): Token
    {
        $start = $this->offset;
        $first = $this->peek();
        $second = $this->peek(1);
        $third = $this->peek(2);

        if (($first === 'r' || $first === 'R') && ($second === '"' || $second === "'")) {
            $this->offset++;
            return $this->scanString(true, false, $start);
        }

        if (($first === 'b' || $first === 'B') && ($second === '"' || $second === "'")) {
            $this->offset++;
            return $this->scanString(false, true, $start);
        }

        if (($first === 'b' || $first === 'B') && ($second === 'r' || $second === 'R') && ($third === '"' || $third === "'")) {
            $this->offset += 2;
            return $this->scanString(true, true, $start);
        }

        if (($first === 'r' || $first === 'R') && ($second === 'b' || $second === 'B') && ($third === '"' || $third === "'")) {
            $this->offset += 2;
            return $this->scanString(true, true, $start);
        }

        while (!$this->isAtEnd() && $this->isIdentifierPart($this->peek())) {
            $this->offset++;
        }

        $text = substr($this->source, $start, $this->offset - $start);

        return match ($text) {
            'true' => new Token('literal', $text, $start, true),
            'false' => new Token('literal', $text, $start, false),
            'null' => new Token('literal', $text, $start, null),
            'in' => new Token('op', $text, $start),
            default => new Token('ident', $text, $start),
        };
    }

    private function scanNumber(): Token
    {
        $start = $this->offset;
        $isFloat = false;

        if ($this->peek() === '0' && in_array($this->peek(1), ['x', 'X'], true)) {
            $this->offset += 2;
            $digitsStart = $this->offset;
            while (!$this->isAtEnd() && ctype_xdigit($this->peek())) {
                $this->offset++;
            }

            if ($digitsStart === $this->offset) {
                throw $this->error('expected hexadecimal digits', $start);
            }

            $isUint = in_array($this->peek(), ['u', 'U'], true);
            if ($isUint) {
                $this->offset++;
            }

            $text = substr($this->source, $start, $this->offset - $start);
            $digits = substr($text, 2, $isUint ? -1 : null);
            $value = self::hexToDecimal($digits);

            return new Token('literal', $text, $start, $isUint ? UInt::from($value) : IntLiteral::fromDecimal($value, $start));
        }

        while (!$this->isAtEnd() && $this->isDigit($this->peek())) {
            $this->offset++;
        }

        if ($this->peek() === '.' && $this->isDigit($this->peek(1))) {
            $isFloat = true;
            $this->offset++;
            while (!$this->isAtEnd() && $this->isDigit($this->peek())) {
                $this->offset++;
            }
        }

        if (in_array($this->peek(), ['e', 'E'], true)) {
            $isFloat = true;
            $this->offset++;
            if (in_array($this->peek(), ['+', '-'], true)) {
                $this->offset++;
            }

            $digitsStart = $this->offset;
            while (!$this->isAtEnd() && $this->isDigit($this->peek())) {
                $this->offset++;
            }

            if ($digitsStart === $this->offset) {
                throw $this->error('expected exponent digits', $start);
            }
        }

        $isUint = !$isFloat && in_array($this->peek(), ['u', 'U'], true);
        if ($isUint) {
            $this->offset++;
        }

        $text = substr($this->source, $start, $this->offset - $start);
        if ($isFloat) {
            return new Token('literal', $text, $start, (float) $text);
        }

        if ($isUint) {
            return new Token('literal', $text, $start, UInt::from(substr($text, 0, -1)));
        }

        return new Token('literal', $text, $start, IntLiteral::fromDecimal($text, $start));
    }

    private function scanEscapedIdentifier(): Token
    {
        $start = $this->offset;
        $this->offset++;
        $buffer = '';

        while (!$this->isAtEnd()) {
            $char = $this->peek();
            $this->offset++;
            if ($char === '`') {
                if ($buffer === '') {
                    throw $this->error('empty escaped identifier', $start);
                }

                return new Token('ident', $buffer, $start, escapedIdentifier: true);
            }

            if ($char === "\n" || $char === "\r") {
                throw $this->error('newline in escaped identifier', $start);
            }

            if ($char === '\\') {
                if ($this->isAtEnd()) {
                    throw $this->error('unterminated escape sequence', $start);
                }
                $buffer .= $this->readEscape(false, $start);
                continue;
            }

            $buffer .= $char;
        }

        throw $this->error('unterminated escaped identifier', $start);
    }

    private function scanString(bool $raw, bool $bytes, int $start): Token
    {
        $quote = $this->peek();
        $triple = $this->peek(1) === $quote && $this->peek(2) === $quote;
        $this->offset += $triple ? 3 : 1;
        $buffer = '';

        while (!$this->isAtEnd()) {
            if ($triple && $this->peek() === $quote && $this->peek(1) === $quote && $this->peek(2) === $quote) {
                $this->offset += 3;
                $value = $bytes ? new Bytes($buffer) : $buffer;
                return new Token('literal', substr($this->source, $start, $this->offset - $start), $start, $value);
            }

            if (!$triple && $this->peek() === $quote) {
                $this->offset++;
                $value = $bytes ? new Bytes($buffer) : $buffer;
                return new Token('literal', substr($this->source, $start, $this->offset - $start), $start, $value);
            }

            $char = $this->peek();
            if (!$triple && ($char === "\n" || $char === "\r")) {
                throw $this->error('newline in string literal', $start);
            }

            $this->offset++;
            if ($char !== '\\' || $raw) {
                $buffer .= $char;
                continue;
            }

            if ($this->isAtEnd()) {
                throw $this->error('unterminated escape sequence', $start);
            }

            $buffer .= $this->readEscape($bytes, $start);
        }

        throw $this->error('unterminated string literal', $start);
    }

    private function readEscape(bool $bytes, int $start): string
    {
        $char = $this->peek();
        $this->offset++;

        return match ($char) {
            'a' => "\x07",
            'b' => "\x08",
            'f' => "\x0c",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'v' => "\x0b",
            '\\', '?', '"', "'", '`' => $char,
            'x', 'X' => $bytes
                ? chr($this->readFixedHex(2, $start))
                : $this->codePointToString($this->readFixedHex(2, $start), false, $start),
            'u' => $this->codePointToString($this->readFixedHex(4, $start), $bytes, $start),
            'U' => $this->codePointToString($this->readFixedHex(8, $start), $bytes, $start),
            default => $this->readOctalOrFail($char, $bytes, $start),
        };
    }

    private function readFixedHex(int $length, int $start): int
    {
        $hex = substr($this->source, $this->offset, $length);
        if (strlen($hex) !== $length || !ctype_xdigit($hex)) {
            throw $this->error('invalid hexadecimal escape', $start);
        }

        $this->offset += $length;

        return hexdec($hex);
    }

    private function readOctalOrFail(string $first, bool $bytes, int $start): string
    {
        if ($first < '0' || $first > '3') {
            throw $this->error(sprintf('invalid escape sequence "\\%s"', $first), $start);
        }

        $rest = substr($this->source, $this->offset, 2);
        if (strlen($rest) !== 2 || !preg_match('/^[0-7]{2}$/', $rest)) {
            throw $this->error('invalid octal escape', $start);
        }

        $this->offset += 2;

        $codePoint = octdec($first . $rest);

        return $bytes ? chr($codePoint) : $this->codePointToString($codePoint, false, $start);
    }

    private function codePointToString(int $codePoint, bool $bytes, int $start): string
    {
        if ($codePoint > 0x10ffff || ($codePoint >= 0xd800 && $codePoint <= 0xdfff)) {
            throw $this->error('invalid unicode code point', $start);
        }

        if ($bytes && $codePoint <= 0xff) {
            return chr($codePoint);
        }

        return mb_chr($codePoint, 'UTF-8');
    }

    private function skipWhitespaceAndComments(): void
    {
        do {
            $moved = false;
            while (!$this->isAtEnd() && str_contains(" \t\r\n\f", $this->peek())) {
                $this->offset++;
                $moved = true;
            }

            if ($this->peek() === '/' && $this->peek(1) === '/') {
                $moved = true;
                while (!$this->isAtEnd() && $this->peek() !== "\n") {
                    $this->offset++;
                }
            }
        } while ($moved);
    }

    private function peek(int $ahead = 0): string
    {
        return $this->source[$this->offset + $ahead] ?? "\0";
    }

    private function isAtEnd(): bool
    {
        return $this->offset >= $this->length;
    }

    private function isIdentifierStart(string $char): bool
    {
        return $char === '_' || ctype_alpha($char);
    }

    private function isIdentifierPart(string $char): bool
    {
        return $this->isIdentifierStart($char) || $this->isDigit($char);
    }

    private function isDigit(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }

    private function error(string $message, int $offset): ParseException
    {
        return new ParseException(DiagnosticFormatter::format($message, $this->source, $offset, $this->sourceDescription, syntax: true));
    }

    private static function hexToDecimal(string $hex): string
    {
        $decimal = '0';
        foreach (str_split($hex) as $char) {
            $decimal = bcadd(bcmul($decimal, '16', 0), (string) hexdec($char), 0);
        }

        return $decimal;
    }
}
