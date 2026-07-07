<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleScheduleReconcileService;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Tests\Support\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

final class ArticleScheduleReconcileServiceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_reconcile_skips_when_status_is_not_scheduled(): void
    {
        $this->requireSeoDatabaseConnection();

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Already published',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'type' => 'article',
        ]);

        $wpSync = Mockery::mock(WordPressArticleSyncService::class);
        $wpSync->shouldNotReceive('publishScheduledArticle');
        $this->instance(WordPressArticleSyncService::class, $wpSync);

        $changed = app(ArticleScheduleReconcileService::class)->reconcileForEditor($article);

        $this->assertFalse($changed);
        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_reconcile_promotes_overdue_scheduled_article_without_wp_post(): void
    {
        $this->requireSeoDatabaseConnection();

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Overdue local schedule',
            'status' => 'scheduled',
            'published_at' => now()->subHour(),
            'wp_post_id' => 0,
            'type' => 'article',
        ]);

        $wpSync = Mockery::mock(WordPressArticleSyncService::class);
        $wpSync->shouldNotReceive('publishScheduledArticle');
        $this->instance(WordPressArticleSyncService::class, $wpSync);

        $changed = app(ArticleScheduleReconcileService::class)->reconcileForEditor($article);

        $this->assertTrue($changed);
        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_reconcile_publishes_overdue_scheduled_article_with_wp_post(): void
    {
        $this->requireSeoDatabaseConnection();

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Overdue WP schedule',
            'status' => 'scheduled',
            'published_at' => now()->subMinutes(10),
            'wp_post_id' => 123,
            'type' => 'article',
        ]);

        $wpSync = Mockery::mock(WordPressArticleSyncService::class);
        $wpSync->shouldReceive('publishScheduledArticle')
            ->once()
            ->andReturnUsing(static function (SeoArticle $target): array {
                $target->update(['status' => 'published']);

                return ['success' => true, 'message' => 'Published'];
            });
        $this->instance(WordPressArticleSyncService::class, $wpSync);

        $changed = app(ArticleScheduleReconcileService::class)->reconcileForEditor($article);

        $this->assertTrue($changed);
        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_schedule_label_visibility_helpers(): void
    {
        $service = app(ArticleScheduleReconcileService::class);

        $this->assertTrue($service->shouldShowScheduleLabel('scheduled'));
        $this->assertFalse($service->shouldShowScheduleLabel('published'));

        $this->assertTrue($service->shouldShowPublishedAtLabel('published', Carbon::now()->subDay()));
        $this->assertFalse($service->shouldShowPublishedAtLabel('scheduled', Carbon::now()->subDay()));
        $this->assertFalse($service->shouldShowPublishedAtLabel('published', null));
    }
}
