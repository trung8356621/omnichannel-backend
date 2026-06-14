<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\CtaKeywordBlacklistDebugService;
use App\Addons\SeoContentAi\Services\KeywordPersistenceService;
use App\Addons\SeoContentAi\Services\OutlineSkipListMatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class CtaKeywordBlacklistDebugServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_scan_matches_only_keywords_table(): void
    {
        $suffix = uniqid('cta_debug_', true);
        $service = app(KeywordPersistenceService::class);

        $blocked = $service->upsert('Báo giá ngay '.$suffix, Keyword::TYPE_NORMAL, 2, '/blocked');
        $service->upsert('balo quảng cáo '.$suffix, Keyword::TYPE_NORMAL, 2, '/ok');

        $this->assertNotNull($blocked);

        $report = (new CtaKeywordBlacklistDebugService(new OutlineSkipListMatcher))->scan(2, ['báo giá ngay']);

        $matchedForTest = collect($report['matched_keywords'])
            ->filter(static fn (array $row): bool => str_contains($row['phrase'], $suffix))
            ->values()
            ->all();

        $this->assertCount(1, $matchedForTest);
        $this->assertSame('Báo giá ngay '.$suffix, $matchedForTest[0]['phrase']);
        $this->assertSame((int) $blocked->id, $matchedForTest[0]['id']);
        $this->assertSame(['báo giá ngay'], $matchedForTest[0]['matched_rules']);
    }
}
