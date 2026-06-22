<?php

declare(strict_types=1);

namespace CEL\Tests;

use CEL\CheckException;
use CEL\Environment;
use CEL\ParseException;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    public function testParseDiagnosticIncludesSnippetAndCaret(): void
    {
        try {
            Environment::standard()->parse('1 +');
        } catch (ParseException $exception) {
            self::assertSame(
                "ERROR: <input>:1:4: Syntax error: expected expression\n | 1 +\n | ...^",
                $exception->getMessage(),
            );

            return;
        }

        self::fail('Expected parse error');
    }

    public function testParseDiagnosticUsesMultilineLocation(): void
    {
        try {
            Environment::standard()->parse("true\n3");
        } catch (ParseException $exception) {
            self::assertSame(
                "ERROR: <input>:2:1: Syntax error: expected end of expression\n | 3\n | ^",
                $exception->getMessage(),
            );

            return;
        }

        self::fail('Expected parse error');
    }

    public function testParseDiagnosticUsesWideIndicatorForUnicodeTarget(): void
    {
        try {
            Environment::standard()->parse('a + 😁');
        } catch (ParseException $exception) {
            self::assertStringContainsString(" | a + 😁\n | ....＾", $exception->getMessage());

            return;
        }

        self::fail('Expected parse error');
    }

    public function testParseDiagnosticOmitsVeryLongSnippet(): void
    {
        try {
            Environment::standard()->parse(str_repeat('a', 17000) . ' +');
        } catch (ParseException $exception) {
            self::assertStringStartsWith('ERROR: <input>:1:17003: Syntax error: expected expression', $exception->getMessage());
            self::assertStringNotContainsString("\n | ", $exception->getMessage());

            return;
        }

        self::fail('Expected parse error');
    }

    public function testCheckDiagnosticIncludesSnippetAndCaret(): void
    {
        try {
            Environment::standard()->compile('missing + 1');
        } catch (CheckException $exception) {
            self::assertSame(
                "ERROR: <input>:1:1: undeclared reference to \"missing\"\n | missing + 1\n | ^",
                $exception->getMessage(),
            );

            return;
        }

        self::fail('Expected check error');
    }

    public function testCheckDiagnosticPreservesSemanticMessage(): void
    {
        try {
            Environment::standard()->compile('"abc".startsWith(1)');
        } catch (CheckException $exception) {
            self::assertStringStartsWith('ERROR: <input>:1:6: startsWith expects string, got int', $exception->getMessage());
            self::assertStringContainsString("\n | \"abc\".startsWith(1)\n | .....^", $exception->getMessage());

            return;
        }

        self::fail('Expected check error');
    }

    public function testCheckDiagnosticUsesCustomSourceDescription(): void
    {
        try {
            Environment::standard()->compile('missing + 1', 'policy.cel');
        } catch (CheckException $exception) {
            self::assertStringStartsWith('ERROR: policy.cel:1:1: undeclared reference to "missing"', $exception->getMessage());

            return;
        }

        self::fail('Expected check error');
    }
}
