<?php

declare(strict_types=1);

namespace CEL\Tests\Conformance;

use CEL\Ast;
use CEL\CheckedAst;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConformanceClassificationTest extends TestCase
{
    public function testExpandedFixtureSetIsAvailable(): void
    {
        $fixtures = glob(__DIR__ . '/fixtures/json/*.json') ?: [];

        self::assertGreaterThanOrEqual(30, count($fixtures));
        self::assertFileExists(__DIR__ . '/fixtures/json/proto3.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/wrappers.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/fields.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/enums.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/optionals.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/timestamps.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/conversions.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/dynamic.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/type_deduction.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/namespace.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/macros2.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/block_ext.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/network_ext.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/plumbing.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/parse.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/proto2.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/proto2_ext.json');
        self::assertFileExists(__DIR__ . '/fixtures/json/unknowns.json');
    }

    public function testProto2FixtureIsExplicitlySkipped(): void
    {
        $harness = new ConformanceHarness();
        $count = 0;

        foreach (['proto2', 'proto2_ext'] as $fixture) {
            foreach ($this->fixtureTests($fixture) as [$section, $test]) {
                $result = $harness->classify($fixture, $section, $test);
                self::assertSame(ConformanceHarness::SKIP_PROTO2, $result->status, $fixture . '/' . $section . '/' . $test['name']);
                self::assertStringContainsString('proto2', $result->reason);
                $count++;
            }
        }

        self::assertGreaterThan(0, $count);
    }

    public function testExtensionFixturesPass(): void
    {
        $harness = new ConformanceHarness();
        $count = 0;

        foreach (['bindings_ext', 'block_ext', 'encoders_ext', 'math_ext', 'network_ext', 'string_ext'] as $fixture) {
            foreach ($this->fixtureTests($fixture) as [$section, $test]) {
                $result = $harness->classify($fixture, $section, $test);
                self::assertSame(ConformanceHarness::PASS, $result->status, $fixture . '/' . $section . '/' . $test['name'] . ': ' . $result->reason);
                $count++;
            }
        }

        self::assertGreaterThan(0, $count);
    }

    public function testAllImportedFixturesHaveNoUnexpectedFailures(): void
    {
        $harness = new ConformanceHarness();
        $unexpected = [];
        $counts = [];

        foreach (glob(__DIR__ . '/fixtures/json/*.json') ?: [] as $path) {
            $fixture = basename($path, '.json');
            foreach ($this->fixtureTests($fixture) as [$section, $test]) {
                $result = $harness->classify($fixture, $section, $test);
                $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;

                if (in_array($result->status, [ConformanceHarness::FAIL, ConformanceHarness::SKIP_UNSUPPORTED_EXTENSION], true)) {
                    $unexpected[] = sprintf('%s/%s/%s: %s', $fixture, $section, (string) ($test['name'] ?? ''), $result->reason);
                }
            }
        }

        self::assertGreaterThan(0, array_sum($counts));
        self::assertArrayNotHasKey(ConformanceHarness::FAIL, $counts);
        self::assertArrayNotHasKey(ConformanceHarness::SKIP_UNSUPPORTED_EXTENSION, $counts);
        self::assertSame([], $unexpected);
    }

    public function testPassingValueFixturesRoundTripThroughProtobufAst(): void
    {
        $harness = new ConformanceHarness();
        $failures = [];
        $parsedCount = 0;
        $checkedCount = 0;

        foreach (glob(__DIR__ . '/fixtures/json/*.json') ?: [] as $path) {
            $fixture = basename($path, '.json');
            foreach ($this->fixtureTests($fixture) as [$section, $test]) {
                $result = $harness->classify($fixture, $section, $test);
                if ($result->status !== ConformanceHarness::PASS || !$this->isRoundTrippableValueFixture($test)) {
                    continue;
                }

                try {
                    $runtime = $harness->environmentForTest($fixture, $section, $test);
                    $ast = !empty($test['disableCheck'])
                        ? $runtime->parse((string) $test['expr'])
                        : $runtime->compile((string) $test['expr']);
                    $bindings = $harness->bindingsFor($test['bindings'] ?? []);
                    $expected = isset($test['value']) ? $harness->decodeValue($test['value']) : true;

                    $parsed = Ast::fromParsedExpr($ast->toParsedExpr());
                    $actual = $runtime->program($parsed)->eval($bindings);
                    if (!$harness->valuesEqual($expected, $actual)) {
                        $failures[] = sprintf(
                            '%s/%s/%s parsed: expected %s, got %s',
                            $fixture,
                            $section,
                            (string) ($test['name'] ?? ''),
                            $harness->debugValue($expected),
                            $harness->debugValue($actual),
                        );
                    }
                    $parsedCount++;

                    if ($ast instanceof CheckedAst) {
                        $checked = Ast::fromCheckedExpr($ast->toCheckedExpr());
                        $actual = $runtime->program($checked)->eval($bindings);
                        if (!$harness->valuesEqual($expected, $actual)) {
                            $failures[] = sprintf(
                                '%s/%s/%s checked: expected %s, got %s',
                                $fixture,
                                $section,
                                (string) ($test['name'] ?? ''),
                                $harness->debugValue($expected),
                                $harness->debugValue($actual),
                            );
                        }
                        $checkedCount++;
                    }
                } catch (\Throwable $exception) {
                    $failures[] = sprintf('%s/%s/%s: %s', $fixture, $section, (string) ($test['name'] ?? ''), $exception->getMessage());
                }

                if (count($failures) >= 20) {
                    break 2;
                }
            }
        }

        self::assertSame([], $failures);
        self::assertGreaterThan(1700, $parsedCount);
        self::assertGreaterThan(1500, $checkedCount);
    }

    /** @param array<string, mixed> $test */
    private function isRoundTrippableValueFixture(array $test): bool
    {
        return !isset($test['evalError'])
            && !isset($test['anyEvalErrors'])
            && !isset($test['parseError'])
            && !isset($test['checkError'])
            && !isset($test['typedResult'])
            && empty($test['checkOnly'])
            && (isset($test['value']) || (!isset($test['unknown']) && !isset($test['anyUnknowns'])));
    }

    /** @return iterable<string, array{0:string,1:string,2:string}> */
    public static function passingExpandedFixtureCases(): iterable
    {
        yield 'proto3 literal uint32' => ['proto3', 'literal_singular', 'uint32'];
        yield 'proto3 well-known struct' => ['proto3', 'literal_wellknown', 'struct'];
        yield 'wrappers any' => ['wrappers', 'bool', 'to_any'];
        yield 'wrappers json value' => ['wrappers', 'bool', 'to_json'];
        yield 'wrappers uint64 json string' => ['wrappers', 'uint64', 'to_json_string'];
        yield 'wrappers null clearing' => ['wrappers', 'bool', 'to_null'];
        yield 'dynamic float32 literal narrowing' => ['dynamic', 'float', 'literal_not_double'];
        yield 'dynamic struct object binding' => ['dynamic', 'struct', 'var'];
        yield 'strong enum literal' => ['enums', 'strong_proto3', 'literal_nested'];
        yield 'strong enum string conversion' => ['enums', 'strong_proto3', 'convert_string'];
        yield 'type deduction proto map field' => ['type_deduction', 'field_access', 'map_bool_int'];
        yield 'runtime error or true' => ['basic', 'variables', 'unbound_is_runtime_error'];
        yield 'fields map key' => ['fields', 'map_fields', 'map_key_int64'];
        yield 'fields mixed numeric key' => ['fields', 'map_fields', 'map_key_mixed_numbers_double_key'];
        yield 'fields missing key or true' => ['fields', 'map_fields', 'map_no_such_key_or_true'];
        yield 'fields quoted selector' => ['fields', 'quoted_map_fields', 'field_access_dash'];
        yield 'fields dotted prefix binding' => ['fields', 'qualified_identifier_resolution', 'map_field_select'];
        yield 'fields duplicate key error' => ['fields', 'qualified_identifier_resolution', 'map_value_repeat_key'];
        yield 'comparison double nan equality' => ['comparisons', 'eq_literal', 'not_eq_double_nan'];
        yield 'comparison double nan inequality' => ['comparisons', 'ne_literal', 'ne_double_nan'];
        yield 'comparison bytes less than' => ['comparisons', 'lt_literal', 'lt_bytes'];
        yield 'comparison bool less than' => ['comparisons', 'lt_literal', 'lt_bool_false_first'];
        yield 'comparison lossy int double less than' => ['comparisons', 'lt_literal', 'not_lt_dyn_int_big_lossy_double'];
        yield 'comparison lossy int double lte' => ['comparisons', 'lte_literal', 'lte_dyn_int_big_double'];
        yield 'comparison uint double less than' => ['comparisons', 'lt_literal', 'lt_dyn_uint_big_double'];
        yield 'fp math divide by zero infinity' => ['fp_math', 'fp_math', 'divide_zero'];
        yield 'fp math positive overflow' => ['fp_math', 'fp_math', 'fp_overflow_positive'];
        yield 'fp math negative overflow' => ['fp_math', 'fp_math', 'fp_overflow_negative'];
        yield 'macro exists map key' => ['macros', 'exists', 'map_key'];
        yield 'macro all error short-circuit' => ['macros', 'all', 'list_elem_error_shortcircuit'];
        yield 'macro exists one error exhaustive' => ['macros', 'exists_one', 'list_no_shortcircuit'];
        yield 'macro exists one map key' => ['macros', 'exists_one', 'map_one'];
        yield 'macro map extracts keys' => ['macros', 'map', 'map_extract_keys'];
        yield 'macro filter map keys' => ['macros', 'filter', 'map_filter_keys'];
        yield 'macro v2 exists list index value' => ['macros2', 'exists', 'list_elem_some_true'];
        yield 'macro v2 all list index value' => ['macros2', 'all', 'list_elem_all_true'];
        yield 'macro v2 existsOne alias map' => ['macros2', 'existsOne', 'map_one'];
        yield 'macro v2 transformList filter' => ['macros2', 'transformList', 'many_filter'];
        yield 'macro v2 transformMap filter' => ['macros2', 'transformMap', 'many_filter'];
        yield 'parse reserved selector' => ['parse', 'selectors', 'as'];
        yield 'parse reserved receiver function name' => ['parse', 'receiver_function_names', 'function'];
        yield 'parse reserved struct field name' => ['parse', 'struct_field_names', 'while'];
        yield 'plumbing minimal program' => ['plumbing', 'min', 'min_program'];
        yield 'plumbing skip check' => ['plumbing', 'check_inputs', 'skip_check'];
        yield 'network parse ipv4' => ['network_ext', 'ip_type', 'parse_ipv4'];
        yield 'network ipv6 noncanonical' => ['network_ext', 'ip_type', 'ip_is_canonical_non_canonical_ipv6'];
        yield 'network cidr contains ip' => ['network_ext', 'cidr', 'cidr_contains_ip_ipv4_string'];
        yield 'network cidr mask' => ['network_ext', 'cidr', 'cidr_masked_ipv4'];
        yield 'type bool' => ['conversions', 'type', 'bool'];
        yield 'type list' => ['conversions', 'type', 'list'];
        yield 'type map' => ['conversions', 'type', 'map'];
        yield 'conversion bool string' => ['conversions', 'bool', 'string_true_uppercase'];
        yield 'conversion bool bad case error' => ['conversions', 'bool', 'string_true_badcase'];
        yield 'conversion uint double truncates' => ['conversions', 'uint', 'double_truncate'];
        yield 'conversion bytes invalid utf8' => ['conversions', 'string', 'bytes_invalid'];
        yield 'conversion duration identity' => ['conversions', 'identity', 'duration'];
        yield 'list dynamic double index' => ['lists', 'index', 'zero_based_double'];
        yield 'list dynamic uint index' => ['lists', 'index', 'zero_based_uint'];
        yield 'proto3 default uint field' => ['proto3', 'empty_field', 'scalar'];
        yield 'proto3 default nested message subfield' => ['proto3', 'empty_field', 'nested_message_subfield'];
        yield 'proto3 repeated has false' => ['proto3', 'has', 'repeated_none_implicit'];
        yield 'proto3 repeated has true' => ['proto3', 'has', 'repeated_one'];
        yield 'proto3 timestamp null pruning' => ['proto3', 'set_null', 'repeated_field_timestamp_null_pruned'];
        yield 'proto3 map duration null pruning' => ['proto3', 'set_null', 'map_duration_null_pruned'];
        yield 'timestamp to int' => ['timestamps', 'timestamp_conversions', 'toInt_timestamp'];
        yield 'timestamp to string' => ['timestamps', 'timestamp_conversions', 'toString_timestamp'];
        yield 'timestamp nanos to string' => ['timestamps', 'timestamp_conversions', 'toString_timestamp_nanos'];
        yield 'timestamp type' => ['timestamps', 'timestamp_conversions', 'toType_timestamp'];
        yield 'timestamp type comparison' => ['timestamps', 'timestamp_conversions', 'type_comparison'];
        yield 'duration to string' => ['timestamps', 'duration_conversions', 'toString_duration'];
        yield 'duration type' => ['timestamps', 'duration_conversions', 'toType_duration'];
        yield 'duration type comparison' => ['timestamps', 'duration_conversions', 'type_comparison'];
        yield 'timestamp get date' => ['timestamps', 'timestamp_selectors', 'getDate'];
        yield 'timestamp get zero based month' => ['timestamps', 'timestamp_selectors', 'getMonth'];
        yield 'timestamp get millis' => ['timestamps', 'timestamp_selectors', 'getMilliseconds'];
        yield 'timestamp timezone date' => ['timestamps', 'timestamp_selectors_tz', 'getDate'];
        yield 'timestamp timezone minutes' => ['timestamps', 'timestamp_selectors_tz', 'getMinutes'];
        yield 'duration hours' => ['timestamps', 'duration_converters', 'get_hours'];
        yield 'duration milliseconds component' => ['timestamps', 'duration_converters', 'get_milliseconds'];
        yield 'timestamp plus duration' => ['timestamps', 'timestamp_arithmetic', 'add_duration_to_time'];
        yield 'duration plus duration' => ['timestamps', 'timestamp_arithmetic', 'add_duration_to_duration'];
        yield 'timestamp minus timestamp' => ['timestamps', 'timestamp_arithmetic', 'subtract_time_from_time'];
        yield 'timestamp under range' => ['timestamps', 'timestamp_range', 'from_string_under'];
        yield 'duration over range' => ['timestamps', 'duration_range', 'from_string_over'];
        yield 'timestamp equality' => ['timestamps', 'timestamp_equality', 'eq_same'];
        yield 'duration equality' => ['timestamps', 'duration_equality', 'eq_same'];
        yield 'bindings extension bind shadows env' => ['bindings_ext', 'bind', 'shadowing'];
        yield 'bindings extension bind with macro' => ['bindings_ext', 'bind', 'macro_exists'];
        yield 'block extension sequential index' => ['block_ext', 'basic', 'int_add'];
        yield 'block extension nested macro vars' => ['block_ext', 'basic', 'nested_macros_2'];
        yield 'block extension optional message' => ['block_ext', 'basic', 'optional_message'];
        yield 'base64 encode extension' => ['encoders_ext', 'encode', 'hello'];
        yield 'base64 decode extension without padding' => ['encoders_ext', 'decode', 'hello_without_padding'];
        yield 'math extension greatest array' => ['math_ext', 'greatest_int_result', 'quaternary_mixed_array'];
        yield 'math extension uint bit not' => ['math_ext', 'bit_not', 'uint_zero'];
        yield 'math extension logical right shift' => ['math_ext', 'bit_shift_right', 'int_negative'];
        yield 'string extension reverse unicode' => ['string_ext', 'reverse', 'multiple'];
        yield 'string extension format map' => ['string_ext', 'format', 'map support for string'];
        yield 'string extension quote unicode' => ['string_ext', 'quote', 'unicode_code_points'];
    }

    #[DataProvider('passingExpandedFixtureCases')]
    public function testSelectedExpandedFixtureCasesPass(string $fixture, string $section, string $name): void
    {
        $test = $this->findFixtureTest($fixture, $section, $name);
        $result = (new ConformanceHarness())->classify($fixture, $section, $test);

        self::assertSame(ConformanceHarness::PASS, $result->status, $result->reason);
    }

    /** @return iterable<array{0:string,1:array<string,mixed>}> */
    private function fixtureTests(string $fixture): iterable
    {
        $json = $this->loadFixture($fixture);
        foreach ($json['section'] ?? [] as $section) {
            foreach ($section['test'] ?? [] as $test) {
                yield [(string) $section['name'], $test];
            }
        }
    }

    /** @return array<string, mixed> */
    private function findFixtureTest(string $fixture, string $sectionName, string $testName): array
    {
        foreach ($this->loadFixture($fixture)['section'] ?? [] as $section) {
            if (($section['name'] ?? '') !== $sectionName) {
                continue;
            }
            foreach ($section['test'] ?? [] as $test) {
                if (($test['name'] ?? '') === $testName) {
                    return $test;
                }
            }
        }

        self::fail(sprintf('Missing fixture case %s/%s/%s', $fixture, $sectionName, $testName));
    }

    /** @return array<string, mixed> */
    private function loadFixture(string $fixture): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/fixtures/json/' . $fixture . '.json') ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
