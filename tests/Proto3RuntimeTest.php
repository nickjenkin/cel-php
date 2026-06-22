<?php

declare(strict_types=1);

namespace CEL\Tests;

use CEL\CheckException;
use CEL\Environment;
use CEL\Tests\Support\Proto3ConformanceRegistry;
use CEL\TimestampValue;
use CEL\UInt;
use PHPUnit\Framework\TestCase;

final class Proto3RuntimeTest extends TestCase
{
    public function testWellKnownTypesAreAvailableInStandardEnvironment(): void
    {
        $this->assertWellKnownTypes(Environment::standard());
    }

    public function testWellKnownTypesAreAvailableInBuilderEnvironment(): void
    {
        $this->assertWellKnownTypes(Environment::builder()->build());
    }

    public function testConformanceTypesAreNotAvailableByDefault(): void
    {
        $this->expectException(CheckException::class);

        Environment::standard()->compile('TestAllTypes{}');
    }

    public function testTestOnlyRegistryEnablesConformanceTypes(): void
    {
        $message = $this->eval('TestAllTypes{single_string: "fixture"}');

        self::assertInstanceOf(\CEL\Generated\Expr\Conformance\Proto3\TestAllTypes::class, $message);
    }

    public function testBuilderRegistersMessageAndEnumTypes(): void
    {
        $env = Environment::builder()
            ->messageType('cel.expr.conformance.proto3.TestAllTypes', \CEL\Generated\Expr\Conformance\Proto3\TestAllTypes::class, ['TestAllTypes'])
            ->enumType('cel.expr.conformance.proto3.TestAllTypes.NestedEnum', \CEL\Generated\Expr\Conformance\Proto3\TestAllTypes\NestedEnum::class, ['TestAllTypes.NestedEnum'])
            ->build();

        self::assertSame('ok', $env->program($env->compile('TestAllTypes{single_string: "ok"}.single_string'))->eval());
        self::assertSame(1, $env->program($env->compile('TestAllTypes.NestedEnum.BAR'))->eval());
    }

    public function testProto3MessageConstructionAndFieldSelection(): void
    {
        $message = $this->eval('TestAllTypes{single_int64: 123, single_string: "abc"}');

        self::assertInstanceOf(\CEL\Generated\Expr\Conformance\Proto3\TestAllTypes::class, $message);
        self::assertSame('abc', $this->eval('TestAllTypes{single_int64: 123, single_string: "abc"}.single_string'));
        self::assertSame(123, $this->eval('TestAllTypes{single_int64: 123, single_string: "abc"}.single_int64'));
    }

    public function testNestedMessagesAndEnumConstants(): void
    {
        self::assertSame(
            7,
            $this->eval('TestAllTypes{single_nested_message: TestAllTypes.NestedMessage{bb: 7}}.single_nested_message.bb'),
        );
        self::assertSame(1, $this->eval('TestAllTypes.NestedEnum.BAR'));
        self::assertSame(2, $this->eval('cel.expr.conformance.proto3.GlobalEnum.GAZ'));
        self::assertSame(1, $this->eval('TestAllTypes{single_nested_enum: TestAllTypes.NestedEnum.BAR}.single_nested_enum'));
    }

    public function testOptionalFieldsAndOneofsUseGeneratedPresence(): void
    {
        self::assertTrue($this->eval('has(TestAllTypes{optional_bool: false}.optional_bool)'));
        self::assertFalse($this->eval('has(TestAllTypes{}.optional_bool)'));
        self::assertFalse($this->eval('TestAllTypes{}.optional_bool'));

        self::assertTrue($this->eval('has(TestAllTypes{oneof_bool: false}.oneof_bool)'));
        self::assertFalse($this->eval('has(TestAllTypes{}.oneof_bool)'));
        self::assertFalse($this->eval('TestAllTypes{oneof_bool: false}.oneof_bool'));
    }

    public function testRepeatedAndMapFieldsAreCelCollections(): void
    {
        self::assertSame(2, $this->eval('TestAllTypes{repeated_int32: [1, 2, 3]}.repeated_int32[1]'));
        self::assertSame(3, $this->eval('size(TestAllTypes{repeated_int32: [1, 2, 3]}.repeated_int32)'));
        self::assertTrue($this->eval('2 in TestAllTypes{repeated_int32: [1, 2, 3]}.repeated_int32'));
        self::assertSame('b', $this->eval('TestAllTypes{map_string_string: {"a": "b"}}.map_string_string["a"]'));
        self::assertTrue($this->eval('"a" in TestAllTypes{map_string_string: {"a": "b"}}.map_string_string'));
        self::assertFalse($this->eval('has(TestAllTypes{}.repeated_int32)'));
        self::assertTrue($this->eval('has(TestAllTypes{repeated_int32: [1]}.repeated_int32)'));
        self::assertFalse($this->eval('has(TestAllTypes{}.map_string_string)'));
        self::assertTrue($this->eval('has(TestAllTypes{map_string_string: {"one": "uno"}}.map_string_string)'));
    }

    public function testReservedWordsAreAllowedAsProtoFieldNames(): void
    {
        self::assertTrue($this->eval('TestAllTypes{as: true}.as'));
        self::assertTrue($this->eval('TestAllTypes{break: true}.break'));
        self::assertTrue($this->eval('TestAllTypes{function: true}.function'));
    }

