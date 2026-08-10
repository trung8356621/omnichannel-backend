<?php

declare(strict_types=1);

/**
 * Align declared namespaces with PSR-4 path under addons/{slug}/src/.
 */
$root = dirname(__DIR__);
$fixed = 0;
$rewrites = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/addons', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $f->getPathname());
    if (! preg_match('#/addons/([^/]+)/src/(.+)$#', $path, $m)) {
        continue;
    }
    // skip database migrations etc outside src already handled
    $slug = $m[1];
    if (str_starts_with($slug, '_')) {
        continue;
    }
    $pascal = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    $rel = preg_replace('/\.php$/', '', $m[2]);
    $dir = dirname($rel);
    $expectedNs = 'Omnichannel\\Addons\\'.$pascal.($dir === '.' ? '' : '\\'.str_replace('/', '\\', $dir));

    $content = file_get_contents($path);
    if (! preg_match('/namespace\s+([^;]+);/', $content, $nm)) {
        continue;
    }
    $actual = trim($nm[1]);
    if ($actual === $expectedNs) {
        continue;
    }

    // Only rewrite Omnichannel/App addon namespaces — leave vendor-style alone if somehow present
    if (! str_starts_with($actual, 'Omnichannel\\Addons\\') && ! str_starts_with($actual, 'App\\Addons\\')) {
        continue;
    }

    $content = preg_replace(
        '/namespace\s+'.preg_quote($actual, '/').'\s*;/',
        'namespace '.$expectedNs.';',
        $content,
        1
    );
    file_put_contents($path, $content);
    $rewrites[$actual] = $expectedNs;
    if (preg_match('/\b(?:class|trait|interface|enum)\s+(\w+)/', $content, $cm)) {
        $rewrites[$actual.'\\'.$cm[1]] = $expectedNs.'\\'.$cm[1];
    }
    $fixed++;
    echo "NS {$actual} => {$expectedNs} (".basename($path).")\n";
}

uksort($rewrites, static fn ($a, $b) => strlen($b) <=> strlen($a));

$patched = 0;
foreach (['app', 'addons', 'tests'] as $d) {
    $scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d, FilesystemIterator::SKIP_DOTS));
    foreach ($scan as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $c = file_get_contents($f->getPathname());
        $o = $c;
        foreach ($rewrites as $old => $new) {
            if ($old !== $new) {
                $c = str_replace($old, $new, $c);
            }
        }
        if ($c !== $o) {
            file_put_contents($f->getPathname(), $c);
            $patched++;
        }
    }
}

echo "namespace files fixed: {$fixed}\n";
echo "import files patched: {$patched}\n";
