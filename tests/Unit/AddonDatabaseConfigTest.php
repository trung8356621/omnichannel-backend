<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Addons\AddonDatabaseConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AddonDatabaseConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'laravel',
            'username' => 'root',
            'password' => 'secret',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function test_legacy_string_database_clones_mysql_with_new_name(): void
    {
        $connection = AddonDatabaseConfig::resolveConnection([
            'database' => 'omi_seo_ai',
        ], 'omi_seo_ai');

        $this->assertNotNull($connection);
        $this->assertSame('omi_seo_ai', $connection['database']);
        $this->assertSame('root', $connection['username']);
        $this->assertSame('secret', $connection['password']);
    }

    public function test_object_database_merges_mysql_defaults(): void
    {
        $connection = AddonDatabaseConfig::resolveConnection([
            'database' => [
                'connection' => 'omi_seo_ai',
                'name' => 'omi_seo_ai',
                'host' => 'db.local',
                'port' => 3307,
                'username' => 'seo_user',
            ],
        ], 'omi_seo_ai');

        $this->assertNotNull($connection);
        $this->assertSame('db.local', $connection['host']);
        $this->assertSame('3307', (string) $connection['port']);
        $this->assertSame('seo_user', $connection['username']);
        $this->assertSame('secret', $connection['password']);
        $this->assertSame('omi_seo_ai', $connection['database']);
    }

    public function test_database_name_from_object_meta(): void
    {
        $name = AddonDatabaseConfig::databaseNameFromMeta([
            'database' => [
                'connection' => 'omi_seo_ai',
                'name' => 'omi_seo_ai',
            ],
        ]);

        $this->assertSame('omi_seo_ai', $name);
    }

    public function test_database_local_php_overrides_credentials(): void
    {
        $addonPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'addon-db-test-'.uniqid('', true);
        mkdir($addonPath);

        file_put_contents($addonPath.DIRECTORY_SEPARATOR.'database.local.php', <<<'PHP'
<?php
return [
    'host' => 'hosting.example',
    'name' => 'remote_db',
    'username' => 'remote_user',
    'password' => 'remote_pass',
];
PHP);

        $connection = AddonDatabaseConfig::resolveConnection([
            '_addon_path' => $addonPath,
            'database' => [
                'connection' => 'omi_seo_ai',
                'name' => 'omi_seo_ai',
                'host' => '127.0.0.1',
                'username' => 'root',
            ],
        ], 'omi_seo_ai');

        $this->assertNotNull($connection);
        $this->assertSame('hosting.example', $connection['host']);
        $this->assertSame('remote_db', $connection['database']);
        $this->assertSame('remote_user', $connection['username']);
        $this->assertSame('remote_pass', $connection['password']);

        @unlink($addonPath.DIRECTORY_SEPARATOR.'database.local.php');
        @rmdir($addonPath);
    }
}
