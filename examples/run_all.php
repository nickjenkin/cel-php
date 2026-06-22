<?php

declare(strict_types=1);

$examples = [
    'basic_eval.php',
    'typed_variables.php',
    'operators_and_values.php',
    'checking_and_errors.php',
    'macros.php',
    'optionals.php',
    'partial_evaluation.php',
    'standard_functions.php',
    'extensions.php',
    'custom_function.php',
    'custom_functions.php',
    'proto3_messages.php',
    'application_protobuf.php',
    'protobuf_ast.php',
];

foreach ($examples as $example) {
    echo '== ', $example, ' ==', PHP_EOL;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $example), $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, sprintf("Example %s failed with exit code %d\n", $example, $exitCode));
        exit($exitCode);
    }

    echo PHP_EOL;
}

echo 'All examples completed.', PHP_EOL;
