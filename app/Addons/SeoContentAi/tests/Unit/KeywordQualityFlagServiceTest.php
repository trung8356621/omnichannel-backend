<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\KeywordReviewStatus;
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

    public function test_short_phrase_no_longer_gets_auto_danger_flag(): void
    {
        $this->requireSeoDatabaseConnection();

        $keyword = Keyword::query()->create([
            'phrase' => 'ab',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        app(KeywordQualityFlagService::class)->recomputeForKeywordFromMaps((int) $keyword->id);

        $this->assertSame([], app(KeywordMetaRepository::class)->getQualityFlags((int) $keyword->id));
        $this->assertSame(KeywordReviewStatus::Active->value, (string) $keyword->fresh()?->review_status);
    }

    public function test_long_phrase_no_longer_gets_auto_warning_flag(): void
    {
        $this->requireSeoDatabaseConnection();

        $keyword = Keyword::query()->create([
            'phrase' => 'one two three four five six seven eight',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        app(KeywordQualityFlagService::class)->recomputeForKeywordFromMaps((int) $keyword->id);

        $this->assertSame([], app(KeywordMetaRepository::class)->getQualityFlags((int) $keyword->id));
        $this->assertSame(KeywordReviewStatus::Active->value, (string) $keyword->fresh()?->review_status);
    }

    public function test_special_phrase_no_longer_gets_auto_flag(): void
    {
        $this->requireSeoDatabaseConnection();

        $keyword = Keyword::query()->create([
            'phrase' => ': "keyword (test)"',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        app(KeywordQualityFlagService::class)->recomputeForKeywordFromMaps((int) $keyword->id);

        $this->assertSame(KeywordReviewStatus::Active->value, (string) $keyword->fresh()?->review_status);
    }

    public function test_empty_context_before_no_longer_adds_auto_danger(): void
    {
        $this->requireSeoDatabaseConnection();

        $keyword = Keyword::query()->create([
            'phrase' => 'valid keyword phrase',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        SeoLinkMap::query()->create([
            'keyword_id' => (int) $keyword->id,
            'anchor_text' => 'valid keyword phrase',
            'context_before' => '',
            'link_type' => 'internal',
            'status' => 'active',
        ]);

        app(KeywordQualityFlagService::class)->recomputeForKeywordFromMaps((int) $keyword->id);

        $this->assertSame(KeywordReviewStatus::Active->value, (string) $keyword->fresh()?->review_status);
    }
}
