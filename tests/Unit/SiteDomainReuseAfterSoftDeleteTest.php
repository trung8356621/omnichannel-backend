<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SiteDomainReuseAfterSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
    }

    public function test_soft_deleted_site_releases_domain_for_reuse(): void
    {
        $firstOwner = User::query()->create([
            'name' => 'Owner A',
            'email' => 'owner-a@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $secondOwner = User::query()->create([
            'name' => 'Owner B',
            'email' => 'owner-b@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $domain = 'benhvienthammyjknhathan.com';

        $deletedSite = Site::query()->create([
            'user_id' => $firstOwner->id,
            'domain' => $domain,
            'status' => 'active',
            'ssl' => true,
        ]);

        $deletedSite->delete();

        $deletedSite->refresh();
        $this->assertSoftDeleted($deletedSite);
        $this->assertStringContainsString('__trashed__', (string) $deletedSite->domain);

        $newSite = Site::query()->create([
            'user_id' => $secondOwner->id,
            'domain' => $domain,
            'status' => 'active',
            'ssl' => true,
        ]);

        $this->assertSame($domain, $newSite->domain);
        $this->assertTrue(
            Site::query()->where('domain', $domain)->whereNull('deleted_at')->exists()
        );
    }
}
