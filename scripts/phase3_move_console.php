<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$from = $root.'/app/Addons/SeoContentAi/Console';
if (! is_dir($from)) {
    echo "no console\n";
    exit(0);
}

$rules = [
    'wordpress' => ['WordPress', 'Wp', 'Plugin'],
    'publishing' => ['Publish', 'Schedule', 'Queue'],
    'site-sync' => ['SiteSync', 'Sync'],
    'search-intelligence' => ['Rank', 'Serp', 'Gsc', 'KeywordDiscovery', 'SearchVolume', 'Opportunity', 'Performance'],
    'media' => ['Media', 'Image', 'Watermark', 'Gallery'],
    'content-projects' => ['ContentProject', 'Project', 'Workflow', 'Task', 'Run'],
    'ai-prompt' => ['Prompt', 'AiConnection', 'AiChat'],
    'agent' => ['Agent', 'Mcp', 'Automation'],
    'seo' => ['SeoAudit', 'SeoScore', 'Cannibal', 'InternalLink'],
    'content' => ['Article', 'Revision', 'Editor'],
    'search-foundation' => ['Keyword', 'Domain', 'SeoDatabase', 'Migrate'],
    'commerce' => ['Product', 'Review'],
];
$pascal = [
    'wordpress' => 'WordPress',
    'publishing' => 'Publishing',
    'site-sync' => 'SiteSync',
    'search-intelligence' => 'SearchIntelligence',
    'media' => 'Media',
    'content-projects' => 'ContentProjects',
    'ai-prompt' => 'AiPrompt',
    'agent' => 'Agent',
    'seo' => 'Seo',
    'content' => 'Content',
    'search-foundation' => 'SearchFoundation',
    'commerce' => 'Commerce',
];

$fqcn = [];
$moved = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = $f->getPathname();
    $rel = ltrim(str_replace('\\', '/', substr($path, strlen($from))), '/');
    $base = basename($path);
    $owner = 'seo';
    foreach ($rules as $slug => $needles) {
        foreach ($needles as $n) {
            if (stripos($rel, $n) !== false || stripos($base, $n) !== false) {
                $owner = $slug;
                break 2;
            }
        }
    }
    $to = $root.'/addons/'.$owner.'/src/Console/'.$rel;
    $c = file_get_contents($path);
    if (! preg_match('/namespace\s+([^;]+);/', $c, $nm) || ! preg_match('/\b(?:abstract\s+|final\s+)?(?:class|trait|interface|enum)\s+(\w+)/', $c, $cm)) {
        echo "SKIP {$rel}\n";
        continue;
    }
    $old = trim($nm[1]);
    $relDir = dirname($rel);
    $new = 'Omnichannel\\Addons\\'.$pascal[$owner].'\\Console'.($relDir === '.' ? '' : '\\'.str_replace('/', '\\', $relDir));
    $c = str_replace('namespace '.$old.';', 'namespace '.$new.';', $c);
    if (! is_dir(dirname($to))) {
        mkdir(dirname($to), 0777, true);
    }
    file_put_contents($to, $c);
    unlink($path);
    $fqcn[$old.'\\'.$cm[1]] = $new.'\\'.$cm[1];
    $moved++;
    echo "{$rel} -> {$owner}\n";
}

uksort($fqcn, static fn ($a, $b) => strlen($b) <=> strlen($a));
$p = 0;
foreach (['app', 'addons', 'tests'] as $d) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $c = file_get_contents($f->getPathname());
        $o = $c;
        foreach ($fqcn as $old => $new) {
            $c = str_replace($old, $new, $c);
        }
        if ($c !== $o) {
            file_put_contents($f->getPathname(), $c);
            $p++;
        }
    }
}
echo "moved {$moved} patched {$p}\n";
