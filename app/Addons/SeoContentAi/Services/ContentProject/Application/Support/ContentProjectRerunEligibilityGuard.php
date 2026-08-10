<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Support;

use App\Addons\SeoContentAi\Enums\ContentProjectItemAction;
use App\Addons\SeoContentAi\Enums\ContentProjectLifecyclePhase;
use App\Addons\SeoContentAi\Enums\ContentProjectRerunFromStep;
use App\Addons\SeoContentAi\Enums\WorkflowExecutionRole;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectRunItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ArticleGenerationInputResolver;
use App\Addons\SeoContentAi\Services\ArticleOutlineResolver;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectExecutionStalenessPolicy;
use App\Addons\SeoContentAi\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectExecutionStatus;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectItemActionGuard;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectItemIdentity;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectLifecycle;

/**
 * Pre-mutation eligibility for full / step rerun. Fail → no run, no jobs, no status change.
 */
final class ContentProjectRerunEligibilityGuard
{
    public function __construct(
        private readonly ContentProjectLifecycle $lifecycle,
        private readonly ArticleOutlineResolver $outlineResolver,
        private readonly WorkflowExecutionRoleResolver $roleResolver,
        private readonly ArticleGenerationInputResolver $generationInput,
        private readonly ContentProjectExecutionStalenessPolicy $staleness,
        private readonly ContentProjectItemActionGuard $actionGuard = new ContentProjectItemActionGuard,
    ) {}

    /**
     * @param  list<int>  $itemIds
     * @return array{ok: bool, message: string, eligible_ids: list<int>, rejected: list<array{task_id: int, reason: string}>}
     */
    public function validateFull(SeoProject $project, array $itemIds): array
    {
        return $this->validateItems($project, $itemIds, null, false);
    }

    /**
     * @param  list<int>  $itemIds
     * @return array{ok: bool, message: string, eligible_ids: list<int>, rejected: list<array{task_id: int, reason: string}>}
     */
    public function validateStep(
        SeoProject $project,
        array $itemIds,
        ContentProjectRerunFromStep $fromStep,
        bool $includeDownstream,
    ): array {
        return $this->validateItems($project, $itemIds, $fromStep, $includeDownstream);
    }

    /**
     * @param  list<int>  $itemIds
     * @return array{ok: bool, message: string, eligible_ids: list<int>, rejected: list<array{task_id: int, reason: string}>}
     */
    private function validateItems(
        SeoProject $project,
        array $itemIds,
        ?ContentProjectRerunFromStep $fromStep,
        bool $includeDownstream,
    ): array {
        $eligible = [];
        $rejected = [];

        foreach ($itemIds as $itemId) {
            $task = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->whereKey((int) $itemId)
                ->first();

            if (! $task instanceof SeoProjectTask) {
                $rejected[] = ['task_id' => (int) $itemId, 'reason' => 'Item not found in project.'];

                continue;
            }

            $reason = $this->rejectReason($project, $task, $fromStep, $includeDownstream);
            if ($reason !== null) {
                $rejected[] = ['task_id' => (int) $task->id, 'reason' => $reason];

                continue;
            }

            $eligible[] = (int) $task->id;
        }

        if ($eligible === []) {
            $message = $rejected !== []
                ? (string) ($rejected[0]['reason'] ?? 'No eligible items for rerun.')
                : 'Rerun requires explicit eligible item selection.';

            return [
                'ok' => false,
                'message' => $message,
                'eligible_ids' => [],
                'rejected' => $rejected,
            ];
        }

        return [
            'ok' => true,
            'message' => 'ok',
            'eligible_ids' => $eligible,
            'rejected' => $rejected,
        ];
    }

