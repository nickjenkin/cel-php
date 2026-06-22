<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$target = $root . '/tools/fuzz/cel-target.php';
$seeds = $root . '/tools/fuzz/seeds';
$corpus = $root . '/var/fuzz-corpus';
$coverage = $root . '/var/fuzz-coverage';
$phpFuzzer = $root . '/vendor/bin/php-fuzzer';

$mode = $argv[1] ?? 'fuzz';
$args = array_slice($argv, 2);

if (!is_dir($corpus) && !mkdir($corpus, 0777, true) && !is_dir($corpus)) {
    fwrite(STDERR, "Unable to create fuzz corpus directory: {$corpus}\n");
    exit(1);
}

foreach (glob($seeds . '/*.cel') ?: [] as $seed) {
    $destination = $corpus . '/' . basename($seed);
    if (!is_file($destination)) {
        copy($seed, $destination);
    }
}

$command = match ($mode) {
    'fuzz' => array_merge([$phpFuzzer, 'fuzz'], $args, [$target, $corpus]),
    'coverage' => array_merge([$phpFuzzer, 'report-coverage'], $args, [$target, $corpus, $coverage]),
    default => null,
};

if ($command === null) {
    fwrite(STDERR, "Unknown fuzz mode: {$mode}\n");
    exit(1);
}

$escaped = array_map(static fn (string $part): string => escapeshellarg($part), $command);
passthru(implode(' ', $escaped), $status);
exit($status);
