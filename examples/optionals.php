<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

use CEL\Environment;

$env = Environment::standard();

example_show('optional.of hasValue', example_eval($env, 'optional.of("ready").hasValue()'));
example_show('optional.none hasValue', example_eval($env, 'optional.none().hasValue()'));
example_show('orValue fallback', example_eval($env, 'optional.none().orValue("fallback")'));
example_show('or optional', example_eval($env, 'optional.none().or(optional.of("backup")).value()'));
example_show('optional select hit', example_eval($env, '{"email": "alice@example.test"}.?email.orValue("missing")'));
example_show('optional select miss', example_eval($env, '{}.?email.orValue("missing")'));
example_show('optional index hit', example_eval($env, '["first"][?0].orValue("missing")'));
example_show('optional index miss', example_eval($env, '[][?0].orValue("missing")'));
example_show('optional list entries', example_eval($env, '[?optional.of("first"), ?optional.none(), "last"]'));
example_show('optional map entries', example_eval($env, '{?"region": optional.of("ap-southeast-2"), ?"skip": optional.none()}'));
