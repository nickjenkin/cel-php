<?php

declare(strict_types=1);

namespace CEL\Tests;

use CEL\Bytes;
use CEL\Environment;
use CEL\EvaluationException;
use CEL\Re2RegexProvider;
use CEL\RegexProvider;
use CEL\Type;
use CEL\UInt;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    public function testArithmeticPrecedence(): void
    {
        self::assertTrue($this->eval('1 + 2 * 3 == 7'));
    }

    public function testSignedIntegerMinimumLiteral(): void
    {
        self::assertSame(PHP_INT_MIN, $this->eval('-9223372036854775808'));
    }

    public function testUintArithmetic(): void
    {
        $value = $this->eval('uint(1) + 2u');

        self::assertInstanceOf(UInt::class, $value);
        self::assertSame('3', $value->value());
    }

    public function testListMapAndInOperators(): void
    {
        self::assertTrue($this->eval('{"a": 1}["a"] in [1, 2]'));
        self::assertSame(7, $this->eval('[7, 8, 9][dyn(0.0)]'));
        self::assertSame(7, $this->eval('[7, 8, 9][dyn(0u)]'));
    }

    public function testCelComparisonSemantics(): void
    {
        self::assertFalse($this->eval('0.0/0.0 == 0.0/0.0'));
        self::assertTrue($this->eval('0.0/0.0 != 0.0/0.0'));
        self::assertTrue($this->eval("b'\\001' > b'\\000'"));
        self::assertFalse($this->eval("b'\\000\\001' > b'\\001'"));
        self::assertTrue($this->eval('false < true'));
        self::assertFalse($this->eval('true < false'));
        self::assertFalse($this->eval('dyn(9223372036854775807) < 9223372036854775808.0'));
        self::assertTrue($this->eval('dyn(9223372036854775807) <= 9223372036854775808.0'));
        self::assertTrue($this->eval('dyn(18446744073709551615u) < 18446744073709590000.0'));
        self::assertTrue(is_infinite($this->eval('15.75 / 0.0')));
    }

    public function testIntegerArithmeticOverflowStillRaisesEvaluationException(): void
    {
        foreach ([
            '9223372036854775807 + 1',
            '-9223372036854775808 - 1',
            '3037000500 * 3037000500',
            '-9223372036854775808 * -1',
        ] as $expression) {
            try {
                $this->eval($expression);
                self::fail($expression . ' should fail evaluation');
            } catch (EvaluationException $exception) {
                self::assertSame('int64 overflow', $exception->getMessage(), $expression);
            }
        }
    }

    public function testStringAndBytesFunctions(): void
    {
        self::assertTrue($this->eval('"abcdef".startsWith("abc") && "abcdef".contains("de")'));

        $bytes = $this->eval('b"\000\xff"');
        self::assertInstanceOf(Bytes::class, $bytes);
        self::assertSame("\x00\xff", $bytes->raw());
        self::assertSame("\xc3\xbf", $this->eval('string(b"\303\277")'));

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('invalid UTF-8');
        $this->eval('string(b"\000\xff")');
    }

    public function testMatchesUsesPcreByDefault(): void
    {
        self::assertTrue($this->eval('"hubba".matches("ubb")'));
        self::assertTrue($this->eval('"a/b".matches("a/b")'));
    }

    public function testInvalidPcrePatternRaisesEvaluationException(): void
    {
        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('invalid regular expression');

        $this->eval('"x".matches("(")');
    }

    public function testMatchesUsesConfiguredRegexProvider(): void
    {
        $provider = new class implements RegexProvider {
            /** @var list<array{0: string, 1: string}> */
            public array $calls = [];

            public function matches(string $value, string $pattern): bool
            {
                $this->calls[] = [$value, $pattern];

                return $value === 'abcdef' && $pattern === 'delegated';
            }
        };
        $env = Environment::builder()->regexProvider($provider)->build();

        self::assertTrue($env->program($env->compile('"abcdef".matches("delegated")'))->eval());
        self::assertSame([['abcdef', 'delegated']], $provider->calls);

        self::assertTrue($env->program($env->compile('"abcdef".startsWith("abc") && "abcdef".contains("de")'))->eval());
        self::assertSame([['abcdef', 'delegated']], $provider->calls);
    }

    public function testUseRe2RegexEvaluatesMatchesWhenExtensionIsLoaded(): void
    {
        if (!extension_loaded('re2')) {
            self::markTestSkipped('re2 extension is not loaded');
        }

        $env = Environment::builder()->useRe2Regex()->build();

        self::assertTrue($env->program($env->compile('"hubba".matches("ubb")'))->eval());
        self::assertFalse($env->program($env->compile('"hubba".matches("xyz")'))->eval());
    }

    public function testInvalidRe2PatternRaisesEvaluationException(): void
    {
        if (!extension_loaded('re2')) {
            self::markTestSkipped('re2 extension is not loaded');
        }

        $env = Environment::builder()->useRe2Regex()->build();

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('invalid regular expression');

        $env->program($env->compile('"abc".matches("(?=a)")'))->eval();
    }

    public function testRe2RegexProviderFailsFastWhenExtensionIsMissing(): void
    {
        if (extension_loaded('re2')) {
            self::markTestSkipped('re2 extension is loaded');
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('RE2 regex provider requires the re2 PHP extension.');

        new Re2RegexProvider();
    }

    public function testTypeValuesAndWellKnownTypeConversions(): void
    {
        self::assertTrue($this->eval('type(true) == bool'));
        self::assertTrue($this->eval('type([1, 2, 3]) == list'));
        self::assertTrue($this->eval('type({"a": 1}) == map'));
        self::assertSame(1234567890, $this->eval('int(timestamp("2009-02-13T23:31:30Z"))'));
        self::assertSame('2009-02-13T23:31:30Z', $this->eval('string(timestamp("2009-02-13T23:31:30Z"))'));
        self::assertSame('9999-12-31T23:59:59.999999999Z', $this->eval('string(timestamp("9999-12-31T23:59:59.999999999Z"))'));
        self::assertTrue($this->protoEval('google.protobuf.Timestamp == type(timestamp("2009-02-13T23:31:30Z"))'));
        self::assertTrue($this->protoEval('google.protobuf.Duration == type(duration("1000000s"))'));
        self::assertTrue($this->eval("bool('TRUE')"));
        self::assertFalse($this->eval("bool('0')"));
        self::assertTrue($this->eval("duration(duration('100s')) == duration('100s')"));
        self::assertSame('3', $this->eval('uint(3.14159265)')->value());
    }

    public function testTimestampAndDurationSelectors(): void
    {
        self::assertSame(13, $this->eval('timestamp("2009-02-13T23:31:30Z").getDate()'));
        self::assertSame(1, $this->eval('timestamp("2009-02-13T23:31:30Z").getMonth()'));
        self::assertSame(123, $this->eval('timestamp("2009-02-13T23:31:20.123456789Z").getMilliseconds()'));
        self::assertSame(14, $this->eval('timestamp("2009-02-13T23:31:30Z").getDate("Australia/Sydney")'));
        self::assertSame(2, $this->eval('duration("10000s").getHours()'));
        self::assertSame(62, $this->eval('duration("3730s").getMinutes()'));
    }

    public function testTimestampAndDurationSelectorsRejectInvalidRuntimeArity(): void
    {
        $env = Environment::standard();

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('no such overload');

        $env->program($env->parse('duration("1s").getHours("UTC")'))->eval();
    }

    public function testInvalidTimestampStringsRaiseEvaluationException(): void
    {
        foreach ([
            'timestamp("2009-02-1sT23:31:30Z")',
            'timestamp("20091--203T23:31:30Z")',
            'timestamp("23:31:300s")',
        ] as $expression) {
            try {
                $this->eval($expression);
                self::fail($expression . ' should fail evaluation');
            } catch (EvaluationException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testTimestampAndDurationArithmetic(): void
    {
        self::assertTrue($this->eval('timestamp("2009-02-13T23:00:00Z") + duration("240s") == timestamp("2009-02-13T23:04:00Z")'));
        self::assertTrue($this->eval('duration("600s") + duration("50s") == duration("650s")'));
        self::assertTrue($this->eval('timestamp("2009-02-13T23:31:00Z") - timestamp("2009-02-13T23:29:00Z") == duration("120s")'));
        self::assertTrue($this->eval('timestamp("0001-01-01T00:00:01.000000001Z") + duration("-999999999ns") == timestamp("0001-01-01T00:00:00.000000002Z")'));
    }

    public function testNetworkExtension(): void
    {
        self::assertSame('192.168.0.1', $this->eval("string(ip('192.168.0.1'))"));
        self::assertTrue($this->eval("isIP('2001:db8::68')"));
        self::assertFalse($this->eval("ip.isCanonical('2001:DB8::68')"));
        self::assertSame(4, $this->eval("ip('127.0.0.1').family()"));
        self::assertTrue($this->eval("ip('127.0.0.1').isLoopback()"));
        self::assertTrue($this->eval("type(ip('192.168.0.1')) == net.IP"));
        self::assertTrue($this->eval("cidr('192.168.0.0/24').containsIP('192.168.0.1')"));
        self::assertTrue($this->eval("cidr('192.168.0.1/24').masked() == cidr('192.168.0.0/24')"));
        self::assertSame(24, $this->eval("cidr('192.168.0.0/24').prefixLength()"));
        self::assertTrue($this->eval("type(cidr('192.168.0.0/24')) == net.CIDR"));
    }

    public function testInvalidIpStringsRaiseEvaluationException(): void
    {
        self::assertFalse($this->eval('isIP("127@\\000.0.1")'));

        $this->expectException(EvaluationException::class);

        $this->eval('ip("127@\\000.0.1")');
    }

    public function testMacros(): void
    {
        self::assertTrue($this->eval('[1, 2, 3].all(x, x > 0)'));
        self::assertSame([2, 3], $this->eval('[1, 2, 3].filter(x, x > 1)'));
        self::assertSame([2, 4, 6], $this->eval('[1, 2, 3].map(x, x * 2)'));
        self::assertTrue($this->eval('[1, 2, 3].exists_one(x, x == 2)'));
        self::assertTrue($this->eval("{'key1': 1, 'key2': 2}.exists(k, k == 'key2')"));
        self::assertSame(['Ringo'], $this->eval("{'John': 'smart', 'Ringo': 'funny'}.filter(key, key == 'Ringo')"));
        self::assertSame(['John'], $this->eval("{'John': 'smart'}.map(key, key)"));
        self::assertTrue($this->eval("{6: 'six', 7: 'seven', 8: 'eight'}.exists_one(k, k % 5 == 2)"));
        self::assertFalse($this->eval('[1, 2, 3].all(e, 6 / (2 - e) == 6)'));

        $this->expectException(EvaluationException::class);
        $this->eval('[3, 2, 1, 0].exists_one(n, 12 / n > 1)');
    }

    public function testComprehensionV2Macros(): void
    {
        self::assertTrue($this->eval('[1, 2, 3].exists(i, v, i == 1 && v == 2)'));
        self::assertTrue($this->eval('[1, 2, 3].all(i, v, i < v)'));
        self::assertTrue($this->eval('[7].existsOne(i, v, i == 0 && v == 7)'));
        self::assertSame([9], $this->eval('[3].transformList(i, v, v * v + i)'));
        self::assertSame([3, 5], $this->eval('[1, 2, 3].transformList(i, v, v > 1, i + v)'));
        self::assertSame(['foo' => 'foobar'], $this->eval("{'foo': 'bar'}.transformMap(k, v, k + v)"));
        self::assertSame(['b' => 4], $this->eval("{'a': 1, 'b': 2}.transformMap(k, v, v == 2, v * 2)"));
    }

    public function testBlockExtensionSyntheticVariables(): void
    {
        self::assertSame(2, $this->eval('cel.block([1, cel.index(0) + 1], cel.index(1))'));
        self::assertSame(3, $this->eval('cel.block([1, cel.block([2], cel.index(0))], cel.index(0) + cel.index(1))'));
        self::assertSame([2, 3], $this->eval('[1, 2].map(cel.iterVar(0, 0), cel.iterVar(0, 0) + 1)'));
    }

    public function testLogicalOperatorsCanShortCircuitRuntimeErrors(): void
    {
        $env = Environment::standard();

        self::assertTrue($env->program($env->parse('missing || true'))->eval());
        self::assertFalse($env->program($env->parse('missing && false'))->eval());
    }

    public function testQuotedSelectorsDottedBindingsAndNumericMapKeys(): void
    {
        self::assertSame('json', $this->eval('{"content-type": "json"}.`content-type`'));
        self::assertTrue($this->eval('3.0 in {1: 1, 2: 2, 3u: 3}'));
        self::assertSame(3, $this->eval('{1u: 1, 2: 2, 3u: 3}[3.0]'));
        self::assertTrue($this->eval('[1, 2u, 3.0] == [1.0, 2, 3u]'));
        self::assertTrue($this->eval('{1: [2u]} == {1u: [2.0]}'));

        $env = Environment::builder()
            ->variable('a.b', Type::map(Type::string(), Type::string()))
            ->build();
        self::assertSame('yeah', $env->program($env->compile('a.b.c'))->eval(['a.b' => ['c' => 'yeah']]));
    }

    public function testHasMacroOnArrayFields(): void
    {
        $env = Environment::builder()
            ->variable('m', Type::map(Type::string(), Type::dyn()))
            ->build();

        $program = $env->program($env->compile('has(m.name) && m.name == "nick"'));

        self::assertTrue($program->eval(['m' => ['name' => 'nick']]));
        self::assertFalse($program->eval(['m' => []]));
    }

    public function testResourceLimits(): void
    {
        $program = Environment::standard()->program(
            Environment::standard()->compile('[1, 2, 3].all(x, x > 0)'),
            new \CEL\ProgramOptions(maxSteps: 2),
        );

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('evaluation step limit exceeded');

        $program->eval();
    }

    private function eval(string $expression): mixed
    {
        $env = Environment::standard();

        return $env->program($env->compile($expression))->eval();
    }

    private function protoEval(string $expression): mixed
    {
        $env = Environment::standard();

        return $env->program($env->compile($expression))->eval();
    }
}
