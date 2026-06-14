<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\KeywordDebugRescrapeService;
use App\Addons\SeoContentAi\Services\KeywordPersistenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class KeywordDebugRescrapeServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_delete_and_rescrape_removes_keyword_without_linked_articles(): void
    {
        $suffix = uniqid('kw_debug_', true);
        $phrase = 'debug keyword '.$suffix;
        $keyword = app(KeywordPersistenceService::class)->upsert($phrase, Keyword::TYPE_NORMAL, 2, '/debug');

        $this->assertNotNull($keyword);

        $summary = app(KeywordDebugRescrapeService::class)->deleteAndRescrapeLinkedArticles($keyword);

        $this->assertSame($phrase, $summary['phrase']);
        $this->assertSame([], $summary['linked_article_ids']);
        $this->assertTrue($summary['deleted']);
        $this->assertSame(0, $summary['rescanned']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertFalse(Keyword::query()->where('phrase', $phrase)->exists());
    }
}
