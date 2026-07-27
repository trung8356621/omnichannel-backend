<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\ContentProject;

use App\Addons\SeoContentAi\Enums\ContentProjectLifecyclePhase;
use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use RuntimeException;

/**
 * Map task/article → business lifecycle phase + transition guard.
 */
final class ContentProjectLifecycle
{
    public function resolvePhase(SeoProjectTask $task, ?SeoArticle $article = null): ContentProjectLifecyclePhase
    {
        $project = $task->relationLoaded('project') ? $task->project : null;
        if ($project instanceof SeoProject && $project->archived_at !== null) {
            return ContentProjectLifecyclePhase::Archived;
        }

        if ($task->archived_at !== null || (string) $task->status === SeoProjectTask::STATUS_ARCHIVED) {
            return ContentProjectLifecyclePhase::Archived;
        }

        $queueStatus = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? 'none'))
            ?? ContentProjectPublishQueueStatus::None;

        if ($queueStatus === ContentProjectPublishQueueStatus::Published
            || $task->publish_published_at !== null
        ) {
            return ContentProjectLifecyclePhase::Published;
        }

        $article ??= $task->relationLoaded('article') ? $task->article : null;
        if ($article instanceof SeoArticle) {
            $articleStatus = strtolower((string) ($article->status ?? ''));
            if (in_array($articleStatus, ['published', 'publish'], true)) {
                return ContentProjectLifecyclePhase::Published;
            }
        }

        if ($queueStatus === ContentProjectPublishQueueStatus::Failed
            || (string) $task->status === SeoProjectTask::STATUS_FAILED
        ) {
            return ContentProjectLifecyclePhase::Failed;
        }

        if ($queueStatus->isActiveQueue() || $task->scheduled_publish_at !== null) {
            return ContentProjectLifecyclePhase::WaitingPublish;
        }

        $taskStatus = (string) $task->status;
        if ($taskStatus === SeoProjectTask::STATUS_WRITING) {
            return ContentProjectLifecyclePhase::Generating;
        }

        if ($taskStatus === SeoProjectTask::STATUS_REVIEWING) {
            return ContentProjectLifecyclePhase::Review;
        }

        if ($taskStatus === SeoProjectTask::STATUS_COMPLETED) {
            $reviewed = $article instanceof SeoArticle && (bool) ($article->is_reviewed ?? false);

            return $reviewed
                ? ContentProjectLifecyclePhase::Approved
                : ContentProjectLifecyclePhase::Review;
        }

        return ContentProjectLifecyclePhase::Draft;
    }

    public function assertCanTransition(
        SeoProjectTask $task,
        ContentProjectLifecyclePhase $to,
        ?SeoArticle $article = null,
    ): void {
        $from = $this->resolvePhase($task, $article);
        if ($from->canTransitionTo($to)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Lifecycle không cho phép chuyển %s → %s.',
            $from->value,
            $to->value,
        ));
    }

    public function assertNotArchivedForGenerate(SeoProjectTask $task): void
    {
        $phase = $this->resolvePhase($task);
        if ($phase === ContentProjectLifecyclePhase::Archived) {
            throw new RuntimeException('Project/Item đã Archived — không được Generate lại trên Workspace cũ.');
        }
    }
}
