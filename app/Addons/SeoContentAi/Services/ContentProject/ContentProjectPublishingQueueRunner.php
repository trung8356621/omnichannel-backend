<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ProcessScheduledProjectItemPublishCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectCommandBus;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectIdempotencyKeyFactory;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Publishing Queue cho Content Project — dispatch due tasks qua Command Bus.
 */
final class ContentProjectPublishingQueueRunner
{
    public function __construct(
        private readonly ContentProjectQueueHealthService $health,
        private readonly ContentProjectCommandBus $commandBus,
    ) {}

    /**
     * @return array{processed: int, published: int, failed: int, skipped: int}
     */
    public function dispatchDue(): array
    {
        $stats = [
            'processed' => 0,
            'published' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $this->health->rememberWorkerRun();

        try {
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'scheduled_publish_at')) {
                return $stats;
            }
        } catch (Throwable $e) {
            RuntimeLogger::warning('content_project_publishing_queue_schema_unavailable', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }

        $this->dueTasks()->each(function (SeoProjectTask $task) use (&$stats): void {
            $stats['processed']++;

            try {
                $itemId = (int) $task->getKey();
                $idemKey = $task->scheduled_publish_at !== null
                    ? ContentProjectIdempotencyKeyFactory::scheduler(
                        $itemId,
                        $task->scheduled_publish_at->toIso8601String(),
                    )
                    : null;

                $actor = new ActorContext(
                    actorType: 'queue',
                    actorId: null,
                    siteId: (int) ($task->site_id ?? $task->project?->site_id ?? 0) ?: null,
                    idempotencyKey: $idemKey,
                    correlationId: 'cp-publish-'.$itemId,
                );

                $result = $this->commandBus->dispatch(
                    new ProcessScheduledProjectItemPublishCommand(
                        itemRef: $itemId,
                        projectRef: (int) ($task->project_id ?? 0) ?: null,
                    ),
                    $actor,
                );

                if ($result->success) {
                    $stats['published']++;
                } elseif ($result->code === ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING) {
                    $stats['skipped']++;
                } else {
                    $stats['failed']++;
                    $this->health->rememberFailure($result->message);
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->health->rememberFailure($e->getMessage());
                RuntimeLogger::warning('content_project_publishing_queue_failed', [
                    'task_id' => (int) $task->id,
                    'message' => $e->getMessage(),
                ]);
            }
        });

        return $stats;
    }

    /**
     * @return Collection<int, SeoProjectTask>
     */
    private function dueTasks(): Collection
    {
        $query = SeoProjectTask::query()
            ->active()
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now())
            ->where('article_id', '>', 0)
            ->whereHas('project', static function ($query): void {
                $query->whereNull('archived_at');
            })
            ->with(['article', 'project'])
            ->orderBy('scheduled_publish_at')
            ->orderBy('id')
            ->limit(50);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $query->whereIn('publish_queue_status', [
                ContentProjectPublishQueueStatus::Waiting->value,
                ContentProjectPublishQueueStatus::Retrying->value,
                ContentProjectPublishQueueStatus::None->value,
            ]);
        }

        return $query->get();
    }
}
