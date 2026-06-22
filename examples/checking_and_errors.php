<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

use CEL\CheckException;
use CEL\Environment;
use CEL\EvaluationException;
use CEL\ProgramOptions;
use CEL\Type;

$env = Environment::builder()
    ->variable('name', Type::string())
    ->variable('age', Type::int())
    ->build();

$program = $env->program($env->compile('name.startsWith("A") && age >= 18'));
example_show('checked eval', $program->eval(['name' => 'Alice', 'age' => 42]));

try {
    $env->compile('missing + 1');
} catch (CheckException $exception) {
    example_show('missing identifier', $exception->getMessage());
}

try {
    $env->compile('"hello".startsWith(1)');
} catch (CheckException $exception) {
    example_show('type error', $exception->getMessage());
}

try {
    $env->program($env->compile('1 / 0'))->eval();
} catch (EvaluationException $exception) {
    example_show('runtime error', $exception->getMessage());
}

try {
    $limited = $env->program(
        $env->compile('[1, 2, 3].all(x, x > 0)'),
        new ProgramOptions(maxSteps: 2),
    );
    $limited->eval();
} catch (EvaluationException $exception) {
    example_show('resource limit', $exception->getMessage());
}
