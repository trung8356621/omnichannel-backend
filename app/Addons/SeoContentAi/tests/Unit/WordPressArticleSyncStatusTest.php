<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use ReflectionMethod;
use Tests\TestCase;

final class WordPressArticleSyncStatusTest extends TestCase
{
    public function test_scheduled_status_syncs_as_wordpress_draft_without_future_date(): void
    {
        $article = new SeoArticle([
            'status' => 'scheduled',
            'published_at' => now()->addDay(),
        ]);

        $service = app(WordPressArticleSyncService::class);
        $method = new ReflectionMethod($service, 'resolveWordPressStatusPayload');
        $method->setAccessible(true);

        /** @var array{status: string, post_date?: string|null} $payload */
        $payload = $method->invoke($service, $article);

        $this->assertSame('draft', $payload['status']);
        $this->assertArrayNotHasKey('post_date', $payload);
    }

    public function test_published_status_maps_to_wordpress_publish(): void
    {
        $article = new SeoArticle([
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        $service = app(WordPressArticleSyncService::class);
        $method = new ReflectionMethod($service, 'resolveWordPressStatusPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $article);

        $this->assertSame('publish', $payload['status']);
        $this->assertNotEmpty($payload['post_date'] ?? null);
    }

    public function test_publish_for_article_entry_point_exists(): void
    {
        $this->assertTrue(method_exists(WordPressArticleSyncService::class, 'publishForArticle'));
    }

    public function test_should_skip_editor_sync_when_fingerprint_matches_and_no_local_edit(): void
    {
        $article = new SeoArticle([
            'id' => 4092,
            'wp_post_id' => 11391,
            'title' => 'Demo title',
            'slug' => 'demo-title',
            'status' => 'published',
        ]);

        $service = app(WordPressArticleSyncService::class);
        $fingerprintMethod = new ReflectionMethod($service, 'editorSyncFingerprint');
        $fingerprintMethod->setAccessible(true);

        $prepared = [
            'request_payload' => [
                'title' => 'Demo title',
                'slug' => 'demo-title',
                'status' => 'publish',
                'post_type' => 'post',
                'seo' => [],
            ],
            'post_content' => '<p>Hello world</p>',
            'faqs' => [],
            'local_media_sync_errors' => [],
        ];

        $fingerprint = $fingerprintMethod->invoke($service, $prepared);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => WordPressArticleSyncService::META_WP_EDITOR_SYNC_FINGERPRINT,
                'meta_value' => $fingerprint,
            ]),
        ]));

        $result = $service->shouldSkipEditorSyncRequest($article, $prepared);

        $this->assertTrue($result['skip']);
        $this->assertSame('fingerprint_match', $result['reason']);
    }

    public function test_should_not_skip_editor_sync_when_local_edit_pending(): void
    {
        $article = new SeoArticle([
            'id' => 1,
            'wp_post_id' => 99,
        ]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => ArticleWordPressSyncFlagService::META_LOCAL_EDIT_PENDING,
                'meta_value' => '1',
            ]),
        ]));

        $service = app(WordPressArticleSyncService::class);
        $result = $service->shouldSkipEditorSyncRequest($article, [
            'request_payload' => ['title' => 'A'],
            'post_content' => '',
            'faqs' => [],
            'local_media_sync_errors' => [],
        ]);

        $this->assertFalse($result['skip']);
        $this->assertSame('local_edit_pending', $result['reason']);
    }

    public function test_should_not_skip_editor_sync_when_post_content_has_local_seo_media(): void
    {
        $article = new SeoArticle([
            'id' => 1,
            'wp_post_id' => 99,
        ]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => WordPressArticleSyncService::META_WP_EDITOR_SYNC_FINGERPRINT,
                'meta_value' => hash('sha256', 'same-fingerprint'),
            ]),
        ]));

        $service = app(WordPressArticleSyncService::class);
        $prepared = [
            'request_payload' => ['title' => 'A'],
            'post_content' => '<p><img src="/storage/uploads/seo_media/new-image.webp" alt=""></p>',
            'faqs' => [],
            'local_media_sync_errors' => [],
        ];

        $result = $service->shouldSkipEditorSyncRequest($article, $prepared);

        $this->assertFalse($result['skip']);
        $this->assertSame('pending_local_media', $result['reason']);
    }

    public function test_create_for_article_accepts_optional_editor_payload(): void
    {
        $method = new ReflectionMethod(WordPressArticleSyncService::class, 'createForArticle');
        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('editorPayload', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->allowsNull());
    }
}
