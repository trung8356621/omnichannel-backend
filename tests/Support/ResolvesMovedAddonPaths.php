<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Shared helper for contract/regression tests that read source files by relative
 * path. Historically all SEO Content AI code lived under `app/Addons/SeoContentAi`,
 * so many tests hardcode that root. Task 7/8 moved most PHP classes to
 * `addons/{owner}/src/...` and most JS to `addons/{owner}/resources/js/...`, while
 * some assets (views, the compatibility `SeoContentAiServiceProvider`) intentionally
 * stayed put.
 *
 * `resolveLegacyOrMovedAddonPath()` keeps the legacy root as the first candidate
 * (so still-valid legacy references keep working) and otherwise searches every
 * addon's `src` and `resources/js` roots for a matching relative suffix, falling
 * back to a basename search when the directory layout changed slightly.
 */
trait ResolvesMovedAddonPaths
{
    private function resolveLegacyOrMovedAddonPath(string $relative): string
    {
        $normalizedRelative = ltrim(str_replace('\\', '/', $relative), '/');
        $legacyPath = $this->legacySeoContentAiRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalizedRelative);

        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        foreach ($this->movedAddonSearchRoots() as $root) {
            $candidate = $root.'/'.$normalizedRelative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $basename = basename($normalizedRelative);
        foreach ($this->movedAddonSearchRoots() as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === $basename) {
                    return $file->getPathname();
                }
            }
        }

        self::fail(sprintf(
            'Unable to locate "%s" under legacy SeoContentAi root or any addons/*/src|resources/js root.',
            $relative
        ));
    }

    private function readLegacyOrMovedAddonFile(string $relative): string
    {
        $path = $this->resolveLegacyOrMovedAddonPath($relative);
        self::assertFileExists($path);

        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }

    /**
     * @return list<string>
     */
    private function movedAddonSearchRoots(): array
    {
        $addonsDir = $this->addonsRootDir();
        $roots = [];

        foreach (glob($addonsDir.'/*', GLOB_ONLYDIR) ?: [] as $addonDir) {
            $roots[] = $addonDir.'/src';
            $roots[] = $addonDir.'/resources/js';
            $roots[] = $addonDir.'/database/migrations';
            $roots[] = $addonDir;
        }

        return $roots;
    }

    private function legacySeoContentAiRoot(): string
    {
        return $this->repositoryRootDir().'/app/Addons/SeoContentAi';
    }

    private function addonsRootDir(): string
    {
        return $this->repositoryRootDir().'/addons';
    }

    private function repositoryRootDir(): string
    {
        return dirname(__DIR__, 2);
    }
}
