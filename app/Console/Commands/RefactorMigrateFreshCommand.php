<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Database\DestructiveMigrationGuard;
use App\Core\Database\RefactorMigrationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Dual-DB migrate:fresh for disposable test databases only.
 *
 * Real/legacy DBs (omi_channel, omi_seo_ai, *_real, …) are hard-blocked.
 * Prefer: php artisan refactor:migrate
 *
 * Does NOT call SeoDatabaseConnectionService::bootstrapLegacySharedConnection()
 * — that can retarget the runtime connection to a protected production DB.
 */
final class RefactorMigrateFreshCommand extends Command
{
    protected $signature = 'refactor:migrate-fresh
                            {--seed : Run RefactorFixtureSeeder after migrate}
                            {--keep-seo-db : Do not DROP/recreate the SEO database}
                            {--confirm-destroy-test-db : Required acknowledgment when destroying disposable *_test DBs outside APP_ENV=testing}';

    protected $description = 'DESTRUCTIVE: wipe disposable test DBs only, then migrate Core + peer addons';

    public function handle(
        RefactorMigrationRunner $runner,
        DestructiveMigrationGuard $guard,
    ): int {
        $this->applyEnvDatabaseOverrides($runner->seoConnectionName());

        $seoConn = $runner->seoConnectionName();
        $targets = $runner->targetDatabaseNames($seoConn);

        try {
            $allowed = $guard->assertMayDestroy(
                $targets,
                (bool) $this->option('confirm-destroy-test-db'),
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->warn('DESTRUCTIVE: databases that will be wiped:');
        foreach ($allowed as $name) {
            $this->warn('  - '.$name);
        }

        if ($this->option('confirm-destroy-test-db')) {
            $this->warn('Override flag --confirm-destroy-test-db acknowledged (targets already passed disposable checks).');
        }

        // Prefer mysql credentials for disposable SEO DB when SEO user auth is broken locally.
        $this->alignSeoConnectionWithMysqlServer($seoConn, $guard);

        $this->ensureDatabaseExists('mysql');

        if (! $this->option('keep-seo-db')) {
            $this->recreateSeoDatabase($seoConn);
        } else {
            $this->ensureDatabaseExists($seoConn);
        }

        $this->assertStillDisposable($guard, $seoConn);

        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--path' => 'database/migrations',
            '--database' => 'mysql',
        ]);
        $this->line(Artisan::output());

        $this->assertStillDisposable($guard, $seoConn);

        $relativePaths = $runner->toRelativePaths($runner->peerAbsolutePaths());
        $seoDb = (string) config('database.connections.'.$seoConn.'.database');
        $this->info('Running peer addon migrations on '.$seoConn.' / DB '.$seoDb.' ('.count($relativePaths).' paths)');

        Artisan::call('migrate', [
            '--database' => $seoConn,
            '--path' => $relativePaths,
            '--force' => true,
        ]);
        $this->line(Artisan::output());

        if ($this->option('seed')) {
            $this->call('db:seed', [
                '--class' => \Database\Seeders\RefactorFixtureSeeder::class,
                '--force' => true,
            ]);
        }

        $this->info('refactor:migrate-fresh complete.');

        return self::SUCCESS;
    }

    private function applyEnvDatabaseOverrides(string $seoConn): void
    {
        $coreDb = env('DB_DATABASE');
        if (is_string($coreDb) && $coreDb !== '') {
            config(['database.connections.mysql.database' => $coreDb]);
            DB::purge('mysql');
        }

        $seoDb = env('SEO_DB_DATABASE', env('SEO_TEST_DATABASE'));
        if (is_string($seoDb) && $seoDb !== '') {
            config(['database.connections.'.$seoConn.'.database' => $seoDb]);
            DB::purge($seoConn);
        }
    }

    private function alignSeoConnectionWithMysqlServer(string $seoConn, DestructiveMigrationGuard $guard): void
    {
        $seoDb = (string) config('database.connections.'.$seoConn.'.database', '');
        if ($seoDb === '' || ! $guard->isDisposable($seoDb)) {
            return;
        }

        $mysql = config('database.connections.mysql', []);
        if (! is_array($mysql) || $mysql === []) {
            return;
        }

        $merged = $mysql;
        $merged['database'] = $seoDb;
        config(['database.connections.'.$seoConn => $merged]);
        DB::purge($seoConn);
        $this->line("Pinned {$seoConn} → database [{$seoDb}] using mysql server credentials (test-only).");
    }

    private function assertStillDisposable(DestructiveMigrationGuard $guard, string $seoConn): void
    {
        $coreDb = (string) config('database.connections.mysql.database', '');
        $seoDb = (string) config('database.connections.'.$seoConn.'.database', '');

        foreach ([$coreDb, $seoDb] as $name) {
            if ($name === '') {
                continue;
            }
            if ($guard->isProtected($name) || ! $guard->isDisposable($name)) {
                throw new \RuntimeException(
                    DestructiveMigrationGuard::BLOCK_PREFIX."\n"
                    ."Abort: connection retargeted to non-disposable database [{$name}]."
                );
            }
        }
    }

    private function ensureDatabaseExists(string $connectionName): void
    {
        $config = config('database.connections.'.$connectionName, []);
        $database = (string) ($config['database'] ?? '');
        if ($database === '' || $database === ':memory:') {
            return;
        }

        $server = $config;
        $server['database'] = null;
        $tmp = '__ensure_db_'.$connectionName;
        config(['database.connections.'.$tmp => $server]);
        DB::purge($tmp);

        try {
            DB::connection($tmp)->statement(
                'CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $this->line("Ensured database {$database}");
        } catch (\Throwable $e) {
            $this->error("Ensure database {$database} failed: ".$e->getMessage());
        } finally {
            DB::purge($tmp);
        }
    }

    private function recreateSeoDatabase(string $connectionName): void
    {
        $config = config('database.connections.'.$connectionName, []);
        $database = (string) ($config['database'] ?? '');
        if ($database === '' || $database === ':memory:') {
            $this->warn('Skip SEO DB recreate — empty/memory database.');

            return;
        }

        $server = $config;
        $server['database'] = null;
        config(['database.connections.__seo_server' => $server]);
        DB::purge('__seo_server');

        try {
            DB::connection('__seo_server')->statement('DROP DATABASE IF EXISTS `'.$database.'`');
            DB::connection('__seo_server')->statement(
                'CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $this->info("Recreated database {$database}");
        } catch (\Throwable $e) {
            $this->error('SEO DB recreate failed: '.$e->getMessage());
        } finally {
            DB::purge('__seo_server');
        }
    }
}
