<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\WpSyncJobStatus;
use App\Addons\SeoContentAi\Models\SeoArticleWpSyncJob;
use App\Addons\SeoContentAi\Services\ArticleWpSyncLeaseService;
use PHPUnit\Framework\TestCase;

final class WpSyncJobStatusAndLeaseContractTest extends TestCase
{
    public function test_status_lifecycle_covers_lease_terminals(): void
    {
        self::assertSame(
            ['pending', 'processing'],
            WpSyncJobStatus::activeValues(),
        );
        self::assertSame(
            ['completed', 'failed', 'cancelled', 'stale'],
            WpSyncJobStatus::terminalValues(),
        );
        self::assertSame('queued', WpSyncJobStatus::Pending->toPublicStatus());
        self::assertSame('success', WpSyncJobStatus::Completed->toPublicStatus());
        self::assertSame('cancelled', WpSyncJobStatus::Cancelled->toPublicStatus());
        self::assertSame('stale', WpSyncJobStatus::Stale->toPublicStatus());
    }

    public function test_idempotency_key_format(): void
    {
        self::assertSame(
            'wordpress_sync:12:34',
            SeoArticleWpSyncJob::makeIdempotencyKey(12, 34),
        );
    }

    public function test_lease_constants(): void
    {
        self::assertSame(120, ArticleWpSyncLeaseService::LEASE_SECONDS);
        self::assertSame(20, ArticleWpSyncLeaseService::HEARTBEAT_INTERVAL_SECONDS);
        self::assertSame('idle', ArticleWpSyncLeaseService::ARTICLE_IDLE);
        self::assertSame(3, ArticleWpSyncLeaseService::MAX_STALE_AUTO_RETRIES);
        self::assertTrue(method_exists(ArticleWpSyncLeaseService::class, 'recoverOrphanWpSyncQueueMetas'));
        self::assertTrue(method_exists(ArticleWpSyncLeaseService::class, 'healArticleOrphanMeta'));
        self::assertTrue(method_exists(ArticleWpSyncLeaseService::class, 'markStale'));

        $leaseSource = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleWpSyncLeaseService.php',
        );
        self::assertStringContainsString('maybeAutoRetryAfterStale', $leaseSource);
        self::assertStringContainsString('stale_auto_retries', $leaseSource);
        self::assertStringContainsString('ManualWordPressSyncJob::dispatch', $leaseSource);
        self::assertStringContainsString('autoRetry: false', $leaseSource);
    }

    public function test_manual_job_requires_sync_job_id_constructor_arg(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Jobs/ManualWordPressSyncJob.php',
        );
        self::assertStringContainsString('public readonly int $syncJobId', $source);
        self::assertStringContainsString('ArticleWpSyncLeaseService $lease', $source);
        self::assertStringContainsString('WpSyncLeaseHeartbeat::bind', $source);
        self::assertStringContainsString('function failed', $source);
        self::assertStringContainsString('$lease->fail', $source);
        self::assertStringContainsString('$lease->complete', $source);
        self::assertStringNotContainsString('$syncQueue->markProcessing', $source);
    }

    public function test_watchdog_command_registered_in_provider(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 2).'/SeoContentAiServiceProvider.php',
        );
        self::assertStringContainsString('WordpressSyncLeaseWatchdogCommand', $provider);
        self::assertStringContainsString('wordpress-sync-lease-watchdog', $provider);
    }

    public function test_cancel_uses_lease_reset(): void
    {
        $queue = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleWpSyncQueueService.php',
        );
        self::assertStringContainsString('STATUS_CANCELLED', $queue);
        self::assertStringContainsString('STATUS_STALE', $queue);
        self::assertStringContainsString('$this->lease->resetArticle', $queue);
        self::assertStringContainsString('$this->lease->complete', $queue);
        self::assertStringContainsString('$this->lease->fail', $queue);
    }
}
