<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // Must run before setUpTraits (DatabaseTransactions) — afterApplicationCreated is too late.
        $this->configureTestingDatabaseConnections();
    }

    /**
     * Testing strategy:
     *
     * - phpunit.xml sets DB_CONNECTION=sqlite + DB_DATABASE=:memory:.
     * - Core models use `database.core_connection` (default mysql) via UsesCoreDatabaseConnection.
     * - SEO models use `omi_seo_ai`.
     *
     * Default (SEO_TEST_USE_MYSQL unset/false):
     *   Point core_connection + omi_seo_ai at the same sqlite default config so
     *   RefreshDatabase migrations run without a live MySQL daemon.
     *
     * Server with real DB (recommended for SEO integration tests):
     *   SEO_TEST_USE_MYSQL=true (+ reachable mysql / omi_seo_ai).
     */
    private function configureTestingDatabaseConnections(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        if (filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->ensureSeoDatabaseConnectionFromMysql();

            return;
        }

        $default = (string) config('database.default', 'sqlite');
        $defaultConfig = config('database.connections.'.$default);
        if (! is_array($defaultConfig)) {
            return;
        }

        Config::set('database.core_connection', $default);
        Config::set('database.connections.omi_seo_ai', $defaultConfig);
        // Tests still listing `mysql` in $connectionsToTransact must not hit
        // MySQL driver with DB_DATABASE=:memory: from phpunit.xml.
        Config::set('database.connections.mysql', $defaultConfig);

        try {
            DB::purge('omi_seo_ai');
            DB::purge('mysql');
            if ($default !== 'mysql' && $default !== 'omi_seo_ai') {
                DB::purge($default);
            }
        } catch (Throwable) {
            // Not resolved yet.
        }
    }

    private function ensureSeoDatabaseConnectionFromMysql(): void
    {
        $this->ensureCoreMysqlDatabaseIsConfigured();

        $connectionName = 'omi_seo_ai';
        $existing = config('database.connections.'.$connectionName);

        if (is_array($existing) && ($existing['driver'] ?? null) !== null) {
            return;
        }

        $mysql = config('database.connections.mysql');
        if (! is_array($mysql) || ($mysql['driver'] ?? '') !== 'mysql') {
            return;
        }

        Config::set('database.connections.'.$connectionName, array_merge($mysql, [
            'database' => (string) env('SEO_TEST_DATABASE', env('SEO_DB_DATABASE', 'omi_seo_ai')),
        ]));

        try {
            DB::purge($connectionName);
        } catch (Throwable) {
        }
    }

    private function ensureCoreMysqlDatabaseIsConfigured(): void
    {
        $mysql = config('database.connections.mysql');
        if (! is_array($mysql) || ($mysql['driver'] ?? '') !== 'mysql') {
            return;
        }

        if (($mysql['database'] ?? '') !== ':memory:') {
            return;
        }

        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            return;
        }

        $contents = (string) file_get_contents($envPath);
        if (preg_match('/^DB_DATABASE=(.+)$/m', $contents, $matches) !== 1) {
            return;
        }

        $database = trim($matches[1], " \t\n\r\0\x0B\"'");
        if ($database === '' || $database === ':memory:') {
            return;
        }

        Config::set('database.connections.mysql.database', $database);
        try {
            DB::purge('mysql');
        } catch (Throwable) {
        }
    }
}
