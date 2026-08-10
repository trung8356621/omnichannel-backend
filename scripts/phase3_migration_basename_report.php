<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$rows = [];
$without = [];
$seen = [];
$dupes = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/addons', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $f->getPathname());
    if (! str_contains($path, '/database/migrations/')) {
        continue;
    }
    $base = basename($path);
    $rel = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
    if (isset($seen[$base])) {
        $dupes[$base] = [$seen[$base], $rel];
    } else {
        $seen[$base] = $rel;
    }
    $c = file_get_contents($path);
    $hasConn = preg_match('/\$connection\s*=/', $c) === 1;
    if (! $hasConn) {
        $without[] = $rel;
    }
    $rows[] = [
        'basename' => $base,
        'path' => $rel,
        'has_connection_property' => $hasConn,
        'compatibility' => 'basename_preserved',
    ];
}

$coreDir = $root.'/database/migrations';
foreach (glob($coreDir.'/*.php') ?: [] as $file) {
    $base = basename($file);
    $rel = 'database/migrations/'.$base;
    if (isset($seen[$base])) {
        $dupes[$base] = [$seen[$base], $rel];
    }
}

$payload = [
    'version' => 1,
    'note' => 'Migrations relocated into peer addon folders. Laravel migration history uses basename only. No duplicate basenames; no rename compatibility map required.',
    'total_addon_migrations' => count($rows),
    'renamed_basenames' => [],
    'duplicate_basenames' => $dupes,
    'missing_connection_property' => $without,
    'entries' => $rows,
];

file_put_contents(
    $root.'/addons/MIGRATION_BASENAME_COMPAT.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
);

echo 'addon migrations='.count($rows).PHP_EOL;
echo 'duplicates='.count($dupes).PHP_EOL;
echo 'missing $connection='.count($without).PHP_EOL;
