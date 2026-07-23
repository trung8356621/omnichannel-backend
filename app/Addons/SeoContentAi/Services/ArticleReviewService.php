<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Enums\ArticleReviewActionType;
use App\Addons\SeoContentAi\Enums\ArticleReviewStatus;
use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoArticleReview;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\Exceptions\ArticleReviewException;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single source of truth cho workflow review bài viết:
 * submit_review (CM) → approve (planner+) → archive (manager).
 *
 * Thay thế 3 flow rời rạc trước đây (SeoProjectApprovalService::approveLinkedProject
 * ở mức project, ArticleResource::markArticleReviewed, SeoProjectArchiveService) bằng
 * một action log gắn trực tiếp vào article (`articles.review_status` + `seo_article_reviews`).
 */
final class ArticleReviewService
{
    /**
     * @var array<string, array{from: ArticleReviewStatus, to: ArticleReviewStatus}>
     */
    private const TRANSITIONS = [
        'submit_review' => ['from' => ArticleReviewStatus::Draft, 'to' => ArticleReviewStatus::PendingReview],
        'approve' => ['from' => ArticleReviewStatus::PendingReview, 'to' => ArticleReviewStatus::Approved],
        'archive' => ['from' => ArticleReviewStatus::Approved, 'to' => ArticleReviewStatus::Archived],
        'reopen' => ['from' => ArticleReviewStatus::Archived, 'to' => ArticleReviewStatus::Approved],
        'unapprove' => ['from' => ArticleReviewStatus::Approved, 'to' => ArticleReviewStatus::PendingReview],
    ];

    /**
     * Metadata phụ của lần `performAction()` gần nhất (vd. task Content Project bị detach/khôi
     * phục khi archive/reopen). Reset mỗi lần gọi `performAction()`, đọc lại qua
     * {@see self::lastSideEffectMeta()} hoặc {@see self::toApiPayload()}.
     *
     * @var array<string, mixed>
     */
    private array $lastSideEffectMeta = [];

    public function __construct(
        private readonly SeoProjectTaskLifecycleService $taskLifecycle,
    ) {}

