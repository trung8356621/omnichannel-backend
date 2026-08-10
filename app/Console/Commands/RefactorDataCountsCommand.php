<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Database\RefactorMigrationRunner;
use App\Core\Database\SeoCliConnectionFixer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Print representative row counts for legacy-upgrade verification.
 */
final class RefactorDataCountsCommand extends Command
{
    protected $signature = 'refactor:data-counts
                            {--json : Emit JSON instead of a table}
                            {--via-mysql : Force SEO DB access using mysql connection credentials}
                            {--bootstrap-seo : Bootstrap credentials from seo_database_connections (may break local MySQL 8.4+)}';

    protected $description = 'Snapshot representative Core + SEO table row counts (read-only)';

    public function handle(
        RefactorMigrationRunner $runner,
        SeoCliConnectionFixer $fixer,
    ): int {
        $seoConn = $runner->seoConnectionName();
        $this->prepareSeoConnection($seoConn, $fixer);

        $map = config('refactor_migrate.verify_tables', []);
        $rows = [];
        $payload = [];

        foreach ($map as $connection => $tables) {
            if (! is_array($tables)) {
                continue;
            }
            foreach ($tables as $table) {
                $table = (string) $table;
                $count = null;
                $status = 'ok';
                try {
                    if (! Schema::connection($connection)->hasTable($table)) {
                        $status = 'missing';
                    } else {
                        $count = (int) DB::connection($connection)->table($table)->count();
                    }
                } catch (\Throwable $e) {
                    $status = 'error: '.$e->getMessage();
                }

                $rows[] = [$connection, $table, $count === null ? $status : (string) $count];
                $payload[$connection][$table] = [
                    'count' => $count,
                    'status' => $status,
                ];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['CONNECTION', 'TABLE', 'COUNT'], $rows);

        return self::SUCCESS;
    }

    private function prepareSeoConnection(string $seoConn, SeoCliConnectionFixer $fixer): void
    {
        if ($this->option('bootstrap-seo')) {
            app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
                ->bootstrapLegacySharedConnection();
        }

        $fixer->ensureReachable(
            $seoConn,
            (bool) $this->option('via-mysql'),
            fn (string $message) => $this->warn($message),
        );
    }
}
