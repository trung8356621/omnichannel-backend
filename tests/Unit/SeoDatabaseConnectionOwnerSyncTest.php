<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Support\SeoDatabaseConnectionOwnerSync;
use App\Models\SeoDatabaseConnection;
use App\Models\Service;
use App\Models\SiteService;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SeoDatabaseConnectionOwnerSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
    }

    public function test_sync_owner_sets_seo_role_manager_for_owner(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
        ]);

        $connection = SeoDatabaseConnection::query()->create([
            'name' => 'Workspace',
            'type' => 'manual',
            'database' => 'omi_seo_ai',
            'is_active' => true,
        ]);

        SeoDatabaseConnectionOwnerSync::syncOwner($connection, $owner->id);

        $owner->refresh();

        $this->assertSame(User::SEO_ROLE_MANAGER, $owner->seo_role);
        $this->assertSame([$owner->id], $connection->users()->pluck('users.id')->all());
    }

    public function test_assert_owner_single_connection_blocks_duplicate(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner2@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $service = Service::query()->firstOrCreate(
            ['slug' => 'seo-content-ai'],
            [
                'name' => 'SEO Content AI',
                'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
                'is_active' => true,
            ],
        );

        SiteService::query()->create([
            'bound_type' => SiteServiceBindingService::BOUND_USER,
            'user_id' => $owner->id,
            'site_id' => null,
            'service_id' => $service->id,
            'status' => 'active',
            'settings' => ['db_config_type' => 'manual'],
        ]);

        $existing = SeoDatabaseConnection::query()->create([
            'name' => 'Existing',
            'type' => 'manual',
            'database' => 'db1',
            'is_active' => true,
        ]);
        $existing->users()->sync([$owner->id]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        SeoDatabaseConnectionOwnerSync::assertOwnerSingleConnection($owner->id);
    }
}
