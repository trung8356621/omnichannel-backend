<?php

declare(strict_types=1);

/**
 * Safe realign: fix declared namespace to match path ONLY.
 * Import rewrites use FQCN (namespace + class) only — never parent namespace prefixes.
 */
$root = dirname(__DIR__);

$slugPascal = [
    'search-foundation' => 'SearchFoundation',
    'seo' => 'Seo',
    'search-intelligence' => 'SearchIntelligence',
    'ai-prompt' => 'AiPrompt',
    'content' => 'Content',
    'content-projects' => 'ContentProjects',
    'media' => 'Media',
    'wordpress' => 'WordPress',
    'publishing' => 'Publishing',
    'site-sync' => 'SiteSync',
    'agent' => 'Agent',
    'social' => 'Social',
    'commerce' => 'Commerce',
];

/** @var array<string,string> FQCN old => new */
$fqcnRewrites = [];
$nsFixed = 0;

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/addons', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $f->getPathname());
    if (! preg_match('#/addons/([^/]+)/src/(.+)$#', $path, $m)) {
        continue;
    }
    $slug = $m[1];
    if (! isset($slugPascal[$slug])) {
        continue;
    }
    $pascal = $slugPascal[$slug];
    $rel = preg_replace('/\.php$/', '', $m[2]);
    $dir = dirname($rel);
    $expectedNs = 'Omnichannel\\Addons\\'.$pascal.($dir === '.' ? '' : '\\'.str_replace('/', '\\', $dir));

    $content = file_get_contents($f->getPathname());
    if (! preg_match('/namespace\s+([^;]+);/', $content, $nm)) {
        continue;
    }
    if (! preg_match('/\b(?:abstract\s+|final\s+)?(?:class|trait|interface|enum)\s+(\w+)/', $content, $cm)) {
        continue;
    }
    $class = $cm[1];
    $actualNs = trim($nm[1]);
    $newFqcn = $expectedNs.'\\'.$class;
    $oldFqcn = $actualNs.'\\'.$class;

    if ($actualNs !== $expectedNs) {
        $content = preg_replace(
            '/namespace\s+'.preg_quote($actualNs, '/').'\s*;/',
            'namespace '.$expectedNs.';',
            $content,
            1
        );
        file_put_contents($f->getPathname(), $content);
        $nsFixed++;
        echo "NS {$oldFqcn} => {$newFqcn}\n";
    }

    if ($oldFqcn !== $newFqcn) {
        $fqcnRewrites[$oldFqcn] = $newFqcn;
    }
}

// Stable panel FQCNs
$fqcnRewrites['Omnichannel\\Addons\\Content\\Filament\\Resources\\SeoPanelResource'] = 'Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource';
$fqcnRewrites['Omnichannel\\Addons\\Content\\Filament\\Resources\\Pages\\SeoCreateRecord'] = 'Omnichannel\\Addons\\Seo\\Filament\\Resources\\Pages\\SeoCreateRecord';
$fqcnRewrites['Omnichannel\\Addons\\Content\\Filament\\Resources\\Pages\\SeoEditRecord'] = 'Omnichannel\\Addons\\Seo\\Filament\\Resources\\Pages\\SeoEditRecord';
$fqcnRewrites['Omnichannel\\Addons\\Content\\Filament\\Pages\\SeoPanelPage'] = 'Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage';

foreach ($slugPascal as $p) {
    if ($p === 'Seo') {
        continue;
    }
    $fqcnRewrites['Omnichannel\\Addons\\'.$p.'\\Filament\\Resources\\SeoPanelResource'] = 'Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource';
    $fqcnRewrites['Omnichannel\\Addons\\'.$p.'\\Filament\\Pages\\SeoPanelPage'] = 'Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage';
    $fqcnRewrites['Omnichannel\\Addons\\'.$p.'\\Filament\\Resources\\Pages\\SeoCreateRecord'] = 'Omnichannel\\Addons\\Seo\\Filament\\Resources\\Pages\\SeoCreateRecord';
    $fqcnRewrites['Omnichannel\\Addons\\'.$p.'\\Filament\\Resources\\Pages\\SeoEditRecord'] = 'Omnichannel\\Addons\\Seo\\Filament\\Resources\\Pages\\SeoEditRecord';
}

uksort($fqcnRewrites, static fn ($a, $b) => strlen($b) <=> strlen($a));

$patched = 0;
foreach (['app', 'addons', 'tests'] as $d) {
    $scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d, FilesystemIterator::SKIP_DOTS));
    foreach ($scan as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $c = file_get_contents($f->getPathname());
        $o = $c;
        foreach ($fqcnRewrites as $old => $new) {
            $c = str_replace($old, $new, $c);
        }
        if ($c !== $o) {
            file_put_contents($f->getPathname(), $c);
            $patched++;
        }
    }
}

echo "ns fixed: {$nsFixed}\n";
echo "fqcn import patched files: {$patched}\n";
echo "fqcn map size: ".count($fqcnRewrites)."\n";
