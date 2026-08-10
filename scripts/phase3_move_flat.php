<?php

declare(strict_types=1);

/**
 * Phase 3b — move remaining flat SeoContentAi Services/Models by filename heuristics.
 */
$root = dirname(__DIR__);
$serviceDir = $root.'/app/Addons/SeoContentAi/Services';
$modelDir = $root.'/app/Addons/SeoContentAi/Models';

$serviceRules = [
    // media first
    ['owner' => 'media', 'ns' => 'Omnichannel\\Addons\\Media\\Services', 'dir' => 'addons/media/src/Services', 'any' => [
        'SeoMedia', 'SeoWatermark', 'MediaGeneration', 'MediaLibrary', 'GeminiMedia', 'GeneratedImage',
        'ImageGeneration', 'AiImage', 'ArticleMedia', 'ArticleFeaturedImage', 'ArticlePostImages',
        'SeoImage', 'EditorImage', 'SeoWpMedia', 'ArticleEditorSupplementalImages', 'ArticleEditorMediaAi',
        'ContentProjectImageRerun',
    ]],
    ['owner' => 'publishing', 'ns' => 'Omnichannel\\Addons\\Publishing\\Services', 'dir' => 'addons/publishing/src/Services', 'any' => [
        'ScheduledArticlePublish', 'ArticleScheduleReconcile',
    ]],
    ['owner' => 'wordpress', 'ns' => 'Omnichannel\\Addons\\WordPress\\Services', 'dir' => 'addons/wordpress/src/Services', 'any' => [
        'ArticleWordPress', 'ArticleWpSync', 'ArticlePolylang', 'ArticleFaqWordPress',
    ]],
    ['owner' => 'search-intelligence', 'ns' => 'Omnichannel\\Addons\\SearchIntelligence\\Services', 'dir' => 'addons/search-intelligence/src/Services', 'any' => [
        'KeywordRank', 'KeywordSerp', 'KeywordGroup', 'KeywordSearchVolume', 'SeoRank', 'AiKeywordDiscovery',
        'KeywordDebugRescrape', 'KeywordDomainResync', 'KeywordReview',
    ]],
    ['owner' => 'search-foundation', 'ns' => 'Omnichannel\\Addons\\SearchFoundation\\Services', 'dir' => 'addons/search-foundation/src/Services', 'any' => [
        'KeywordMeta', 'KeywordPersistence', 'KeywordPhrase', 'KeywordLink', 'KeywordQuality',
        'KeywordCannibalization', 'DomainLinkListKeyword', 'CtaKeyword',
    ]],
    ['owner' => 'ai-prompt', 'ns' => 'Omnichannel\\Addons\\AiPrompt\\Services', 'dir' => 'addons/ai-prompt/src/Services', 'any' => [
        'Prompt', 'SeoPrompt', 'ImageOutputModePrompt', 'SiteDomainPromptContext',
    ]],
    ['owner' => 'seo', 'ns' => 'Omnichannel\\Addons\\Seo\\Services', 'dir' => 'addons/seo/src/Services', 'any' => [
        'SeoAudit', 'SeoArticleScoring', 'SeoArticleQuality', 'ArticleContentSeo', 'ArticleGoogleSerp',
        'SeoCreateArticle', 'SeoKeywordSettings', 'WorkflowKeywordResearch',
    ]],
    ['owner' => 'content-projects', 'ns' => 'Omnichannel\\Addons\\ContentProjects\\Services', 'dir' => 'addons/content-projects/src/Services', 'any' => [
        'SeoProject', 'ContentProject', 'CreateArticlesFromTask', 'KeywordProjectAssignment',
        'ArticlePipelineRerun', 'ArticleGenerationInput',
    ]],
    ['owner' => 'content', 'ns' => 'Omnichannel\\Addons\\Content\\Services', 'dir' => 'addons/content/src/Services', 'any' => [
        'Article', 'ClearDomainArticles', 'ArticleWriting',
    ]],
    ['owner' => 'commerce', 'ns' => 'Omnichannel\\Addons\\Commerce\\Services', 'dir' => 'addons/commerce/src/Services', 'any' => [
        'ArticleProductGallery',
    ]],
];

