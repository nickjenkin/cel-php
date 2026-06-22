<?php

declare(strict_types=1);

namespace CEL\Tests;

use CEL\Environment;
use CEL\EvaluationException;
use CEL\Tests\Support\Proto3ConformanceRegistry;
use CEL\Type;
use CEL\UnknownValue;
use PHPUnit\Framework\TestCase;

final class PartialEvaluationTest extends TestCase
{
    private int $nextExprId = 1;

    public function testPartialEvaluationReturnsKnownValues(): void
    {
        $env = Environment::builder()
            ->variable('x', Type::int())
            ->build();

        $result = $env->program($env->compile('x + 1'))->evalPartial(['x' => 2]);

        self::assertTrue($result->isKnown());
        self::assertSame(3, $result->value());
        self::assertNull($result->unknown());
        self::assertNull($result->error());
        self::assertNull($result->residualExpression());
    }

    public function testPartialEvaluationCapturesRuntimeErrors(): void
    {
        $result = Environment::standard()
            ->program(Environment::standard()->compile('1 / 0'))
            ->evalPartial();

        self::assertFalse($result->isKnown());
        self::assertNull($result->unknown());
        self::assertSame('division by zero', $result->error()?->message());
        self::assertSame('1 / 0', $result->residualExpression());

        $this->expectException(EvaluationException::class);
        $result->value();
    }

    public function testPartialEvaluationPropagatesUnknownAttributes(): void
    {
        $env = Environment::builder()
            ->variable('request', Type::dyn())
            ->build();

        $result = $env
            ->program($env->compile('request.user == "nick"'))
            ->evalPartial(['request' => UnknownValue::attribute('request')]);

        self::assertFalse($result->isKnown());
        self::assertSame(['request.user'], $result->unknown()?->attributes());
        self::assertNull($result->error());
        self::assertSame('request.user == "nick"', $result->residualExpression());
    }

    public function testPartialEvaluationShortCircuitsUnknowns(): void
    {
        $env = Environment::builder()
            ->variable('x', Type::bool())
            ->build();

        $unknown = UnknownValue::attribute('x');

        self::assertTrue($env->program($env->compile('x || true'))->evalPartial(['x' => $unknown])->value());
        self::assertFalse($env->program($env->compile('x && false'))->evalPartial(['x' => $unknown])->value());

        $result = $env->program($env->compile('x && true'))->evalPartial(['x' => $unknown]);
        self::assertFalse($result->isKnown());
        self::assertSame(['x'], $result->unknown()?->attributes());
        self::assertSame('x', $result->residualExpression());
    }

    public function testPartialEvaluationMergesUnknowns(): void
    {
        $env = Environment::builder()
            ->variable('a', Type::int())
            ->variable('b', Type::int())
            ->build();

        $result = $env
            ->program($env->compile('a + b'))
            ->evalPartial([
                'a' => UnknownValue::attribute('a'),
                'b' => UnknownValue::attribute('b'),
            ]);

        self::assertSame(['a', 'b'], $result->unknown()?->attributes());
        self::assertSame('a + b', $result->residualExpression());
    }

    public function testPartialEvaluationFoldsKnownSubexpressionsInResiduals(): void
    {
        $env = Environment::builder()
            ->variable('request', Type::dyn())
            ->variable('limit', Type::int())
            ->build();

        $result = $env
            ->program($env->compile('request.score > limit + 1'))
            ->evalPartial([
                'request' => UnknownValue::attribute('request'),
                'limit' => 2,
            ]);

        self::assertSame(['request.score'], $result->unknown()?->attributes());
        self::assertSame('request.score > 3', $result->residualExpression());
    }

    public function testPartialEvaluationRendersKnownProtoMessagesInResiduals(): void
    {
        $env = Environment::builder()
            ->protoRegistry(Proto3ConformanceRegistry::create())
            ->variable('x', Type::dyn())
            ->variable('msg', Type::message('cel.expr.conformance.proto3.TestAllTypes'))
            ->build();

        $literal = $env
            ->program($env->compile('x == TestAllTypes{single_int64: 1 + 2, single_string: "ok"}'))
            ->evalPartial(['x' => UnknownValue::attribute('x')]);

        self::assertSame(['x'], $literal->unknown()?->attributes());
        self::assertSame(
            'x == cel.expr.conformance.proto3.TestAllTypes{single_int64: 3, single_string: "ok"}',
            $literal->residualExpression(),
        );

        $message = (new \CEL\Generated\Expr\Conformance\Proto3\TestAllTypes())
            ->setSingleInt64(7)
            ->setSingleBytes('hi');
        $activation = $env
            ->program($env->compile('x == msg'))
            ->evalPartial([
                'x' => UnknownValue::attribute('x'),
                'msg' => $message,
            ]);

        self::assertSame(['x'], $activation->unknown()?->attributes());
        self::assertSame(
            'x == cel.expr.conformance.proto3.TestAllTypes{single_int64: 7, single_bytes: b"hi"}',
            $activation->residualExpression(),
        );
    }

    public function testPartialEvaluationPreservesProto3PresenceFieldsInResiduals(): void
    {
        $env = Environment::builder()
            ->protoRegistry(Proto3ConformanceRegistry::create())
            ->variable('x', Type::dyn())
            ->build();

        $result = $env
            ->program($env->compile('x == TestAllTypes{optional_bool: false, oneof_bool: false}'))
            ->evalPartial(['x' => UnknownValue::attribute('x')]);

        self::assertSame(['x'], $result->unknown()?->attributes());
        self::assertSame(
            'x == cel.expr.conformance.proto3.TestAllTypes{optional_bool: false, oneof_bool: false}',
            $result->residualExpression(),
        );
    }

