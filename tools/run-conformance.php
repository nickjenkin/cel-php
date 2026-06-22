<?php

declare(strict_types=1);

use CEL\Tests\Conformance\ConformanceHarness;

require __DIR__ . '/../vendor/autoload.php';

$fixtureDir = __DIR__ . '/../tests/Conformance/fixtures/json';
$paths = array_slice($argv, 1);
if ($paths === []) {
    $paths = glob($fixtureDir . '/*.json') ?: [];
}
sort($paths);

$harness = new ConformanceHarness();
$unexpected = [];
$total = 0;

foreach ($paths as $path) {
    $fixture = basename($path, '.json');
    $data = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);
    $counts = [];

    foreach ($data['section'] ?? [] as $section) {
        foreach ($section['test'] ?? [] as $test) {
            $result = $harness->classify($fixture, (string) ($section['name'] ?? ''), $test);
            $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;
            $total++;

            if (in_array($result->status, [ConformanceHarness::FAIL, ConformanceHarness::SKIP_UNSUPPORTED_EXTENSION], true)) {
                $unexpected[] = sprintf(
                    '%s/%s/%s: %s: %s',
                    $fixture,
                    (string) ($section['name'] ?? ''),
                    (string) ($test['name'] ?? ''),
                    $result->status,
                    $result->reason,
                );
            }
        }
    }

    ksort($counts);
    fwrite(STDOUT, $fixture . ' ' . json_encode($counts, JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

fwrite(STDOUT, sprintf('total %d' . PHP_EOL, $total));

if ($unexpected !== []) {
    fwrite(STDERR, PHP_EOL . 'Unexpected conformance results:' . PHP_EOL);
    foreach ($unexpected as $line) {
        fwrite(STDERR, '  - ' . $line . PHP_EOL);
    }

    exit(1);
}

exit(0);
