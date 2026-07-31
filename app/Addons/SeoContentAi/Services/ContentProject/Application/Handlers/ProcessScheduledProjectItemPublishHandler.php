<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter;
use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ProcessScheduledProjectItemPublishCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ArticlePublishFailed;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ArticlePublishRequested;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ArticlePublished;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\ArticlePublishPayload;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\PublishAttemptRefs;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\PublisherResolutionException;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\PublisherResolver;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectIdempotencyKeyFactory;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueService;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectQueueHealthService;
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class ProcessScheduledProjectItemPublishHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectPublishingQueueService $queue,
        private readonly ContentProjectQueueHealthService $health,
        private readonly PublisherResolver $publisherResolver,
        private readonly BusinessHookEmitter $emitter,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly ContentProjectIdempotencyStore $idempotencyStore,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ProcessScheduledProjectItemPublishCommand) {
            throw new InvalidArgumentException('Expected ProcessScheduledProjectItemPublishCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $itemId = $this->resolveItemIds([$command->itemRef])[0] ?? 0;
            if ($itemId <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Invalid item ref.',
                );
            }

            $task = SeoProjectTask::query()->with(['article', 'project'])->find($itemId);
            if (! $task instanceof SeoProjectTask) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'Task không tồn tại.',
                );
            }

            $project = $task->project;
            if (! $project instanceof SeoProject) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_NOT_FOUND,
                    'Project không tồn tại.',
                );
            }

            $projectId = (int) $project->getKey();

            if ($actor->actorType !== 'queue') {
                $this->tenantGuard->assertCanAccessProject($project, $actor);
            }

            $idemKey = $this->resolveIdempotencyKey($task, $actor);
            $tenantKey = 'site:'.(string) ($project->site_id ?? 0).':queue';
            if ($idemKey !== '') {
                $replay = $this->idempotencyStore->begin($tenantKey, $command->name(), $idemKey);
                if ($replay instanceof ContentProjectActionResult) {
                    return $replay;
                }
            }

            $result = $this->businessLock->withLock(
                $this->businessLock->itemPublish($itemId),
                function () use ($task, $projectId, $command): ContentProjectActionResult {
                    return $this->processPublish($task->fresh() ?? $task, $projectId, $command->attemptRef);
                },
            );

            if ($idemKey !== '') {
                $this->idempotencyStore->complete($tenantKey, $command->name(), $idemKey, $result);
            }

            return $result;
        });
    }

    private function processPublish(SeoProjectTask $task, int $projectId, ?string $attemptRef): ContentProjectActionResult
    {
        $itemId = (int) $task->getKey();
        $article = $task->article;

        if (! $article instanceof SeoArticle) {
            $this->queue->markFailed($task, 'Task không có article.');
            $this->health->rememberFailure('Task #'.$itemId.' missing article');

            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::FAILED,
                'Task không có article.',
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')
            && (string) ($task->publish_queue_status ?? '') === ContentProjectPublishQueueStatus::Processing->value
        ) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING,
                'Item đang processing.',
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        $this->queue->markProcessing($task);

        $attemptRef = $attemptRef ?? PublishAttemptRefs::newAttemptRef();
        $externalRef = PublishAttemptRefs::forArticle((int) $article->id);
        $payload = new ArticlePublishPayload(
            articleId: (int) $article->id,
            siteId: (int) ($article->site_id ?? 0),
            wpPostId: (int) ($article->wp_post_id ?? 0) ?: null,
            externalReference: $externalRef,
            attemptRef: $attemptRef,
            idempotencyKey: 'cp_publish_task_'.$itemId,
            projectId: $projectId,
            taskId: $itemId,
            actorUserId: null,
        );

        try {
            $publisher = $this->publisherResolver->resolveForSiteId((int) ($article->site_id ?? 0));
            $publishResult = $publisher->publish($payload);
        } catch (PublisherResolutionException $e) {
            $this->queue->markFailed($task, $e->getMessage());
            $this->health->rememberFailure($e->getMessage());
            $this->domainEvents->dispatchAfterCommit(new ArticlePublishFailed(
                projectId: $projectId,
                itemId: $itemId,
                articleId: (int) $article->id,
                error: $e->getMessage(),
            ));

            return ContentProjectActionResult::fail(
                $e->resultCode,
                $e->getMessage(),
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        if ($publishResult->alreadyPublished && $publishResult->wpPostId !== null && $publishResult->wpPostId > 0) {
            $this->queue->markPublished($task->fresh() ?? $task);
            $this->health->rememberSuccess(1);
            $this->rememberPublishedContentHash($article);
            $this->domainEvents->dispatchAfterCommit(new ArticlePublished(
                projectId: $projectId,
                itemId: $itemId,
                articleId: (int) $article->id,
                wpPostId: $publishResult->wpPostId,
            ));

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
                'Already published — reconciled.',
                $projectId,
                [$itemId],
            );
        }

        if (! $publishResult->success) {
            $this->queue->markFailed($task, $publishResult->message);
            $this->health->rememberFailure($publishResult->message);
            $this->domainEvents->dispatchAfterCommit(new ArticlePublishFailed(
                projectId: $projectId,
                itemId: $itemId,
                articleId: (int) $article->id,
                error: $publishResult->message,
            ));

            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::FAILED,
                $publishResult->message,
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        if ($publishResult->deliveryRequested) {
            $this->emitter->emit(BusinessEventName::ArticlePublishRequested, $article, [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'wp_post_id' => (int) ($article->wp_post_id ?? 0) ?: null,
                'project_id' => $projectId ?: null,
                'task_id' => $itemId,
                'scheduled_publish_at' => $task->scheduled_publish_at?->toIso8601String(),
                'status' => 'publish_requested',
                'source' => 'content_project_publishing_queue',
                'attempt_ref' => $attemptRef,
                'external_reference' => $externalRef,
            ]);

            $this->domainEvents->dispatchAfterCommit(new ArticlePublishRequested(
                projectId: $projectId,
                itemId: $itemId,
                articleId: (int) $article->id,
                attemptRef: $attemptRef,
            ));

            // Queue accepted delivery — do NOT clear has_unpublished_changes here.
            // WordPressArticleSyncService remembers published hash after real WP success.
            $this->queue->markPublished($task->fresh() ?? $task);
            $this->health->rememberSuccess(1);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
                'Publish delivery requested.',
                $projectId,
                [$itemId],
                metadata: ['attempt_ref' => $attemptRef, 'delivery_requested' => true],
            );
        }

        $this->queue->markPublished($task->fresh() ?? $task);
        $this->health->rememberSuccess(1);
        $this->rememberPublishedContentHash($article);

        return ContentProjectActionResult::ok(
            ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
            'Publish processed.',
            $projectId,
            [$itemId],
            metadata: ['attempt_ref' => $attemptRef],
        );
    }

    private function rememberPublishedContentHash(SeoArticle $article): void
    {
        $fresh = $article->fresh() ?? $article;
        $this->syncFlags->rememberPublishedContentHash(
            $fresh,
            hash('sha256', trim((string) ($fresh->body ?? ''))),
        );
    }

    private function resolveIdempotencyKey(SeoProjectTask $task, ActorContext $actor): string
    {
        if ($actor->idempotencyKey !== null && trim($actor->idempotencyKey) !== '') {
            return trim($actor->idempotencyKey);
        }

        if ($task->scheduled_publish_at !== null) {
            return ContentProjectIdempotencyKeyFactory::scheduler(
                (int) $task->getKey(),
                $task->scheduled_publish_at->toIso8601String(),
            );
        }

        return '';
    }
}
