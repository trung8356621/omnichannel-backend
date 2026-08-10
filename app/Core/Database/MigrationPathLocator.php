<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Locates migration files after Phase 2 ownership move — no Laravel container required.
 */
final class MigrationPathLocator
{
    /**
     * @return list<string>
     */
    public static function searchRoots(?string $projectRoot = null): array
    {
        $root = $projectRoot ?? dirname(__DIR__, 3);
        $roots = [
            $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations',
            $root.DIRECTORY_SEPARATOR.'addons'.DIRECTORY_SEPARATOR.'_legacy-obsolete'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations',
        ];

        foreach ([
            'search-foundation', 'seo', 'search-intelligence', 'ai-prompt', 'content',
            'content-projects', 'media', 'wordpress', 'publishing', 'site-sync',
            'agent', 'social', 'commerce',
        ] as $slug) {
            $roots[] = $root.DIRECTORY_SEPARATOR.'addons'.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        }

        return $roots;
    }

    public static function find(string $basename, ?string $projectRoot = null): ?string
    {
        $basename = basename($basename);
        foreach (self::searchRoots($projectRoot) as $dir) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$basename;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
