<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SeoDatabaseConnectionServiceTest extends TestCase
{
    public function test_auto_connection_uses_model_database_or_generated_name(): void
    {
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'username' => 'root',
            'password' => '',
        ]);

        $connection = new SeoDatabaseConnection([
            'type' => 'auto',
            'database' => 'omi_seo_ai',
        ]);
        $connection->id = 3;

        $service = new SeoDatabaseConnectionService;
        $resolved = $service->resolveConnectionArrayFromModel($connection);

        $this->assertSame('omi_seo_ai', $resolved['database']);
    }

    public function test_manual_connection_uses_model_fields(): void
    {
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'username' => 'root',
            'password' => '',
        ]);

        $connection = new SeoDatabaseConnection([
            'type' => 'manual',
            'host' => 'db.example.test',
            'port' => '3307',
            'database' => 'seo_custom',
            'username' => 'seo_user',
        ]);
        $connection->password = 'secret';

        $service = new SeoDatabaseConnectionService;
        $resolved = $service->resolveConnectionArrayFromModel($connection);

        $this->assertSame('db.example.test', $resolved['host']);
        $this->assertSame('seo_custom', $resolved['database']);
        $this->assertSame('seo_user', $resolved['username']);
        $this->assertSame('secret', $resolved['password']);
    }

    public function test_hash_format_validation(): void
    {
        $this->assertTrue(SeoConnectionContext::isValidHashFormat(str_repeat('a', 32)));
        $this->assertFalse(SeoConnectionContext::isValidHashFormat('short'));
        $this->assertFalse(SeoConnectionContext::isValidHashFormat('invalid-chars!'));
    }
}
