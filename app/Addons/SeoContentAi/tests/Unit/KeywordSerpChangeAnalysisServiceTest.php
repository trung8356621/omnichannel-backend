<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\KeywordSerpChangeAnalysisService;
use Tests\TestCase;

final class KeywordSerpChangeAnalysisServiceTest extends TestCase
{
    public function test_empty_group_returns_no_changes(): void
    {
        $changes = app(KeywordSerpChangeAnalysisService::class)->buildChanges(null, 'serpapi');

        $this->assertSame([], $changes);
    }
}
