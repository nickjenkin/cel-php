<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CEL\Environment;

$env = Environment::standard();
$program = $env->program($env->compile('1 + 2 * 3 == 7'));

var_export($program->eval());
echo PHP_EOL;

