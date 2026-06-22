<?php

declare(strict_types=1);

namespace CEL\Tests\Conformance;

use CEL\Bytes;
use CEL\Environment;
use CEL\UInt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConformanceSmokeTest extends TestCase
{
    /** @return iterable<string, array{0:string,1:mixed}> */
    public static function selectedBasicCases(): iterable
    {
        $wanted = [
            'self_eval_int_zero',
            'self_eval_uint_nonzero',
            'self_eval_int_negative_min',
            'self_eval_float_negative_exp',
            'self_eval_string_escape',
            'self_eval_bytes_invalid_utf8',
            'self_eval_list_singleitem',
            'self_eval_map_singleitem',
            'self_eval_bool_true',
            'self_eval_int_hex',
            'self_eval_unicode_escape_four',
            'binop',
        ];

        $json = json_decode(
            file_get_contents(__DIR__ . '/fixtures/json/basic.json') ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($json['section'] as $section) {
            foreach ($section['test'] as $test) {
                if (!in_array($test['name'], $wanted, true)) {
                    continue;
                }

                yield $test['name'] => [$test['expr'], self::decodeValue($test['value'])];
            }
        }
    }

    #[DataProvider('selectedBasicCases')]
    public function testSelectedUpstreamBasicConformanceCases(string $expr, mixed $expected): void
    {
        $env = Environment::standard();
        $actual = $env->program($env->compile($expr))->eval();

        self::assertCelEquals($expected, $actual);
    }

    /** @param array<string, mixed> $value */
    private static function decodeValue(array $value): mixed
    {
        if (array_key_exists('nullValue', $value)) {
            return null;
        }
        if (array_key_exists('boolValue', $value)) {
            return $value['boolValue'];
        }
        if (array_key_exists('int64Value', $value)) {
            return (int) $value['int64Value'];
        }
        if (array_key_exists('uint64Value', $value)) {
            return UInt::from($value['uint64Value']);
        }
        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }
        if (array_key_exists('stringValue', $value)) {
            return $value['stringValue'];
        }
        if (array_key_exists('bytesValue', $value)) {
            return new Bytes(base64_decode($value['bytesValue'], true) ?: '');
        }
        if (array_key_exists('listValue', $value)) {
            return array_map(
                self::decodeValue(...),
                $value['listValue']['values'] ?? [],
            );
        }
        if (array_key_exists('mapValue', $value)) {
            $map = [];
            foreach ($value['mapValue']['entries'] ?? [] as $entry) {
                $key = self::decodeValue($entry['key']);
                $map[is_bool($key) ? ($key ? 'true' : 'false') : (string) $key] = self::decodeValue($entry['value']);
            }

            return $map;
        }

        self::fail('Unsupported conformance value: ' . json_encode($value));
    }

    private static function assertCelEquals(mixed $expected, mixed $actual): void
    {
        if ($expected instanceof UInt) {
            self::assertInstanceOf(UInt::class, $actual);
            self::assertSame($expected->value(), $actual->value());
            return;
        }

        if ($expected instanceof Bytes) {
            self::assertInstanceOf(Bytes::class, $actual);
            self::assertSame($expected->raw(), $actual->raw());
            return;
        }

        self::assertEquals($expected, $actual);
    }
}
