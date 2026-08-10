<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$legacy = $root.'/app/Addons/SeoContentAi';
$map = [
    'Enums' => [
        'default' => 'seo',
        'rules' => [
            'content-projects' => ['Project', 'Task', 'Workflow'],
            'content' => ['Article', 'Review'],
            'search-foundation' => ['Keyword', 'Domain'],
        ],
    ],
    'Support' => [
        'default' => 'seo',
        'rules' => [
            'content-projects' => ['SeoProject', 'Project', 'Task', 'Workflow', 'Queue'],
            'content' => ['Article', 'Typography', 'Markdown', 'Utf8', 'SourceAware', 'SystemDate'],
            'search-foundation' => ['Keyword', 'Domain', 'SqlStream', 'SiteServiceDatabase'],
            'ai-prompt' => ['Vision', 'Prompt'],
            'media' => ['Image'],
            'agent' => ['Agent'],
        ],
    ],
];
$pascal = [
    'seo' => 'Seo',
    'content-projects' => 'ContentProjects',
    'content' => 'Content',
    'search-foundation' => 'SearchFoundation',
    'ai-prompt' => 'AiPrompt',
    'media' => 'Media',
    'agent' => 'Agent',
];
$fqcn = [];
$moved = 0;
foreach ($map as $kind => $cfg) {
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
        $owner = $cfg['default'];
        foreach ($cfg['rules'] as $slug => $needles) {
            foreach ($needles as $n) {
                if (stripos($rel, $n) !== false || stripos($base, $n) !== false) {
                    $owner = $slug;
                    break 2;
                }
            }
        }
        $to = $root.'/addons/'.$owner.'/src/'.$kind.'/'.$rel;
        $c = file_get_contents($path);
        preg_match('/namespace\s+([^;]+);/', $c, $nm);
        preg_match('/\b(?:abstract\s+|final\s+)?(?:class|trait|interface|enum)\s+(\w+)/', $c, $cm);
        $old = trim($nm[1]);
        $relDir = dirname($rel);
        $new = 'Omnichannel\\Addons\\'.$pascal[$owner].'\\'.$kind.($relDir === '.' ? '' : '\\'.str_replace('/', '\\', $relDir));
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
