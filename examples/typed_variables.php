<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CEL\Environment;
use CEL\Type;

$env = Environment::builder()
    ->variable('name', Type::string())
    ->variable('group', Type::string())
    ->build();

$program = $env->program($env->compile('name.startsWith("/groups/" + group)'));

$result = $program->eval([
    'name' => '/groups/acme.co/documents/secret',
    'group' => 'acme.co',
]);

var_export($result);
echo PHP_EOL;

