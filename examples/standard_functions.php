<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

use CEL\Environment;

$env = Environment::standard();

example_show('int conversion', example_eval($env, 'int("42") + 1'));
example_show('uint conversion', example_eval($env, 'uint("42") + 1u'));
example_show('string conversion', example_eval($env, 'string(42)'));
example_show('string startsWith', example_eval($env, '"abcdef".startsWith("abc")'));
example_show('regex matches', example_eval($env, '"abc123".matches("[a-z]+[0-9]+")'));
example_show('split', example_eval($env, '"a,b,c".split(",")'));
example_show('join', example_eval($env, '["a", "b", "c"].join("-")'));
example_show('timestamp parse', example_eval($env, 'timestamp("2009-02-13T23:31:30Z")'));
example_show('timestamp selector', example_eval($env, 'timestamp("2009-02-13T23:31:30Z").getFullYear()'));
example_show('duration parse', example_eval($env, 'duration("3730s")'));
example_show('duration selector', example_eval($env, 'duration("3730s").getMinutes()'));
example_show('time arithmetic', example_eval($env, 'timestamp("2009-02-13T23:31:30Z") + duration("240s")'));
