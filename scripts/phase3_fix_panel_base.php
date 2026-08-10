<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$targets = [
    'SeoPanelResource' => 'Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource',
    'SeoPanelPage' => 'Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage',
];

$wrongOwners = [
    'SearchFoundation', 'ContentProjects', 'Content', 'AiPrompt', 'Media', 'WordPress',
    'Publishing', 'SiteSync', 'SearchIntelligence', 'Agent', 'Commerce', 'SeoContentAi',
];

$rewrites = [];
foreach ($targets as $class => $correct) {
    $rewrites['App\\Addons\\SeoContentAi\\Filament\\Resources\\'.$class] = $correct;
    $rewrites['App\\Addons\\SeoContentAi\\Filament\\Pages\\'.$class] = $correct;
    foreach ($wrongOwners as $owner) {
        $rewrites['Omnichannel\\Addons\\'.$owner.'\\Filament\\Resources\\'.$class] = $correct;
        $rewrites['Omnichannel\\Addons\\'.$owner.'\\Filament\\Pages\\'.$class] = $correct;
    }
}

uksort($rewrites, static fn ($a, $b) => strlen($b) <=> strlen($a));

$patched = 0;
foreach (['app', 'addons', 'tests'] as $d) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        $c = file_get_contents($path);
        $o = $c;
        foreach ($rewrites as $old => $new) {
            $c = str_replace($old, $new, $c);
        }
        // Ensure files that extend SeoPanel* have a use import
        if (preg_match('/extends\s+SeoPanel(Resource|Page)\b/', $c) && ! preg_match('/use\s+Omnichannel\\\\Addons\\\\Seo\\\\Filament\\\\(Resources|Pages)\\\\SeoPanel(Resource|Page)\s*;/', $c)) {
            if (str_contains($c, 'extends SeoPanelResource') && ! str_contains($c, 'use Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource;')) {
                $c = preg_replace('/(namespace\s+[^;]+;\s*)/', "$1\nuse Omnichannel\\Addons\\Seo\\Filament\\Resources\\SeoPanelResource;\n", $c, 1);
            }
            if (str_contains($c, 'extends SeoPanelPage') && ! str_contains($c, 'use Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage;')) {
                $c = preg_replace('/(namespace\s+[^;]+;\s*)/', "$1\nuse Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoPanelPage;\n", $c, 1);
            }
        }
        if ($c !== $o) {
            file_put_contents($path, $c);
            $patched++;
            echo basename($path)."\n";
        }
    }
}

echo "patched {$patched}\n";
