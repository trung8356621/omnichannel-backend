<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\ContentProject;

use App\Addons\SeoContentAi\Enums\ContentProjectItemAction;
use App\Addons\SeoContentAi\Enums\ContentProjectItemArchiveState;
use App\Addons\SeoContentAi\Enums\ContentProjectItemGenerationState;
use App\Addons\SeoContentAi\Enums\ContentProjectItemPublishState;
use App\Addons\SeoContentAi\Enums\ContentProjectItemReviewState;
use App\Addons\SeoContentAi\Enums\ContentProjectLifecyclePhase;
use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use RuntimeException;

/**
 * Shared eligibility for read-model available_actions + command assertCan (Batch D verify).
 * Schedule/PublishNow: Review (Needs Review / In Review reporting) or Approved / WaitingPublish.
 * Block Archived / Generating / Draft / Failed / busy. Reporting states are not hard gates
 * against each other — Approved is optional marker, not required before Schedule.
 * Archive blocked while generation or publish-queue busy (matches ArchiveProjectItemsHandler).
 */
final class ContentProjectItemActionGuard
{
    /**
     * @return list<ContentProjectItemAction>
     */
    public function availableActions(
        ContentProjectLifecyclePhase $lifecycle,
        ContentProjectItemArchiveState $archive,
        ContentProjectItemPublishState $publish,
        ContentProjectItemGenerationState $generation,
        ContentProjectItemReviewState $review,
        bool $hasPublished,
        bool $latestPublishAttemptFailed = false,
        ?ContentProjectPublishQueueStatus $queue = null,
    ): array {
        if ($archive === ContentProjectItemArchiveState::ContentArchived) {
            // Option B: item-level Restore is not offered — restoring content-archived
            // items happens at the project level via content_project.restore.
            return [];
        }

        $actions = [];

        $genBusy = $generation === ContentProjectItemGenerationState::Writing
            || $generation === ContentProjectItemGenerationState::Processing;
        $queueBusy = $publish === ContentProjectItemPublishState::Queued
            || $queue === ContentProjectPublishQueueStatus::Processing
            || $queue === ContentProjectPublishQueueStatus::Retrying
            || $queue === ContentProjectPublishQueueStatus::Waiting;

        if (! $genBusy && ! $queueBusy) {
            $actions[] = ContentProjectItemAction::Archive;

            // Generate-pending: draft or generation-failed only (not review/publish-failed).
            if (! $hasPublished && (
                $lifecycle === ContentProjectLifecyclePhase::Draft
                || ($lifecycle === ContentProjectLifecyclePhase::Failed
                    && $generation === ContentProjectItemGenerationState::Failed)
            )) {
                $actions[] = ContentProjectItemAction::Generate;
            }

            if (in_array($lifecycle, [
                ContentProjectLifecyclePhase::Review,
                ContentProjectLifecyclePhase::Approved,
                ContentProjectLifecyclePhase::Published,
                ContentProjectLifecyclePhase::WaitingPublish,
                ContentProjectLifecyclePhase::Failed,
            ], true) || $hasPublished) {
                $actions[] = ContentProjectItemAction::Rerun;
            }

            // Align StartReviewHandler: pending/completed → reviewing (not approve/publish).
            if (! $hasPublished && $review !== ContentProjectItemReviewState::Approved) {
                if ($lifecycle === ContentProjectLifecyclePhase::Draft
                    && in_array($generation, [
                        ContentProjectItemGenerationState::Pending,
                        ContentProjectItemGenerationState::Completed,
                        ContentProjectItemGenerationState::Idle,
                    ], true)
                ) {
                    $actions[] = ContentProjectItemAction::StartReview;
                }
                if ($lifecycle === ContentProjectLifecyclePhase::Review
                    && $generation === ContentProjectItemGenerationState::Completed
                    && $review !== ContentProjectItemReviewState::ReviewArchived
                ) {
                    $actions[] = ContentProjectItemAction::StartReview;
                }
            }
        }

        if ($lifecycle === ContentProjectLifecyclePhase::Review
            && $review !== ContentProjectItemReviewState::Approved
            && $review !== ContentProjectItemReviewState::None
            && $review !== ContentProjectItemReviewState::ReviewArchived
        ) {
            $actions[] = ContentProjectItemAction::Approve;
        }

        $scheduleEligible = $this->queueScheduleEligible($lifecycle, $genBusy, $queueBusy);

        if ($scheduleEligible) {
            if (in_array($publish, [
                ContentProjectItemPublishState::None,
                ContentProjectItemPublishState::Cancelled,
                ContentProjectItemPublishState::Skipped,
            ], true) && ! $latestPublishAttemptFailed) {
                $actions[] = ContentProjectItemAction::Schedule;
                $actions[] = ContentProjectItemAction::PublishNow;
            }
            if ($publish === ContentProjectItemPublishState::Scheduled) {
                $actions[] = ContentProjectItemAction::Unschedule;
                $actions[] = ContentProjectItemAction::PublishNow;
            }
        }

        // Waiting/Retrying: unschedule + cancel/skip (Processing: cancel/skip only).
        if ($queue === ContentProjectPublishQueueStatus::Waiting
            || $queue === ContentProjectPublishQueueStatus::Retrying
        ) {
            $actions[] = ContentProjectItemAction::Unschedule;
            $actions[] = ContentProjectItemAction::CancelPublish;
            $actions[] = ContentProjectItemAction::SkipPublish;
        }

        if ($queue === ContentProjectPublishQueueStatus::Processing
            || ($publish === ContentProjectItemPublishState::Queued && $queue === null)
        ) {
            $actions[] = ContentProjectItemAction::CancelPublish;
            $actions[] = ContentProjectItemAction::SkipPublish;
        }

        if ($publish === ContentProjectItemPublishState::PublishFailed || $latestPublishAttemptFailed) {
            $actions[] = ContentProjectItemAction::RetryPublish;
            $actions[] = ContentProjectItemAction::SkipPublish;
        }

        return array_values(array_unique($actions, SORT_REGULAR));
    }

    public function assertCan(
        ContentProjectItemAction $action,
        SeoProjectTask $task,
        ?SeoArticle $article = null,
        ?ContentProjectItemStateResolver $resolver = null,
        array $hints = [],
    ): void {
        $resolver ??= new ContentProjectItemStateResolver($this);
        $state = $resolver->resolve($task, $article, $hints);
        if (! in_array($action, $state->availableActions, true)) {
            throw new RuntimeException(sprintf(
                'Action %s not allowed in lifecycle=%s (blocking: %s).',
                $action->value,
                $state->lifecycleState->value,
                $state->blockingReason ?? 'n/a',
            ));
        }
    }

    public function allows(ContentProjectItemAction $action, ContentProjectItemState $state): bool
    {
        return in_array($action, $state->availableActions, true);
    }

    private function queueScheduleEligible(
        ContentProjectLifecyclePhase $lifecycle,
        bool $genBusy,
        bool $queueBusy,
    ): bool {
        if ($genBusy || $queueBusy) {
            return false;
        }

        // Schedule / Publish Now from Review (Needs Review or In Review reporting) or Approved.
        // Needs Review / In Review / Approved are NOT hard gates against each other.
        return in_array($lifecycle, [
            ContentProjectLifecyclePhase::Review,
            ContentProjectLifecyclePhase::Approved,
            ContentProjectLifecyclePhase::WaitingPublish,
        ], true);
    }
}