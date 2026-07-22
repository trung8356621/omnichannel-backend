<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticleEditorHistoryService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ArticleEditorHistoryLocalDraftIntervalTest extends TestCase
{
    public function test_default_local_draft_interval_is_two_seconds(): void
    {
        self::assertSame(2, ArticleEditorHistoryService::DEFAULT_AUTOSAVE_INTERVAL_SECONDS);
    }

    public function test_local_draft_interval_clamped_between_zero_and_thirty(): void
    {
        if (! Schema::hasTable('wp_options')) {
            $this->markTestSkipped('wp_options table is not available in this test database.');
        }

        $service = app(ArticleEditorHistoryService::class);

        $service->saveSettings(['autosave_interval_seconds' => 999]);
        self::assertSame(30, $service->getSettings()['autosave_interval_seconds']);

        $service->saveSettings(['autosave_interval_seconds' => 0]);
        self::assertSame(0, $service->getSettings()['autosave_interval_seconds']);

        $service->saveSettings(['autosave_interval_seconds' => 15]);
        self::assertSame(15, $service->getSettings()['autosave_interval_seconds']);
    }
}
