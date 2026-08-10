<?php

declare(strict_types=1);

/**
 * Phase 3c — move remaining Filament/Jobs leftovers out of SeoContentAi + rewrite namespaces.
 */
$root = dirname(__DIR__);
$legacy = $root.'/app/Addons/SeoContentAi';

/** @var array<string,string> $rewrites oldNs => newNs (and FQCN => FQCN) */
$rewrites = [];

$moves = [
    ['Filament/Resources/TaskResource.php', 'addons/content-projects/src/Filament/Resources/TaskResource.php'],
    ['Filament/Resources/TaskResource', 'addons/content-projects/src/Filament/Resources/TaskResource'],
    ['Filament/Resources/TagResource.php', 'addons/content/src/Filament/Resources/TagResource.php'],
    ['Filament/Resources/TagResource', 'addons/content/src/Filament/Resources/TagResource'],
    ['Filament/Resources/AiConnectionResource', 'addons/ai-prompt/src/Filament/Resources/AiConnectionResource'],
    ['Filament/Resources/SeoProjectResource', 'addons/content-projects/src/Filament/Resources/SeoProjectResource'],
    ['Filament/Resources/Pages', 'addons/seo/src/Filament/Resources/Pages'],
    ['Filament/Resources/KeywordResource', 'addons/search-foundation/src/Filament/Resources/KeywordResource'],
    ['Filament/Widgets/WordPressPluginWidget.php', 'addons/wordpress/src/Filament/Widgets/WordPressPluginWidget.php'],
    ['Filament/Widgets/WpPluginReleaseWidget.php', 'addons/wordpress/src/Filament/Widgets/WpPluginReleaseWidget.php'],
    ['Filament/Widgets/WpSyncStatusTable.php', 'addons/wordpress/src/Filament/Widgets/WpSyncStatusTable.php'],
    ['Filament/Widgets/AllDomainsListWidget.php', 'addons/seo/src/Filament/Widgets/AllDomainsListWidget.php'],
    ['Filament/Widgets/AllDomainsProjectsWidget.php', 'addons/content-projects/src/Filament/Widgets/AllDomainsProjectsWidget.php'],
    ['Filament/Widgets/AllDomainsTeamWidget.php', 'addons/seo/src/Filament/Widgets/AllDomainsTeamWidget.php'],
    ['Filament/Widgets/SeoOverviewStats.php', 'addons/seo/src/Filament/Widgets/SeoOverviewStats.php'],
    ['Filament/Widgets/SeoScoreChart.php', 'addons/seo/src/Filament/Widgets/SeoScoreChart.php'],
    ['Filament/Concerns/InteractsWithAiKeywordDiscovery.php', 'addons/search-intelligence/src/Filament/Concerns/InteractsWithAiKeywordDiscovery.php'],
    ['Filament/Concerns/InteractsWithSeoAllDomainsDashboard.php', 'addons/seo/src/Filament/Concerns/InteractsWithSeoAllDomainsDashboard.php'],
    ['Filament/Concerns/InteractsWithSeoDashboardSite.php', 'addons/seo/src/Filament/Concerns/InteractsWithSeoDashboardSite.php'],
    ['Models/Concerns/BelongsToOnDefaultConnection.php', 'addons/search-foundation/src/Models/Concerns/BelongsToOnDefaultConnection.php'],
];

foreach ($moves as [$fromRel, $toRel]) {
    $from = $legacy.'/'.$fromRel;
    $to = $root.'/'.$toRel;
    if (is_dir($from)) {
        foreach (rglob($from, '*.php') as $file) {
            $rel = ltrim(str_replace('\\', '/', substr($file, strlen($from))), '/');
            relocatePhp($file, $to.'/'.$rel, $rewrites);
            echo "DIR-FILE {$fromRel}/{$rel}\n";
        }
        removeEmptyDirs($from);
        continue;
    }
    if (is_file($from)) {
        relocatePhp($from, $to, $rewrites);
        echo "FILE {$fromRel}\n";
    }
}

