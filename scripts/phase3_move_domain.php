<?php

declare(strict_types=1);

/**
 * Phase 3 physical cutover helper — move SeoContentAi slices into peer addons.
 *
 * Usage:
 *   php scripts/phase3_move_domain.php wordpress
 *   php scripts/phase3_move_domain.php all
 */

$root = dirname(__DIR__);

/** @var array<string, list<array{from:string,to:string,ns_from:string,ns_to:string}>> */
$moves = [
    'wordpress' => [
        [
            'from' => 'app/Addons/SeoContentAi/Services/WordPress',
            'to' => 'addons/wordpress/src/Services',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\WordPress',
            'ns_to' => 'Omnichannel\\Addons\\WordPress\\Services',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Extension/Builtin/Wordpress',
            'to' => 'addons/wordpress/src/Extension',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Extension\\Builtin\\Wordpress',
            'ns_to' => 'Omnichannel\\Addons\\WordPress\\Extension',
        ],
    ],
    'site-sync' => [
        [
            'from' => 'app/Addons/SeoContentAi/Services/SiteSync',
            'to' => 'addons/site-sync/src/Services',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\SiteSync',
            'ns_to' => 'Omnichannel\\Addons\\SiteSync\\Services',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Models/SiteSync',
            'to' => 'addons/site-sync/src/Models',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Models\\SiteSync',
            'ns_to' => 'Omnichannel\\Addons\\SiteSync\\Models',
        ],
    ],
    'search-intelligence' => [
        [
            'from' => 'app/Addons/SeoContentAi/Services/KeywordIntelligence',
            'to' => 'addons/search-intelligence/src/Services/KeywordIntelligence',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\KeywordIntelligence',
            'ns_to' => 'Omnichannel\\Addons\\SearchIntelligence\\Services\\KeywordIntelligence',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Services/GscIntelligence',
            'to' => 'addons/search-intelligence/src/Services/GscIntelligence',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\GscIntelligence',
            'ns_to' => 'Omnichannel\\Addons\\SearchIntelligence\\Services\\GscIntelligence',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Services/SerpIntelligence',
            'to' => 'addons/search-intelligence/src/Services/SerpIntelligence',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\SerpIntelligence',
            'ns_to' => 'Omnichannel\\Addons\\SearchIntelligence\\Services\\SerpIntelligence',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Models/KeywordIntelligence',
            'to' => 'addons/search-intelligence/src/Models/KeywordIntelligence',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Models\\KeywordIntelligence',
            'ns_to' => 'Omnichannel\\Addons\\SearchIntelligence\\Models\\KeywordIntelligence',
        ],
    ],
    'agent' => [
        [
            'from' => 'app/Addons/SeoContentAi/Services/AgentWorkspace',
            'to' => 'addons/agent/src/Services/AgentWorkspace',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\AgentWorkspace',
            'ns_to' => 'Omnichannel\\Addons\\Agent\\Services\\AgentWorkspace',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Models/AgentWorkspace',
            'to' => 'addons/agent/src/Models/AgentWorkspace',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Models\\AgentWorkspace',
            'ns_to' => 'Omnichannel\\Addons\\Agent\\Models\\AgentWorkspace',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Automation',
            'to' => 'addons/agent/src/Automation',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Automation',
            'ns_to' => 'Omnichannel\\Addons\\Agent\\Automation',
        ],
    ],
    'ai-prompt' => [
        [
            'from' => 'app/Addons/SeoContentAi/PromptHooks',
            'to' => 'addons/ai-prompt/src/PromptHooks',
            'ns_from' => 'App\\Addons\\SeoContentAi\\PromptHooks',
            'ns_to' => 'Omnichannel\\Addons\\AiPrompt\\PromptHooks',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Services/PromptOwnership',
            'to' => 'addons/ai-prompt/src/Services/PromptOwnership',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\PromptOwnership',
            'ns_to' => 'Omnichannel\\Addons\\AiPrompt\\Services\\PromptOwnership',
        ],
    ],
    'media' => [
        // individual files handled separately after folder moves
    ],
    'publishing' => [
        [
            'from' => 'app/Addons/SeoContentAi/Support/PublishingQueue',
            'to' => 'addons/publishing/src/Support/PublishingQueue',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Support\\PublishingQueue',
            'ns_to' => 'Omnichannel\\Addons\\Publishing\\Support\\PublishingQueue',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Services/ContentProject/Publishing',
            'to' => 'addons/publishing/src/Services/Publishing',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\ContentProject\\Publishing',
            'ns_to' => 'Omnichannel\\Addons\\Publishing\\Services\\Publishing',
        ],
    ],
    'content-projects' => [
        [
            'from' => 'app/Addons/SeoContentAi/Services/ContentProject',
            'to' => 'addons/content-projects/src/Services/ContentProject',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\ContentProject',
            'ns_to' => 'Omnichannel\\Addons\\ContentProjects\\Services\\ContentProject',
        ],
    ],
    'commerce' => [
        [
            'from' => 'app/Addons/SeoContentAi/Services/ProductGallery',
            'to' => 'addons/commerce/src/Services/ProductGallery',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\ProductGallery',
            'ns_to' => 'Omnichannel\\Addons\\Commerce\\Services\\ProductGallery',
        ],
        [
            'from' => 'app/Addons/SeoContentAi/Services/ProductReview',
            'to' => 'addons/commerce/src/Services/ProductReview',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\ProductReview',
            'ns_to' => 'Omnichannel\\Addons\\Commerce\\Services\\ProductReview',
        ],
    ],
    'content' => [
        [
            'from' => 'app/Addons/SeoContentAi/Services/ArticleEditor',
            'to' => 'addons/content/src/Services/ArticleEditor',
            'ns_from' => 'App\\Addons\\SeoContentAi\\Services\\ArticleEditor',
            'ns_to' => 'Omnichannel\\Addons\\Content\\Services\\ArticleEditor',
        ],
    ],
];

