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
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Publishing Queue cho Content Project — dispatch due tasks qua Command Bus.
 * Assumes caller already bootstrapped+verified SEO DB via SeoDatabaseConnectionService.
 */
final class ContentProjectPublishingQueueRunner
{
    public function __construct(
        private readonly ContentProjectQueueHealthService $health,
        private readonly ContentProjectCommandBus $commandBus,
        private readonly SeoDatabaseConnectionService $databaseConnection,
    ) {}

    public function health(): ContentProjectQueueHealthService
    {
        return $this->health;
    }

    /**
     * @param  array<string, mixed>  $connectionMeta  safe bootstrap metadata from canonical resolver
     * @return array{processed: int, published: int, failed: int, skipped: int}
     */
    public function dispatchDue(array $connectionMeta = []): array
    {
        $stats = [
            'processed' => 0,
            'published' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $connectionName = $this->databaseConnection->connectionName();

        try {
            if (! Schema::connection($connectionName)->hasColumn('seo_project_tasks', 'scheduled_publish_at')) {
                RuntimeLogger::warning('content_project_publishing_queue_schema_unavailable', [
                    'phase' => 'missing_column',
                    'column' => 'scheduled_publish_at',
                    'connection_name' => $connectionName,
                    'connection_id' => $connectionMeta['connection_id'] ?? null,
                    'database' => $connectionMeta['database'] ?? null,
                ]);

                return $stats;
            }
        } catch (Throwable $e) {
            // Auth/bootstrap failures must not be labeled as schema missing.
            if ($this->looksLikeConnectionFailure($e)) {
                RuntimeLogger::warning('publishing.connection_bootstrap_failed', [
                    'phase' => 'schema_probe',
                    'connection_name' => $connectionName,
                    'connection_id' => $connectionMeta['connection_id'] ?? null,
                    'database' => $connectionMeta['database'] ?? null,
                    'resolver' => $connectionMeta['resolver'] ?? 'SeoDatabaseConnectionService',
                    'runtime' => $connectionMeta['runtime'] ?? 'console',
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $this->health->rememberBootstrapFailure(
                    $e->getMessage(),
                    (int) ($connectionMeta['connection_id'] ?? 0) ?: null,
                );
            } else {
                RuntimeLogger::warning('content_project_publishing_queue_schema_unavailable', [
                    'phase' => 'schema_probe',
                    'connection_name' => $connectionName,
                    'connection_id' => $connectionMeta['connection_id'] ?? null,
                    'hash_id' => $connectionMeta['hash_id'] ?? null,
                    'database' => $connectionMeta['database'] ?? null,
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }

            throw $e;
        }

        $scopedConnectionId = (int) ($connectionMeta['connection_id'] ?? 0) ?: null;

        // Heartbeat only after verified connection + schema — never on bootstrap failure.
        $this->health->rememberWorkerRun($scopedConnectionId);

        $this->dueTasks($connectionName)->each(function (SeoProjectTask $task) use (&$stats, $scopedConnectionId, $connectionMeta): void {
            $stats['processed']++;
            $projectId = (int) ($task->project_id ?? 0) ?: null;

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

                RuntimeLogger::info('publishing.due_item_dispatch', [
                    'project_id' => $projectId,
                    'task_id' => $itemId,
                    'expected_connection_id' => $scopedConnectionId,
                    'expected_hash_id' => $connectionMeta['hash_id'] ?? null,
                    'resolved_connection_id' => $scopedConnectionId,
                    'resolved_hash_id' => $connectionMeta['hash_id'] ?? null,
                    'database' => $connectionMeta['database'] ?? null,
                ]);

                $result = $this->commandBus->dispatch(
                    new ProcessScheduledProjectItemPublishCommand(
                        itemRef: $itemId,
                        projectRef: $projectId,
                    ),
                    $actor,
                );

                if ($result->success) {
                    $stats['published']++;
                } elseif ($result->code === ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING) {
                    $stats['skipped']++;
                } else {
                    $stats['failed']++;
                    $this->health->rememberFailure($result->message, $scopedConnectionId);
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->health->rememberFailure($e->getMessage(), $scopedConnectionId);
                RuntimeLogger::warning('content_project_publishing_queue_failed', [
                    'task_id' => (int) $task->id,
                    'project_id' => $projectId,
                    'connection_id' => $scopedConnectionId,
                    'hash_id' => $connectionMeta['hash_id'] ?? null,
                    'database' => $connectionMeta['database'] ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        });

        $this->health->rememberSuccess(
            (int) $stats['processed'],
            $scopedConnectionId,
        );

        return $stats;
    }

    /**
     * @return Collection<int, SeoProjectTask>
     */
    private function dueTasks(string $connectionName): Collection
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

        if (Schema::connection($connectionName)->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $query->where(static function ($q): void {
                $q->whereIn('publish_queue_status', [
                    ContentProjectPublishQueueStatus::Waiting->value,
                    ContentProjectPublishQueueStatus::Retrying->value,
                    ContentProjectPublishQueueStatus::None->value,
                ])->orWhereNull('publish_queue_status');
            });
        }

        return $query->get();
    }

    private function looksLikeConnectionFailure(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'access denied')
            || str_contains($message, '1045')
            || str_contains($message, '2002')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'unknown database')
            || str_contains($message, 'không kết nối được');
    }
}
