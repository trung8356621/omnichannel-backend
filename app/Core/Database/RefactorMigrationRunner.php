<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Shared dual-DB migration path resolution for refactor:migrate(+-fresh).
 */
final class RefactorMigrationRunner
{
    public function __construct(
        private readonly AddonMigrationRegistrar $registrar,
    ) {}

    /**
     * Absolute peer addon migration directories (excludes core database/migrations).
     *
     * @return list<string>
     */
    public function peerAbsolutePaths(): array
    {
        return array_values(array_filter(
            $this->registrar->migrationPaths(),
            static function (string $path): bool {
                $real = realpath($path);
                $core = realpath(database_path('migrations'));

                return $real === false || $core === false || $real !== $core;
            },
        ));
    }

    /**
     * @param  list<string>  $absolutePaths
     * @return list<string> Paths relative to base_path() using forward slashes
     */
    public function toRelativePaths(array $absolutePaths): array
    {
        $base = str_replace('\\', '/', base_path());

        return array_values(array_map(
            static function (string $absolute) use ($base): string {
                $norm = str_replace('\\', '/', $absolute);
                if (str_starts_with($norm, $base.'/')) {
                    return substr($norm, strlen($base) + 1);
                }
                if (str_starts_with($norm, $base)) {
                    return ltrim(substr($norm, strlen($base)), '/');
                }

                return ltrim(str_replace('\\', '/', str_replace(base_path(), '', $absolute)), '/');
            },
            $absolutePaths,
        ));
    }

    /**
     * Target database names for core + SEO connections.
     *
     * @return list<string>
     */
    public function targetDatabaseNames(?string $seoConnection = null): array
    {
        $seoConnection ??= (string) config('seo-content-ai.connection', 'omi_seo_ai');
        $names = [];

        $core = (string) config('database.connections.mysql.database', '');
        if ($core !== '') {
            $names[] = $core;
        }

        $seo = (string) config('database.connections.'.$seoConnection.'.database', '');
        if ($seo !== '') {
            $names[] = $seo;
        }

        return array_values(array_unique($names));
    }

    public function seoConnectionName(): string
    {
        return (string) config('seo-content-ai.connection', 'omi_seo_ai');
    }
}
