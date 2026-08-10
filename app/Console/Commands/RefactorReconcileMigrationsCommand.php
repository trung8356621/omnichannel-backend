<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Database\LegacySchemaMigrationReconciler;
use App\Core\Database\RefactorMigrationRunner;
use App\Core\Database\SeoCliConnectionFixer;
use Illuminate\Console\Command;

/**
 * After legacy SQL import: record pending migrations that are already reflected in schema.
 */
final class RefactorReconcileMigrationsCommand extends Command
{
    protected $signature = 'refactor:reconcile-migrations
                            {--apply : Write proven already-applied migrations into the migrations table}
                            {--connection= : Override SEO connection name}
                            {--via-mysql : Force SEO DB access using mysql connection credentials}
                            {--bootstrap-seo : Bootstrap credentials from seo_database_connections (may break local MySQL 8.4+)}';

    protected $description = 'Prove-and-record pending migrations already present in imported legacy schema (non-destructive)';

    public function handle(
        RefactorMigrationRunner $runner,
        LegacySchemaMigrationReconciler $reconciler,
        SeoCliConnectionFixer $fixer,
    ): int {
        $seoConn = (string) ($this->option('connection') ?: $runner->seoConnectionName());
        $this->prepareSeoConnection($seoConn, $fixer);

        $this->info('Core (mysql / database/migrations)');
        $coreRows = $reconciler->analyzePaths([database_path('migrations')], 'mysql');
        $this->renderAnalysis($coreRows);
        if ($this->option('apply')) {
            $recorded = $reconciler->apply($coreRows, 'mysql');
            $this->info('Core recorded '.count($recorded).' migration(s).');
        }

        $this->newLine();
        $this->info('Peer addons ('.$seoConn.')');
        $dirs = $runner->peerAbsolutePaths();
        $rows = $reconciler->analyzePaths($dirs, $seoConn);
        $this->renderAnalysis($rows);

        if (! $this->option('apply')) {
            $this->warn('Dry-run only. Re-run with --apply to record already_applied rows.');

            return self::SUCCESS;
        }

        $recorded = $reconciler->apply($rows, $seoConn);
        $this->info('Peer recorded '.count($recorded).' migration(s) on connection '.$seoConn.' (schema unchanged).');

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

    /**
     * @param  list<array{migration: string, path: string, status: string, reason: string}>  $rows
     */
    private function renderAnalysis(array $rows): void
    {
        if ($rows === []) {
            $this->info('No pending migrations to analyze.');

            return;
        }

        $table = [];
        $already = 0;
        $pending = 0;
        $unknown = 0;
        foreach ($rows as $row) {
            $table[] = [$row['migration'], $row['status'], $row['reason']];
            match ($row['status']) {
                'already_applied' => $already++,
                'pending' => $pending++,
                default => $unknown++,
            };
        }

        $this->table(['MIGRATION', 'STATUS', 'PROOF'], $table);
        $this->line("already_applied={$already} pending={$pending} unknown={$unknown}");
    }
}
