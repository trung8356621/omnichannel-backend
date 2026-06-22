<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoLinkMap;
use App\Addons\SeoContentAi\Services\KeywordMetaRepository;
use App\Addons\SeoContentAi\Services\KeywordQualityFlagService;
use App\Addons\SeoContentAi\Tests\Support\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class KeywordQualityFlagServiceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_short_phrase_gets_danger_flag(): void
    {
        $this->requireSeoDatabaseConnection();

        $keyword = Keyword::query()->create([
            'phrase' => 'ab',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        app(KeywordQualityFlagService::class)->recomputeForKeywordFromMaps((int) $keyword->id);

        $this->assertSame(['danger'], app(KeywordMetaRepository::class)->getQualityFlags((int) $keyword->id));
    }

    public function test_long_phrase_gets_warning_flag(): void
    {
        $this->requireSeoDatabaseConnection();

        $keyword = Keyword::query()->create([
            'phrase' => 'one two three four five six seven eight',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        app(KeywordQualityFlagService::class)->recomputeForKeywordFromMaps((int) $keyword->id);

        $this->assertSame(['warning'], app(KeywordMetaRepository::class)->getQualityFlags((int) $keyword->id));
    }

    public function test_empty_context_before_adds_danger_flag(): void
    {
        $this->requireSeoDatabaseConnection();

        $keyword = Keyword::query()->create([
            'phrase' => 'valid keyword phrase',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        $article = \App\Addons\SeoContentAi\Models\SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Quality test',
            'body' => '<p>Text</p>',
            'status' => 'publish',
            'type' => 'article',
        ]);

        SeoLinkMap::query()->create([
            'keyword_id' => $keyword->id,
            'source_article_id' => $article->id,
            'anchor_text' => 'valid keyword phrase',
            'context_before' => '',
            'link_type' => \App\Addons\SeoContentAi\Enums\SeoLinkMapType::Internal,
            'status' => \App\Addons\SeoContentAi\Enums\SeoLinkMapStatus::Active,
        ]);

        app(KeywordQualityFlagService::class)->recomputeForKeywordFromMaps((int) $keyword->id);

        $this->assertContains('danger', app(KeywordMetaRepository::class)->getQualityFlags((int) $keyword->id));
    }
}
