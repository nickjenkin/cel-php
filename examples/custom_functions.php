<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

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
    ->function(new FunctionDeclaration('maskDomain', [
        new Overload(
            id: 'mask_domain_string_string',
            argumentTypes: [Type::string(), Type::string()],
            resultType: Type::string(),
            implementation: static function (array $args, EvaluationContext $context): string {
                [$email, $replacementDomain] = $args;
                $localPart = strstr($email, '@', before_needle: true);

                return ($localPart === false ? $email : $localPart) . '@' . $replacementDomain;
            },
            receiverStyle: true,
        ),
    ]))
    ->variable('email', Type::string())
    ->build();

example_show(
    'global overload',
    $env->program($env->compile('isAllowedDomain(email)'))->eval(['email' => 'alice@example.test']),
);
example_show(
    'receiver overload',
    $env->program($env->compile('email.maskDomain("redacted.test")'))->eval(['email' => 'alice@example.test']),
);