$target = $argv[1] ?? 'all';
$selected = $target === 'all' ? array_keys($moves) : [$target];

$rewrites = []; // ns_from => ns_to accumulated
$movedFiles = 0;

foreach ($selected as $domain) {
    if (! isset($moves[$domain]) || $moves[$domain] === []) {
        fwrite(STDERR, "Skip empty/unknown domain: {$domain}\n");
        continue;
    }

    foreach ($moves[$domain] as $spec) {
        $from = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $spec['from']);
        $to = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $spec['to']);

        if (! is_dir($from)) {
            fwrite(STDERR, "Missing source: {$spec['from']}\n");
            continue;
        }

        if (! is_dir($to)) {
            mkdir($to, 0777, true);
        }

        // If ContentProject Publishing already moved, skip leftover empty
        $count = moveTree($from, $to);
        $movedFiles += $count;
        $rewrites[$spec['ns_from']] = $spec['ns_to'];
        echo "Moved {$count} files: {$spec['from']} -> {$spec['to']}\n";
    }
}

// Longest namespace first to avoid partial replacements
uksort($rewrites, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

$scanRoots = [
    $root.'/app',
    $root.'/addons',
    $root.'/tests',
    $root.'/bootstrap',
    $root.'/routes',
    $root.'/config',
    $root.'/database',
];

$updated = 0;
foreach ($scanRoots as $scanRoot) {
    if (! is_dir($scanRoot)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot));
    foreach ($it as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        if (! in_array($ext, ['php', 'json', 'md', 'jsx', 'js', 'blade.php'], true) && ! str_ends_with($file->getFilename(), '.blade.php')) {
            if ($ext !== 'php') {
                continue;
            }
        }
        if ($ext !== 'php' && ! str_ends_with($file->getPathname(), '.blade.php')) {
            continue;
        }

        $path = $file->getPathname();
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            continue;
        }
        $new = $contents;
        foreach ($rewrites as $fromNs => $toNs) {
            $new = str_replace($fromNs, $toNs, $new);
        }
        if ($new !== $contents) {
            file_put_contents($path, $new);
            $updated++;
        }
    }
}

echo "Namespace rewrites applied in {$updated} files.\n";
echo "Total moved files this run: {$movedFiles}\n";
file_put_contents(
    $root.'/addons/PHASE3_LAST_MOVE.json',
    json_encode(['rewrites' => $rewrites, 'moved' => $movedFiles, 'updated' => $updated], JSON_PRETTY_PRINT)
);

function moveTree(string $from, string $to): int
{
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($from) + 1);
        $dest = $to.DIRECTORY_SEPARATOR.$rel;
        if ($item->isDir()) {
            if (! is_dir($dest)) {
                mkdir($dest, 0777, true);
            }
            continue;
        }
        $destDir = dirname($dest);
        if (! is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        if (is_file($dest)) {
            // Prefer keeping already-moved peer file; remove source duplicate.
            unlink($item->getPathname());
            continue;
        }
        rename($item->getPathname(), $dest);
        $count++;
    }

    // Cleanup empty dirs
    removeEmptyDirs($from);

    return $count;
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
    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($dir);
}
