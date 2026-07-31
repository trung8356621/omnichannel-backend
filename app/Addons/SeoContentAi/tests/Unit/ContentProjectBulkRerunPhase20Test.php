<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\ContentProjectRerunFromStep;
use PHPUnit\Framework\TestCase;

/**
 * Batch B freeze — BulkRerunService removed; enum maps legacy regenerate_* strings.
 */
final class ContentProjectBulkRerunPhase20Test extends TestCase
{
    public function test_bulk_rerun_service_file_removed(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__, 2).'/Services/ContentProjectBulkRerunService.php',
        );
    }

    public function test_rerun_from_step_try_from_mixed_accepts_regenerate_strings(): void
    {
        self::assertSame(
            ContentProjectRerunFromStep::Outline,
            ContentProjectRerunFromStep::tryFromMixed('regenerate_outline'),
        );
        self::assertSame(
            ContentProjectRerunFromStep::Article,
            ContentProjectRerunFromStep::tryFromMixed('regenerate_article'),
        );
        self::assertNull(ContentProjectRerunFromStep::tryFromMixed('regenerate_outline_and_article'));
    }

    public function test_filament_uses_step_command(): void
    {
        $view = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        self::assertStringContainsString('RerunProjectItemStepCommand', $view);
        self::assertStringContainsString('dispatchBulkStep', $view);
        self::assertStringContainsString('ContentProjectRerunFromStep', $view);
    }
}
