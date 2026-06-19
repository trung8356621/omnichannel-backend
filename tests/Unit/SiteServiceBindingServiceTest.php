<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SiteServiceBindingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
    }

    public function test_eligible_owner_select_options_excludes_admin_and_owners_without_seo_service(): void
    {
        $ownerWithService = $this->createOwner('with-seo@test.test');
        $ownerWithoutService = $this->createOwner('no-seo@test.test');
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_NORMAL,
        ]);

        $service = $this->createSeoService();
        SiteService::query()->create([
            'bound_type' => SiteServiceBindingService::BOUND_USER,
            'user_id' => $ownerWithService->id,
            'site_id' => null,
            'service_id' => $service->id,
            'status' => 'active',
            'settings' => ['db_config_type' => 'manual'],
        ]);

        $options = app(SiteServiceBindingService::class)->eligibleOwnerSelectOptions();

        $this->assertArrayHasKey($ownerWithService->id, $options);
        $this->assertArrayNotHasKey($ownerWithoutService->id, $options);
        $this->assertCount(1, $options);
    }

    public function test_finds_user_bound_seo_service_for_owner(): void
    {
        $owner = $this->createOwner('owner-bind@test.test');
        $service = $this->createSeoService();

        SiteService::query()->create([
            'bound_type' => SiteServiceBindingService::BOUND_USER,
            'user_id' => $owner->id,
            'site_id' => null,
            'service_id' => $service->id,
            'status' => 'active',
            'settings' => ['db_config_type' => 'manual'],
        ]);

        $binding = app(SiteServiceBindingService::class);

        $this->assertTrue($binding->ownerHasActiveSeoService($owner->id));
        $this->assertSame($owner->id, $binding->resolveOwnerId($binding->findActiveSeoServiceForOwner($owner->id)));
    }

    public function test_assert_owners_have_seo_service_rejects_owner_without_service(): void
    {
        $owner = $this->createOwner('no-service@test.test');

        $this->expectException(ValidationException::class);

        app(SiteServiceBindingService::class)->assertOwnersHaveActiveSeoService([$owner->id]);
    }

    public function test_normalize_bound_payload_clears_opposite_field(): void
    {
        $binding = app(SiteServiceBindingService::class);

        $userBound = $binding->normalizeBoundPayload([
            'bound_type' => SiteServiceBindingService::BOUND_USER,
            'user_id' => 2,
            'site_id' => 5,
        ]);

        $this->assertNull($userBound['site_id']);
        $this->assertSame(2, $userBound['user_id']);

        $siteBound = $binding->normalizeBoundPayload([
            'bound_type' => SiteServiceBindingService::BOUND_SITE,
            'user_id' => 2,
            'site_id' => 5,
        ]);

        $this->assertNull($siteBound['user_id']);
        $this->assertSame(5, $siteBound['site_id']);
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

    private function createSeoService(): Service
    {
        return Service::query()->create([
            'name' => 'SEO Content AI',
            'slug' => 'seo-content-ai',
            'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
            'is_active' => true,
        ]);
    }
}
