<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$legacy = $root.'/app/Addons/SeoContentAi';

$batches = [
    // mostly agent owns Extension SDK / registry surface
    ['Extension', 'agent', 'Agent', [
        'seo' => ['Seo', 'RankMath', 'Scoring'],
        'wordpress' => ['Wordpress', 'WordPress', 'Wp'],
        'media' => ['Media', 'Image'],
        'publishing' => ['Publish'],
        'content' => ['Article', 'Editor'],
        'content-projects' => ['ContentProject', 'Project'],
        'ai-prompt' => ['Prompt', 'Ai'],
        'search-intelligence' => ['Keyword', 'Gsc', 'Performance'],
    ]],
    ['Contracts', 'seo', 'Seo', [
        'publishing' => ['Publish'],
        'wordpress' => ['WordPress', 'Wp'],
        'content' => ['Article', 'Content'],
        'media' => ['Media'],
        'agent' => ['Agent', 'Mcp'],
    ]],
    ['DataTransfer', 'content', 'Content', [
        'media' => ['Media', 'Image'],
        'seo' => ['Seo'],
        'wordpress' => ['Wp', 'WordPress'],
    ]],
    ['Exceptions', 'seo', 'Seo', [
        'ai-prompt' => ['Prompt'],
        'content' => ['Article'],
        'wordpress' => ['WordPress', 'Wp'],
        'publishing' => ['Publish'],
    ]],
    ['Observers', 'content', 'Content', [
        'search-foundation' => ['Keyword', 'Domain'],
        'media' => ['Media'],
        'content-projects' => ['Project'],
    ]],
];

$pascalAll = [
    'agent' => 'Agent', 'seo' => 'Seo', 'wordpress' => 'WordPress', 'media' => 'Media',
    'publishing' => 'Publishing', 'content' => 'Content', 'content-projects' => 'ContentProjects',
    'ai-prompt' => 'AiPrompt', 'search-intelligence' => 'SearchIntelligence',
    'search-foundation' => 'SearchFoundation', 'site-sync' => 'SiteSync', 'commerce' => 'Commerce',
];

$fqcn = [];
$moved = 0;

foreach ($batches as [$kind, $defaultSlug, $defaultPascal, $rules]) {
    $dir = $legacy.'/'.$kind;
    if (! is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($dir))), '/');
        $base = basename($path);
        $owner = $defaultSlug;
        foreach ($rules as $slug => $needles) {
            foreach ($needles as $n) {
                if (stripos($rel, $n) !== false || stripos($base, $n) !== false) {
                    $owner = $slug;
                    break 2;
                }
            }
        }
        $pascal = $pascalAll[$owner];
        $to = $root.'/addons/'.$owner.'/src/'.$kind.'/'.$rel;
        $c = file_get_contents($path);
        if (! preg_match('/namespace\s+([^;]+);/', $c, $nm) || ! preg_match('/\b(?:abstract\s+|final\s+)?(?:class|trait|interface|enum)\s+(\w+)/', $c, $cm)) {
            echo "SKIP {$kind}/{$rel}\n";
            continue;
        }
        $old = trim($nm[1]);
        $relDir = dirname($rel);
        $new = 'Omnichannel\\Addons\\'.$pascal.'\\'.$kind.($relDir === '.' ? '' : '\\'.str_replace('/', '\\', $relDir));
        $c = str_replace('namespace '.$old.';', 'namespace '.$new.';', $c);
        if (! is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }
        file_put_contents($to, $c);
        unlink($path);
        $fqcn[$old.'\\'.$cm[1]] = $new.'\\'.$cm[1];
        $moved++;
        echo "{$kind}/{$rel} -> {$owner}\n";
    }
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
