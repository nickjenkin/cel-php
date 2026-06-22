<?php

declare(strict_types=1);

namespace CEL\Tests;

use CEL\Ast;
use CEL\CheckException;
use CEL\DurationValue;
use CEL\Environment;
use CEL\EvaluationException;
use CEL\FunctionDeclaration;
use CEL\Overload;
use CEL\Tests\Support\Proto3ConformanceRegistry;
use CEL\TimestampValue;
use CEL\Type;
use CEL\UnsupportedFeatureException;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testReadmeExample(): void
    {
        $env = Environment::builder()
            ->variable('name', Type::string())
            ->variable('group', Type::string())
            ->build();

        $program = $env->program($env->compile('name.startsWith("/groups/" + group)'));

        self::assertTrue($program->eval([
            'name' => '/groups/acme.co/documents/secret',
            'group' => 'acme.co',
        ]));
    }

    public function testUndeclaredIdentifierFailsAtCheckTime(): void
    {
        $this->expectException(CheckException::class);

        Environment::standard()->compile('missing + 1');
    }

    public function testGeneratedProtoInteropObjectsAreAvailable(): void
    {
        $ast = Environment::standard()->compile('1 + 1');

        $parsed = $ast->toParsedExpr();
        $checked = $ast->toCheckedExpr();

        self::assertInstanceOf(\CEL\Generated\Expr\ParsedExpr::class, $parsed);
        self::assertInstanceOf(\CEL\Generated\Expr\CheckedExpr::class, $checked);
        self::assertTrue($parsed->hasExpr());
        self::assertTrue($checked->hasExpr());
        self::assertSame('_+_', $parsed->getExpr()->getCallExpr()->getFunction());
    }

    public function testParsedExprCanRoundTripBackToExecutableAst(): void
    {
        $env = Environment::builder()
            ->variable('x', Type::int())
            ->build();

        $parsed = $env->parse('x == 1 ? {"ok": [x + 1]}["ok"][0] : 0')->toParsedExpr();
        $imported = Ast::fromParsedExpr($parsed);

        self::assertSame(2, $env->program($imported)->eval(['x' => 1]));
        self::assertSame(0, $env->program($imported)->eval(['x' => 2]));
        self::assertSame($parsed->getExpr()->serializeToJsonString(), $imported->toParsedExpr()->getExpr()->serializeToJsonString());
    }

    public function testParsedExprImportHandlesOptionalSelectAndIndexCalls(): void
    {
        $env = Environment::standard();
        $parsed = $env->parse("{'a': {'b': ['c']}}.?a.b[?0].orValue('x')")->toParsedExpr();
        $imported = Ast::fromParsedExpr($parsed);

        self::assertSame('c', $env->program($imported)->eval());
    }

    public function testContainerResolvesNamespacedIdentifiers(): void
    {
        $env = Environment::builder()
            ->container('com.example')
            ->variable('com.example.y', Type::bool())
            ->variable('y', Type::string())
            ->build();

        $activation = [
            'com.example.y' => true,
            'y' => 'root',
        ];

        $checked = $env->compile('y');
        self::assertSame('bool', $checked->resultType()->name());
        self::assertTrue($env->program($checked)->eval($activation));

        self::assertTrue($env->program($env->parse('y'))->eval($activation));
        self::assertSame('root', $env->program($env->compile('.y'))->eval($activation));
        self::assertTrue($env->program($env->compile("['local'].exists(y, .y == 'root' && y == 'local')"))->eval($activation));
    }

    public function testCheckedExprCanRoundTripBackToExecutableAst(): void
    {
        $env = Environment::builder()
            ->protoRegistry(Proto3ConformanceRegistry::create())
            ->variable('x', Type::int())
            ->build();

        $checked = $env->compile('TestAllTypes{single_int64: x}.single_int64 + 1')->toCheckedExpr();
        $imported = Ast::fromCheckedExpr($checked);

        self::assertSame(4, $env->program($imported)->eval(['x' => 3]));
    }

    public function testProtobufAstRoundTripsInt64AndUint64Boundaries(): void
    {
        $env = Environment::standard();

        foreach ([
            ['-9223372036854775808', '-9223372036854775808'],
            ['18446744073709551615u', '18446744073709551615u'],
        ] as [$expression, $expected]) {
            $parsed = Ast::fromParsedExpr($env->parse($expression)->toParsedExpr());
            self::assertSame($expected, (string) $env->program($parsed)->eval(), $expression . ' parsed');

            $checked = Ast::fromCheckedExpr($env->compile($expression)->toCheckedExpr());
            self::assertSame($expected, (string) $env->program($checked)->eval(), $expression . ' checked');
        }
    }

    public function testParsedExprImportLiftsTestOnlySelectToHasMacro(): void
    {
        $operand = (new \CEL\Generated\Expr\Expr())
            ->setId(1)
            ->setIdentExpr((new \CEL\Generated\Expr\Expr\Ident())->setName('m'));
        $expr = (new \CEL\Generated\Expr\Expr())
            ->setId(2)
            ->setSelectExpr(
                (new \CEL\Generated\Expr\Expr\Select())
                    ->setOperand($operand)
                    ->setField('name')
                    ->setTestOnly(true),
            );
        $parsed = (new \CEL\Generated\Expr\ParsedExpr())
            ->setExpr($expr)
            ->setSourceInfo(
                (new \CEL\Generated\Expr\SourceInfo())
                    ->setLocation('<test>')
                    ->setPositions([1 => 0, 2 => 0]),
            );

        $env = Environment::builder()
            ->variable('m', Type::map(Type::string(), Type::dyn()))
            ->build();
        $program = $env->program(Ast::fromParsedExpr($parsed));

        self::assertTrue($program->eval(['m' => ['name' => 'nick']]));
        self::assertFalse($program->eval(['m' => []]));
    }

    public function testParsedExprImportRoundTripsTimestampAndDurationConstants(): void
    {
        $timestampConstant = new \CEL\Generated\Expr\Constant();
        @$timestampConstant->setTimestampValue(
            (new \Google\Protobuf\Timestamp())
                ->setSeconds(1577934245)
                ->setNanos(123_000_000),
        );
        $durationConstant = new \CEL\Generated\Expr\Constant();
        @$durationConstant->setDurationValue(
            (new \Google\Protobuf\Duration())
                ->setSeconds(1)
                ->setNanos(500_000_000),
        );

        $parsed = (new \CEL\Generated\Expr\ParsedExpr())
            ->setExpr(
                (new \CEL\Generated\Expr\Expr())
                    ->setId(1)
                    ->setListExpr(
                        (new \CEL\Generated\Expr\Expr\CreateList())
                            ->setElements([
                                (new \CEL\Generated\Expr\Expr())->setId(2)->setConstExpr($timestampConstant),
                                (new \CEL\Generated\Expr\Expr())->setId(3)->setConstExpr($durationConstant),
                            ]),
                    ),
            );

        $env = Environment::standard();
        $imported = Ast::fromParsedExpr($parsed);
        $roundTripped = Ast::fromParsedExpr($imported->toParsedExpr());

        $values = $env->program($roundTripped)->eval();
        self::assertInstanceOf(TimestampValue::class, $values[0]);
        self::assertInstanceOf(DurationValue::class, $values[1]);
        self::assertSame('2020-01-02T03:04:05.123Z', (string) $values[0]);
        self::assertSame('1.5s', (string) $values[1]);
    }

    public function testParsedExprImportExecutesLoweredListComprehension(): void
    {
        $parsed = (new \CEL\Generated\Expr\ParsedExpr())
            ->setExpr($this->comprehensionExpr(
                iterVar: 'x',
                iterVar2: '',
                accuVar: 'acc',
                range: $this->listExpr([$this->intExpr(1), $this->intExpr(2), $this->intExpr(3), $this->intExpr(4)]),
                init: $this->intExpr(0),
                condition: $this->binaryExpr('<', $this->identExpr('acc'), $this->intExpr(3)),
                step: $this->binaryExpr('+', $this->identExpr('acc'), $this->identExpr('x')),
                result: $this->identExpr('acc'),
            ));

        $env = Environment::standard();
        $ast = Ast::fromParsedExpr($parsed);

        self::assertSame(3, $env->program($ast)->eval());
        self::assertSame('int', $env->check($ast)->resultType()->name());
        self::assertTrue($ast->toParsedExpr()->getExpr()->hasComprehensionExpr());
    }

    public function testParsedExprImportExecutesLoweredMapComprehensionWithTwoVars(): void
    {
        $parsed = (new \CEL\Generated\Expr\ParsedExpr())
            ->setExpr($this->comprehensionExpr(
                iterVar: 'k',
                iterVar2: 'v',
                accuVar: 'acc',
                range: $this->mapExpr([
                    [$this->stringExpr('a'), $this->intExpr(1)],
                    [$this->stringExpr('b'), $this->intExpr(2)],
                ]),
                init: $this->intExpr(0),
                condition: $this->boolExpr(true),
                step: $this->binaryExpr(
                    '+',
                    $this->identExpr('acc'),
                    $this->conditionalExpr(
                        $this->binaryExpr('==', $this->identExpr('k'), $this->stringExpr('a')),
                        $this->identExpr('v'),
                        $this->intExpr(0),
                    ),
                ),
                result: $this->identExpr('acc'),
            ));

        $env = Environment::standard();
        $ast = Ast::fromParsedExpr($parsed);

        self::assertSame(1, $env->program($ast)->eval());
        self::assertSame('int', $env->check($ast)->resultType()->name());
    }

    public function testParsedExprImportRejectsMalformedComprehensionAst(): void
    {
        $parsed = (new \CEL\Generated\Expr\ParsedExpr())
            ->setExpr(
                (new \CEL\Generated\Expr\Expr())
                    ->setId(1)
                    ->setComprehensionExpr(new \CEL\Generated\Expr\Expr\Comprehension()),
            );

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('Comprehension expression does not contain iterRange');

        Ast::fromParsedExpr($parsed);
    }

    public function testCheckedExprCarriesTypeAndReferenceMaps(): void
    {
        $env = Environment::builder()
            ->protoRegistry(Proto3ConformanceRegistry::create())
            ->variable('x', Type::int())
            ->build();

        $checked = $env->compile('TestAllTypes{single_int64: x}.single_int64 == 1')->toCheckedExpr();

        self::assertGreaterThan(0, count($checked->getTypeMap()));
        self::assertGreaterThan(0, count($checked->getReferenceMap()));
    }

    public function testCheckedExprAnnotatesOperatorIndexAndProtoFieldTypes(): void
    {
        $env = Environment::builder()
            ->protoRegistry(Proto3ConformanceRegistry::create())
            ->build();

        $checked = $env
            ->compile('TestAllTypes{repeated_int64: [1, 2]}.repeated_int64[0] + 1')
            ->toCheckedExpr();
        [$types, $references] = $this->checkedMaps($checked);

        $root = $checked->getExpr();
        $index = iterator_to_array($root->getCallExpr()->getArgs())[0];
        $select = iterator_to_array($index->getCallExpr()->getArgs())[0];
        $message = $select->getSelectExpr()->getOperand();

        self::assertSame('_+_', $references[(int) $root->getId()]->getName());
        self::assertContains('_+_', iterator_to_array($references[(int) $root->getId()]->getOverloadId()));
        self::assertSame('_[_]', $references[(int) $index->getId()]->getName());
        self::assertContains('_[_]', iterator_to_array($references[(int) $index->getId()]->getOverloadId()));

        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $types[(int) $root->getId()]);
        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $types[(int) $index->getId()]);
        self::assertTrue($types[(int) $select->getId()]->hasListType());
        $this->assertPrimitiveProtoType(
            \CEL\Generated\Expr\Type\PrimitiveType::INT64,
            $types[(int) $select->getId()]->getListType()->getElemType(),
        );
        self::assertSame('cel.expr.conformance.proto3.TestAllTypes', $types[(int) $message->getId()]->getMessageType());
    }

    public function testCheckedExprAnnotatesStandardCallResultTypes(): void
    {
        $env = Environment::builder()
            ->variable('name', Type::string())
            ->build();

        $stringReceiver = $env->compile('name.startsWith("n")')->toCheckedExpr();
        $this->assertRootPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL, $stringReceiver);

        $macro = $env->compile('[1, 2].all(i, i > 0)')->toCheckedExpr();
        $this->assertRootPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL, $macro);

        $split = $env->compile('"a,b".split(",")')->toCheckedExpr();
        [$types] = $this->checkedMaps($split);
        $splitType = $types[(int) $split->getExpr()->getId()];
        self::assertTrue($splitType->hasListType());
        $this->assertPrimitiveProtoType(
            \CEL\Generated\Expr\Type\PrimitiveType::STRING,
            $splitType->getListType()->getElemType(),
        );

        $optional = $env->compile('optional.of("x").hasValue()')->toCheckedExpr();
        $this->assertRootPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::BOOL, $optional);

        $base64 = $env->compile('base64.encode(b"hi")')->toCheckedExpr();
        $this->assertRootPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::STRING, $base64);
    }

    public function testCheckedExprAnnotatesCustomFunctionResultTypes(): void
    {
        $env = Environment::builder()
            ->function(new FunctionDeclaration('fn', [
                new Overload(
                    'fn_string_int',
                    [Type::string(), Type::int()],
                    Type::string(),
                    static fn (): mixed => null,
                ),
            ]))
            ->build();

        $checked = $env->compile('fn("abc", 123)')->toCheckedExpr();

        $this->assertRootPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::STRING, $checked);
    }

    public function testCheckedExprAnnotatesFlexibleProtoTypeMetadata(): void
    {
        $env = Environment::builder()
            ->protoRegistry(Proto3ConformanceRegistry::create())
            ->variable('msg', Type::message('cel.expr.conformance.proto3.TestAllTypes'))
            ->build();

        $mapped = $env->compile('msg.repeated_nested_message.map(x, x).map(y, y.bb)')->toCheckedExpr();
        [$mappedTypes] = $this->checkedMaps($mapped);
        $mappedRoot = $mappedTypes[(int) $mapped->getExpr()->getId()];
        self::assertTrue($mappedRoot->hasListType(), 'expected list type');
        $this->assertPrimitiveProtoType(
            \CEL\Generated\Expr\Type\PrimitiveType::INT64,
            $mappedRoot->getListType()->getElemType(),
        );

        $optional = $env->compile('[optional.none(), optional.of(1)]')->toCheckedExpr();
        [$optionalTypes] = $this->checkedMaps($optional);
        $optionalRoot = $optionalTypes[(int) $optional->getExpr()->getId()];
        self::assertTrue($optionalRoot->hasListType(), 'expected list type');
        $optionalElem = $optionalRoot->getListType()->getElemType();
        self::assertTrue($optionalElem->hasAbstractType(), 'expected optional abstract type');
        self::assertSame('optional_type', $optionalElem->getAbstractType()->getName());
        $optionalParams = iterator_to_array($optionalElem->getAbstractType()->getParameterTypes());
        self::assertCount(1, $optionalParams);
        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $optionalParams[0]);

        $wrapperArithmetic = $env->compile('msg.single_int64_wrapper + 1')->toCheckedExpr();
        $this->assertRootPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $wrapperArithmetic);

        $dynSelect = Environment::standard()->compile('([].map(x, x))[0].foo')->toCheckedExpr();
        $this->assertRootDynProtoType($dynSelect);
    }

    public function testCheckedExprCarriesLexicalTypesForBlockAndSyntheticMacroVariables(): void
    {
        $env = Environment::standard();

        $block = $env->compile('cel.block([1, cel.index(0) + 1], cel.index(1))')->toCheckedExpr();
        [$blockTypes] = $this->checkedMaps($block);
        $blockRoot = $block->getExpr();
        $blockArgs = iterator_to_array($blockRoot->getCallExpr()->getArgs());
        $blockSequence = $blockArgs[0];
        $blockSequenceElements = iterator_to_array($blockSequence->getListExpr()->getElements());
        $blockResult = $blockArgs[1];

        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $blockTypes[(int) $blockRoot->getId()]);
        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $blockTypes[(int) $blockSequenceElements[1]->getId()]);
        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $blockTypes[(int) $blockResult->getId()]);

        $map = $env->compile('[1, 2].map(cel.iterVar(0, 0), cel.iterVar(0, 0) + 1)')->toCheckedExpr();
        [$mapTypes] = $this->checkedMaps($map);
        $mapRoot = $map->getExpr();
        $mapRootType = $mapTypes[(int) $mapRoot->getId()];
        $mapArgs = iterator_to_array($mapRoot->getCallExpr()->getArgs());
        $mapBody = $mapArgs[1];
        $iterVar = iterator_to_array($mapBody->getCallExpr()->getArgs())[0];

        self::assertTrue($mapRootType->hasListType(), 'expected map macro list type');
        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $mapRootType->getListType()->getElemType());
        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $mapTypes[(int) $mapBody->getId()]);
        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $mapTypes[(int) $iterVar->getId()]);

        $bind = $env->compile('cel.bind(x, 1, x + 2)')->toCheckedExpr();
        [$bindTypes] = $this->checkedMaps($bind);
        $bindRoot = $bind->getExpr();
        $bindBody = iterator_to_array($bindRoot->getCallExpr()->getArgs())[2];

        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $bindTypes[(int) $bindRoot->getId()]);
        $this->assertPrimitiveProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $bindTypes[(int) $bindBody->getId()]);
    }

    public function testCheckerDeducesTimestampAndDurationTypes(): void
    {
        $env = Environment::standard();

        $timestamp = $env->compile('timestamp("2009-02-13T23:31:30Z")');
        self::assertSame('google.protobuf.Timestamp', $timestamp->resultType()->messageType());
        $this->assertRootWellKnownProtoType(\CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP, $timestamp->toCheckedExpr());

        $duration = $env->compile('duration("1s")');
        self::assertSame('google.protobuf.Duration', $duration->resultType()->messageType());
        $this->assertRootWellKnownProtoType(\CEL\Generated\Expr\Type\WellKnownType::DURATION, $duration->toCheckedExpr());

        self::assertSame(
            'google.protobuf.Timestamp',
            $env->compile('timestamp("2009-02-13T23:31:30Z") + duration("1s")')->resultType()->messageType(),
        );
        self::assertSame(
            'google.protobuf.Duration',
            $env->compile('timestamp("2009-02-13T23:31:30Z") - timestamp("2009-02-13T23:31:29Z")')->resultType()->messageType(),
        );
    }

    public function testCheckedExprAnnotatesWrapperAndWellKnownProtoLiteralTypes(): void
    {
        $env = Environment::standard();

        $wrapper = $env->compile('google.protobuf.Int64Value{value: 9}')->toCheckedExpr();
        $this->assertRootWrapperProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $wrapper);

        $timestamp = $env->compile('google.protobuf.Timestamp{seconds: 1}')->toCheckedExpr();
        $this->assertRootWellKnownProtoType(\CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP, $timestamp);

        $envWithMessage = Environment::builder()
            ->protoRegistry(Proto3ConformanceRegistry::create())
            ->variable('msg', Type::message('cel.expr.conformance.proto3.TestAllTypes'))
            ->build();

        $selectedWrapper = $envWithMessage
            ->compile('TestAllTypes{single_int64_wrapper: google.protobuf.Int64Value{value: 9}}.single_int64_wrapper')
            ->toCheckedExpr();
        $this->assertRootWrapperProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $selectedWrapper);

        $wrapperPromotion = $envWithMessage->compile('[msg.single_int64_wrapper, msg.single_int64]')->toCheckedExpr();
        [$types] = $this->checkedMaps($wrapperPromotion);
        $rootType = $types[(int) $wrapperPromotion->getExpr()->getId()];
        self::assertTrue($rootType->hasListType(), 'expected list type');
        self::assertTrue($rootType->getListType()->getElemType()->hasWrapper(), 'expected wrapper element type');
        self::assertSame(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $rootType->getListType()->getElemType()->getWrapper());

        $wrapperNull = $envWithMessage->compile('false ? msg.single_int64_wrapper : null')->toCheckedExpr();
        $this->assertRootWrapperProtoType(\CEL\Generated\Expr\Type\PrimitiveType::INT64, $wrapperNull);

        $timestampNull = $envWithMessage->compile('[msg.single_timestamp, null][0]')->toCheckedExpr();
        $this->assertRootWellKnownProtoType(\CEL\Generated\Expr\Type\WellKnownType::TIMESTAMP, $timestampNull);
    }

    public function testCheckerRejectsInvalidStandardCallOverloads(): void
    {
        $env = Environment::builder()
            ->variable('names', Type::list(Type::string()))
            ->build();

        $this->assertCompileFails($env, '1.startsWith("x")', 'requires string target');
        $this->assertCompileFails($env, '"abc".startsWith(1)', 'startsWith expects string');
        $this->assertCompileFails($env, '"abc".substring("1")', 'substring expects int');
        $this->assertCompileFails($env, 'names.join(1)', 'join expects string');
        $this->assertCompileFails($env, 'size(1)', 'size expects string, bytes, list, or map');
        $this->assertCompileFails($env, 'timestamp("2009-02-13T23:31:30Z").getDate("UTC", "UTC")', 'getDate expects 0 or 1');
        $this->assertCompileFails($env, 'duration("1s").getDate()', 'requires timestamp');
        $this->assertCompileFails($env, 'duration("1s").getHours("UTC")', 'getHours expects 0');
        $this->assertCompileFails($env, 'math.ceil(1)', 'math.ceil expects double');
    }

    public function testCheckerValidatesProto3MessageLiteralFields(): void
    {
        $env = Environment::builder()
            ->protoRegistry(Proto3ConformanceRegistry::create())
            ->build();

        self::assertSame(
            'cel.expr.conformance.proto3.TestAllTypes',
            $env
                ->compile('TestAllTypes{single_nested_message: TestAllTypes.NestedMessage{bb: 7}, single_struct: {"one": 1}, single_value: google.protobuf.FieldMask{paths: ["foo"]}, single_any: [1], single_timestamp: "2020-01-02T03:04:05Z", single_duration: "1s"}')
                ->resultType()
                ->messageType(),
        );
        self::assertSame(
            'wrapper.int64',
            $env->compile('google.protobuf.Int64Value{value: 9}')->resultType()->messageType(),
        );

        $this->assertCompileFails($env, 'TestAllTypes{no_such_field: 1}', 'no such field "no_such_field"');
        $this->assertCompileFails($env, 'TestAllTypes{single_int64: 1, single_int64: 2}', 'duplicate message field "single_int64"');
        $this->assertCompileFails($env, 'TestAllTypes{single_int64: "bad"}', 'field "single_int64" expects int');
        $this->assertCompileFails($env, 'TestAllTypes{repeated_int64: ["bad"]}', 'field "repeated_int64" expects list(int)');
        $this->assertCompileFails($env, 'TestAllTypes{map_string_string: {"a": 1}}', 'field "map_string_string" expects map(string, string)');
        $this->assertCompileFails($env, 'TestAllTypes{single_nested_message: 1}', 'field "single_nested_message" expects cel.expr.conformance.proto3.TestAllTypes.NestedMessage');
        $this->assertCompileFails($env, 'google.protobuf.Int64Value{value: "bad"}', 'field "value" expects int');
    }

    public function testMacrosCanBeDisabledAtRuntime(): void
    {
        $env = Environment::builder()->disableMacros()->build();
        $program = $env->program($env->compile('[1, 2, 3].all(x, x > 0)'));

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('macro "all" is disabled');

        $program->eval();
    }

    private function intExpr(int $value): \CEL\Generated\Expr\Expr
    {
        return $this->expr()->setConstExpr((new \CEL\Generated\Expr\Constant())->setInt64Value($value));
    }

    private function stringExpr(string $value): \CEL\Generated\Expr\Expr
    {
        return $this->expr()->setConstExpr((new \CEL\Generated\Expr\Constant())->setStringValue($value));
    }

    private function boolExpr(bool $value): \CEL\Generated\Expr\Expr
    {
        return $this->expr()->setConstExpr((new \CEL\Generated\Expr\Constant())->setBoolValue($value));
    }

    private function identExpr(string $name): \CEL\Generated\Expr\Expr
    {
        return $this->expr()->setIdentExpr((new \CEL\Generated\Expr\Expr\Ident())->setName($name));
    }

    /** @param list<\CEL\Generated\Expr\Expr> $elements */
    private function listExpr(array $elements): \CEL\Generated\Expr\Expr
    {
        return $this->expr()->setListExpr((new \CEL\Generated\Expr\Expr\CreateList())->setElements($elements));
    }

    /** @param list<array{0: \CEL\Generated\Expr\Expr, 1: \CEL\Generated\Expr\Expr}> $entries */
    private function mapExpr(array $entries): \CEL\Generated\Expr\Expr
    {
        $protoEntries = [];
        foreach ($entries as [$key, $value]) {
            $protoEntries[] = (new \CEL\Generated\Expr\Expr\CreateStruct\Entry())
                ->setId($this->nextExprId())
                ->setMapKey($key)
                ->setValue($value);
        }

        return $this->expr()->setStructExpr((new \CEL\Generated\Expr\Expr\CreateStruct())->setEntries($protoEntries));
    }

    private function binaryExpr(string $operator, \CEL\Generated\Expr\Expr $left, \CEL\Generated\Expr\Expr $right): \CEL\Generated\Expr\Expr
    {
        return $this->callExpr(match ($operator) {
            '+', '-', '*', '/', '%', '==', '!=', '<', '<=', '>', '>=', 'in' => '_' . $operator . '_',
            '&&' => '_&&_',
            '||' => '_||_',
            default => throw new \InvalidArgumentException(sprintf('unsupported test operator "%s"', $operator)),
        }, [$left, $right]);
    }

    private function conditionalExpr(\CEL\Generated\Expr\Expr $condition, \CEL\Generated\Expr\Expr $then, \CEL\Generated\Expr\Expr $else): \CEL\Generated\Expr\Expr
    {
        return $this->callExpr('_?_:_', [$condition, $then, $else]);
    }

    /** @param list<\CEL\Generated\Expr\Expr> $args */
    private function callExpr(string $function, array $args): \CEL\Generated\Expr\Expr
    {
        return $this->expr()->setCallExpr(
            (new \CEL\Generated\Expr\Expr\Call())
                ->setFunction($function)
                ->setArgs($args),
        );
    }

    private function comprehensionExpr(
        string $iterVar,
        string $iterVar2,
        string $accuVar,
        \CEL\Generated\Expr\Expr $range,
        \CEL\Generated\Expr\Expr $init,
        \CEL\Generated\Expr\Expr $condition,
        \CEL\Generated\Expr\Expr $step,
        \CEL\Generated\Expr\Expr $result,
    ): \CEL\Generated\Expr\Expr {
        $comprehension = (new \CEL\Generated\Expr\Expr\Comprehension())
            ->setIterVar($iterVar)
            ->setAccuVar($accuVar)
            ->setIterRange($range)
            ->setAccuInit($init)
            ->setLoopCondition($condition)
            ->setLoopStep($step)
            ->setResult($result);

        if ($iterVar2 !== '') {
            $comprehension->setIterVar2($iterVar2);
        }

        return $this->expr()->setComprehensionExpr($comprehension);
    }

    private function expr(): \CEL\Generated\Expr\Expr
    {
        return (new \CEL\Generated\Expr\Expr())->setId($this->nextExprId());
    }

    /** @return array{0: array<int, \CEL\Generated\Expr\Type>, 1: array<int, \CEL\Generated\Expr\Reference>} */
    private function checkedMaps(\CEL\Generated\Expr\CheckedExpr $checked): array
    {
        $types = [];
        foreach ($checked->getTypeMap() as $id => $type) {
            $types[(int) $id] = $type;
        }

        $references = [];
        foreach ($checked->getReferenceMap() as $id => $reference) {
            $references[(int) $id] = $reference;
        }

        return [$types, $references];
    }

    private function assertPrimitiveProtoType(int $primitive, \CEL\Generated\Expr\Type $type): void
    {
        self::assertTrue($type->hasPrimitive(), 'expected primitive type');
        self::assertSame($primitive, $type->getPrimitive());
    }

    private function assertRootPrimitiveProtoType(int $primitive, \CEL\Generated\Expr\CheckedExpr $checked): void
    {
        [$types] = $this->checkedMaps($checked);

        $this->assertPrimitiveProtoType($primitive, $types[(int) $checked->getExpr()->getId()]);
    }

    private function assertRootDynProtoType(\CEL\Generated\Expr\CheckedExpr $checked): void
    {
        [$types] = $this->checkedMaps($checked);

        self::assertTrue($types[(int) $checked->getExpr()->getId()]->hasDyn(), 'expected dyn type');
    }

    private function assertRootWellKnownProtoType(int $wellKnown, \CEL\Generated\Expr\CheckedExpr $checked): void
    {
        [$types] = $this->checkedMaps($checked);
        $type = $types[(int) $checked->getExpr()->getId()];

        self::assertTrue($type->hasWellKnown(), 'expected well-known type');
        self::assertSame($wellKnown, $type->getWellKnown());
    }

    private function assertRootWrapperProtoType(int $primitive, \CEL\Generated\Expr\CheckedExpr $checked): void
    {
        [$types] = $this->checkedMaps($checked);
        $type = $types[(int) $checked->getExpr()->getId()];

        self::assertTrue($type->hasWrapper(), 'expected wrapper type');
        self::assertSame($primitive, $type->getWrapper());
    }

    private function assertCompileFails(Environment $env, string $expression, string $message): void
    {
        try {
            $env->compile($expression);
        } catch (CheckException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());

            return;
        }

        self::fail(sprintf('Expected "%s" to fail checking', $expression));
    }

    private function nextExprId(): int
    {
        static $id = 1000;

        return $id++;
    }
}