    public function testPartialEvaluationSurfacesUnknownsInsideOptionalValues(): void
    {
        $env = Environment::builder()
            ->variable('x', Type::dyn())
            ->variable('items', Type::dyn())
            ->build();

        foreach ([
            'x.?field' => [['x' => UnknownValue::attribute('x')], ['x.field'], 'x.?field'],
            'items[?0]' => [['items' => UnknownValue::attribute('items')], ['items[0]'], 'items[?0]'],
            'optional.of(x)' => [['x' => UnknownValue::attribute('x')], ['x'], 'optional.of(x)'],
            '[?optional.of(x)]' => [['x' => UnknownValue::attribute('x')], ['x'], '[?optional.of(x)]'],
            '{?"k": optional.of(x)}' => [['x' => UnknownValue::attribute('x')], ['x'], '{?"k": optional.of(x)}'],
        ] as $expression => [$activation, $attributes, $residual]) {
            $result = $env->program($env->compile($expression))->evalPartial($activation);

            self::assertFalse($result->isKnown(), $expression);
            self::assertSame($attributes, $result->unknown()?->attributes(), $expression);
            self::assertSame($residual, $result->residualExpression(), $expression);
        }
    }

    public function testPartialEvaluationPropagatesUnknownsThroughHasPresenceChecks(): void
    {
        $env = Environment::builder()
            ->variable('x', Type::dyn())
            ->build();

        foreach ([
            'has(x.y)' => ['x.y', 'has(x.y)'],
            'has(x.?y)' => ['x.y', 'has(x.?y)'],
            'has(x[0])' => ['x[0]', 'has(x[0])'],
            'has(x[?0])' => ['x[0]', 'has(x[?0])'],
            'has(optional.of(x).y)' => ['x.y', 'has(optional.of(x).y)'],
        ] as $expression => [$attribute, $residual]) {
            $result = $env
                ->program($env->compile($expression))
                ->evalPartial(['x' => UnknownValue::attribute('x')]);

            self::assertFalse($result->isKnown(), $expression);
            self::assertSame([$attribute], $result->unknown()?->attributes(), $expression);
            self::assertSame($residual, $result->residualExpression(), $expression);
        }
    }

    public function testPartialEvaluationPropagatesUnknownsThroughMacros(): void
    {
        $env = Environment::builder()
            ->variable('limit', Type::int())
            ->build();

        foreach ([
            '[1, 2].all(i, i != limit)' => ['limit', '[1, 2].all(i, i != limit)'],
            '[1, 2].exists(i, i == limit)' => ['limit', '[1, 2].exists(i, i == limit)'],
            '[1, 2].exists_one(i, i == limit)' => ['limit', '[1, 2].exists_one(i, i == limit)'],
            '[1, 2].filter(i, i == limit)' => ['limit', '[1, 2].filter(i, i == limit)'],
            '[1, 2].map(i, i + limit)' => ['limit', '[1, 2].map(i, i + limit)'],
        ] as $expression => [$attribute, $residual]) {
            $result = $env
                ->program($env->compile($expression))
                ->evalPartial(['limit' => UnknownValue::attribute('limit')]);

            self::assertFalse($result->isKnown(), $expression);
            self::assertSame([$attribute], $result->unknown()?->attributes(), $expression);
            self::assertSame($residual, $result->residualExpression(), $expression);
        }
    }

    public function testPartialEvaluationMacroUnknownsRespectDecisiveResults(): void
    {
        $env = Environment::builder()
            ->variable('limit', Type::int())
            ->build();

        self::assertFalse(
            $env
                ->program($env->compile('[1, 2].all(i, i == limit && i == 3)'))
                ->evalPartial(['limit' => UnknownValue::attribute('limit')])
                ->value(),
        );
        self::assertTrue(
            $env
                ->program($env->compile('[1, 2].exists(i, i == limit || i == 1)'))
                ->evalPartial(['limit' => UnknownValue::attribute('limit')])
                ->value(),
        );
        self::assertFalse(
            $env
                ->program($env->compile('[1, 2, 3].exists_one(i, i < 3 || i == limit)'))
                ->evalPartial(['limit' => UnknownValue::attribute('limit')])
                ->value(),
        );
    }

    public function testPartialEvaluationRendersImportedLoweredComprehensionResiduals(): void
    {
        $env = Environment::builder()
            ->variable('limit', Type::int())
            ->build();

        $parsed = (new \CEL\Generated\Expr\ParsedExpr())->setExpr(
            $this->comprehensionExpr(
                'i',
                '',
                'acc',
                $this->listExpr([$this->intExpr(1), $this->intExpr(2)]),
                $this->intExpr(0),
                $this->boolExpr(true),
                $this->binaryExpr(
                    '+',
                    $this->identExpr('acc'),
                    $this->conditionalExpr(
                        $this->binaryExpr('==', $this->identExpr('i'), $this->intExpr(1)),
                        $this->identExpr('limit'),
                        $this->intExpr(0),
                    ),
                ),
                $this->identExpr('acc'),
            ),
        );

        $result = $env
            ->program(\CEL\Ast::fromParsedExpr($parsed))
            ->evalPartial(['limit' => UnknownValue::attribute('limit')]);

        self::assertFalse($result->isKnown());
        self::assertSame(['limit'], $result->unknown()?->attributes());
        self::assertSame(
            '__comprehension__([1, 2], i, acc, 0, true, acc + (i == 1 ? limit : 0), acc)',
            $result->residualExpression(),
        );
    }

    private function intExpr(int $value): \CEL\Generated\Expr\Expr
    {
        return $this->expr()->setConstExpr((new \CEL\Generated\Expr\Constant())->setInt64Value($value));
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
        return (new \CEL\Generated\Expr\Expr())->setId($this->nextExprId++);
    }
}
