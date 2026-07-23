<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Database\DatabasePhysicalIdentity;
use App\Support\Database\DatabaseTableOwnershipRegistry;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class DatabaseTableOwnershipRegistryTest extends TestCase
{
    public function test_core_owner_resolves_via_core_connection_config(): void
    {
        Config::set('database.core_connection', 'mysql');
        Config::set('database_table_ownership.connection_map', [
            'core' => null,
            'omi_seo_ai' => 'omi_seo_ai',
        ]);
        Config::set('database_table_ownership.owners', [
            'core' => [
                'tables' => ['users'],
                'patterns' => [],
            ],
            'omi_seo_ai' => [
                'tables' => ['articles'],
                'patterns' => ['automation_*'],
            ],
        ]);
        Config::set('database_table_ownership.ignored_tables', ['migrations']);
        Config::set('database_table_ownership.review_required_patterns', ['automation_*']);

        $registry = new DatabaseTableOwnershipRegistry($this->app);

        $users = $registry->resolveOwner('users');
        $this->assertSame('owned', $users['status']);
        $this->assertSame(['mysql'], $users['owners']);

        $rules = $registry->resolveOwner('automation_rules');
        $this->assertSame('owned', $rules['status']);
        $this->assertSame(['omi_seo_ai'], $rules['owners']);
        $this->assertTrue($registry->requiresReview('automation_rules'));
    }

    public function test_physical_identity_ignores_password(): void
    {
        $a = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'app',
            'password' => 'secret-a',
        ];
        $b = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'app',
            'password' => 'secret-b',
        ];

        $this->assertTrue(DatabasePhysicalIdentity::samePhysicalDatabase($a, $b));
        $summary = DatabasePhysicalIdentity::safeSummary($a);
        $this->assertArrayNotHasKey('password', $summary);
    }
}
