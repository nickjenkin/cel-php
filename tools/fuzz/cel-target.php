<?php

declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

use CEL\Bytes;
use CEL\CheckException;
use CEL\Environment;
use CEL\EvaluationException;
use CEL\ParseException;
use CEL\ProgramOptions;
use CEL\Type;
use CEL\UInt;
use CEL\UnsupportedFeatureException;

require __DIR__ . '/../../vendor/autoload.php';

$env = Environment::builder()
    ->variable('x', Type::int())
    ->variable('y', Type::int())
    ->variable('u', Type::uint())
    ->variable('d', Type::double())
    ->variable('s', Type::string())
    ->variable('bytes', Type::bytes())
    ->variable('flag', Type::bool())
    ->variable('items', Type::list(Type::dyn()))
    ->variable('m', Type::map(Type::string(), Type::dyn()))
    ->variable('request', Type::map(Type::string(), Type::dyn()))
    ->variable('dyn', Type::dyn())
    ->build();

$activation = [
    'x' => 3,
    'y' => 7,
    'u' => UInt::from(5),
    'd' => 1.5,
    's' => 'hello world',
    'bytes' => new Bytes("abc"),
    'flag' => true,
    'items' => [1, 2, 3],
    'm' => ['a' => 1, 'b' => 'two', 'nested' => ['ok' => true]],
    'request' => ['user' => 'nick', 'path' => '/admin/settings'],
    'dyn' => ['a' => [1, 2], 'ok' => true],
];

$options = new ProgramOptions(maxSteps: 500, maxDepth: 50);

$config->setAllowedExceptions([
    ParseException::class,
    CheckException::class,
    EvaluationException::class,
    UnsupportedFeatureException::class,
]);
$config->setMaxLen(2048);
$config->addDictionary(__DIR__ . '/cel.dict');
$config->setTarget(static function (string $input) use ($env, $activation, $options): void {
    $ast = $env->parse($input, '<fuzz>');
    $checked = $env->check($ast);

    $env->program($checked, $options)->eval($activation);
});
