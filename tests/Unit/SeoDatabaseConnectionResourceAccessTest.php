<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Filament\Support\SeoDatabaseConnectionAccess;
use App\Models\SeoDatabaseConnection;
use App\Models\Service;
use App\Models\SiteService;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SeoDatabaseConnectionResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
    }

    public function test_eligible_owner_can_create_one_connection_and_edit_own(): void
    {
        $owner = $this->createOwner('owner-seo@test.test');
        $this->grantSeoService($owner);
        $this->actingAs($owner);

        $this->assertTrue(SeoDatabaseConnectionAccess::canAccessResource());
        $this->assertTrue(SeoDatabaseConnectionResource::canCreate());
        $this->assertFalse(SeoDatabaseConnectionAccess::ownerHasConnection());

        $connection = SeoDatabaseConnection::query()->create([
            'name' => 'Owner DB',
            'type' => 'manual',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $connection->users()->sync([$owner->id]);

        $this->assertTrue(SeoDatabaseConnectionAccess::ownerHasConnection());
        $this->assertFalse(SeoDatabaseConnectionResource::canCreate());
        $this->assertTrue(SeoDatabaseConnectionResource::canEdit($connection));
        $this->assertFalse(SeoDatabaseConnectionResource::canDelete($connection));
    }

    public function test_owner_without_seo_service_cannot_access(): void
    {
        $owner = $this->createOwner('owner-no-seo@test.test');
        $this->actingAs($owner);

        $this->assertFalse(SeoDatabaseConnectionAccess::canAccessResource());
        $this->assertFalse(SeoDatabaseConnectionResource::canCreate());
    }

    public function test_owner_cannot_edit_other_owner_connection(): void
    {
        $ownerA = $this->createOwner('owner-a@test.test');
        $ownerB = $this->createOwner('owner-b@test.test');
        $this->grantSeoService($ownerA);
        $this->grantSeoService($ownerB);

        $connection = SeoDatabaseConnection::query()->create([
            'name' => 'Owner B DB',
            'type' => 'manual',
            'database' => 'db_b',
            'is_active' => true,
        ]);
        $connection->users()->sync([$ownerB->id]);

        $this->actingAs($ownerA);

        $this->assertFalse(SeoDatabaseConnectionResource::canEdit($connection));
        $this->assertCount(0, SeoDatabaseConnectionResource::getEloquentQuery()->get());
    }

    public function test_admin_has_full_access(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_NORMAL,
        ]);

        $connection = SeoDatabaseConnection::query()->create([
            'name' => 'Any DB',
            'type' => 'manual',
            'database' => 'db',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        $this->assertTrue(SeoDatabaseConnectionResource::canCreate());
        $this->assertTrue(SeoDatabaseConnectionResource::canEdit($connection));
        $this->assertTrue(SeoDatabaseConnectionResource::canDelete($connection));
    }

    private function createOwner(string $email): User
    {
        return User::query()->create([
            'name' => 'Owner',
            'email' => $email,
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    private function grantSeoService(User $owner): void
    {
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
    }
}
