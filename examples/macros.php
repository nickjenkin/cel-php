<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CEL\Environment;
use CEL\Type;

$env = Environment::builder()
    ->variable('users', Type::list(Type::dyn()))
    ->build();

$activeUsers = $env->program($env->compile('users.filter(u, u.active).map(u, u.email)'));

$result = $activeUsers->eval([
    'users' => [
        ['email' => 'alice@example.test', 'active' => true],
        ['email' => 'bob@example.test', 'active' => false],
        ['email' => 'carol@example.test', 'active' => true],
    ],
]);

print_r($result);