    private function rejectReason(
        SeoProject $project,
        SeoProjectTask $task,
        ?ContentProjectRerunFromStep $fromStep,
        bool $includeDownstream,
    ): ?string {
        if ($task->archived_at !== null || (string) $task->status === SeoProjectTask::STATUS_ARCHIVED) {
            return 'Archived item cannot be rerun.';
        }

        if ($task->isGenerationBlocked()) {
            return 'Item đã được đánh dấu bỏ qua tạo bài.';
        }

        if ((string) $task->status === SeoProjectTask::STATUS_CANCELLED) {
            return 'Cancelled item cannot be rerun.';
        }

        $phase = $this->lifecycle->resolvePhase($task);
        if ($phase === ContentProjectLifecyclePhase::Archived) {
            return 'Archived lifecycle — rerun blocked.';
        }

        if ($this->hasConflictingActiveExecution((int) $project->id, (int) $task->id)) {
            return 'Active conflicting execution — rerun blocked until current run finishes.';
        }

        try {
            $this->actionGuard->assertCan(ContentProjectItemAction::Rerun, $task);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        $type = SeoProjectTask::normalizeType($task->type);
        if (in_array($type, SeoProjectTask::typesRequiringExistingArticle(), true)) {
            $articleId = (int) ($task->article_id ?? 0);
            $article = $articleId > 0 ? SeoArticle::query()->find($articleId) : null;
            if ($articleId <= 0 || ! $article instanceof SeoArticle) {
                return 'Rewrite/improve requires an Existing Article before rerun.';
            }
        }

        if ($fromStep === null) {
            return null;
        }

        $articleId = (int) ($task->article_id ?? 0);
        $article = $articleId > 0 ? SeoArticle::query()->find($articleId) : null;

        if ($fromStep === ContentProjectRerunFromStep::Article) {
            if ($articleId <= 0 || ! $article instanceof SeoArticle) {
                return 'Article-only rerun requires an existing article.';
            }

            $outline = trim((string) $this->outlineResolver->resolveMarkdown($article));
            if ($outline === '') {
                try {
                    $resolved = $this->generationInput->resolveForArticle($article);
                    $outline = trim((string) ($resolved->rawArtifact ?? ''));
                } catch (\Throwable) {
                    $outline = '';
                }
            }
            if ($outline === '') {
                return 'Article-only rerun requires a usable outline.';
            }

            return null;
        }

        // Outline — keyword-only or title-only both eligible (fresh payload on retry).
        if (! ContentProjectItemIdentity::isValid(
            $task->keyword !== null ? (string) $task->keyword : null,
            $task->title !== null ? (string) $task->title : null,
        )) {
            return ContentProjectItemIdentity::failureMessage();
        }

        if ($includeDownstream) {
            // Editor from-outline: article may exist; outline source is enough.
            return null;
        }

        // Filament outline-only: article optional; still need workflow outline role.
        try {
            $seoTaskId = app(\App\Addons\SeoContentAi\Services\SeoCreateArticleSettingsService::class)
                ->getPublishArticleTaskId();
            if ($seoTaskId === null) {
                return 'Publish workflow not configured.';
            }
            $seoTask = \App\Addons\SeoContentAi\Models\SeoTask::query()->find($seoTaskId);
            if (! $seoTask instanceof \App\Addons\SeoContentAi\Models\SeoTask || ! $seoTask->is_active) {
                return 'Publish workflow unavailable.';
            }
            $this->roleResolver->requireNodeId($seoTask, WorkflowExecutionRole::ArticleOutlineGenerate);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function hasConflictingActiveExecution(int $projectId, int $taskId): bool
    {
        if ($taskId <= 0 || $projectId <= 0) {
            return false;
        }

        $activeRunIds = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->whereIn('status', [
                SeoProjectRun::STATUS_RUNNING,
                SeoProjectRun::STATUS_STOPPING,
            ])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($activeRunIds === []) {
            return false;
        }

        $items = SeoProjectRunItem::query()
            ->whereIn('run_id', $activeRunIds)
            ->where('task_id', $taskId)
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
            ->whereNull('finished_at')
            ->get();

        if ($items->isEmpty()) {
            return false;
        }

        $staleness = $this->staleness;
        foreach ($items as $item) {
            if ($item instanceof SeoProjectRunItem && $staleness->isFreshActiveRunItem($item)) {
                return true;
            }
        }

        return false;
    }

    public function isPublishedLifecycle(SeoProjectTask $task): bool
    {
        return $this->lifecycle->resolvePhase($task) === ContentProjectLifecyclePhase::Published;
    }
}
