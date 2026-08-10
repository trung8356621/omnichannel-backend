<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/** @var array<string,string> */
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

// 1) Fix Wordpress -> WordPress namespace damage
$fixed = 0;
$rewrites = [
    'Omnichannel\\Addons\\Wordpress\\' => 'Omnichannel\\Addons\\WordPress\\',
    'Omnichannel\\Addons\\Wordpress;' => 'Omnichannel\\Addons\\WordPress;',
];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/addons/wordpress', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = $f->getPathname();
    $c = file_get_contents($path);
    $o = $c;
    $c = str_replace('namespace Omnichannel\\Addons\\Wordpress', 'namespace Omnichannel\\Addons\\WordPress', $c);
    $c = str_replace('Omnichannel\\Addons\\Wordpress\\', 'Omnichannel\\Addons\\WordPress\\', $c);
    if ($c !== $o) {
        file_put_contents($path, $c);
        $fixed++;
    }
}

// Align ALL addon namespaces using correct pascal map
$alignFixed = 0;
$nsRewrites = [];
$addonsRoot = $root.'/addons';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($addonsRoot, FilesystemIterator::SKIP_DOTS));
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
    if (! str_starts_with($actual, 'Omnichannel\\Addons\\') && ! str_starts_with($actual, 'App\\Addons\\')) {
        continue;
    }
    $content = preg_replace('/namespace\s+'.preg_quote($actual, '/').'\s*;/', 'namespace '.$expected.';', $content, 1);
    file_put_contents($f->getPathname(), $content);
    $nsRewrites[$actual] = $expected;
    if (preg_match('/\b(?:class|trait|interface|enum)\s+(\w+)/', $content, $cm)) {
        $nsRewrites[$actual.'\\'.$cm[1]] = $expected.'\\'.$cm[1];
    }
    $alignFixed++;
}

// Global import rewrites
$nsRewrites['Omnichannel\\Addons\\Wordpress'] = 'Omnichannel\\Addons\\WordPress';
$nsRewrites['Omnichannel\\Addons\\Wordpress\\'] = 'Omnichannel\\Addons\\WordPress\\';
// Panel bases always Seo
$panelFixes = [
    'SeoPanelResource' => 'Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource',
    'SeoPanelPage' => 'Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage',
];
foreach ($slugPascal as $pascal) {
    foreach ($panelFixes as $class => $correct) {
        $nsRewrites['Omnichannel\\Addons\\'.$pascal.'\\Filament\\Resources\\'.$class] = $correct;
        $nsRewrites['Omnichannel\\Addons\\'.$pascal.'\\Filament\\Pages\\'.$class] = $correct;
    }
}

uksort($nsRewrites, static fn ($a, $b) => strlen($b) <=> strlen($a));

$patched = 0;
foreach (['app', 'addons', 'tests'] as $d) {
    $scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d, FilesystemIterator::SKIP_DOTS));
    foreach ($scan as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $c = file_get_contents($f->getPathname());
        $o = $c;
        foreach ($nsRewrites as $old => $new) {
            if ($old !== '' && $old !== $new) {
                $c = str_replace($old, $new, $c);
            }
        }
        // ensure use for extends SeoPanel*
        if (str_contains($c, 'extends SeoPanelResource') && ! str_contains($c, 'use Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource;')) {
            $c = preg_replace('/(namespace\s+[^;]+;\s*)/', "$1\nuse Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource;\n", $c, 1);
        }
        if (str_contains($c, 'extends SeoPanelPage') && ! str_contains($c, 'use Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage;')) {
            $c = preg_replace('/(namespace\s+[^;]+;\s*)/', "$1\nuse Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage;\n", $c, 1);
        }
        // strip wrong SeoPanel use lines that aren't Seo owner
        $c = preg_replace('/^use Omnichannel\\\\Addons\\\\(?!Seo\\\\)[^;]+\\\\SeoPanelResource\s*;\r?\n/m', '', $c);
        $c = preg_replace('/^use Omnichannel\\\\Addons\\\\(?!Seo\\\\)[^;]+\\\\SeoPanelPage\s*;\r?\n/m', '', $c);
        if ($c !== $o) {
            file_put_contents($f->getPathname(), $c);
            $patched++;
        }
    }
}

echo "wordpress files fixed: {$fixed}\n";
echo "align fixed: {$alignFixed}\n";
echo "import patched: {$patched}\n";
