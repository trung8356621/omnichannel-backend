<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeoDatabaseConnectionIsConfigured();
    }

    private function ensureSeoDatabaseConnectionIsConfigured(): void
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

        DB::purge($connectionName);
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
        DB::purge('mysql');
    }
}