    public function testWrappersUseNullablePrimitiveSemantics(): void
    {
        self::assertSame(9, $this->eval('TestAllTypes{single_int64_wrapper: google.protobuf.Int64Value{value: 9}}.single_int64_wrapper'));
        self::assertNull($this->eval('TestAllTypes{}.single_int64_wrapper'));
        self::assertSame('wrapped', $this->eval('TestAllTypes{single_string_wrapper: "wrapped"}.single_string_wrapper'));
        self::assertTrue($this->eval('google.protobuf.BoolValue{value: false} == false'));
        self::assertSame(
            '18446744073709551615',
            $this->eval('TestAllTypes{single_value: google.protobuf.UInt64Value{value: 18446744073709551615u}}.single_value'),
        );
    }

    public function testAnyStructValueAndListValueWellKnownTypes(): void
    {
        self::assertSame('packed', $this->eval('TestAllTypes{single_any: TestAllTypes{single_string: "packed"}}.single_any.single_string'));
        self::assertSame([], $this->eval('TestAllTypes{single_any: []}.single_any'));
        self::assertSame('nick', $this->eval('google.protobuf.Struct{fields: {"name": google.protobuf.Value{string_value: "nick"}}}.name'));
        self::assertSame([1.5], $this->eval('google.protobuf.Value{list_value: google.protobuf.ListValue{values: [google.protobuf.Value{number_value: 1.5}]}}'));
        self::assertSame(['x' => true], $this->eval('google.protobuf.Value{struct_value: google.protobuf.Struct{fields: {"x": google.protobuf.Value{bool_value: true}}}}'));
        self::assertSame('foo,bar', $this->eval('TestAllTypes{single_value: google.protobuf.FieldMask{paths: ["foo", "bar"]}}.single_value'));
        self::assertSame([], $this->eval('TestAllTypes{single_value: google.protobuf.Empty{}}.single_value'));
    }

    public function testTimestampAndDurationWellKnownTypes(): void
    {
        self::assertTrue($this->eval('TestAllTypes{single_timestamp: timestamp("2020-01-02T03:04:05Z")}.single_timestamp == timestamp("2020-01-02T03:04:05Z")'));
        self::assertTrue($this->eval('TestAllTypes{single_duration: duration("1.5s")}.single_duration == duration("1.5s")'));

        $timestamp = $this->eval('google.protobuf.Timestamp{seconds: 1577934245}.seconds');
        self::assertSame(1577934245, $timestamp);
        self::assertInstanceOf(TimestampValue::class, $this->eval('TestAllTypes{single_timestamp: timestamp("2020-01-02T03:04:05Z")}.single_timestamp'));
    }

    public function testProto3DefaultFieldsAndNullPruning(): void
    {
        $fixed32 = $this->eval('TestAllTypes{}.single_fixed32');
        self::assertInstanceOf(UInt::class, $fixed32);
        self::assertSame('0', $fixed32->value());
        self::assertSame(0, $this->eval('TestAllTypes{}.single_nested_message.bb'));
        self::assertFalse($this->eval('has(TestAllTypes{}.single_int32)'));
        self::assertTrue($this->eval('has(TestAllTypes{single_int32: 16}.single_int32)'));
        self::assertFalse($this->eval('has(TestAllTypes{single_int32: 0}.single_int32)'));
        self::assertTrue($this->eval('TestAllTypes{repeated_timestamp: [timestamp(1), null]}.repeated_timestamp == [timestamp(1)]'));
        self::assertTrue($this->eval('TestAllTypes{map_bool_duration: {true: null, false: duration("1s")}}.map_bool_duration == {false: duration("1s")}'));
    }

    private function eval(string $expression): mixed
    {
        $env = Environment::builder()
            ->protoRegistry(Proto3ConformanceRegistry::create())
            ->build();

        return $env->program($env->compile($expression))->eval();
    }

    private function assertWellKnownTypes(Environment $env): void
    {
        self::assertSame(1, $env->program($env->compile('google.protobuf.Timestamp{seconds: 1}.seconds'))->eval());
        self::assertSame(1, $env->program($env->compile('google.protobuf.Duration{seconds: 1}.seconds'))->eval());
        self::assertTrue($env->program($env->compile('google.protobuf.Int64Value{value: 9} == 9'))->eval());
        self::assertInstanceOf(\Google\Protobuf\Any::class, $env->program($env->compile('google.protobuf.Any{type_url: "type.googleapis.com/acme.Message", value: b""}'))->eval());
        self::assertSame('nick', $env->program($env->compile('google.protobuf.Struct{fields: {"name": google.protobuf.Value{string_value: "nick"}}}.name'))->eval());
        self::assertSame(['x' => true], $env->program($env->compile('google.protobuf.Value{struct_value: google.protobuf.Struct{fields: {"x": google.protobuf.Value{bool_value: true}}}}'))->eval());
        self::assertSame('a', $env->program($env->compile('google.protobuf.ListValue{values: [google.protobuf.Value{string_value: "a"}]}[0]'))->eval());
        self::assertNull($env->program($env->compile('google.protobuf.Value{null_value: google.protobuf.NullValue.NULL_VALUE}'))->eval());
    }
}
