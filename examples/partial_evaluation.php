<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

use CEL\Environment;
use CEL\Type;
use CEL\UnknownValue;

$env = Environment::builder()
    ->variable('request', Type::dyn())
    ->variable('limit', Type::int())
    ->build();

$partial = $env
    ->program($env->compile('request.score > limit + 1'))
    ->evalPartial([
        'request' => UnknownValue::attribute('request'),
        'limit' => 2,
    ]);

example_show('partial known', $partial->isKnown());
example_show('unknown attributes', $partial->unknown()?->attributes());
example_show('residual expression', $partial->residualExpression());

$boolEnv = Environment::builder()
    ->variable('x', Type::bool())
    ->build();

example_show(
    'short-circuit true',
    $boolEnv->program($boolEnv->compile('x || true'))->evalPartial(['x' => UnknownValue::attribute('x')])->value(),
);
example_show(
    'short-circuit residual',
    $boolEnv->program($boolEnv->compile('x && true'))->evalPartial(['x' => UnknownValue::attribute('x')])->residualExpression(),
);

$error = $env->program($env->compile('1 / 0'))->evalPartial();
example_show('captured error', $error->error());
example_show('error residual', $error->residualExpression());
