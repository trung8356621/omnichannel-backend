<?php

declare(strict_types=1);

/**
 * Fix broken Filament concern/page/resource use statements after mass moves.
 * Build FQCN map by basename across peer addons, then rewrite missing use targets.
 */
$root = dirname(__DIR__);

$classMap = []; // basename => list of FQCN
$fileMap = []; // FQCN => path

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/addons', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = $f->getPathname();
    $content = file_get_contents($path);
    if (! preg_match('/namespace\s+([^;]+);/', $content, $nm)) {
        continue;
    }
    if (! preg_match('/\b(?:class|trait|interface|enum)\s+(\w+)/', $content, $cm)) {
        continue;
    }
    $fqcn = trim($nm[1]).'\\'.$cm[1];
    $fileMap[$fqcn] = $path;
    $classMap[$cm[1]][] = $fqcn;
}

// Prefer seo panel bases / concerns when duplicates
$preferPrefix = [
    'SeoPanelResource' => 'Omnichannel\\Addons\\Seo\\',
    'SeoPanelPage' => 'Omnichannel\\Addons\\Seo\\',
    'BelongsToAdminAutomationPanel' => 'Omnichannel\\Addons\\Seo\\',
    'RedirectsSeoAutomationToAdmin' => 'Omnichannel\\Addons\\Seo\\',
    'HidesFilamentPageHeader' => 'Omnichannel\\Addons\\Seo\\',
    'InteractsWithSeoConnectionResourceRoutes' => 'Omnichannel\\Addons\\Seo\\',
    'InteractsWithSeoConnectionRoutes' => 'Omnichannel\\Addons\\Seo\\',
    'InteractsWithSeoFilamentFormSaveActions' => 'Omnichannel\\Addons\\Seo\\',
    'InteractsWithSeoAllDomainsDashboard' => 'Omnichannel\\Addons\\Seo\\',
    'InteractsWithSeoDashboardSite' => 'Omnichannel\\Addons\\Seo\\',
    'PersistsDomainPromptContext' => 'Omnichannel\\Addons\\AiPrompt\\',
];

$patched = 0;
$missing = [];

foreach (['app', 'addons', 'tests'] as $d) {
    $scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d, FilesystemIterator::SKIP_DOTS));
    foreach ($scan as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        $c = file_get_contents($path);
        $o = $c;

        if (! preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?\s*;/m', $c, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $m) {
            $use = $m[1];
            if (isset($fileMap[$use])) {
                continue; // exists
            }
            // Only fix Omnichannel addon uses
            if (! str_starts_with($use, 'Omnichannel\\Addons\\') && ! str_starts_with($use, 'App\\Addons\\SeoContentAi\\')) {
                continue;
            }
            $base = substr($use, strrpos($use, '\\') + 1);
            $candidates = $classMap[$base] ?? [];
            if ($candidates === []) {
                $missing[$use] = ($missing[$use] ?? 0) + 1;
                continue;
            }
            $chosen = $candidates[0];
            if (isset($preferPrefix[$base])) {
                foreach ($candidates as $cand) {
                    if (str_starts_with($cand, $preferPrefix[$base])) {
                        $chosen = $cand;
                        break;
                    }
                }
            } elseif (count($candidates) > 1) {
                // Prefer same owner as current file if possible
                if (preg_match('#/addons/([^/]+)/#', str_replace('\\', '/', $path), $om)) {
                    $pascal = str_replace(' ', '', ucwords(str_replace('-', ' ', $om[1])));
                    foreach ($candidates as $cand) {
                        if (str_starts_with($cand, 'Omnichannel\\Addons\\'.$pascal.'\\')) {
                            $chosen = $cand;
                            break;
                        }
                    }
                }
                if (count($candidates) > 1 && ! isset($preferPrefix[$base])) {
                    // ambiguous — skip unless exact single match after owner filter still multiple
                    $uniqueOwners = [];
                    foreach ($candidates as $cand) {
                        if (preg_match('/^Omnichannel\\\\Addons\\\\([^\\\\]+)\\\\/', $cand, $x)) {
                            $uniqueOwners[$x[1]] = true;
                        }
                    }
                    if (count($uniqueOwners) > 1 && ! isset($preferPrefix[$base])) {
                        $missing[$use.' => AMBIG '.implode('|', $candidates)] = ($missing[$use] ?? 0) + 1;
                        continue;
                    }
                }
            }

            if ($chosen !== $use) {
                $c = str_replace($use, $chosen, $c);
            }
        }

        if ($c !== $o) {
            file_put_contents($path, $c);
            $patched++;
        }
    }
}

echo "patched files: {$patched}\n";
echo "still missing/ambiguous:\n";
arsort($missing);
foreach (array_slice($missing, 0, 40, true) as $k => $v) {
    echo "  {$v}x {$k}\n";
}
