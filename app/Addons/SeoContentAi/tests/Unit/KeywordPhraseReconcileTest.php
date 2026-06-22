<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\KeywordPersistenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class KeywordPhraseReconcileTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_merge_suffix_truncated_keywords_absorbs_corrupt_phrase_and_link(): void
    {
        $service = app(KeywordPersistenceService::class);
        $siteId = 2;
        $suffix = uniqid('reconcile_', true);

        $corrupt = $service->upsert(
            'àu sắc túi canvas '.$suffix,
            Keyword::TYPE_NORMAL,
            $siteId,
            '/mau-sac-tui-canvas-'.$suffix,
        );
        $canonical = $service->upsert(
            'màu sắc túi canvas '.$suffix,
            Keyword::TYPE_NORMAL,
            $siteId,
            'https://maytuicanvas.com/mau-sac-tui-canvas-'.$suffix.'/',
        );

        $this->assertNotNull($corrupt);
        $this->assertNotNull($canonical);
        $this->assertNotSame($corrupt->id, $canonical->id);

        $service->mergeSuffixTruncatedKeywords($canonical, $siteId);

        $this->assertNull(Keyword::query()->find($corrupt->id));
        $this->assertSame(
            'https://maytuicanvas.com/mau-sac-tui-canvas-'.$suffix.'/',
            $canonical->fresh()?->targetUrlForSite($siteId),
        );
    }
}
