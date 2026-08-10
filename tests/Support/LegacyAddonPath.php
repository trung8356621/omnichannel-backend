<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Resolve historical SeoContentAi-relative paths to current peer-addon locations.
 * Views / ServiceProvider / Compat remaining under SeoContentAi still resolve first.
 */
final class LegacyAddonPath
{
    public static function resolve(string $relative): string
    {
        $normalizedRelative = ltrim(str_replace('\\', '/', $relative), '/');
        $root = dirname(__DIR__, 2);
        $legacyPath = $root.'/app/Addons/SeoContentAi/'.str_replace('/', DIRECTORY_SEPARATOR, $normalizedRelative);

        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        foreach (glob($root.'/addons/*', GLOB_ONLYDIR) ?: [] as $addonDir) {
            foreach (['/src/', '/resources/js/', '/resources/', '/database/migrations/', '/'] as $suffix) {
                $candidate = $addonDir.$suffix.$normalizedRelative;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        $basename = basename($normalizedRelative);
        foreach (glob($root.'/addons/*', GLOB_ONLYDIR) ?: [] as $addonDir) {
            foreach ([$addonDir.'/src', $addonDir.'/resources/js', $addonDir] as $searchRoot) {
                if (! is_dir($searchRoot)) {
                    continue;
                }
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($searchRoot, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getFilename() === $basename) {
                        return $file->getPathname();
                    }
                }
            }
        }

        throw new RuntimeException(sprintf(
            'Unable to locate "%s" under SeoContentAi shell or addons/*.',
            $relative
        ));
    }

    public static function read(string $relative): string
    {
        $path = self::resolve($relative);
        $body = file_get_contents($path);
        if (! is_string($body)) {
            throw new RuntimeException('Failed reading '.$path);
        }

        return $body;
    }
}
