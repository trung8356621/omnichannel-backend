<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Filament\Pages\PublishingQueueHub;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Concerns\InteractsWithContentProjectPublishingActions;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RecoverStuckPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectAutoScheduleService;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueService;
use App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueuePublishingDefinition;
use App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueScheduledDefinition;
use App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueStateClassifier;
use App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublishingQueueCanonicalStateMachineTest extends TestCase
{
    public function test_due_waiting_is_scheduled_not_publishing(): void
    {
        $row = [
            'publish_queue_status' => 'waiting',
            'scheduled_publish_at' => now()->subHour()->toIso8601String(),
            'scheduled_raw' => now()->subHour()->toIso8601String(),
        ];

        self::assertFalse(PublishingQueuePublishingDefinition::matches($row));
        self::assertTrue(PublishingQueueScheduledDefinition::matches($row));
        self::assertSame('scheduled', PublishingQueueStateClassifier::classify($row)['state']);
    }

    public function test_processing_is_publishing(): void
    {
        $row = [
            'publish_queue_status' => 'processing',
            'scheduled_publish_at' => now()->subHour()->toIso8601String(),
            'last_publish_attempt_at' => now()->toIso8601String(),
        ];

        self::assertTrue(PublishingQueuePublishingDefinition::matches($row));
        self::assertSame('publishing', PublishingQueueStateClassifier::classify($row)['state']);
    }

    public function test_stuck_publishing_without_attempt_or_stale_ttl(): void
    {
        self::assertTrue(PublishingQueueStuckPublishingDefinition::matches([
            'publish_queue_status' => 'processing',
        ]));

        self::assertTrue(PublishingQueueStuckPublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'last_publish_attempt_at' => now()->subHours(2)->toIso8601String(),
        ]));

        self::assertFalse(PublishingQueueStuckPublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'last_publish_attempt_at' => now()->subMinutes(5)->toIso8601String(),
        ]));
    }

    public function test_processing_to_cancelled_still_rejected(): void
    {
        $guard = new ContentProjectPublishTransitionGuard;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lifecycle.invalid_transition: processing → cancelled');
        $guard->assertCanTransition('processing', 'cancelled');
    }

    public function test_schedule_service_never_sets_waiting_for_plan(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPublishingQueueService::class))->getFileName(),
        );
        self::assertStringContainsString('Always execution none', $src);
        self::assertStringContainsString('recoverStuckPublishing', $src);
        self::assertStringContainsString('publishing.busy_cannot_reschedule', $src);
        self::assertStringNotContainsString('Past/now at ⇒ waiting for runner', $src);
    }

    public function test_auto_schedule_has_in_day_and_excludes_publishing(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAutoScheduleService::class))->getFileName(),
        );
        self::assertStringContainsString("'in_day'", $src);
        self::assertStringContainsString('buildInDaySlots', $src);
        self::assertStringContainsString('resolveEligible', $src);
        self::assertStringContainsString("reason' => 'publishing'", $src);
        self::assertStringContainsString('SeoDisplayTimezone', $src);
        self::assertStringContainsString('MIN_INTERVAL_MINUTES', $src);
        self::assertStringContainsString('Preview never crashes the page', $src);
    }

    public function test_hub_auto_quick_no_selection_required(): void
    {
        $hub = (string) file_get_contents(
            (string) (new ReflectionClass(PublishingQueueHub::class))->getFileName(),
        );
        self::assertStringContainsString("'mode' => 'project_month'", $hub);
        self::assertStringContainsString('selectAllMatchingResults', $hub);
        self::assertStringContainsString('togglePageSelection', $hub);
        self::assertStringContainsString('RecoverStuckPublishingCommand', $hub);
        self::assertStringContainsString('in_day', $hub);

        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithContentProjectPublishingActions::class))->getFileName(),
        );
        self::assertStringContainsString("allowsEmptySelection = in_array(\$op, ['auto_schedule']", $trait);
        self::assertStringContainsString('if (! $result->success)', $trait);

        $view = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/pages/publishing-queue-hub.blade.php',
        );
        self::assertStringContainsString('Auto Schedule', $view);
        self::assertStringContainsString('Quick Mode', $view);
        self::assertStringContainsString('In Day', $view);
        self::assertStringContainsString('Timezone:', $view);
        self::assertStringContainsString('Recover stuck publishing', $view);
        self::assertStringContainsString('Runner healthy', $view);
        self::assertStringContainsString('Không cần tick checkbox', $view);
    }

    public function test_process_handler_marks_processing_after_dispatch(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ProcessScheduledProjectItemPublishHandler::class))->getFileName(),
        );
        $posPublisher = strpos($source, '$publisher->publish($payload)');
        $posProcessing = strpos($source, 'markProcessing($task->fresh()');
        self::assertNotFalse($posPublisher);
        self::assertNotFalse($posProcessing);
        self::assertGreaterThan($posPublisher, $posProcessing);
    }

    public function test_recover_command_registered(): void
    {
        self::assertSame(
            'content_project.recover_stuck_publishing',
            (new RecoverStuckPublishingCommand(1, [1], 'scheduled'))->name(),
        );
        $registrar = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );
        self::assertStringContainsString('RecoverStuckPublishingCommand::class', $registrar);
    }

    public function test_notifier_maps_processing_cancelled(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectActionResultNotifier::class))->getFileName(),
        );
        self::assertStringContainsString('Bài đang được xuất bản nên không thể đổi lịch.', $src);
        self::assertStringContainsString('allowSuccessToast', $src);
        self::assertStringContainsString('stale_processing', $src);
    }
}
