<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Runs pending migrations and records ones that fail only because legacy schema
 * already contains the intended tables/columns.
 */
final class LegacyTolerantMigrationRunner
{
    public function __construct(
        private readonly LegacySchemaMigrationReconciler $reconciler,
    ) {}

    /**
     * @param  list<string>  $relativePaths
     * @param  callable(string):void|null  $notify
     * @return array{ok: bool, skipped_existing: int, failed: null|string}
     */
    public function migrate(
        string $connection,
        array $relativePaths,
        bool $pretend = false,
        ?callable $notify = null,
    ): array {
        $skipped = 0;
        $guard = 0;

        while ($guard < 500) {
            $guard++;
            try {
                $args = [
                    '--database' => $connection,
                    '--path' => $relativePaths,
                    '--force' => true,
                ];
                if ($pretend) {
                    $args['--pretend'] = true;
                }

                Artisan::call('migrate', $args);
                $output = trim(Artisan::output());
                if ($output !== '' && $notify !== null) {
                    $notify($output);
                }

                return [
                    'ok' => true,
                    'skipped_existing' => $skipped,
                    'failed' => null,
                ];
            } catch (Throwable $e) {
                if ($pretend || ! $this->isExistingSchemaError($e)) {
                    return [
                        'ok' => false,
                        'skipped_existing' => $skipped,
                        'failed' => $e->getMessage(),
                    ];
                }

                $pending = $this->nextPendingAcrossPaths($relativePaths, $connection);
                if ($pending === null) {
                    return [
                        'ok' => false,
                        'skipped_existing' => $skipped,
                        'failed' => $e->getMessage(),
                    ];
                }

                $analysis = $this->reconciler->analyzeFile($pending, $connection);
                $canSkip = ($analysis['status'] ?? '') === 'already_applied'
                    || $this->forceProofFromException($e, $pending, $connection);

                if (! $canSkip) {
                    return [
                        'ok' => false,
                        'skipped_existing' => $skipped,
                        'failed' => $e->getMessage()
                            .' | pending='.basename($pending, '.php')
                            .' proof='.($analysis['status'] ?? 'n/a')
                            .' '.($analysis['reason'] ?? ''),
                    ];
                }

                $this->reconciler->apply([[
                    'migration' => basename($pending, '.php'),
                    'path' => $pending,
                    'status' => 'already_applied',
                    'reason' => $analysis['reason'] ?? 'existing-schema error while migrating',
                ]], $connection);

                $skipped++;
                if ($notify !== null) {
                    $notify('Recorded already-present migration: '.basename($pending, '.php'));
                }
            }
        }

        return [
            'ok' => false,
            'skipped_existing' => $skipped,
            'failed' => 'Exceeded legacy tolerate loop guard',
        ];
    }

    public function isExistingSchemaError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '42S01')
            || str_contains($message, '42S21')
            || str_contains($message, '1091')
            || str_contains($message, 'already exists')
            || str_contains($message, 'Duplicate column')
            || str_contains($message, "Can't DROP");
    }

    /**
     * @param  list<string>  $relativePaths
     */
    private function nextPendingAcrossPaths(array $relativePaths, string $connection): ?string
    {
        $ran = [];
        if (Schema::connection($connection)->hasTable('migrations')) {
            $ran = array_fill_keys(
                array_map('strval', DB::connection($connection)->table('migrations')->pluck('migration')->all()),
                true
            );
        }

        // Match Laravel Migrator: key by basename, then ksort (not full-path sort).
        $files = [];
        foreach ($relativePaths as $relativePath) {
            $absolute = base_path($relativePath);
            if (! is_dir($absolute)) {
                continue;
            }
            foreach (glob($absolute.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
                $files[basename($file, '.php')] = $file;
            }
        }
        ksort($files);

        foreach ($files as $base => $file) {
            if (! isset($ran[$base])) {
                return $file;
            }
        }

        return null;
    }

    private function forceProofFromException(Throwable $e, string $pendingFile, string $connection): bool
    {
        $message = $e->getMessage();
        $proofConnection = $this->resolveProofConnection($pendingFile, $connection);

        if (preg_match('/Table [\'`](?:[^\'`.]+\.)?([^\'`]+)[\'`] already exists/i', $message, $m)
            || preg_match('/1050 Table \'(?:[^\'`.]+\.)?([^\']+)\' already exists/i', $message, $m)) {
            return Schema::connection($proofConnection)->hasTable($m[1])
                || Schema::connection($connection)->hasTable($m[1]);
        }

        if (preg_match('/Duplicate column name [\'`]([^\'`]+)[\'`]/i', $message, $m)
            || preg_match('/1060 Duplicate column name \'([^\']+)\'/i', $message, $m)) {
            $analysis = $this->reconciler->analyzeFile($pendingFile, $proofConnection);
            if (($analysis['status'] ?? '') === 'already_applied') {
                return true;
            }

            // Column already present: safe to record when migration source targets that add.
            $source = @file_get_contents($pendingFile) ?: '';
            $column = $m[1];

            return $source !== '' && (
                str_contains($source, '->'.$column.'(')
                || str_contains($source, "'{$column}'")
                || str_contains($source, "\"{$column}\"")
            );
        }

        // Missing index/column on DROP — schema already at intended end-state for that drop.
        if (str_contains($message, '1091') || str_contains($message, "Can't DROP")) {
            $source = @file_get_contents($pendingFile) ?: '';

            return $source !== '' && (
                str_contains($source, 'dropIndex')
                || str_contains($source, 'dropUnique')
                || str_contains($source, 'dropColumn')
                || str_contains($source, 'dropForeign')
                || str_contains($source, 'dropIfExists')
            );
        }

        return false;
    }

    private function resolveProofConnection(string $pendingFile, string $fallback): string
    {
        $source = @file_get_contents($pendingFile) ?: '';
        if (preg_match("/protected\s+\\\$connection\s*=\s*['\"]([^'\"]+)['\"]/", $source, $m)) {
            return $m[1];
        }

        return $fallback;
    }
}
