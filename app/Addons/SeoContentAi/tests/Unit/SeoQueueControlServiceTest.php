<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Jobs\AuditLinkStatusJob;
use App\Addons\SeoContentAi\Jobs\SyncArticleToWordPressFromQueueJob;
use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\SeoQueueControlService;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_should_show_offline_alert_when_pending_jobs_without_worker(): void
    {
        $owner = $this->createOwner('offline-alert@test.test');
        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'offline-alert.test',
            'status' => 'active',
            'ssl' => true,
        ]);

        $this->insertAuditJob((int) $site->id, reserved: false);

        $service = app(SeoQueueControlService::class);
        $status = $service->statusForOwner($owner->id);

        $this->assertSame('offline', $status['worker_status']);
        $this->assertTrue($service->shouldShowOfflineAlertForOwner($owner->id));
    }

    public function test_should_not_show_offline_alert_when_worker_is_active(): void
    {
        $owner = $this->createOwner('active-worker@test.test');
        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'active-worker.test',
            'status' => 'active',
            'ssl' => true,
        ]);

        $this->insertAuditJob((int) $site->id, reserved: true);

        $service = app(SeoQueueControlService::class);
        $status = $service->statusForOwner($owner->id);

        $this->assertSame('running', $status['worker_status']);
        $this->assertFalse($service->shouldShowOfflineAlertForOwner($owner->id));
    }

    public function test_should_show_offline_alert_for_pending_wp_sync_meta_without_queue_job(): void
    {
        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
                $this->markTestSkipped('omi_seo_ai articles table is not available in this test database.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('omi_seo_ai connection is not available in this test database.');
        }

        $owner = $this->createOwner('wp-sync-alert@test.test');
        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'wp-sync-alert.test',
            'status' => 'active',
            'ssl' => true,
        ]);

        $article = SeoArticle::query()->create([
            'site_id' => $site->id,
            'user_id' => $owner->id,
            'title' => 'Queued article',
            'slug' => 'queued-article',
            'status' => 'draft',
            'type' => 'article',
        ]);

        ArticleMeta::query()->create([
            'article_id' => $article->id,
            'meta_key' => ArticleWpSyncQueueService::META_KEY,
            'meta_value' => json_encode([
                'status' => ArticleWpSyncQueueService::STATUS_PENDING,
                'queued_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
        ]);

        $service = app(SeoQueueControlService::class);
        $status = $service->statusForOwner($owner->id);

        $this->assertSame(1, $status['pending_wp_sync_jobs']);
        $this->assertSame('offline', $status['worker_status']);
        $this->assertTrue($service->shouldShowOfflineAlertForOwner($owner->id));
    }

    public function test_should_not_show_offline_alert_for_completed_wp_sync_meta(): void
    {
        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
                $this->markTestSkipped('omi_seo_ai articles table is not available in this test database.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('omi_seo_ai connection is not available in this test database.');
        }

        $owner = $this->createOwner('wp-sync-complete@test.test');
        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'wp-sync-complete.test',
            'status' => 'active',
            'ssl' => true,
        ]);

        $article = SeoArticle::query()->create([
            'site_id' => $site->id,
            'user_id' => $owner->id,
            'title' => 'Completed sync article',
            'slug' => 'completed-sync-article',
            'status' => 'draft',
            'type' => 'article',
        ]);

        ArticleMeta::query()->create([
            'article_id' => $article->id,
            'meta_key' => ArticleWpSyncQueueService::META_KEY,
            'meta_value' => json_encode([
                'status' => ArticleWpSyncQueueService::STATUS_COMPLETED,
                'queued_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->insertWpSyncJob((int) $article->id, reserved: false);

        $service = app(SeoQueueControlService::class);
        $status = $service->statusForOwner($owner->id);

        $this->assertSame(0, $status['pending_wp_sync_jobs']);
        $this->assertSame(0, $status['pending_work_total']);
        $this->assertFalse($service->shouldShowOfflineAlertForOwner($owner->id));
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

    private function insertWpSyncJob(int $articleId, bool $reserved): void
    {
        $job = new SyncArticleToWordPressFromQueueJob($articleId);
        $payload = json_encode([
            'displayName' => SyncArticleToWordPressFromQueueJob::class,
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
