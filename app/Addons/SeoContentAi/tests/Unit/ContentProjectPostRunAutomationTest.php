<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Automation\BusinessHook\Events\BridgingAutomationEventDispatcher;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPostRunPipeline;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPostSectionAnalyzer;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use App\Addons\SeoContentAi\Support\ContentProjectRunSettings;
use PHPUnit\Framework\TestCase;

final class ContentProjectPostRunAutomationTest extends TestCase
{
    public function test_run_settings_default_generate_post_images_false(): void
    {
        $settings = ContentProjectRunSettings::defaults();

        self::assertFalse($settings->generatePostImages);
        self::assertSame(1, $settings->settingsVersion);
    }

    public function test_run_settings_from_user_input_snapshots_boolean(): void
    {
        $on = ContentProjectRunSettings::fromUserInput(['generate_post_images' => true]);
        $off = ContentProjectRunSettings::fromUserInput([]);

        self::assertTrue($on->generatePostImages);
        self::assertFalse($off->generatePostImages);
        self::assertSame(['generate_post_images' => true, 'settings_version' => 1], $on->toArray());
    }

    public function test_start_run_accepts_settings_snapshot(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(SeoProjectWorkflowRunService::class))->getFileName() ?: '',
        );

        self::assertStringContainsString("'settings' => \$snapshot", $source);
        self::assertStringContainsString('ContentProjectRunSettings::snapshotForRun', $source);
    }

    public function test_post_pipeline_only_for_article_post_type(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(ContentProjectPostRunPipeline::class))->getFileName() ?: '',
        );

        self::assertStringContainsString('POST_TYPE_ARTICLE', $source);
        self::assertStringContainsString('isNewArticleType', $source);

        self::assertTrue($this->matchesPostTask(new SeoProjectTask([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
        ])));
        self::assertFalse($this->matchesPostTask(new SeoProjectTask([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_PRODUCT,
        ])));
    }

    private function matchesPostTask(SeoProjectTask $task): bool
    {
        return SeoProjectTask::isNewArticleType($task->type)
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_ARTICLE;
    }

    public function test_section_analyzer_caps_at_three_sections(): void
    {
        $analyzer = new ContentProjectPostSectionAnalyzer();
        $html = '';
        for ($i = 1; $i <= 6; $i++) {
            $html .= '<h2>Section '.$i.'</h2><p>'.str_repeat('Nội dung dài cho section '.$i.' ', 20).'</p>';
        }

        $sections = $analyzer->eligibleSections($html);

        self::assertCount(3, $sections);
    }

    public function test_section_analyzer_skips_faq_and_conclusion_headings(): void
    {
        $analyzer = new ContentProjectPostSectionAnalyzer();
        $html = '<h2>FAQ</h2><p>'.str_repeat('câu hỏi ', 40).'</p>'
            .'<h2>Kết luận</h2><p>'.str_repeat('tóm tắt ', 40).'</p>'
            .'<h2>Nội dung chính</h2><p>'.str_repeat('chi tiết ', 40).'</p>';

        $sections = $analyzer->eligibleSections($html);

        self::assertCount(1, $sections);
        self::assertSame('Nội dung chính', $sections[0]['heading']);
    }

    public function test_content_project_run_suppresses_article_completed_bridge(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(BridgingAutomationEventDispatcher::class))->getFileName() ?: '',
        );

        self::assertStringContainsString('shouldBridgeArticleCompleted', $source);
        self::assertStringContainsString('content_project_run', $source);
        self::assertStringContainsString('suppress_article_completed_bridge', $source);
    }

    public function test_seo_project_run_model_casts_settings(): void
    {
        $casts = (new SeoProjectRun())->getCasts();

        self::assertArrayHasKey('settings', $casts);
        self::assertSame('array', $casts['settings']);
    }

    public function test_bulk_sync_service_uses_cache_lock(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ContentProject/ContentProjectRunBulkSyncService.php',
        );

        self::assertStringContainsString('content-project-run-bulk-sync:', $source);
        self::assertStringContainsString('WordPressManualSyncService', $source);
        self::assertStringNotContainsString('WordPressArticleSyncService', $source);
    }
}