$modelRules = [
    ['owner' => 'media', 'ns' => 'Omnichannel\\Addons\\Media\\Models', 'dir' => 'addons/media/src/Models', 'any' => [
        'SeoMedia', 'SeoWatermark', 'SeoWpMedia',
    ]],
    ['owner' => 'search-foundation', 'ns' => 'Omnichannel\\Addons\\SearchFoundation\\Models', 'dir' => 'addons/search-foundation/src/Models', 'any' => [
        'Keyword.php', 'KeywordMeta', 'KeywordTag', 'KeywordLink', 'SeoKeyword.php',
    ]],
    ['owner' => 'search-intelligence', 'ns' => 'Omnichannel\\Addons\\SearchIntelligence\\Models', 'dir' => 'addons/search-intelligence/src/Models', 'any' => [
        'KeywordRank', 'KeywordGroup', 'KeywordReview', 'SeoRank',
    ]],
    ['owner' => 'ai-prompt', 'ns' => 'Omnichannel\\Addons\\AiPrompt\\Models', 'dir' => 'addons/ai-prompt/src/Models', 'any' => [
        'Prompt', 'SeoPrompt',
    ]],
    ['owner' => 'content', 'ns' => 'Omnichannel\\Addons\\Content\\Models', 'dir' => 'addons/content/src/Models', 'any' => [
        'Article', 'SeoArticle', 'SeoFaq',
    ]],
    ['owner' => 'wordpress', 'ns' => 'Omnichannel\\Addons\\WordPress\\Models', 'dir' => 'addons/wordpress/src/Models', 'any' => [
        'SeoArticleWpSync',
    ]],
    ['owner' => 'commerce', 'ns' => 'Omnichannel\\Addons\\Commerce\\Models', 'dir' => 'addons/commerce/src/Models', 'any' => [
        'ArticleProductReview',
    ]],
];

$rewrites = [];
$moved = 0;

$moved += moveByRules($serviceDir, $serviceRules, 'App\\Addons\\SeoContentAi\\Services', $rewrites);
$moved += moveByRules($modelDir, $modelRules, 'App\\Addons\\SeoContentAi\\Models', $rewrites);

// Move remaining service subdirs
$subdirMoves = [
    ['from' => 'Services/SeoAudit', 'owner' => 'seo', 'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\SeoAudit', 'ns_to' => 'Omnichannel\\Addons\\Seo\\Services\\SeoAudit', 'to' => 'addons/seo/src/Services/SeoAudit'],
    ['from' => 'Services/SiteMcp', 'owner' => 'search-foundation', 'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\SiteMcp', 'ns_to' => 'Omnichannel\\Addons\\SearchFoundation\\Services\\SiteMcp', 'to' => 'addons/search-foundation/src/Services/SiteMcp'],
    ['from' => 'Services/RunEngine', 'owner' => 'content-projects', 'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\RunEngine', 'ns_to' => 'Omnichannel\\Addons\\ContentProjects\\Services\\RunEngine', 'to' => 'addons/content-projects/src/Services/RunEngine'],
    ['from' => 'Services/ArticleAiHistory', 'owner' => 'content', 'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\ArticleAiHistory', 'ns_to' => 'Omnichannel\\Addons\\Content\\Services\\ArticleAiHistory', 'to' => 'addons/content/src/Services/ArticleAiHistory'],
    ['from' => 'Services/ArticleWriting', 'owner' => 'content', 'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\ArticleWriting', 'ns_to' => 'Omnichannel\\Addons\\Content\\Services\\ArticleWriting', 'to' => 'addons/content/src/Services/ArticleWriting'],
    ['from' => 'Services/Ai', 'owner' => 'ai-prompt', 'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\Ai', 'ns_to' => 'Omnichannel\\Addons\\AiPrompt\\Services\\Ai', 'to' => 'addons/ai-prompt/src/Services/Ai'],
    ['from' => 'Services/Notifications', 'owner' => 'seo', 'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\Notifications', 'ns_to' => 'Omnichannel\\Addons\\Seo\\Services\\Notifications', 'to' => 'addons/seo/src/Services/Notifications'],
    ['from' => 'Services/WorkflowRoles', 'owner' => 'content-projects', 'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\WorkflowRoles', 'ns_to' => 'Omnichannel\\Addons\\ContentProjects\\Services\\WorkflowRoles', 'to' => 'addons/content-projects/src/Services/WorkflowRoles'],
    ['from' => 'Services/Workflow', 'owner' => 'content-projects', 'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\Workflow', 'ns_to' => 'Omnichannel\\Addons\\ContentProjects\\Services\\Workflow', 'to' => 'addons/content-projects/src/Services/Workflow'],
];

