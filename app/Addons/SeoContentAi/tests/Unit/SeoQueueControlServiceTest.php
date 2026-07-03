<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Jobs\AuditLinkStatusJob;
use App\Addons\SeoContentAi\Services\SeoQueueControlService;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoQueueControlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.connection', 'sqlite');
        Config::set('queue.connections.database.table', 'jobs');
        Config::set('queue.connections.database.queue', 'default');
    }

    public function test_pause_purges_pending_audit_jobs_for_owner_sites(): void
    {
        $owner = $this->createOwner('owner-queue@test.test');
        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'queue-test.test',
            'status' => 'active',
            'ssl' => true,
        ]);

        $otherOwner = $this->createOwner('other-queue@test.test');
        $otherSite = Site::query()->create([
            'user_id' => $otherOwner->id,
            'domain' => 'other-queue.test',
            'status' => 'active',
            'ssl' => true,
        ]);

        $this->insertAuditJob($site->id, reserved: false);
        $this->insertAuditJob($otherSite->id, reserved: false);

        $service = app(SeoQueueControlService::class);
        $service->pauseForOwner($owner->id);

        $this->assertTrue($service->isPausedForOwner($owner->id));
        $this->assertTrue($service->isPausedForSite($site->id));
        $this->assertSame(0, $service->statusForOwner($owner->id)['pending_audit_jobs']);
        $this->assertSame(1, $service->statusForOwner($otherOwner->id)['pending_audit_jobs']);
    }

    public function test_extract_site_id_from_audit_payload(): void
    {
        $job = new AuditLinkStatusJob(15, 42);
        $payload = json_encode([
            'displayName' => AuditLinkStatusJob::class,
            'data' => [
                'command' => serialize($job),
            ],
        ], JSON_THROW_ON_ERROR);

        $service = app(SeoQueueControlService::class);

        $this->assertTrue($service->isAuditLinkJobPayload($payload));
        $this->assertSame(42, $service->extractSiteIdFromAuditPayload($payload));
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

    private function insertAuditJob(int $siteId, bool $reserved): void
    {
        $job = new AuditLinkStatusJob(1, $siteId);
        $payload = json_encode([
            'displayName' => AuditLinkStatusJob::class,
            'data' => [
                'command' => serialize($job),
            ],
        ], JSON_THROW_ON_ERROR);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => $reserved ? now()->getTimestamp() : null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
