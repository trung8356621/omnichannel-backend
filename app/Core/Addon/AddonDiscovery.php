<?php

declare(strict_types=1);

namespace App\Core\Addon;

/**
 * Discovers peer addons from configured roots. Core never hard-codes business addons.
 */
final class AddonDiscovery
{
    /**
     * @param  list<string>  $roots Absolute or base_path-relative roots containing addon folders.
     * @param  list<string>  $skipSlugs
     * @return list<AddonManifest>
     */
    public function discover(array $roots, array $skipSlugs = []): array
    {
        $found = [];
        $skip = array_fill_keys(array_map('strval', $skipSlugs), true);

        foreach ($roots as $root) {
            $absolute = $this->absoluteRoot($root);
            if ($absolute === null || ! is_dir($absolute)) {
                continue;
            }

            foreach ($this->directories($absolute) as $dir) {
                $jsonPath = $dir.DIRECTORY_SEPARATOR.'addon.json';
                if (! is_file($jsonPath)) {
                    continue;
                }

                $meta = json_decode((string) file_get_contents($jsonPath), true);
                if (! is_array($meta)) {
                    continue;
                }

                try {
                    $manifest = AddonManifest::fromArray($meta, $dir);
                } catch (\InvalidArgumentException) {
                    continue;
                }

                if (isset($skip[$manifest->slug])) {
                    continue;
                }

                // Later roots win on duplicate slug (peer addons/ overrides legacy app/Addons/).
                $found[$manifest->slug] = $manifest;
            }
        }

        return array_values($found);
    }

    /**
     * @return list<string>
     */
    private function directories(string $absolute): array
    {
        $dirs = glob($absolute.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);

        return is_array($dirs) ? array_values($dirs) : [];
    }

    private function absoluteRoot(string $root): ?string
    {
        $root = trim($root);
        if ($root === '') {
            return null;
        }

        if ($this->isAbsolutePath($root)) {
            return $root;
        }

        if (function_exists('base_path')) {
            return base_path($root);
        }

        return $root;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
