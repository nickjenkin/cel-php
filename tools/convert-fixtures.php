<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$inputDir = $root . '/tests/Conformance/fixtures/textproto';
$outputDir = $root . '/tests/Conformance/fixtures/json';

if (!is_dir($inputDir)) {
    fwrite(STDERR, "Missing fixture input directory: {$inputDir}\n");
    exit(1);
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Unable to create fixture output directory: {$outputDir}\n");
    exit(1);
}

$fixtures = glob($inputDir . '/*.textproto') ?: [];
foreach ($fixtures as $fixture) {
    $name = basename($fixture, '.textproto');
    $output = $outputDir . '/' . $name . '.json';
    $command = [
        'buf',
        'convert',
        $root . '/proto/cel-spec',
        '--type',
        'cel.expr.conformance.test.SimpleTestFile',
        '--from',
        $fixture . '#format=txtpb',
        '--to',
        $output . '#format=json',
    ];

    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );

    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to start buf for {$fixture}\n");
        exit(1);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0) {
        fwrite(STDERR, "Failed converting {$fixture}\n{$stdout}{$stderr}\n");
        exit($status);
    }
}

printf("Converted %d fixture file(s).\n", count($fixtures));
