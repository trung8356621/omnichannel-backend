<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hub "all projects" must not abort(404) on row schedule actions.
 */
final class PublishingQueueHubScheduleWithoutProjectContractTest extends TestCase
{
    public function test_hub_resolves_project_from_task_instead_of_abort_404(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Pages/PublishingQueueHub.php',
        );

        self::assertStringContainsString('actionProjectOverride', $src);
        self::assertStringContainsString('withProjectFromTask', $src);
        self::assertStringContainsString('loadAccessibleProjectForTasks', $src);
        self::assertStringContainsString('function scheduleOneInMinutes', $src);
        self::assertStringContainsString('throw new Halt', $src);
        self::assertStringNotContainsString('abort(404)', $src);
    }

    public function test_row_menu_still_calls_schedule_one_in_minutes_5(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/components/publishing-queue-item-actions-menu.blade.php',
        );
        self::assertStringContainsString('scheduleOneInMinutes({{ $tid }}, 5)', $blade);
    }
}
