<?php

declare(strict_types=1);

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

$rewrites = [];
$fixed = 0;

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
    $expected = 'Omnichannel\\Addons\\'.$pascal.($dir === '.' ? '' : '\\'.str_replace('/', '\\', $dir));

    $content = file_get_contents($f->getPathname());
    if (! preg_match('/namespace\s+([^;]+);/', $content, $nm)) {
        continue;
    }
    $actual = trim($nm[1]);
    if ($actual === $expected) {
        continue;
    }

    $content = preg_replace('/namespace\s+'.preg_quote($actual, '/').'\s*;/', 'namespace '.$expected.';', $content, 1);
    file_put_contents($f->getPathname(), $content);
    $rewrites[$actual] = $expected;
    if (preg_match('/\b(?:class|trait|interface|enum)\s+(\w+)/', $content, $cm)) {
        $rewrites[$actual.'\\'.$cm[1]] = $expected.'\\'.$cm[1];
    }
    $fixed++;
    echo "{$actual} => {$expected} (".basename($path).")\n";
}

// Known mangled service imports from earlier scripts
$mangled = [
    'Omnichannel\\Addons\\WordPress\\ServicesArticleSyncService' => 'Omnichannel\\Addons\\WordPress\\Services\\WordPressArticleSyncService',
    'Omnichannel\\Addons\\WordPress\\ServicesArticleContentService' => 'Omnichannel\\Addons\\WordPress\\Services\\WordPressArticleContentService',
    'Omnichannel\\Addons\\WordPress\\ServicesMediaLibraryService' => 'Omnichannel\\Addons\\WordPress\\Services\\WordPressMediaLibraryService',
    'Omnichannel\\Addons\\WordPress\\ServicesArticleMediaService' => 'Omnichannel\\Addons\\WordPress\\Services\\WordPressArticleMediaService',
];

foreach ($mangled as $old => $new) {
    $rewrites[$old] = $new;
}

// Panel bases
foreach ($slugPascal as $pascal) {
    $rewrites['Omnichannel\\Addons\\'.$pascal.'\\Filament\\Resources\\SeoPanelResource'] = 'Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource';
    $rewrites['Omnichannel\\Addons\\'.$pascal.'\\Filament\\Pages\\SeoPanelPage'] = 'Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage';
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
        // Drop wrong panel uses, ensure Seo use when extends
        $c = preg_replace('/^use Omnichannel\\\\Addons\\\\(?!Seo\\\\)[A-Za-z\\\\]+\\\\SeoPanelResource\s*;\r?\n/m', '', $c) ?? $c;
        $c = preg_replace('/^use Omnichannel\\\\Addons\\\\(?!Seo\\\\)[A-Za-z\\\\]+\\\\SeoPanelPage\s*;\r?\n/m', '', $c) ?? $c;
        if (str_contains($c, 'extends SeoPanelResource') && ! str_contains($c, 'use Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource;')) {
            $c = preg_replace('/(namespace\s+[^;]+;\s*)/', "$1\nuse Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource;\n", $c, 1) ?? $c;
        }
        if (str_contains($c, 'extends SeoPanelPage') && ! str_contains($c, 'use Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage;')) {
            $c = preg_replace('/(namespace\s+[^;]+;\s*)/', "$1\nuse Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage;\n", $c, 1) ?? $c;
        }
        if ($c !== $o) {
            file_put_contents($f->getPathname(), $c);
            $patched++;
        }
    }
}

echo "ns fixed: {$fixed}\n";
echo "imports patched: {$patched}\n";
