<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Database\LegacySchemaMigrationReconciler;
use App\Core\Database\LegacyTolerantMigrationRunner;
use App\Core\Database\RefactorMigrationRunner;
use App\Core\Database\SeoCliConnectionFixer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-destructive dual-DB migrate for legacy/real local databases.
 *
 * Never drops tables or truncates. Respects Laravel migrations history.
 */
final class RefactorMigrateCommand extends Command
{
    protected $signature = 'refactor:migrate
                            {--seed : Run RefactorFixtureSeeder after migrate (does not wipe data first)}
                            {--verify : Snapshot row counts before/after and print delta table}
                            {--reconcile : Prove-and-record already-applied pending migrations before migrate}
                            {--pretend : Dump SQL that would run without executing}
                            {--via-mysql : Force SEO DB access using mysql connection credentials}
                            {--bootstrap-seo : Bootstrap credentials from seo_database_connections (may break local MySQL 8.4+)}';

    protected $description = 'Non-destructive: run pending Core + peer-addon migrations on configured connections';

    public function handle(
        RefactorMigrationRunner $runner,
        LegacySchemaMigrationReconciler $reconciler,
        LegacyTolerantMigrationRunner $tolerant,
        SeoCliConnectionFixer $fixer,
    ): int {
        $seoConn = $runner->seoConnectionName();
        $targets = $runner->targetDatabaseNames($seoConn);

        $this->info('refactor:migrate (NON-DESTRUCTIVE)');
        $this->line('Targets: '.implode(', ', $targets));
        $this->line('SEO connection: '.$seoConn);

        $this->prepareSeoConnection($seoConn, $fixer);

        $before = [];
        if ($this->option('verify')) {
            $before = $this->captureCounts();
        }

        $pretend = (bool) $this->option('pretend');

        if ($this->option('reconcile') && ! $pretend) {
            $this->info('Reconciling already-applied Core migrations (schema proof)…');
            $coreRows = $reconciler->analyzePaths([database_path('migrations')], 'mysql');
            $coreRecorded = $reconciler->apply($coreRows, 'mysql');
            $this->line('Core recorded '.count($coreRecorded).' already-applied migration(s).');

            $this->info('Reconciling already-applied peer migrations (schema proof)…');
            $rows = $reconciler->analyzePaths($runner->peerAbsolutePaths(), $seoConn);
            $recorded = $reconciler->apply($rows, $seoConn);
            $this->line('Peer recorded '.count($recorded).' already-applied migration(s).');
        }

        $coreArgs = [
            '--force' => true,
            '--path' => 'database/migrations',
            '--database' => 'mysql',
        ];
        if ($pretend) {
            $coreArgs['--pretend'] = true;
        }

        $this->info('Migrating Core (mysql / database/migrations)…');
        Artisan::call('migrate', $coreArgs);
        $this->line(trim(Artisan::output()));

        // Re-pin after core migrate in case anything mutated connection config.
        $this->prepareSeoConnection($seoConn, $fixer);

        $relativePaths = $runner->toRelativePaths($runner->peerAbsolutePaths());
        if ($relativePaths === []) {
            $this->warn('No peer addon migration paths found.');
        } else {
            $seoDb = (string) config('database.connections.'.$seoConn.'.database');
            $this->info('Migrating peer addons on '.$seoConn.' / DB '.$seoDb.' ('.count($relativePaths).' paths)…');
            $result = $tolerant->migrate(
                $seoConn,
                $relativePaths,
                $pretend,
                fn (string $message) => $this->line($message),
            );
            if ($result['skipped_existing'] > 0) {
                $this->warn('Skipped/recorded already-present migrations: '.$result['skipped_existing']);
            }
            if (! $result['ok']) {
                $this->error($result['failed'] ?? 'Peer migrate failed');

                return self::FAILURE;
            }
        }

        if ($this->option('seed') && ! $pretend) {
            $this->warn('--seed will insert fixture rows without wiping. Prefer only on empty DBs.');
            $this->call('db:seed', [
                '--class' => \Database\Seeders\RefactorFixtureSeeder::class,
                '--force' => true,
            ]);
        }

        if ($this->option('verify')) {
            $after = $this->captureCounts();
            $this->printDelta($before, $after);
        }

        $this->info('refactor:migrate complete.');

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
     * @return array<string, array<string, int|null>>
     */
    private function captureCounts(): array
    {
        $map = config('refactor_migrate.verify_tables', []);
        $out = [];

        foreach ($map as $connection => $tables) {
            if (! is_array($tables)) {
                continue;
            }
            $out[$connection] = [];
            foreach ($tables as $table) {
                $table = (string) $table;
                try {
                    if (! Schema::connection($connection)->hasTable($table)) {
                        $out[$connection][$table] = null;
                        continue;
                    }
                    $out[$connection][$table] = (int) DB::connection($connection)->table($table)->count();
                } catch (\Throwable) {
                    $out[$connection][$table] = null;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, int|null>>  $before
     * @param  array<string, array<string, int|null>>  $after
     */
    private function printDelta(array $before, array $after): void
    {
        $rows = [];
        $connections = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($connections as $connection) {
            $tables = array_unique(array_merge(
                array_keys($before[$connection] ?? []),
                array_keys($after[$connection] ?? []),
            ));
            foreach ($tables as $table) {
                $b = $before[$connection][$table] ?? null;
                $a = $after[$connection][$table] ?? null;
                $delta = ($b === null || $a === null) ? 'n/a' : (string) ($a - $b);
                $rows[] = [
                    $connection.'.'.$table,
                    $b === null ? 'missing' : (string) $b,
                    $a === null ? 'missing' : (string) $a,
                    $delta,
                ];
            }
        }

        $this->newLine();
        $this->table(['TABLE', 'BEFORE', 'AFTER', 'DELTA'], $rows);
    }
}
