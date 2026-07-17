<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ArticleEditorReadinessService;
use App\Addons\SeoContentAi\Services\SeoProjectArchiveService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoProjectRunErrorFormatter;
use App\Addons\SeoContentAi\Support\SeoProjectRunItemsDisplayPresenter;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ViewSeoProjectRun extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.view-project-run';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static bool $shouldRegisterNavigation = false;

    /** Route parameter `{run}` — scalar only; model is loaded in mount(). */
    public int|string $run;

    public ?SeoProjectRun $projectRun = null;

    public function mount(int|string $run): void
    {
        static::authorizeResourceAccess();

        $this->run = (int) $run;
        $this->projectRun = SeoProjectRun::query()
            ->with(['project.site', 'user', 'project.tasks'])
            ->findOrFail($this->run);

        abort_unless(
            SeoAccessControl::canAccessContentProjectRun($this->projectRun->project),
            403,
        );

        $runner = app(SeoProjectWorkflowRunService::class);
        $runner->ensureFailedTasksQueued($this->projectRun);
        $this->projectRun = $runner->reconcileMissingCompletedItems($this->projectRun);
        $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
    }

    public function getTitle(): string|Htmlable
    {
        $projectName = (string) ($this->projectRun?->project?->name ?? '');

        return __('seo-content-ai::filament.projects.run_results_title', [
            'project' => $projectName,
            'id' => (int) ($this->projectRun?->id ?? 0),
        ]);
    }

    public function getHeading(): string|Htmlable
    {
        return new HtmlString(
            view('seo-content-ai::filament.resources.seo-project-resource.pages.partials.run-queue-heading', [
                'title' => $this->getTitle(),
            ])->render(),
        );
    }

    /**
     * @return list<int>
     */
    public function getRerunAllTaskIds(): array
    {
        if ($this->projectRun === null) {
            return [];
        }

        if (! SeoAccessControl::canRetryProjectRunItem($this->projectRun->project)) {
            return [];
        }

        $taskIds = [];

        foreach ($this->getAllItems() as $item) {
            if ($this->itemIsImproveType($item)) {
                continue;
            }

            if ((bool) ($item['article_is_reviewed'] ?? false)) {
                continue;
            }

            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }

            $status = (string) ($item['status'] ?? '');
            if (! in_array($status, ['success', 'failed', 'pending'], true)) {
                continue;
            }

            $taskIds[] = $taskId;
        }

        return array_values(array_unique($taskIds));
    }

    public function canRerunAllItems(): bool
    {
        return $this->getRerunAllTaskIds() !== [];
    }

    /**
     * @return list<int>
     */
    public function getQueueTaskIds(): array
    {
        if ($this->projectRun === null) {
            return [];
        }

        // Chỉ đếm hạng mục đã kết thúc (không tính pending đã seed) để remainingSlots đúng.
        $processedInRun = collect($this->getResultItems())
            ->filter(static fn (array $item): bool => ! in_array((string) ($item['status'] ?? ''), ['pending'], true))
            ->count();

        $plannedTotal = (int) $this->projectRun->total;
        $remainingSlots = $plannedTotal > 0
            ? max(0, $plannedTotal - $processedInRun)
            : PHP_INT_MAX;

        if ($remainingSlots === 0) {
            return [];
        }

        $taskIds = [];

        foreach ($this->getAllItems() as $item) {
            if ((string) ($item['status'] ?? '') !== 'pending') {
                continue;
            }

            if (SeoProjectTask::isManualRunType((string) ($item['type'] ?? ''))) {
                continue;
            }

            if ((bool) ($item['article_is_reviewed'] ?? false)) {
                continue;
            }

            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }

            $taskIds[] = $taskId;

            if (count($taskIds) >= $remainingSlots) {
                break;
            }
        }

        return $taskIds;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueueBootstrapData(): array
    {
        return [
            'livewireId' => $this->getId(),
            'runStatus' => (string) ($this->projectRun?->status ?? ''),
            'taskIds' => $this->getQueueTaskIds(),
            'rerunAllTaskIds' => $this->getRerunAllTaskIds(),
            'canRerunAll' => $this->canRerunAllItems(),
            'autorun' => request()->boolean('autorun'),
            'labels' => [
                'running' => __('seo-content-ai::filament.projects.run_queue_running'),
                'ok' => 'OK',
                'failed' => __('seo-content-ai::filament.projects.run_item_failed'),
                'pending' => __('seo-content-ai::filament.projects.run_item_pending'),
                'archiveConfirm' => __('seo-content-ai::filament.projects.archive_item_confirm'),
                'rerunAll' => __('seo-content-ai::filament.projects.run_rerun_all'),
                'rerunAllConfirm' => __('seo-content-ai::filament.projects.run_rerun_all_confirm'),
                'rerunAllRunning' => __('seo-content-ai::filament.projects.run_rerun_all_running'),
                'stop' => __('seo-content-ai::filament.projects.run_stop'),
                'stopping' => __('seo-content-ai::filament.projects.run_stopping'),
                'retryItemConfirm' => __('seo-content-ai::filament.projects.run_retry_item_confirm'),
                'rerunBadgeTooltip' => __('seo-content-ai::filament.projects.run_item_rerun_badge_tooltip', [
                    'count' => ':count',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, int|string>
     */
    public function getRunStatsPayload(): array
    {
        $allItems = $this->getAllItems();
        $succeeded = collect($allItems)->where('status', 'success')->count();
        $failed = collect($allItems)->where('status', 'failed')->count();
        $pending = collect($allItems)->where('status', 'pending')->count();
        $total = max((int) ($this->projectRun?->total ?? 0), count($allItems));

        return [
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'pending' => $pending,
            'status' => (string) ($this->projectRun?->status ?? ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getResultItems(): array
    {
        $items = is_array($this->projectRun?->items) ? $this->projectRun->items : [];

        return array_values($items);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPendingItems(): array
    {
        $project = $this->projectRun?->project;
        if ($project === null) {
            return [];
        }

        return $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->orderBy('target_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SeoProjectTask $task): array => [
                'task_id' => (int) $task->id,
                'type' => (string) $task->type,
                'source_content' => (string) $task->source_content,
                'post_type' => SeoProjectTask::isNewArticleType($task->type)
                    ? SeoProjectTask::normalizePostType($task->post_type)
                    : null,
                'loai_san_pham' => SeoProjectTask::isNewArticleType($task->type)
                    && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                        ? (string) ($task->loai_san_pham ?? '')
                        : null,
                'gallery_description' => SeoProjectTask::isNewArticleType($task->type)
                    && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                        ? (string) ($task->description ?? '')
                        : null,
                'target_date' => $task->target_date?->format('Y-m-d'),
                'rewrite_mode' => $task->type === SeoProjectTask::TYPE_REWRITE
                    ? SeoProjectTask::normalizeRewriteMode($task->rewrite_mode)
                    : null,
                'rewrite_notes' => $task->type === SeoProjectTask::TYPE_REWRITE
                    ? $task->rewrite_notes
                    : null,
                'status' => 'pending',
                'article_id' => null,
                'article_edit_url' => null,
                'message' => '',
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllItems(): array
    {
        $results = $this->getResultItems();
        $resultTaskIds = collect($results)
            ->pluck('task_id')
            ->filter(fn (mixed $taskId): bool => (int) $taskId > 0)
            ->map(fn (mixed $taskId): int => (int) $taskId)
            ->all();

        $pending = collect($this->getPendingItems())
            ->reject(fn (array $item): bool => in_array((int) ($item['task_id'] ?? 0), $resultTaskIds, true))
            ->values()
            ->all();

        $items = array_merge($results, $pending);
        $items = app(SeoProjectRunItemsDisplayPresenter::class)->consolidate($items);

        $enriched = array_map(
            fn (array $item): array => $this->enrichItemRewriteMeta($this->enrichItemArticleLink($item)),
            $items,
        );

        usort($enriched, static function (array $left, array $right): int {
            $leftRun = strtotime((string) ($left['last_run_at'] ?? '')) ?: 0;
            $rightRun = strtotime((string) ($right['last_run_at'] ?? '')) ?: 0;
            if ($leftRun !== $rightRun) {
                return $rightRun <=> $leftRun;
            }

            $leftDate = (string) ($left['target_date'] ?? '');
            $rightDate = (string) ($right['target_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return $rightDate <=> $leftDate;
            }

            return ((int) ($right['task_id'] ?? 0)) <=> ((int) ($left['task_id'] ?? 0));
        });

        return $enriched;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemKeywordLabel(array $item): string
    {
        $label = trim((string) ($item['source_content'] ?? ''));

        return $label !== '' ? $label : '—';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemKeywordEditUrl(array $item): ?string
    {
        $articleId = (int) ($item['article_id'] ?? 0);
        if ($articleId > 0) {
            if (! (bool) ($item['article_editor_ready'] ?? app(ArticleEditorReadinessService::class)->isReady($articleId))) {
                return null;
            }

            return ArticleResource::getUrl('edit', ['record' => $articleId]);
        }

        $url = trim((string) ($item['article_edit_url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * @return array{ready: bool, edit_url: ?string, message: string}
     */
    public function checkArticleEditorReady(int $articleId): array
    {
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return [
                'ready' => false,
                'edit_url' => null,
                'message' => __('seo-content-ai::filament.projects.article_editor_preparing_body'),
            ];
        }

        $readiness = app(ArticleEditorReadinessService::class)->evaluate($article);

        return [
            'ready' => $readiness->isReady,
            'edit_url' => $readiness->isReady
                ? ArticleResource::getUrl('edit', ['record' => $articleId])
                : null,
            'message' => app(ArticleEditorReadinessService::class)->userMessage($readiness),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemRunDate(array $item): string
    {
        $targetDate = trim((string) ($item['target_date'] ?? ''));
        if ($targetDate !== '') {
            return $targetDate;
        }

        if ($this->projectRun?->started_at !== null) {
            return $this->projectRun->started_at->format('Y-m-d');
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemLastRunAt(array $item): string
    {
        $raw = trim((string) ($item['last_run_at'] ?? ''));
        if ($raw === '') {
            return '—';
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return $raw;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemStepsUrl(array $item): ?string
    {
        $articleId = (int) ($item['article_id'] ?? 0);
        if ($articleId <= 0 || $this->projectRun === null) {
            return null;
        }

        return SeoProjectResource::getUrl('view-run-step', [
            'run' => $this->projectRun,
            'article' => $articleId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enrichItemArticleLink(array $item): array
    {
        $articleId = (int) ($item['article_id'] ?? 0);

        if ($articleId <= 0) {
            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId > 0) {
                $fromTask = (int) ($this->projectRun?->project?->tasks
                    ?->firstWhere('id', $taskId)
                    ?->article_id ?? 0);
                if ($fromTask <= 0) {
                    $fromTask = (int) ($this->projectRun?->project?->tasks()
                        ->whereKey($taskId)
                        ->value('article_id') ?? 0);
                }
                if ($fromTask > 0) {
                    $articleId = $fromTask;
                    $item['article_id'] = $fromTask;
                }
            }
        }

        if ($articleId > 0) {
            $article = SeoArticle::query()
                ->select(['id', 'is_reviewed'])
                ->whereKey($articleId)
                ->first();

            $item['article_edit_url'] = ArticleResource::getUrl('edit', ['record' => $articleId]);
            $readiness = app(ArticleEditorReadinessService::class)->evaluate(
                SeoArticle::query()->find($articleId) ?? $article,
            );
            $item['article_editor_ready'] = $readiness->isReady;
            if (! $readiness->isReady) {
                $item['article_edit_url'] = null;
                $item['article_editor_preparing_message'] = app(ArticleEditorReadinessService::class)->userMessage($readiness);
            }
            $item['article_is_reviewed'] = (bool) ($article?->is_reviewed ?? false);

            return $item;
        }

        $source = trim((string) ($item['source_content'] ?? ''));
        if ($source === '') {
            return $item;
        }

        $resolvedId = $this->resolveArticleIdForSource($source);
        if ($resolvedId > 0) {
            $article = SeoArticle::query()
                ->select(['id', 'is_reviewed'])
                ->whereKey($resolvedId)
                ->first();

            $item['article_id'] = $resolvedId;
            $fullArticle = SeoArticle::query()->find($resolvedId);
            $readiness = $fullArticle instanceof SeoArticle
                ? app(ArticleEditorReadinessService::class)->evaluate($fullArticle)
                : new \App\Addons\SeoContentAi\Services\ArticleEditorReadinessResult(isReady: false, reasons: ['missing_article']);
            $item['article_editor_ready'] = $readiness->isReady;
            $item['article_edit_url'] = $readiness->isReady
                ? ArticleResource::getUrl('edit', ['record' => $resolvedId])
                : null;
            if (! $readiness->isReady) {
                $item['article_editor_preparing_message'] = app(ArticleEditorReadinessService::class)->userMessage($readiness);
            }
            $item['article_is_reviewed'] = (bool) ($article?->is_reviewed ?? false);
        }

        return $item;
    }

    private function resolveArticleIdForSource(string $source): int
    {
        $projectSiteId = (int) ($this->projectRun?->project?->site_id ?? 0);
        $normalized = mb_strtolower($source);
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $source);

        $baseQuery = function () use ($projectSiteId): Builder {
            $query = SeoArticle::query();

            if (SeoAccessControl::shouldScopeToAccountOwner()) {
                SeoAccessControl::applyAccessibleSiteScope($query);
            }

            if ($projectSiteId > 0) {
                $query->where('site_id', $projectSiteId);
            }

            return $query;
        };

        $byTitle = $baseQuery()
            ->where('title', $source)
            ->orderByDesc('id')
            ->value('id');

        if ($byTitle !== null) {
            return (int) $byTitle;
        }

        $byTitleLike = $baseQuery()
            ->where('title', 'like', '%'.$like.'%')
            ->orderByDesc('id')
            ->value('id');

        if ($byTitleLike !== null) {
            return (int) $byTitleLike;
        }

        return (int) ($baseQuery()
            ->whereHas('articleMetas', function (Builder $query) use ($normalized, $like): void {
                $query->where('meta_key', 'seo_focus_keyword')
                    ->where(function (Builder $inner) use ($normalized, $like): void {
                        $inner->whereRaw('LOWER(meta_value) = ?', [$normalized])
                            ->orWhere('meta_value', 'like', '%'.$like.'%');
                    });
            })
            ->orderByDesc('id')
            ->value('id') ?? 0);
    }

    public function getPendingCount(): int
    {
        return collect($this->getAllItems())
            ->where('status', 'pending')
            ->count();
    }

    public function isDebugMode(): bool
    {
        return app(SeoProjectRunErrorFormatter::class)->isDebug();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function displayItemError(array $item): string
    {
        return app(SeoProjectRunErrorFormatter::class)->displayMessage($item);
    }

    public function postTypeLabel(?string $postType): string
    {
        if ($postType === null || $postType === '') {
            return '—';
        }

        return SeoProjectResource::postTypeSelectOptions()[$postType] ?? $postType;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemTypeLabel(array $item): string
    {
        if ($this->itemIsImproveType($item)) {
            return __('seo-content-ai::filament.projects.run_type_improve');
        }

        if (($item['type'] ?? '') === SeoProjectTask::TYPE_REWRITE) {
            $mode = SeoProjectTask::normalizeRewriteMode($item['rewrite_mode'] ?? null);
            $modeLabel = SeoProjectTask::rewriteModeOptions()[$mode] ?? $mode;

            return __('seo-content-ai::filament.projects.run_type_rewrite').' ('.$modeLabel.')';
        }

        return __('seo-content-ai::filament.projects.run_type_new');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemRewriteNotes(array $item): ?string
    {
        if (($item['type'] ?? '') !== SeoProjectTask::TYPE_REWRITE) {
            return null;
        }

        $notes = trim((string) ($item['rewrite_notes'] ?? ''));

        return $notes !== '' ? $notes : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enrichItemRewriteMeta(array $item): array
    {
        if (($item['type'] ?? '') !== SeoProjectTask::TYPE_REWRITE) {
            return $item;
        }

        $taskId = (int) ($item['task_id'] ?? 0);
        if ($taskId > 0) {
            $task = $this->projectRun?->project?->tasks?->firstWhere('id', $taskId);
            if ($task instanceof SeoProjectTask) {
                $item['rewrite_mode'] = SeoProjectTask::normalizeRewriteMode($task->rewrite_mode);
                $item['rewrite_notes'] = $task->rewrite_notes;
            }
        }

        $item['rewrite_mode'] = SeoProjectTask::normalizeRewriteMode($item['rewrite_mode'] ?? null);

        $notes = trim((string) ($item['rewrite_notes'] ?? ''));
        if (
            $item['rewrite_mode'] !== SeoProjectTask::REWRITE_MODE_CONTENT
            || $notes === ''
        ) {
            $item['rewrite_notes'] = null;
        } else {
            $item['rewrite_notes'] = $notes;
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemIsImproveType(array $item): bool
    {
        return SeoProjectTask::isManualRunType((string) ($item['type'] ?? ''));
    }

    public function runItem(int $taskId): void
    {
        if ($this->projectRun === null) {
            return;
        }

        if (! SeoAccessControl::canRetryProjectRunItem($this->projectRun->project)) {
            abort(403, __('seo-content-ai::filament.projects.run_retry_failed'));
        }

        if ($this->isImproveTaskId($taskId)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_item_failed'))
                ->body(__('seo-content-ai::filament.projects.run_item_manual_hint'))
                ->warning()
                ->send();

            return;
        }

        $formatter = app(SeoProjectRunErrorFormatter::class);

        try {
            $resolvedArticleId = $this->syncResolvedArticleIdForRunTask($taskId);
            $item = app(SeoProjectWorkflowRunService::class)->retryTask(
                $this->projectRun,
                $taskId,
                forcedArticleId: $resolvedArticleId > 0 ? $resolvedArticleId : null,
            );
            $this->projectRun->refresh();

            if (($item['status'] ?? '') === 'success') {
                Notification::make()
                    ->title(__('seo-content-ai::filament.projects.run_item_success'))
                    ->body((string) ($item['message'] ?? ''))
                    ->success()
                    ->send();

                $this->reloadCurrentRunPage();

                return;
            }

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_item_failed'))
                ->body($formatter->displayMessage($item))
                ->danger()
                ->persistent()
                ->send();

            $this->reloadCurrentRunPage();
        } catch (\Throwable $exception) {
            $error = $formatter->fromThrowable($exception);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_item_failed'))
                ->body($formatter->displayMessage([
                    'status' => 'failed',
                    'message' => $error['message'],
                    'error_detail' => $error['error_detail'],
                ]))
                ->danger()
                ->persistent()
                ->send();

            $this->reloadCurrentRunPage();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function runItemQueued(int $taskId, bool $markCompleted = false): array
    {
        \Illuminate\Support\Facades\Log::info('seo.project_run.runItemQueued.called', [
            'run_id' => (int) ($this->projectRun?->id ?? 0),
            'task_id' => $taskId,
            'mark_completed' => $markCompleted,
            'user_id' => (int) auth()->id(),
        ]);

        if ($this->projectRun === null) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => 'Run không tồn tại.',
            ];
        }

        if (! SeoAccessControl::canRetryProjectRunItem($this->projectRun->project)) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => __('seo-content-ai::filament.projects.run_retry_failed'),
            ];
        }

        if ($this->isImproveTaskId($taskId)) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => __('seo-content-ai::filament.projects.run_item_manual_hint'),
            ];
        }

        $formatter = app(SeoProjectRunErrorFormatter::class);

        try {
            $resolvedArticleId = $this->syncResolvedArticleIdForRunTask($taskId);
            $item = app(SeoProjectWorkflowRunService::class)->retryTask(
                $this->projectRun,
                $taskId,
                markCompleted: $markCompleted,
                forcedArticleId: $resolvedArticleId > 0 ? $resolvedArticleId : null,
            );
            $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);

            $enriched = $this->enrichItemRewriteMeta($this->enrichItemArticleLink($item));

            \Illuminate\Support\Facades\Log::info('seo.project_run.runItemQueued.done', [
                'run_id' => (int) $this->projectRun->id,
                'task_id' => $taskId,
                'item_status' => (string) ($enriched['status'] ?? ''),
                'last_run_at' => (string) ($enriched['last_run_at'] ?? ''),
                'message' => (string) ($enriched['message'] ?? ''),
                'debug' => $enriched['debug'] ?? null,
                'step_stats' => $enriched['step_stats'] ?? null,
            ]);

            return [
                'success' => true,
                'item' => $enriched,
                'displayError' => $formatter->displayMessage($item),
                'stats' => $this->getRunStatsPayload(),
            ];
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('seo.project_run.runItemQueued.exception', [
                'run_id' => (int) ($this->projectRun?->id ?? 0),
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
                'class' => $exception::class,
            ]);

            $error = $formatter->fromThrowable($exception);

            return [
                'success' => false,
                'message' => $formatter->displayMessage([
                    'status' => 'failed',
                    'message' => $error['message'],
                    'error_detail' => $error['error_detail'],
                ]),
                'stats' => $this->getRunStatsPayload(),
            ];
        } finally {
            $this->skipRender();
        }
    }

    /**
     * Kết thúc queue thủ công (chạy lẻ / rerun) — cập nhật counter, không toast, không consolidate/redirect.
     */
    public function finalizePartialQueue(): void
    {
        if ($this->projectRun === null) {
            return;
        }

        abort_unless(SeoAccessControl::canRetryProjectRunItem($this->projectRun->project), 403);

        $this->projectRun->refresh();
        app(SeoProjectWorkflowRunService::class)->markRunCompletedQuietly($this->projectRun);
        $this->projectRun->refresh();
        $this->skipRender();
    }

    public function beginRunQueue(): void
    {
        if ($this->projectRun === null) {
            return;
        }

        abort_unless(SeoAccessControl::canRetryProjectRunItem($this->projectRun->project), 403);

        if ($this->projectRun->status !== SeoProjectRun::STATUS_RUNNING) {
            $this->projectRun->update([
                'status' => SeoProjectRun::STATUS_RUNNING,
                'finished_at' => null,
            ]);
            $this->projectRun->refresh();
        }

        $this->skipRender();
    }

    public function completeRunQueue(bool $stopped = false): void
    {
        if ($this->projectRun === null) {
            return;
        }

        if ($this->projectRun->status === SeoProjectRun::STATUS_RUNNING) {
            $completedRun = app(SeoProjectWorkflowRunService::class)->completeRunQueue($this->projectRun);
            if ((int) $completedRun->id !== (int) $this->projectRun->id) {
                $this->redirect(
                    SeoProjectResource::getUrl('view-run', ['run' => $completedRun->id]),
                    navigate: false,
                );

                return;
            }

            $this->projectRun->refresh();
        }

        if ($stopped) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_stopped'))
                ->body(__('seo-content-ai::filament.projects.run_stopped_body'))
                ->warning()
                ->send();

            return;
        }

        $run = $this->projectRun;
        $notification = Notification::make()
            ->title(__('seo-content-ai::filament.projects.run_completed'))
            ->body(__('seo-content-ai::filament.projects.run_completed_body', [
                'succeeded' => (int) $run->succeeded,
                'failed' => (int) $run->failed,
                'total' => (int) $run->total,
            ]));

        if ((int) $run->failed > 0) {
            $notification->warning()->send();
        } else {
            $notification->success()->send();
        }
    }

    private function reloadCurrentRunPage(): void
    {
        if ($this->projectRun === null) {
            return;
        }

        $this->redirect(
            SeoProjectResource::getUrl('view-run', ['run' => $this->projectRun]),
            navigate: false,
        );
    }

    public function markItemFixed(int $taskId, int $articleId): void
    {
        if ($this->projectRun === null) {
            return;
        }

        try {
            app(SeoProjectWorkflowRunService::class)->markTaskFixed(
                $this->projectRun,
                $taskId,
                $articleId,
            );
            $this->projectRun->refresh();

            Notification::make()
                ->title('Đã đánh dấu bài viết OK')
                ->body('Hạng mục được ghi nhận là đã sửa lỗi thủ công.')
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Không thể đánh dấu đã fix')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function archiveItem(int $taskId): void
    {
        if ($this->projectRun === null || $taskId <= 0) {
            $this->skipRender();

            return;
        }

        $project = $this->projectRun->project;
        if ($project === null) {
            $this->skipRender();

            return;
        }

        abort_unless(SeoAccessControl::canArchiveContentProjects(), 403);

        $articleId = $this->resolveArticleIdForRunTask($taskId);
        if ($articleId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_failed'))
                ->body(__('seo-content-ai::filament.projects.archive_no_active_articles'))
                ->danger()
                ->send();
            $this->skipRender();

            return;
        }

        try {
            $result = app(SeoProjectArchiveService::class)->archiveTasks(
                $project,
                [$taskId],
                (int) auth()->id(),
                null,
                [$taskId => $articleId],
            );

            $this->removeTaskFromCurrentRunItems($taskId);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_item_completed'))
                ->body(__('seo-content-ai::filament.projects.archive_item_completed_body', $result))
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        $this->skipRender();
    }

    private function removeTaskFromCurrentRunItems(int $taskId): void
    {
        if ($this->projectRun === null || $taskId <= 0) {
            return;
        }

        $items = is_array($this->projectRun->items) ? $this->projectRun->items : [];
        $filtered = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => ! is_array($item) || (int) ($item['task_id'] ?? 0) !== $taskId,
        ));

        if (count($filtered) === count($items)) {
            return;
        }

        $succeeded = collect($filtered)->where('status', 'success')->count();
        $failed = collect($filtered)->where('status', 'failed')->count();

        $this->projectRun->update([
            'items' => $filtered,
            'total' => count($filtered),
            'succeeded' => $succeeded,
            'failed' => $failed,
        ]);
        $this->projectRun->refresh();
    }

    private function syncResolvedArticleIdForRunTask(int $taskId): int
    {
        $articleId = $this->resolveArticleIdForRunTask($taskId);
        if ($articleId <= 0 || $this->projectRun === null) {
            return 0;
        }

        $this->projectRun->refresh()->loadMissing(['project.tasks']);
        $items = is_array($this->projectRun->items) ? $this->projectRun->items : [];
        $changed = false;

        foreach ($items as $index => $item) {
            if (! is_array($item) || (int) ($item['task_id'] ?? 0) !== $taskId) {
                continue;
            }

            if ((int) ($item['article_id'] ?? 0) !== $articleId) {
                $items[$index]['article_id'] = $articleId;
                $changed = true;
            }

            break;
        }

        $task = $this->projectRun->project?->tasks?->firstWhere('id', $taskId);
        if ($task instanceof SeoProjectTask && (int) ($task->article_id ?? 0) !== $articleId) {
            $task->article_id = $articleId;
            $task->save();
            $this->projectRun->project?->unsetRelation('tasks');
            $this->projectRun->loadMissing('project.tasks');
        }

        if ($changed) {
            $this->projectRun->update(['items' => array_values($items)]);
            $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
        }

        return $articleId;
    }

    private function resolveArticleIdForRunTask(int $taskId): int
    {
        if ($taskId <= 0) {
            return 0;
        }

        // Đọc raw run.items trước — tránh getAllItems() enrich fuzzy gắn nhầm bài.
        foreach ($this->getResultItems() as $item) {
            if ((int) ($item['task_id'] ?? 0) !== $taskId) {
                continue;
            }

            $fromItem = (int) ($item['article_id'] ?? 0);
            if ($fromItem > 0) {
                return $fromItem;
            }

            break;
        }

        $project = $this->projectRun?->project;
        if ($project === null) {
            return 0;
        }

        return (int) ($project->tasks()->whereKey($taskId)->value('article_id') ?? 0);
    }

    public function canArchiveRunItem(array $item): bool
    {
        if (! SeoAccessControl::canArchiveContentProjects()) {
            return false;
        }

        $taskId = (int) ($item['task_id'] ?? 0);
        $articleId = (int) ($item['article_id'] ?? 0);

        return $taskId > 0 && $articleId > 0;
    }

    protected function getHeaderActions(): array
    {
        $project = $this->projectRun?->project;

        return [
            Actions\Action::make('back_to_project')
                ->label(__('seo-content-ai::filament.projects.view_runs'))
                ->icon('heroicon-o-arrow-left')
                ->url(
                    $project !== null
                        ? SeoProjectResource::getRunHistoryUrl($project)
                        : SeoProjectResource::getUrl('index'),
                ),
            Actions\Action::make('back_to_list')
                ->label(__('seo-content-ai::filament.projects.back_to_projects'))
                ->color('gray')
                ->url(SeoProjectResource::getUrl('index')),
        ];
    }

    private function isImproveTaskId(int $taskId): bool
    {
        if ($taskId <= 0) {
            return false;
        }

        $project = $this->projectRun?->project;
        if ($project === null) {
            return false;
        }

        $type = $project->tasks()
            ->whereKey($taskId)
            ->value('type');

        return SeoProjectTask::isManualRunType((string) ($type ?? ''));
    }
}