foreach ($subdirMoves as $spec) {
    $from = $root.'/app/Addons/SeoContentAi/'.$spec['from'];
    $to = $root.'/'.$spec['to'];
    if (! is_dir($from)) {
        continue;
    }
    $n = moveTreeSimple($from, $to);
    $moved += $n;
    $rewrites[$spec['ns_from']] = $spec['ns_to'];
    echo "subdir {$spec['from']}: {$n}\n";
}

uksort($rewrites, static fn ($a, $b) => strlen($b) <=> strlen($a));
$updated = rewriteAll($root, $rewrites);
echo "Moved {$moved} files, rewrote {$updated} files\n";

function moveByRules(string $dir, array $rules, string $oldNsRoot, array &$rewrites): int
{
    if (! is_dir($dir)) {
        return 0;
    }
    $moved = 0;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..' || ! str_ends_with($file, '.php')) {
            continue;
        }
        $owner = null;
        $ns = null;
        $destDir = null;
        foreach ($rules as $rule) {
            foreach ($rule['any'] as $needle) {
                if (str_contains($file, rtrim($needle, '.php')) || $file === $needle) {
                    $owner = $rule['owner'];
                    $ns = $rule['ns'];
                    $destDir = $rule['dir'];
                    break 2;
                }
            }
        }
        if ($owner === null) {
            continue;
        }
        $absoluteDestDir = dirname(__DIR__).'/'.$destDir;
        if (! is_dir($absoluteDestDir)) {
            mkdir($absoluteDestDir, 0777, true);
        }
        $src = $dir.'/'.$file;
        $dest = $absoluteDestDir.'/'.$file;
        if (is_file($dest)) {
            unlink($src);
            continue;
        }
        rename($src, $dest);
        $contents = file_get_contents($dest);
        $contents = str_replace('namespace '.$oldNsRoot.';', 'namespace '.$ns.';', $contents);
        file_put_contents($dest, $contents);
        $class = basename($file, '.php');
        $rewrites[$oldNsRoot.'\\'.$class] = $ns.'\\'.$class;
        $moved++;
        echo "{$owner}: {$file}\n";
    }

    return $moved;
}

function moveTreeSimple(string $from, string $to): int
{
    $count = 0;
    if (! is_dir($to)) {
        mkdir($to, 0777, true);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($from) + 1);
        $dest = $to.'/'.$rel;
        if ($item->isDir()) {
            if (! is_dir($dest)) {
                mkdir($dest, 0777, true);
            }
            continue;
        }
        if (! is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0777, true);
        }
        if (is_file($dest)) {
            unlink($item->getPathname());
            continue;
        }
        rename($item->getPathname(), $dest);
        $count++;
    }
    // prune
    $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it2 as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($from);

    return $count;
}

function rewriteAll(string $root, array $rewrites): int
{
    $updated = 0;
    foreach (['app', 'addons', 'tests'] as $base) {
        $path = $root.'/'.$base;
        if (! is_dir($path)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $c = file_get_contents($file->getPathname());
            $n = $c;
            foreach ($rewrites as $from => $to) {
                $n = str_replace($from, $to, $n);
            }
            if ($n !== $c) {
                file_put_contents($file->getPathname(), $n);
                $updated++;
            }
        }
    }

    return $updated;
}
