<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

use CEL\Environment;

$env = Environment::standard();

example_show('arithmetic', example_eval($env, '1 + 2 * 3'));
example_show('comparison', example_eval($env, '1 + 2 * 3 == 7'));
example_show('membership list', example_eval($env, '"write" in ["read", "write"]'));
example_show('membership map', example_eval($env, '"role" in {"role": "admin"}'));
example_show('list index', example_eval($env, '["read", "write", "delete"][1]'));
example_show('map select', example_eval($env, '{"role": "admin", "level": 3}.role'));
example_show('bytes literal', example_eval($env, 'b"cel"'));
example_show('uint literal', example_eval($env, '18446744073709551615u'));
example_show('type function', example_eval($env, 'type({"role": "admin"})'));
example_show('php result values', example_eval($env, '[1, true, "three"]'));
