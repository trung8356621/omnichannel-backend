<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Resolve migration file by basename across owned addon paths (compat for tests/tooling).
 */
final class MigrationPathResolver
{
    public function findByBasename(string $basename): ?string
    {
        $basename = basename($basename);
        $registrar = app(AddonMigrationRegistrar::class);

        foreach ($registrar->migrationPaths() as $dir) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$basename;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $core = database_path('migrations'.DIRECTORY_SEPARATOR.$basename);
        if (is_file($core)) {
            return $core;
        }

        return null;
    }
}
