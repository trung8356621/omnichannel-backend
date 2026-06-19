<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\SiteServiceResource;
use App\Models\SiteService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SiteServiceResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
    }

    public function test_admin_can_create_and_edit(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin);

        $this->assertTrue(SiteServiceResource::canCreate());
        $this->assertTrue(SiteServiceResource::canEdit(new SiteService));
    }

    public function test_owner_can_only_view(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($owner);

        $this->assertFalse(SiteServiceResource::canCreate());
        $this->assertFalse(SiteServiceResource::canEdit(new SiteService));
        $this->assertFalse(SiteServiceResource::canDelete(new SiteService));
    }
}
