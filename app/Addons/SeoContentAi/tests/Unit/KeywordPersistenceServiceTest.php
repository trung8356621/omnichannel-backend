<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoLink;
use App\Addons\SeoContentAi\Services\KeywordPersistenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class KeywordPersistenceServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_upsert_creates_global_keyword_and_site_link_pivot(): void
    {
        $suffix = uniqid('kw_persist_', true);
        $phrase = 'balo quảng cáo '.$suffix;
        $service = app(KeywordPersistenceService::class);

        $first = $service->upsert($phrase, Keyword::TYPE_NORMAL, 2, '/a');
        $second = $service->upsert($phrase, Keyword::TYPE_NORMAL, 3, '/b');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Keyword::query()->where('phrase', $phrase)->count());
        $this->assertSame('/a', $first->fresh(['links'])?->targetUrlForSite(2));
        $this->assertSame('/b', $second->fresh(['links'])?->targetUrlForSite(3));
        $this->assertSame(1, SeoLink::query()->where('site_id', 2)->where('url', '/a')->count());
        $this->assertSame(1, SeoLink::query()->where('site_id', 3)->where('url', '/b')->count());
    }

    public function test_upsert_returns_null_for_empty_phrase(): void
    {
        $service = app(KeywordPersistenceService::class);

        $this->assertNull($service->upsert('   ', Keyword::TYPE_NORMAL, 2));
        $this->assertNull($service->upsert('&nbsp;', Keyword::TYPE_NORMAL, 2));
    }
}
