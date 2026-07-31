#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Relative markdown link checker for docs/ (canonical + root README).
 * Usage: php scripts/check-docs-links.php
 */

$root = dirname(__DIR__);
$docsRoot = $root.DIRECTORY_SEPARATOR.'docs';
$targets = [
    $root.DIRECTORY_SEPARATOR.'README.md',
    $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Addons'.DIRECTORY_SEPARATOR.'SeoContentAi'.DIRECTORY_SEPARATOR.'README_ADDON_SEOCONTENTAI.md',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($docsRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) {
        continue;
    }
    if (strtolower($file->getExtension()) !== 'md') {
        continue;
    }
    // Skip archive — historical links may intentionally point at removed paths
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($rel, 'docs/archive/')) {
        continue;
    }
    if (str_starts_with($rel, 'docs/_consolidation/')) {
        continue;
    }
    $targets[] = $file->getPathname();
}

$linkPattern = '/\[([^\]]*)\]\(([^)]+)\)/';
$checked = 0;
$broken = [];

foreach ($targets as $path) {
    $content = @file_get_contents($path);
    if ($content === false) {
        $broken[] = ['file' => $path, 'link' => '(unreadable file)', 'target' => ''];
        continue;
    }
    if (!preg_match_all($linkPattern, $content, $matches, PREG_SET_ORDER)) {
        continue;
    }
    $baseDir = dirname($path);
    foreach ($matches as $m) {
        $href = trim($m[2]);
        if ($href === '' || str_starts_with($href, '#') || preg_match('#^(https?|mailto):#i', $href)) {
            continue;
        }
        // strip anchor
        $hrefPath = explode('#', $href, 2)[0];
        if ($hrefPath === '') {
            continue;
        }
        $checked++;
        $resolved = $hrefPath;
        if (!preg_match('#^[a-zA-Z]:\\\\#', $hrefPath) && !str_starts_with($hrefPath, '/')) {
            $resolved = $baseDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $hrefPath);
        }
        $real = realpath($resolved);
        if ($real === false || !is_file($real)) {
            $relFile = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $broken[] = ['file' => $relFile, 'link' => $href, 'target' => $resolved];
        }
    }
}

echo "links_checked={$checked}\n";
echo 'broken_count='.count($broken)."\n";
foreach ($broken as $b) {
    echo "BROKEN\t{$b['file']}\t{$b['link']}\n";
}

exit(count($broken) > 0 ? 1 : 0);
