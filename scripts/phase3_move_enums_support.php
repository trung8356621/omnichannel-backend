<?php

declare(strict_types=1);

/**
 * Move SeoContentAi Enums + Support by filename heuristics. FQCN-only rewrites.
 */
$root = dirname(__DIR__);
$legacy = $root.'/app/Addons/SeoContentAi';

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

$enumRules = [
    'search-intelligence' => ['KeywordIntelligence', 'KeywordReview', 'KeywordCluster', 'KeywordSearch', 'Gsc', 'Rank', 'Serp', 'Opportunity'],
    'content-projects' => ['ContentProject', 'SeoProject', 'WorkflowExecution', 'ProjectRun', 'RerunFrom'],
    'publishing' => ['PublishQueue', 'PublishStatus', 'Schedule'],
    'commerce' => ['ProductReview', 'ArticleProduct'],
    'media' => ['Media', 'Watermark', 'Gallery', 'ImageTool'],
    'wordpress' => ['WpSync', 'WordPress'],
    'site-sync' => ['SiteSync'],
    'agent' => ['Agent', 'Automation', 'Mcp'],
    'ai-prompt' => ['Prompt', 'ApiConnection', 'AiChat', 'Generation'],
    'seo' => ['SeoLink', 'SeoScore', 'SeoAudit', 'Cannibal'],
    'content' => ['Article', 'ReviewStatus', 'WritingSource', 'Revision'],
    'search-foundation' => ['Keyword', 'Domain', 'LinkMap'],
];

$supportRules = [
    'search-intelligence' => ['SerpProvider', 'Gsc', 'Rank', 'KeywordIntelligence', 'KeywordFocus', 'KeywordGroup'],
    'content-projects' => ['ContentProject', 'TaskTest', 'Workflow'],
    'ai-prompt' => ['Prompt', 'ApiConnection', 'ImageOutput'],
    'media' => ['ImageTool', 'ProductGallery', 'Watermark', 'Media'],
    'seo' => ['SeoAccess', 'SeoConnection', 'SeoScoring', 'SeoLink', 'SeoMain', 'CtaKeyword'],
    'wordpress' => ['WordPress', 'Wp'],
    'publishing' => ['Publish', 'Schedule'],
    'site-sync' => ['SiteSync'],
    'agent' => ['Agent', 'Mcp', 'Automation'],
    'commerce' => ['Product'],
    'content' => ['Article', 'ArticleEditor', 'ArticleWriting', 'ArticlePostType'],
    'search-foundation' => ['Keyword', 'Domain', 'LinkMap'],
];

$fqcnRewrites = [];
$moved = 0;

$moved += moveDir($legacy.'/Enums', 'Enums', $enumRules, $slugPascal, $fqcnRewrites, $root);
$moved += moveDir($legacy.'/Support', 'Support', $supportRules, $slugPascal, $fqcnRewrites, $root);

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

echo "moved files: {$moved}\n";
echo "fqcn patched files: {$patched}\n";
echo "remaining Enums: ".countPhp($legacy.'/Enums')."\n";
echo "remaining Support: ".countPhp($legacy.'/Support')."\n";

function moveDir(string $fromDir, string $kind, array $rules, array $slugPascal, array &$fqcnRewrites, string $root): int
{
    if (! is_dir($fromDir)) {
        return 0;
    }
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fromDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($fromDir))), '/');
        $base = basename($path);
        $owner = null;
        foreach ($rules as $slug => $needles) {
            foreach ($needles as $n) {
                if (stripos($rel, $n) !== false || stripos($base, $n) !== false) {
                    $owner = $slug;
                    break 2;
                }
            }
        }
        if ($owner === null) {
            echo "SKIP {$kind}/{$rel}\n";
            continue;
        }
        $pascal = $slugPascal[$owner];
        $to = $root.'/addons/'.$owner.'/src/'.$kind.'/'.$rel;
        $content = file_get_contents($path);
        if (! preg_match('/namespace\s+([^;]+);/', $content, $nm)) {
            continue;
        }
        if (! preg_match('/\b(?:abstract\s+|final\s+)?(?:class|trait|interface|enum)\s+(\w+)/', $content, $cm)) {
            continue;
        }
        $oldNs = trim($nm[1]);
        $relDir = dirname($rel);
        $newNs = 'Omnichannel\\Addons\\'.$pascal.'\\'.$kind.($relDir === '.' ? '' : '\\'.str_replace('/', '\\', $relDir));
        $class = $cm[1];
        $content = str_replace('namespace '.$oldNs.';', 'namespace '.$newNs.';', $content);
        if (! is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }
        file_put_contents($to, $content);
        unlink($path);
        $fqcnRewrites[$oldNs.'\\'.$class] = $newNs.'\\'.$class;
        $count++;
        echo "{$kind}/{$rel} -> {$owner}\n";
    }

    return $count;
}

function countPhp(string $dir): int
{
    if (! is_dir($dir)) {
        return 0;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $n++;
        }
    }

    return $n;
}
