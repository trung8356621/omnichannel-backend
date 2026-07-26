<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ContentProjectBulkRerunService;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectStepRerunService;
use PHPUnit\Framework\TestCase;

final class ContentProjectBulkRerunPhase20Test extends TestCase
{
    public function test_bulk_actions_three_article_roles_only(): void
    {
        self::assertSame('regenerate_outline', ContentProjectBulkRerunService::ACTION_OUTLINE);
        self::assertSame('regenerate_article', ContentProjectBulkRerunService::ACTION_ARTICLE);
        self::assertSame('regenerate_outline_and_article', ContentProjectBulkRerunService::ACTION_OUTLINE_AND_ARTICLE);
    }

    public function test_bulk_preview_requires_confirm_when_partial(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ContentProjectBulkRerunService.php',
        );
        self::assertStringContainsString('invalid_count\'] > 0 && ! $allowPartial', $src);
        self::assertStringContainsString('Xác nhận chạy phần hợp lệ', $src);
    }

    public function test_bulk_serial_via_step_rerun(): void
    {
        $bulk = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ContentProjectBulkRerunService.php',
        );
        $rerun = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ContentProject/ContentProjectStepRerunService.php',
        );
        self::assertStringContainsString('executeBulkSerial', $bulk);
        self::assertStringContainsString('foreach ($preview[\'valid\'] as $row)', $rerun);
        self::assertTrue(class_exists(ContentProjectStepRerunService::class));
    }

    public function test_js_bulk_confirm_partial_gate(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/project-run-queue.js',
        );
        self::assertStringContainsString('allowPartial', $js);
        self::assertStringContainsString('invalid_count', $js);
        self::assertStringContainsString('openGenericStepPicker', $js);
        self::assertStringNotContainsString('window.prompt(', $js);
    }
}
