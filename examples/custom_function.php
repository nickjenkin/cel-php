<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CEL\Environment;
use CEL\EvaluationContext;
use CEL\FunctionDeclaration;
use CEL\Overload;
use CEL\Type;

$env = Environment::builder()
    ->function(new FunctionDeclaration('isAllowedDomain', [
        new Overload(
            id: 'is_allowed_domain_string',
            argumentTypes: [Type::string()],
            resultType: Type::bool(),
            implementation: static function (array $args, EvaluationContext $context): bool {
                return str_ends_with($args[0], '@example.test');
            },
        ),
    ]))
    ->variable('email', Type::string())
    ->build();

$program = $env->program($env->compile('isAllowedDomain(email)'));

var_export($program->eval(['email' => 'alice@example.test']));
echo PHP_EOL;

