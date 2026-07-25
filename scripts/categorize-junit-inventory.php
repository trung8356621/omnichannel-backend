<?php

declare(strict_types=1);

/**
 * Aggregate JUnit fail inventory into category buckets for LEGACY_TEST_AUDIT.
 */
$inventoryFile = $argv[1] ?? 'storage/app/testing-audit/seo-unit-fail-inventory.txt';
$lines = is_file($inventoryFile) ? file($inventoryFile, FILE_IGNORE_NEW_LINES) : [];

$buckets = [
    'ENV_MYSQL' => [],
    'CONFIG_NOT_BOOTED' => [],
    'FINAL_MOCK' => [],
    'STALE_SOURCE_ASSERT' => [],
    'OTHER_FAILURE' => [],
    'SKIPPED' => [],
];

foreach ($lines as $line) {
    if (! str_contains($line, '|')) {
        continue;
    }
    [$status, $file, $n, $sample] = array_pad(explode('|', $line, 4), 4, '');
    $row = compact('status', 'file', 'n', 'sample');
    if ($status === 'skipped') {
        $buckets['SKIPPED'][] = $row;
    } elseif (str_contains($sample, 'actively refused') || str_contains($sample, 'SQLSTATE[HY000] [2002]')) {
        $buckets['ENV_MYSQL'][] = $row;
    } elseif (str_contains($sample, 'Target class [config] does not exist')) {
        $buckets['CONFIG_NOT_BOOTED'][] = $row;
    } elseif (str_contains($sample, 'ClassIsFinalException') || str_contains($sample, 'marked final')) {
        $buckets['FINAL_MOCK'][] = $row;
    } elseif ($status === 'failure' && (
        str_contains($sample, 'Failed asserting that')
        || str_contains($sample, 'does not contain')
    )) {
        $buckets['STALE_SOURCE_ASSERT'][] = $row;
    } else {
        $buckets['OTHER_FAILURE'][] = $row;
    }
}

foreach ($buckets as $name => $rows) {
    echo "{$name}=".count($rows)."\n";
    foreach ($rows as $row) {
        echo "  - {$row['file']} ({$row['n']}) {$row['status']}\n";
    }
}
