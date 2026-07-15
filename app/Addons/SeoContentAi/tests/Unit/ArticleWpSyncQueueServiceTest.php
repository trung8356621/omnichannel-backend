<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Jobs\SyncArticleToWordPressFromQueueJob;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ArticleWpSyncQueueServiceTest extends TestCase
{
    public function test_enqueue_rejects_when_already_pending(): void
    {
        Bus::fake();

        $article = new SeoArticle(['id' => 7, 'site_id' => 0, 'status' => 'draft']);
        $article->wp_sync_queue_meta = json_encode(['status' => ArticleWpSyncQueueService::STATUS_PENDING]);

        $service = app(ArticleWpSyncQueueService::class);
        $result = $service->enqueueFromEditorBundle($article, ['html' => '<p>x</p>']);

        $this->assertFalse($result['success']);
        Bus::assertNothingDispatched();
    }

    public function test_read_queue_meta_uses_subquery_column_when_relation_whitelisted(): void
    {
        $article = new SeoArticle(['id' => 21]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => 'seo_focus_keyword',
                'meta_value' => 'keyword',
            ]),
        ]));
        $article->wp_sync_queue_meta = json_encode([
            'status' => ArticleWpSyncQueueService::STATUS_FAILED,
            'error' => 'WP timeout',
        ]);

        $payload = app(ArticleWpSyncQueueService::class)->readQueueMeta($article);

        $this->assertSame(ArticleWpSyncQueueService::STATUS_FAILED, $payload['status'] ?? null);
        $this->assertSame('WP timeout', $payload['error'] ?? null);
    }

    public function test_read_queue_meta_decodes_json_payload(): void
    {
        $article = new SeoArticle(['id' => 9]);
        $article->wp_sync_queue_meta = json_encode([
            'status' => ArticleWpSyncQueueService::STATUS_FAILED,
            'error' => 'WP timeout',
        ]);

        $payload = app(ArticleWpSyncQueueService::class)->readQueueMeta($article);

        $this->assertSame(ArticleWpSyncQueueService::STATUS_FAILED, $payload['status'] ?? null);
        $this->assertSame('WP timeout', $payload['error'] ?? null);
    }

    public function test_queue_status_label_maps_known_status(): void
    {
        $article = new SeoArticle(['id' => 11]);
        $article->wp_sync_queue_meta = json_encode(['status' => ArticleWpSyncQueueService::STATUS_PROCESSING]);

        $label = app(ArticleWpSyncQueueService::class)->queueStatusLabel($article);

        $this->assertNotNull($label);
        $this->assertNotSame('', $label);
    }

    public function test_resync_rejects_completed_status(): void
    {
        Bus::fake();

        $article = new SeoArticle(['id' => 15, 'site_id' => 0]);
        $article->wp_sync_queue_meta = json_encode(['status' => ArticleWpSyncQueueService::STATUS_COMPLETED]);
        $article->wp_sync_queue_bundle = json_encode(['html' => '<p>queued</p>']);

        $result = app(ArticleWpSyncQueueService::class)->resync($article);

        $this->assertFalse($result['success']);
        Bus::assertNothingDispatched();
    }

    public function test_dispatch_wp_sync_job_targets_seo_queue(): void
    {
        Bus::fake();

        $article = new SeoArticle(['id' => 42, 'site_id' => 0, 'status' => 'draft']);
        $article->wp_sync_queue_meta = json_encode(['status' => ArticleWpSyncQueueService::STATUS_FAILED]);
        $article->wp_sync_queue_bundle = json_encode(['html' => '<p>retry</p>']);

        $result = app(ArticleWpSyncQueueService::class)->resync($article);

        $this->assertTrue($result['success']);
        Bus::assertDispatched(SyncArticleToWordPressFromQueueJob::class, function (SyncArticleToWordPressFromQueueJob $job): bool {
            return $job->articleId === 42 && $job->queue === ArticleWpSyncQueueService::QUEUE_NAME;
        });
    }

    public function test_prepare_bundle_for_immediate_sync_publishes_now(): void
    {
        $bundle = app(ArticleWpSyncQueueService::class)->prepareBundleForImmediateSync([
            'html' => '<p>x</p>',
            'publish_box' => [
                'publish_immediately' => true,
                'status' => 'scheduled',
                'publish_day' => '01',
                'publish_month' => '01',
                'publish_year' => '2099',
                'publish_hour' => '23',
                'publish_minute' => '59',
            ],
        ]);

        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $this->assertSame('published', $publishBox['status'] ?? null);
        $this->assertFalse(filter_var($publishBox['publish_immediately'] ?? true, FILTER_VALIDATE_BOOL));
        $this->assertNotSame('2099', $publishBox['publish_year'] ?? null);
    }
}
