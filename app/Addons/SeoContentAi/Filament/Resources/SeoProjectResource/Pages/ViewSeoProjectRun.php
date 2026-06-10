<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoProjectRunErrorFormatter;
use App\Models\Site;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

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
        abort_if(SeoAccessControl::isContentManager(), 403);

        $this->run = (int) $run;
        $this->projectRun = SeoProjectRun::query()
            ->with(['project.site', 'user', 'project.tasks'])
            ->findOrFail($this->run);

        app(SeoProjectWorkflowRunService::class)->ensureFailedTasksQueued($this->projectRun);
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
                'post_type' => $task->type === SeoProjectTask::TYPE_NEW_KEYWORD
                    ? SeoProjectTask::normalizePostType($task->post_type)
                    : null,
                'target_date' => $task->target_date?->format('Y-m-d'),
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
        $items = array_merge($this->getResultItems(), $this->getPendingItems());

        return array_map(fn (array $item): array => $this->enrichItemArticleLink($item), $items);
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
        $url = trim((string) ($item['article_edit_url'] ?? ''));

        return $url !== '' ? $url : null;
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
        if (filled($item['article_edit_url'] ?? null)) {
            return $item;
        }

        $articleId = (int) ($item['article_id'] ?? 0);
        if ($articleId > 0) {
            $item['article_edit_url'] = ArticleResource::getUrl('edit', ['record' => $articleId]);

            return $item;
        }

        $source = trim((string) ($item['source_content'] ?? ''));
        if ($source === '') {
            return $item;
        }

        $resolvedId = $this->resolveArticleIdForSource($source);
        if ($resolvedId > 0) {
            $item['article_id'] = $resolvedId;
            $item['article_edit_url'] = ArticleResource::getUrl('edit', ['record' => $resolvedId]);
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

            if (auth()->user()?->role !== 'admin') {
                $query->whereIn(
                    'site_id',
                    Site::query()
                        ->where('user_id', SeoAccessControl::accountOwnerId() ?? (int) auth()->id())
                        ->select('id'),
                );
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

        $byKeyword = $baseQuery()
            ->whereHas('keywords', function (Builder $query) use ($normalized, $like): void {
                $query->whereRaw('LOWER(phrase) = ?', [$normalized])
                    ->orWhere('phrase', 'like', '%'.$like.'%');
            })
            ->orderByDesc('id')
            ->value('id');

        if ($byKeyword !== null) {
            return (int) $byKeyword;
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
        return count($this->getPendingItems());
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

    public function runItem(int $taskId): void
    {
        if ($this->projectRun === null) {
            return;
        }

        $formatter = app(SeoProjectRunErrorFormatter::class);

        try {
            $item = app(SeoProjectWorkflowRunService::class)->retryTask($this->projectRun, $taskId);
            $this->projectRun->refresh();

            if (($item['status'] ?? '') === 'success') {
                Notification::make()
                    ->title(__('seo-content-ai::filament.projects.run_item_success'))
                    ->body((string) ($item['message'] ?? ''))
                    ->success()
                    ->send();

                return;
            }

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_item_failed'))
                ->body($formatter->displayMessage($item))
                ->danger()
                ->persistent()
                ->send();
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
        }
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
}
