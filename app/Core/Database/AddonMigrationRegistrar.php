<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Resolves addon migration directories from config/addon_migration_ownership.php.
 * Discovery does NOT require app/Addons/SeoContentAi.
 */
final class AddonMigrationRegistrar
{
    /**
     * @return list<string> Absolute paths that exist
     */
    public function migrationPaths(): array
    {
        $owners = config('addon_migration_ownership.owners', []);
        $paths = [];

        foreach ($owners as $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $rel = (string) ($meta['path'] ?? '');
            if ($rel === '') {
                continue;
            }
            $absolute = base_path($rel);
            if (is_dir($absolute)) {
                $paths[] = $absolute;
            }
        }

        // Optional leftover only — empty/missing legacy dir is ignored.
        $legacy = (string) config('addon_migration_ownership.default_legacy_path', '');
        if ($legacy !== '') {
            $legacyAbs = base_path($legacy);
            if (is_dir($legacyAbs) && $this->directoryHasPhpMigrations($legacyAbs)) {
                $paths[] = $legacyAbs;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string, list<string>> connection name => absolute migration dirs
     */
    public function pathsByConnection(): array
    {
        $owners = config('addon_migration_ownership.owners', []);
        $byConnection = [];

        foreach ($owners as $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $connection = (string) ($meta['connection'] ?? 'omi_seo_ai');
            $rel = (string) ($meta['path'] ?? '');
            if ($rel === '') {
                continue;
            }
            $absolute = base_path($rel);
            if (! is_dir($absolute)) {
                continue;
            }
            $byConnection[$connection] ??= [];
            $byConnection[$connection][] = $absolute;
        }

        foreach ($byConnection as $connection => $paths) {
            $byConnection[$connection] = array_values(array_unique($paths));
        }

        return $byConnection;
    }

    private function directoryHasPhpMigrations(string $absolute): bool
    {
        $files = glob($absolute.DIRECTORY_SEPARATOR.'*.php') ?: [];

        return $files !== [];
    }

    public function classifyFilename(string $filename, ?array $rules = null): string
    {
        $name = strtolower(basename($filename));
        if ($rules === null) {
            $rules = [];
            $configFile = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'addon_migration_ownership.php';
            if (is_file($configFile)) {
                $all = require $configFile;
                $rules = is_array($all['classify_rules'] ?? null) ? $all['classify_rules'] : [];
            }
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $owner = (string) ($rule['owner'] ?? '');
            $any = $rule['any'] ?? [];
            if ($owner === '' || ! is_array($any)) {
                continue;
            }
            foreach ($any as $needle) {
                if ($needle !== '' && str_contains($name, strtolower((string) $needle))) {
                    return $owner;
                }
            }
        }

        $configFile = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'addon_migration_ownership.php';
        if (is_file($configFile)) {
            $all = require $configFile;

            return (string) ($all['fallback_owner'] ?? 'legacy-obsolete');
        }

        return 'legacy-obsolete';
    }
}
