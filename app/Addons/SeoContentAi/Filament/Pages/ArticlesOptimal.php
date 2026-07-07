<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\SeoPromptSettingsService;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use App\Services\SeoEngineService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

final class ArticlesOptimal extends SeoPanelPage
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Article SEO audit';

    protected static ?string $title = 'Article SEO audit';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationParentItem = 'Articles';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'articles/optimal';

    protected static string $view = 'seo-content-ai::filament.pages.articles-optimal';

    #[Url(as: 'site')]
    public ?int $filterSiteId = null;

    #[Url(as: 'thin')]
    public bool $filterThinContent = false;

    #[Url(as: 'img')]
    public bool $filterPoorImageDensity = false;

    #[Url(as: 'h2')]
    public bool $filterMissingH2 = false;

    #[Url(as: 'faq')]
    public bool $filterMissingFaq = false;

    #[Url(as: 'low')]
    public bool $filterLowSeoScore = false;

    #[Url(as: 'tech')]
    public bool $filterTechnicalSeoScore = false;

    #[Url(as: 'lang')]
    public ?string $filterLanguage = null;

    #[Url(as: 'scan')]
    public bool $hasScanned = false;

    public bool $scanning = false;

    /** @var array<int, int> */
    public array $selectedArticleIds = [];

    public ?int $sidebarProjectId = null;

    public static function canAccess(array $parameters = []): bool
    {
        return ArticleResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.articles_optimal.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.articles_optimal.navigation');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('seo-content-ai::filament.nav.articles');
    }

    /**
     * @return array<int, string>
     */
    public function getSiteFilterOptions(): array
    {
        $query = Site::query()->orderBy('domain')->limit(5);

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query->pluck('domain', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    public function getLanguageOptions(): array
    {
        $languages = SeoArticle::query()
            ->whereNotIn('type', ['category', 'product_category'])
            ->where('status', '!=', 'trash')
            ->select('language')
            ->distinct()
            ->orderBy('language')
            ->pluck('language')
            ->filter()
            ->all();

        $options = [];
        foreach ($languages as $lang) {
            $label = match ($lang) {
                'vi' => 'Tiếng Việt',
                'en' => 'English',
                'ja' => '日本語',
                'ko' => '한국어',
                'zh' => '中文',
                'fr' => 'Français',
                default => mb_strtoupper((string) $lang),
            };
            $options[(string) $lang] = $label;
        }

        return $options;
    }

    public function runScan(): void
    {
        $this->scanning = true;
        $this->hasScanned = true;
        $this->resetPage();
        $this->scanning = false;
    }

    /**
     * @return array<int, string>
     */
    public function getContentProjectOptions(): array
    {
        $siteId = $this->filterSiteId !== null && $this->filterSiteId > 0
            ? $this->filterSiteId
            : SeoAccessControl::globalSiteId();

        if ($siteId !== null && $siteId > 0) {
            return ArticleResource::contentProjectOptions($siteId);
        }

        $options = [];
        foreach (SeoAccessControl::accessibleSiteIds() as $accessibleSiteId) {
            $options += ArticleResource::contentProjectOptions((int) $accessibleSiteId);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function getWriterOptions(): array
    {
        return SeoProjectResource::userSelectOptions();
    }

    /**
     * @return array<int, string>
     */
    public function getSidebarProjectSiteOptions(): array
    {
        return SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->pluck('domain', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function getAssignTypeOptions(): array
    {
        return SeoProjectTask::typeOptions();
    }

    /**
     * @return array<string, string>
     */
    public function getRewriteModeOptions(): array
    {
        return SeoProjectTask::rewriteModeOptions();
    }

    public function selectSidebarProject(mixed $projectId): void
    {
        $this->sidebarProjectId = (int) $projectId > 0 ? (int) $projectId : null;
    }

    /**
     * @return list<array{id:int,title:string,status:string,type:string}>
     */
    public function getSidebarProjectArticles(): array
    {
        $projectId = (int) ($this->sidebarProjectId ?? 0);
        if ($projectId <= 0) {
            return [];
        }

        return SeoProjectTask::query()
            ->with('article:id,title,status')
            ->where('project_id', $projectId)
            ->orderBy('target_date')
            ->orderBy('id')
            ->get()
            ->map(function (SeoProjectTask $task): array {
                $article = $task->article;

                return [
                    'id' => (int) ($article?->id ?? 0),
                    'title' => trim((string) ($article?->title ?? $task->source_content)),
                    'status' => (string) ($article?->status ?? $task->status ?? ''),
                    'type' => (string) ($task->type ?? ''),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function quickCreateSidebarProject(array $data): void
    {
        $siteId = (int) ($data['site_id'] ?? 0);
        if ($siteId <= 0) {
            $siteId = (int) ($this->filterSiteId ?: SeoAccessControl::globalSiteId() ?: 0);
        }

        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_content_project_failed'))
                ->body(__('seo-content-ai::filament.article_list.assign_projects_mixed_domains'))
                ->danger()
                ->send();

            return;
        }

        try {
            $project = ArticleResource::quickCreateContentProject($siteId, (int) ($data['user_id'] ?? 0));
            $this->sidebarProjectId = (int) $project->id;

            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_content_project_success'))
                ->body(__('seo-content-ai::filament.article_list.quick_create_content_project_success_body', [
                    'name' => $project->name,
                ]))
                ->success()
                ->send();
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_content_project_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assignArticleToContentProject(int $articleId, array $data): void
    {
        $this->assignArticlesToContentProject([$articleId], $data);
    }

    public function assignArticleToSelectedProject(int $articleId): void
    {
        $this->assignArticlesToContentProject([$articleId], [
            'project_id' => $this->sidebarProjectId,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
        ]);
    }

    public function assignSelectedArticlesToSelectedProject(mixed $projectId = null): void
    {
        $this->assignArticlesToContentProject($this->selectedArticleIds, [
            'project_id' => $projectId !== null && (int) $projectId > 0 ? (int) $projectId : $this->sidebarProjectId,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
        ]);
    }

    /**
     * @param  array<int, int|string>  $articleIds
     * @param  array<string, mixed>  $data
     */
    private function assignArticlesToContentProject(array $articleIds, array $data): void
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        if ($projectId <= 0 || ! SeoProject::query()->whereKey($projectId)->exists()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body(__('seo-content-ai::filament.articles_optimal.assign_no_project'))
                ->warning()
                ->send();

            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }

        $records = $this->accessibleArticleQuery()
            ->whereIn('id', $ids)
            ->get();

        $summary = ArticleResource::assignArticlesFromFormData(
            Collection::make($records),
            $projectId,
            $data,
        );

        $this->selectedArticleIds = array_values(array_diff(array_map('intval', $this->selectedArticleIds), $ids));
        $this->sidebarProjectId = $projectId;
        unset($this->resultsPaginator);

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.assign_completed'))
            ->body(ArticleResource::buildAssignContentProjectBody($summary))
            ->success()
            ->send();
    }

    public function demoteToDraft(int $articleId): void
    {
        $article = $this->findAccessibleArticle($articleId);
        if ($article === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.demote_failed'))
                ->danger()
                ->send();

            return;
        }

        $article->update(['status' => 'draft']);
        $result = app(WordPressArticleSyncService::class)->syncForArticle($article->fresh());

        if (($result['success'] ?? false) === true) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.demote_success'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.articles_optimal.demote_failed'))
            ->body((string) ($result['message'] ?? ''))
            ->warning()
            ->send();
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function resultsPaginator(): LengthAwarePaginator
    {
        return $this->getResultsPaginator();
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function getResultsPaginator(): LengthAwarePaginator
    {
        if (! $this->hasScanned) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15);
        }

        $analyzer = app(SeoAnalyzerService::class);
        $engine = app(SeoEngineService::class);

        $rows = [];
        foreach ($this->baseArticleQuery()->get() as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }

            if ((bool) ($article->is_reviewed ?? false) || ArticleResource::articleIsInContentProject($article)) {
                continue;
            }

            $row = $this->mapArticleRow($article, $analyzer, $engine);
            if ($row['matches_filters']) {
                $rows[] = $row;
            }
        }

        $perPage = 15;
        $page = max(1, (int) $this->getPage());
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapArticleRow(
        SeoArticle $article,
        SeoAnalyzerService $analyzer,
        SeoEngineService $engine,
    ): array {
        $article->loadMissing(['site', 'articleMetas', 'faqs']);
        $body = (string) ($article->body ?? '');
        $focusKeyword = $analyzer->resolveFocusKeywordForArticle($article) ?? '';

        $analysis = $engine->analyzeHtml(
            $body,
            $focusKeyword,
            $article->resolveFaqs(),
            [
                'seo_title' => (string) ($article->title ?? ''),
                'meta_description' => $this->resolveMetaDescription($article),
                'slug' => (string) ($article->slug ?? ''),
                'domain' => (string) ($article->site?->domain ?? ''),
                'article_length_target' => app(SeoPromptSettingsService::class)->resolveArticleLengthTarget(
                    ArticlePostTypeResolver::resolve($article),
                ),
            ],
        );

        $reasonKeys = $analysis['reason_keys'] ?? [];
        $score = (int) ($analysis['seo_score'] ?? 0);

        return [
            'id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0),
            'title' => (string) ($article->title ?? ''),
            'domain' => (string) ($article->site?->domain ?? ''),
            'permalink' => $this->resolveCachedPermalink($article),
            'edit_url' => ArticleResource::getUrl('edit', ['record' => $article]),
            'score' => $score,
            'reason_keys' => $reasonKeys,
            'reason_labels' => array_map(
                static fn (string $key): string => __($key),
                $reasonKeys,
            ),
            'matches_filters' => $this->articleMatchesActiveFilters($analysis, $body, $article),
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function articleMatchesActiveFilters(array $analysis, string $body, SeoArticle $article): bool
    {
        $activeFilters = array_filter([
            $this->filterThinContent,
            $this->filterPoorImageDensity,
            $this->filterMissingH2,
            $this->filterMissingFaq,
            $this->filterLowSeoScore,
            $this->filterTechnicalSeoScore,
        ]);

        if ($activeFilters === []) {
            return true;
        }

        $reasonKeys = $analysis['reason_keys'] ?? [];
        $score = (int) ($analysis['seo_score'] ?? 0);
        $matched = false;

        if ($this->filterThinContent && in_array('seo.length', $reasonKeys, true)) {
            $matched = true;
        }

        if ($this->filterPoorImageDensity && in_array('seo.image_ratio', $reasonKeys, true)) {
            $matched = true;
        }

        if ($this->filterMissingH2 && in_array('seo.heading', $reasonKeys, true)) {
            $matched = true;
        }

        if ($this->filterMissingFaq && in_array('seo.faq_schema', $reasonKeys, true)) {
            $matched = true;
        }

        if ($this->filterLowSeoScore && $score < 60) {
            $matched = true;
        }

        if ($this->filterTechnicalSeoScore && $score < 60) {
            $matched = true;
        }

        return $matched;
    }

    private function resolveCachedPermalink(SeoArticle $article): ?string
    {
        $meta = $article->articleMetas->firstWhere('meta_key', 'wp_permalink');
        $permalink = trim((string) ($meta?->meta_value ?? ''));

        return $permalink !== '' ? $permalink : null;
    }

    /**
     * @return Builder<SeoArticle>
     */
    private function baseArticleQuery(): Builder
    {
        $query = SeoArticle::query()
            ->countsTowardSeoScore()
            ->whereNotIn('type', ['category', 'product_category'])
            ->where('status', '!=', 'trash')
            ->where(function (Builder $sub): void {
                $sub->where('is_reviewed', false)->orWhereNull('is_reviewed');
            })
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('seo_project_tasks')
                    ->whereColumn('seo_project_tasks.article_id', 'articles.id');
            })
            ->with(['site:id,domain', 'articleMetas', 'faqs'])
            ->orderByDesc('updated_at');

        if ($this->filterSiteId !== null && $this->filterSiteId > 0) {
            $query->where('site_id', $this->filterSiteId);
        } elseif (SeoAccessControl::shouldScopeToAccountOwner()) {
            $siteIds = array_map('intval', array_keys($this->getSiteFilterOptions()));
            if ($siteIds !== []) {
                $query->whereIn('site_id', $siteIds);
            }
        }

        if ($this->filterLanguage !== null && $this->filterLanguage !== '') {
            $query->where('language', $this->filterLanguage);
        }

        return $query;
    }

    private function findAccessibleArticle(int $articleId): ?SeoArticle
    {
        return $this->accessibleArticleQuery()->whereKey($articleId)->first();
    }

    /**
     * @return Builder<SeoArticle>
     */
    private function accessibleArticleQuery(): Builder
    {
        $query = SeoArticle::query();

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $siteIds = array_map('intval', array_keys($this->getSiteFilterOptions()));
            if ($siteIds !== []) {
                $query->whereIn('site_id', $siteIds);
            }
        }

        return $query;
    }

    private function resolveMetaDescription(SeoArticle $article): string
    {
        $meta = $article->articleMetas->first(
            static fn ($item): bool => in_array((string) $item->meta_key, [
                'meta_description',
                'seo_meta_description',
                '_yoast_wpseo_metadesc',
                'rank_math_description',
            ], true),
        );

        if ($meta && is_string($meta->meta_value) && trim($meta->meta_value) !== '') {
            return trim($meta->meta_value);
        }

        return trim((string) ($article->excerpt ?? ''));
    }
}