// Jobs by heuristic
$jobDir = $legacy.'/Jobs';
$jobRules = [
    'wordpress' => ['Wp', 'WordPress', 'Plugin'],
    'publishing' => ['Publish', 'Schedule', 'PublishingQueue'],
    'site-sync' => ['SiteSync', 'SyncBatch', 'SyncRun', 'SyncStep'],
    'search-intelligence' => ['Rank', 'Serp', 'Gsc', 'KeywordDiscovery', 'SearchVolume', 'Opportunity'],
    'media' => ['Media', 'Image', 'Watermark', 'Gallery'],
    'content-projects' => ['ContentProject', 'ProjectRun', 'Workflow', 'RunEngine', 'TaskRun'],
    'ai-prompt' => ['Prompt', 'AiGeneration', 'AiChat', 'AiConnection'],
    'seo' => ['SeoAudit', 'SeoScore', 'Cannibalization', 'InternalLink'],
    'content' => ['Article', 'Revision', 'Autosave', 'EditorLock'],
];
$pascalMap = [
    'wordpress' => 'WordPress',
    'publishing' => 'Publishing',
    'site-sync' => 'SiteSync',
    'search-intelligence' => 'SearchIntelligence',
    'media' => 'Media',
    'content-projects' => 'ContentProjects',
    'ai-prompt' => 'AiPrompt',
    'seo' => 'Seo',
    'content' => 'Content',
];

if (is_dir($jobDir)) {
    foreach (rglob($jobDir, '*.php') as $file) {
        $base = basename($file);
        $owner = null;
        foreach ($jobRules as $slug => $needles) {
            foreach ($needles as $needle) {
                if (stripos($base, $needle) !== false) {
                    $owner = $slug;
                    break 2;
                }
            }
        }
        if ($owner === null) {
            echo "JOB-SKIP {$base}\n";
            continue;
        }
        $rel = ltrim(str_replace('\\', '/', substr($file, strlen($jobDir))), '/');
        $to = $root.'/addons/'.$owner.'/src/Jobs/'.$rel;
        relocatePhp($file, $to, $rewrites);
        echo "JOB {$rel} -> {$owner}\n";
    }
    removeEmptyDirs($jobDir);
}

// Global import rewrite
$scan = array_merge(
    rglob($root.'/app', '*.php'),
    rglob($root.'/addons', '*.php'),
    rglob($root.'/tests', '*.php'),
    is_dir($root.'/bootstrap') ? rglob($root.'/bootstrap', '*.php') : [],
);

// Prefer longer keys first
uksort($rewrites, static fn ($a, $b) => strlen($b) <=> strlen($a));

$patched = 0;
foreach ($scan as $file) {
    $content = file_get_contents($file);
    $orig = $content;
    foreach ($rewrites as $old => $new) {
        if ($old !== '' && $old !== $new) {
            $content = str_replace($old, $new, $content);
        }
    }
    $content = str_replace(
        'Omnichannel\\Addons\\SearchFoundation\\Filament\\Resources\\DomainResource\\Pages\\Concerns\\PersistsDomainPromptContext',
        'Omnichannel\\Addons\\AiPrompt\\Filament\\Resources\\DomainResource\\Pages\\Concerns\\PersistsDomainPromptContext',
        $content
    );
    if ($content !== $orig) {
        file_put_contents($file, $content);
        $patched++;
    }
}

echo "Rewrite map: ".count($rewrites)."\n";
echo "Patched files: {$patched}\n";

function relocatePhp(string $from, string $to, array &$rewrites): void
{
    $content = file_get_contents($from);
    if (! preg_match('/namespace\s+([^;]+);/', $content, $m)) {
        throw new RuntimeException("No namespace in {$from}");
    }
    $oldNs = trim($m[1]);
    $newNs = namespaceForTarget($to);
    $class = pathinfo($from, PATHINFO_FILENAME);

    $content = preg_replace(
        '/namespace\s+'.preg_quote($oldNs, '/').'\s*;/',
        'namespace '.$newNs.';',
        $content,
        1
    );

    $rewrites[$oldNs.'\\'.$class] = $newNs.'\\'.$class;
    $rewrites[$oldNs] = $newNs;

    $dir = dirname($to);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($to, $content);
    unlink($from);
}

function namespaceForTarget(string $to): string
{
    $norm = str_replace('\\', '/', $to);
    if (! preg_match('#/addons/([^/]+)/src/(.+)$#', $norm, $m)) {
        throw new RuntimeException("Cannot derive namespace for {$to}");
    }
    $pascal = str_replace(' ', '', ucwords(str_replace('-', ' ', $m[1])));
    $rel = preg_replace('/\.php$/', '', $m[2]);
    $dir = dirname($rel);
    if ($dir === '.' || $dir === '') {
        return 'Omnichannel\\Addons\\'.$pascal;
    }

    return 'Omnichannel\\Addons\\'.$pascal.'\\'.str_replace('/', '\\', $dir);
}

/** @return list<string> */
function rglob(string $dir, string $pattern): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && fnmatch($pattern, $f->getFilename())) {
            $out[] = $f->getPathname();
        }
    }

    return $out;
}

function removeEmptyDirs(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } elseif ($f->isFile() && $f->getFilename() === '.gitkeep') {
            // keep
        }
    }
    @rmdir($dir);
}
