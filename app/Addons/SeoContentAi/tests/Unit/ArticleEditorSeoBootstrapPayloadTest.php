<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleContentSeoBonusService;
use App\Addons\SeoContentAi\Services\ArticleEditorSeoPayloadService;
use App\Addons\SeoContentAi\Services\ArticleInternalLinkSuggestionService;
use App\Models\Site;
use Mockery;
use Tests\TestCase;

final class ArticleEditorSeoBootstrapPayloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_for_editor_bootstrap_is_light_and_defers_heavy_catalogs(): void
    {
        $suggestionMock = Mockery::mock(ArticleInternalLinkSuggestionService::class);
        $suggestionMock->shouldNotReceive('suggest');
        $suggestionMock->shouldNotReceive('suggestCatalog');
        $suggestionMock->shouldNotReceive('suggestExternal');
        $suggestionMock->shouldNotReceive('suggestExternalCatalog');
        $this->app->instance(ArticleInternalLinkSuggestionService::class, $suggestionMock);

        $article = new SeoArticle([
            'id' => 99,
            'site_id' => 1,
            'title' => 'Bootstrap article',
            'slug' => 'bootstrap-article',
            'body' => '<p>Hello</p>',
            'type' => 'post',
            'status' => 'draft',
        ]);
        $article->setRelation('articleMetas', collect([]));
        $article->setRelation('site', new Site([
            'domain' => 'example.com',
            'ssl' => true,
        ]));

        $service = new ArticleEditorSeoPayloadService(app(ArticleContentSeoBonusService::class));
        $payload = $service->forEditorBootstrap($article);

        self::assertSame('light', $payload['bootstrap_mode'] ?? null);
        self::assertSame('cached', $payload['status'] ?? null);
        self::assertSame([], $payload['suggested_internal_links'] ?? null);
        self::assertSame([], $payload['suggested_internal_links_catalog'] ?? null);
        self::assertSame([], $payload['domain_link_list'] ?? null);
        self::assertSame([], $payload['domain_link_list_catalog'] ?? null);
        self::assertSame([], $payload['domain_cta_list'] ?? null);
        self::assertSame(['internal' => [], 'external' => []], $payload['extracted_links'] ?? null);
        self::assertNull($payload['content_bonus'] ?? 'unset');
        self::assertSame(hash('sha256', '<p>Hello</p>'), $payload['analyzed_content_hash'] ?? null);
    }

    public function test_for_article_full_payload_method_still_exists(): void
    {
        $service = app(ArticleEditorSeoPayloadService::class);
        self::assertTrue(method_exists($service, 'forArticle'));
        self::assertTrue(method_exists($service, 'forEditorBootstrap'));
    }
}
