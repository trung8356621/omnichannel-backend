<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$http = $root.'/app/Addons/SeoContentAi/Http';

if (! is_dir($http)) {
    echo "No Http dir\n";
    exit(0);
}

$rules = [
    'wordpress' => ['WordPress', 'Wp', 'Plugin'],
    'publishing' => ['Publish', 'Schedule', 'Queue'],
    'site-sync' => ['SiteSync'],
    'search-intelligence' => ['Rank', 'Serp', 'Gsc', 'KeywordIntelligence', 'Performance', 'Opportunity'],
    'media' => ['Media', 'Image', 'Gallery', 'Watermark'],
    'content-projects' => ['ContentProject', 'Project', 'Workflow', 'Task'],
    'ai-prompt' => ['Prompt', 'AiConnection', 'AiChat', 'Generation'],
    'seo' => ['SeoAudit', 'SeoScore', 'SeoMeta', 'SeoSetting', 'InternalLink', 'Cannibal'],
    'agent' => ['Agent', 'Mcp'],
    'commerce' => ['Product', 'Commerce', 'Sku'],
    'content' => ['Article', 'Editor', 'Revision', 'Autosave', 'Lock'],
    'search-foundation' => ['Keyword', 'Domain'],
];

$pascal = [
    'wordpress' => 'WordPress',
    'publishing' => 'Publishing',
    'site-sync' => 'SiteSync',
    'search-intelligence' => 'SearchIntelligence',
    'media' => 'Media',
    'content-projects' => 'ContentProjects',
    'ai-prompt' => 'AiPrompt',
    'seo' => 'Seo',
    'agent' => 'Agent',
    'commerce' => 'Commerce',
    'content' => 'Content',
    'search-foundation' => 'SearchFoundation',
];

$rewrites = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($http, FilesystemIterator::SKIP_DOTS));

foreach ($it as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $base = $f->getFilename();
    $owner = null;
    foreach ($rules as $slug => $needles) {
        foreach ($needles as $n) {
            if (stripos($base, $n) !== false) {
                $owner = $slug;
                break 2;
            }
        }
    }
    if ($owner === null) {
        echo "HTTP-SKIP {$base}\n";
        continue;
    }

    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($http))), '/');
    $to = $root.'/addons/'.$owner.'/src/Http/'.$rel;
    $content = file_get_contents($f->getPathname());
    if (! preg_match('/namespace\s+([^;]+);/', $content, $m)) {
        echo "HTTP-NO-NS {$base}\n";
        continue;
    }
    $old = trim($m[1]);
    $relDir = dirname($rel);
    $new = 'Omnichannel\\Addons\\'.$pascal[$owner].'\\Http'.($relDir === '.' ? '' : '\\'.str_replace('/', '\\', $relDir));
    $content = str_replace('namespace '.$old.';', 'namespace '.$new.';', $content);
    if (! is_dir(dirname($to))) {
        mkdir(dirname($to), 0777, true);
    }
    file_put_contents($to, $content);
    unlink($f->getPathname());
    $cls = pathinfo($base, PATHINFO_FILENAME);
    $rewrites[$old.'\\'.$cls] = $new.'\\'.$cls;
    $rewrites[$old] = $new;
    echo "HTTP {$rel} -> {$owner}\n";
}

uksort($rewrites, static fn ($a, $b) => strlen($b) <=> strlen($a));

$files = [];
foreach (['app', 'addons', 'tests'] as $d) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $ff) {
        if ($ff->isFile() && $ff->getExtension() === 'php') {
            $files[] = $ff->getPathname();
        }
    }
}

$patched = 0;
foreach ($files as $file) {
    $c = file_get_contents($file);
    $o = $c;
    foreach ($rewrites as $old => $new) {
        if ($old !== $new) {
            $c = str_replace($old, $new, $c);
        }
    }
    if ($c !== $o) {
        file_put_contents($file, $c);
        $patched++;
    }
}

echo "HTTP rewrite map: ".count($rewrites)."\n";
echo "HTTP patched: {$patched}\n";
