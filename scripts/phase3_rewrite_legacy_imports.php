<?php

declare(strict_types=1);

/**
 * Rewrite remaining App\Addons\SeoContentAi\* imports to peer FQCNs by class basename.
 */
$root = dirname(__DIR__);

$classMap = []; // basename => [fqcn,...]
$fileMap = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/addons', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $content = file_get_contents($f->getPathname());
    if (! preg_match('/namespace\s+([^;]+);/', $content, $nm)) {
        continue;
    }
    if (! preg_match('/\b(?:abstract\s+|final\s+)?(?:class|trait|interface|enum)\s+(\w+)/', $content, $cm)) {
        continue;
    }
    $fqcn = trim($nm[1]).'\\'.$cm[1];
    $fileMap[$fqcn] = $f->getPathname();
    $classMap[$cm[1]][] = $fqcn;
}

// Prefer known owners for ambiguous names
$prefer = [
    'ApiConnectionProviders' => 'Omnichannel\\Addons\\AiPrompt\\',
    'SeoAccessControl' => 'Omnichannel\\Addons\\Seo\\',
    'SeoConnectionContext' => 'Omnichannel\\Addons\\Seo\\',
    'SeoPanelResource' => 'Omnichannel\\Addons\\Seo\\',
    'SeoPanelPage' => 'Omnichannel\\Addons\\Seo\\',
    'ArticleResource' => 'Omnichannel\\Addons\\Content\\',
    'KeywordResource' => 'Omnichannel\\Addons\\SearchIntelligence\\',
];

$patched = 0;
$unresolved = [];

foreach (['app', 'addons', 'tests', 'database'] as $d) {
    if (! is_dir($root.'/'.$d)) {
        continue;
    }
    $scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d, FilesystemIterator::SKIP_DOTS));
    foreach ($scan as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        $c = file_get_contents($path);
        if (! str_contains($c, 'App\\Addons\\SeoContentAi\\')) {
            continue;
        }
        $o = $c;
        if (preg_match_all('/App\\\\Addons\\\\SeoContentAi\\\\[A-Za-z0-9_\\\\]+/', $c, $matches)) {
            $uniq = array_unique($matches[0]);
            foreach ($uniq as $old) {
                $base = substr($old, strrpos($old, '\\') + 1);
                $cands = $classMap[$base] ?? [];
                if ($cands === []) {
                    $unresolved[$old] = ($unresolved[$old] ?? 0) + 1;
                    continue;
                }
                $chosen = $cands[0];
                if (isset($prefer[$base])) {
                    foreach ($cands as $cand) {
                        if (str_starts_with($cand, $prefer[$base])) {
                            $chosen = $cand;
                            break;
                        }
                    }
                } elseif (count($cands) > 1) {
                    // Try match trailing path segments
                    $oldTail = substr($old, strlen('App\\Addons\\SeoContentAi\\'));
                    foreach ($cands as $cand) {
                        if (str_ends_with($cand, $oldTail) || str_contains($cand, '\\'.str_replace('\\', '\\', $oldTail))) {
                            $chosen = $cand;
                            break;
                        }
                    }
                    // If still ambiguous and not preferred, skip
                    if (! isset($prefer[$base])) {
                        $tails = [];
                        foreach ($cands as $cand) {
                            $tails[] = $cand;
                        }
                        // pick first Omnichannel match with same relative suffix after owner
                        $suffix = $oldTail;
                        $matched = null;
                        foreach ($cands as $cand) {
                            if (preg_match('/Omnichannel\\\\Addons\\\\[^\\\\]+\\\\(.+)$/', $cand, $mm) && $mm[1] === $suffix) {
                                $matched = $cand;
                                break;
                            }
                        }
                        if ($matched) {
                            $chosen = $matched;
                        } elseif (count($cands) > 1) {
                            $unresolved[$old.' => '.implode('|', $cands)] = ($unresolved[$old] ?? 0) + 1;
                            continue;
                        }
                    }
                }
                $c = str_replace($old, $chosen, $c);
            }
        }
        if ($c !== $o) {
            file_put_contents($path, $c);
            $patched++;
        }
    }
}

echo "patched files: {$patched}\n";
echo "unresolved (top 40):\n";
arsort($unresolved);
foreach (array_slice($unresolved, 0, 40, true) as $k => $v) {
    echo "  {$v}x {$k}\n";
}