    public function performAction(
        SeoArticle $article,
        User $user,
        ArticleReviewActionType $action,
        ?string $note = null,
    ): SeoArticleReview {
        $this->authorize($action, $user);

        $normalizedNote = $this->normalizeNote($note);
        $this->lastSideEffectMeta = [];

        try {
            return DB::connection($article->getConnectionName())->transaction(
                function () use ($article, $user, $action, $normalizedNote): SeoArticleReview {
                    /** @var SeoArticle|null $locked */
                    $locked = SeoArticle::query()
                        ->whereKey($article->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $locked instanceof SeoArticle) {
                        throw ArticleReviewException::invalidTransition(
                            __('seo-content-ai::filament.article_review.errors.invalid_transition'),
                        );
                    }

                    $currentStatus = $this->resolveStatus($locked);
                    $transition = $this->validateTransition($action, $currentStatus);

                    if (
                        $action === ArticleReviewActionType::SubmitReview
                        && ! ArticleResource::articleIsInContentProject($locked)
                    ) {
                        throw ArticleReviewException::invalidTransition(
                            __('seo-content-ai::filament.article_review.errors.invalid_transition'),
                        );
                    }

                    $this->applySideEffects($locked, $user, $action);

                    $locked->forceFill(['review_status' => $transition['to']->value])->save();

                    $review = SeoArticleReview::query()->create([
                        'article_id' => (int) $locked->getKey(),
                        'action_type' => $action->value,
                        'from_status' => $transition['from']?->value,
                        'to_status' => $transition['to']->value,
                        'reviewer_id' => (int) $user->id,
                        'reviewer_role' => SeoAccessControl::effectiveRole(),
                        'note' => $normalizedNote,
                    ]);

                    $article->setRawAttributes($locked->getAttributes());
                    $article->syncOriginal();

                    return $review;
                },
            );
        } catch (ArticleReviewException $exception) {
            RuntimeLogger::warning('seo.article_review.action_rejected', [
                'article_id' => (int) $article->getKey(),
                'action' => $action->value,
                'code' => $exception->errorCode(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'article_id' => (int) $article->getKey(),
                'action' => $action->value,
            ]);

            throw $exception;
        }
    }

    /**
     * @return list<array{type: string, label: string, quick_label: string, note_label: string, note_modal_title: string}>
     */
    public function availableActions(SeoArticle $article, User $user): array
    {
        $status = $this->resolveStatus($article);
        $actions = [];

        if (
            $status === ArticleReviewStatus::Draft
            && $this->actorMay($user, ArticleReviewActionType::SubmitReview)
            && ArticleResource::articleIsInContentProject($article)
        ) {
            $actions[] = $this->describeAction(ArticleReviewActionType::SubmitReview);
        }

        if ($status === ArticleReviewStatus::PendingReview && $this->actorMay($user, ArticleReviewActionType::Approve)) {
            $actions[] = $this->describeAction(ArticleReviewActionType::Approve);
        }

        if ($status === ArticleReviewStatus::Approved) {
            if ($this->actorMay($user, ArticleReviewActionType::Archive)) {
                // Manager (canFinalize): "Complete" — approved → archived.
                $actions[] = $this->describeAction(ArticleReviewActionType::Archive);
            } elseif ($this->actorMay($user, ArticleReviewActionType::Unapprove)) {
                // Planner (canApprove, không canFinalize): bỏ duyệt — approved → pending_review.
                $actions[] = $this->describeAction(ArticleReviewActionType::Unapprove);
            }
        }

        if ($status === ArticleReviewStatus::Archived && $this->actorMay($user, ArticleReviewActionType::Reopen)) {
            $actions[] = $this->describeAction(ArticleReviewActionType::Reopen);
        }

        return $actions;
    }

    public function resolveStatus(SeoArticle $article): ArticleReviewStatus
    {
        $stored = ArticleReviewStatus::tryFromString($article->review_status ?? null);
        if ($stored instanceof ArticleReviewStatus) {
            return $stored;
        }

        if ($article->content_archived_at !== null) {
            return ArticleReviewStatus::Archived;
        }

        if ((bool) $article->is_reviewed) {
            return ArticleReviewStatus::Approved;
        }

        return ArticleReviewStatus::Draft;
    }

    /**
     * @return Collection<int, SeoArticleReview>
     */
    public function history(SeoArticle $article): Collection
    {
        return SeoArticleReview::query()
            ->where('article_id', (int) $article->getKey())
            ->with('reviewer:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{success: bool, message: string, data: array{article_id: int, review_status: string, available_actions: array<int, array<string, string>>, latest_review: array<string, mixed>|null, content_project?: array<string, mixed>}}
     */
    public function toApiPayload(SeoArticle $article, User $user, ?SeoArticleReview $latest = null): array
    {
        $status = $this->resolveStatus($article);
        $latest ??= SeoArticleReview::query()
            ->where('article_id', (int) $article->getKey())
            ->with('reviewer:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $message = $latest instanceof SeoArticleReview
            ? (string) __('seo-content-ai::filament.article_review.success.'.$latest->action_type)
            : '';

        $data = [
            'article_id' => (int) $article->getKey(),
            'review_status' => $status->value,
            'available_actions' => $this->availableActions($article, $user),
            'latest_review' => $latest instanceof SeoArticleReview ? $this->reviewToArray($latest) : null,
        ];

        if (isset($this->lastSideEffectMeta['content_project'])) {
            $data['content_project'] = $this->lastSideEffectMeta['content_project'];
        }

        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    private function authorize(ArticleReviewActionType $action, User $user): void
    {
        if (! $this->actorMay($user, $action)) {
            throw ArticleReviewException::forbidden(
                __('seo-content-ai::filament.article_review.errors.forbidden'),
            );
        }
    }

    /**
     * Session auth + role simulation khi actor = user hiện tại; automation dùng seo_role của actor.
     */
    private function actorMay(User $user, ArticleReviewActionType $action): bool
    {
        if ((int) auth()->id() === (int) $user->id) {
            return match ($action) {
                ArticleReviewActionType::SubmitReview => SeoAccessControl::canSubmitArticleReview(),
                ArticleReviewActionType::Approve => SeoAccessControl::canApproveArticleReview(),
                ArticleReviewActionType::Archive => SeoAccessControl::canFinalizeArticleReview(),
                ArticleReviewActionType::Reopen => SeoAccessControl::canFinalizeArticleReview()
                    || SeoAccessControl::canApproveArticleReview(),
                ArticleReviewActionType::Unapprove => SeoAccessControl::canApproveArticleReview(),
                default => false,
            };
        }

        $role = in_array((string) ($user->role ?? ''), [User::ROLE_ADMIN, User::ROLE_OWNER], true)
            ? SeoAccessControl::ROLE_MANAGER
            : SeoAccessControl::normalizeRole((string) ($user->seo_role ?? SeoAccessControl::ROLE_CONTENT_MANAGER));

        return match ($action) {
            ArticleReviewActionType::SubmitReview => $role === SeoAccessControl::ROLE_CONTENT_MANAGER,
            ArticleReviewActionType::Approve,
            ArticleReviewActionType::Reopen,
            ArticleReviewActionType::Unapprove => in_array($role, [
                SeoAccessControl::ROLE_PLANNER,
                SeoAccessControl::ROLE_MANAGER,
            ], true),
            ArticleReviewActionType::Archive => $role === SeoAccessControl::ROLE_MANAGER,
            default => false,
        };
    }

    /**
     * @return array{from: ArticleReviewStatus, to: ArticleReviewStatus}
     */
    private function validateTransition(ArticleReviewActionType $action, ArticleReviewStatus $currentStatus): array
    {
        $transition = self::TRANSITIONS[$action->value] ?? null;
        if ($transition === null) {
            throw ArticleReviewException::invalidTransition(
                __('seo-content-ai::filament.article_review.errors.invalid_transition'),
            );
        }

        if ($currentStatus !== $transition['from']) {
            throw ArticleReviewException::conflict(
                __('seo-content-ai::filament.article_review.errors.stale_status'),
            );
        }

        return $transition;
    }

    private function applySideEffects(SeoArticle $article, User $user, ArticleReviewActionType $action): void
    {
        match ($action) {
            ArticleReviewActionType::Approve => ArticleResource::markArticleReviewed($article),
            ArticleReviewActionType::Archive => $this->archiveAndDetachProjectTasks($article, $user),
            // Mở lại bài đã hoàn tất: về approved, giữ nguyên is_reviewed/reviewed_at (đã duyệt trước đó).
            ArticleReviewActionType::Reopen => $this->reopenAndRestoreProjectTasks($article, $user),
            // Bỏ duyệt: về pending_review, xoá cờ is_reviewed/reviewed_at như flow cũ markArticleUnreviewed.
            ArticleReviewActionType::Unapprove => ArticleResource::markArticleUnreviewed($article),
            default => null,
        };
    }

    /**
     * Archive bài viết: đóng cờ content_archived_at/by (như cũ) + detach khỏi Content Project
     * bằng cách archive mọi task active còn trỏ tới article này (cùng transaction — savepoint,
     * xem {@see self::performAction()}). Task đã archive/soft-delete không bị đụng tới.
     */
    private function archiveAndDetachProjectTasks(SeoArticle $article, User $user): void
    {
        $article->forceFill([
            'content_archived_at' => now(),
            'content_archived_by' => (int) $user->id,
        ])->save();

        $tasks = SeoProjectTask::query()
            ->where('article_id', (int) $article->getKey())
            ->active()
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        $detachedTaskIds = [];
        $projectId = null;

        foreach ($tasks as $task) {
            // Fail → ném exception → rollback toàn bộ performAction (không để article archived
            // trong khi task vẫn active). Lifecycle đã no-op khi task đã archived.
            $this->taskLifecycle->archive($task, (int) $user->id, ['from_article_review' => true]);
            $detachedTaskIds[] = (int) $task->id;
            $projectId ??= $task->project_id !== null ? (int) $task->project_id : null;
        }

        $this->lastSideEffectMeta['content_project'] = [
            'assignment' => $detachedTaskIds !== [] ? 'archived_detached' : 'unassigned',
            'project_id' => $projectId,
            'detached_task_ids' => $detachedTaskIds,
        ];
    }

    /**
     * Reopen bài viết đã hoàn tất: xoá cờ content_archived_at/by (như cũ) + khôi phục lại các
     * task Content Project đã bị detach lúc archive (best-effort — nếu project đã bị xoá thì
     * bài vẫn reopen về approved nhưng không gắn lại project nào).
     */
    private function reopenAndRestoreProjectTasks(SeoArticle $article, User $user): void
    {
        $article->forceFill([
            'content_archived_at' => null,
            'content_archived_by' => null,
        ])->save();

        $tasks = SeoProjectTask::query()
            ->where('article_id', (int) $article->getKey())
            ->archived()
            ->whereHas('project')
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        $restoredTaskIds = [];
        $projectId = null;

        foreach ($tasks as $task) {
            // Fail → rollback toàn bộ reopen. Lifecycle no-op khi task chưa archived.
            $this->taskLifecycle->restore($task, (int) $user->id, ['from_article_review' => true]);
            $restoredTaskIds[] = (int) $task->id;
            $projectId ??= $task->project_id !== null ? (int) $task->project_id : null;
        }

        $this->lastSideEffectMeta['content_project'] = [
            'assignment' => $restoredTaskIds !== [] ? 'active' : 'unassigned',
            'project_id' => $projectId,
            'restored_task_ids' => $restoredTaskIds,
        ];
    }

    /**
     * Metadata phụ (Content Project detach/restore) của lần `performAction()` gần nhất trên
     * cùng instance service. Rỗng nếu action không phải archive/reopen hoặc chưa gọi.
     *
     * @return array<string, mixed>
     */
    public function lastSideEffectMeta(): array
    {
        return $this->lastSideEffectMeta;
    }

    private function normalizeNote(?string $note): ?string
    {
        $trimmed = trim((string) $note);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) < 3) {
            throw ArticleReviewException::invalidTransition(
                __('seo-content-ai::filament.article_review.errors.note_too_short'),
            );
        }

        return mb_substr($trimmed, 0, 5000);
    }

    /**
     * @return array<string, string>
     */
    private function describeAction(ArticleReviewActionType $action): array
    {
        $key = $action->value;

        return [
            'type' => $key,
            'label' => (string) __('seo-content-ai::filament.article_review.actions.'.$key.'.label'),
            'quick_label' => (string) __('seo-content-ai::filament.article_review.actions.'.$key.'.quick_label'),
            'note_label' => (string) __('seo-content-ai::filament.article_review.actions.'.$key.'.note_label'),
            'note_modal_title' => (string) __('seo-content-ai::filament.article_review.actions.'.$key.'.note_modal_title'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewToArray(SeoArticleReview $review): array
    {
        /** @var User|null $reviewer */
        $reviewer = $review->relationLoaded('reviewer') ? $review->reviewer : null;

        return [
            'id' => (int) $review->id,
            'action_type' => (string) $review->action_type,
            'from_status' => $review->from_status,
            'to_status' => (string) $review->to_status,
            'reviewer_id' => (int) $review->reviewer_id,
            'reviewer_role' => $review->reviewer_role,
            'reviewer_name' => $reviewer?->name,
            'note' => $review->note,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }
}
