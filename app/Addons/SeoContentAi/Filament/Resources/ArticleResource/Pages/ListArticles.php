<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleKeywordLinkReconcileService;
use App\Addons\SeoContentAi\Services\CreateArticlesFromTaskService;
use App\Addons\SeoContentAi\Services\DomainOverviewService;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\SeoMainDomainService;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListArticles extends ListRecords
{
    public const TAB_POSTS = 'posts';

    public const TAB_CATEGORIES = 'categories';

    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.list-articles';

    public string $contentTab = self::TAB_POSTS;

    public function mount(): void
    {
        parent::mount();

        $tab = request()->query('tab', self::TAB_POSTS);
        if (is_string($tab) && in_array($tab, [self::TAB_POSTS, self::TAB_CATEGORIES], true)) {
            $this->contentTab = $tab;
        }

        $categoryFilter = request()->input('tableFilters.category_id.value');
        if ($categoryFilter !== null && $categoryFilter !== '') {
            $this->contentTab = self::TAB_POSTS;
        }
    }

    public function getContentTabUrl(string $tab): string
    {
        $params = ['tab' => $tab];

        $filters = $this->tableFilters ?? [];
        unset($filters['type']);

        if ($tab === self::TAB_CATEGORIES) {
            unset($filters['category_id'], $filters['post_type']);
        } else {
            unset($filters['taxonomy']);
        }

        if ($filters !== []) {
            $params['tableFilters'] = $filters;
        }

        return ArticleResource::panelUrl('index').'?'.http_build_query($params);
    }

    public function getArticlesFilterUrlForCategory(int $categoryWpId, ?int $siteId = null): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForCategory($categoryWpId, $siteId);
    }

    public function table(Table $table): Table
    {
        return ArticleResource::table($table)
            ->modifyQueryUsing(function (Builder $query): Builder {
                ArticleResource::applyContentTabScope($query, $this->contentTab);

                if ($this->contentTab === self::TAB_CATEGORIES) {
                    return ArticleResource::appendArticlesInCategoryCountSelect($query);
                }

                return $query;
            });
    }

    public function setSeoScoreBandFilter(?string $band = null): void
    {
        $this->tableFilters ??= [];

        // Legacy query-string key from older filter UI — ignore to avoid stale state.
        unset($this->tableFilters['seo_score']);

        if ($band === null || $band === '') {
            unset($this->tableFilters['seo_score_band']);
        } else {
            $this->tableFilters['seo_score_band'] = [
                'value' => $band,
            ];
        }

        $this->getTableFiltersForm()->fill($this->tableFilters);
        $this->handleTableFilterUpdates();
        $this->flushCachedTableRecords();
    }

    public function syncArticleMainKeyword(int $articleId, string $phrase): void
    {
        abort_unless(SeoAccessControl::canAccessPlannerFeatures(), 403);

        $article = ArticleResource::getEloquentQuery()
            ->whereKey($articleId)
            ->first();

        if (! $article instanceof SeoArticle) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.main_keyword_sync_failed'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) (ArticleResource::resolveArticleSiteId($article) ?? SeoAccessControl::globalSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.main_keyword_sync_failed'))
                ->body(__('seo-content-ai::filament.article_list.main_keyword_no_domain'))
                ->warning()
                ->send();

            return;
        }

        $current = trim((string) (app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? ''));
        $phrase = trim($phrase);

        if ($phrase === $current) {
            return;
        }

        KeywordFocusAttach::syncMainKeyword(
            $article,
            $siteId,
            (int) auth()->id(),
            $phrase,
        );

        app(ArticleKeywordLinkReconcileService::class)->reconcileForArticle($article->fresh());

        $article = $article->fresh();
        if (! $article instanceof SeoArticle) {
            return;
        }

        $content = app(ArticleKeywordLinkReconcileService::class)->resolveArticleContent($article);
        $scoreResult = app(SeoAnalyzerService::class)->analyzeSubmittedContent($article, $content);
        $article = $article->fresh();
        $score = (int) ($scoreResult['score'] ?? $article?->seo_score ?? 0);

        $wpPostId = (int) ($article?->wp_post_id ?? 0);

        if ($wpPostId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.main_keyword_synced'))
                ->body(__('seo-content-ai::filament.article_list.main_keyword_synced_score_only', [
                    'score' => $score,
                    'keyword' => $phrase !== '' ? $phrase : __('seo-content-ai::filament.article_list.seo_keyword_empty'),
                ]))
                ->warning()
                ->send();

            return;
        }

        $wpResult = app(WordPressArticleSyncService::class)->syncSeoMetaForArticle($article, [
            'focus_keyword' => $phrase,
        ]);

        if (! ($wpResult['success'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.main_keyword_sync_failed'))
                ->body(__('seo-content-ai::filament.article_list.main_keyword_wp_sync_failed_with_score', [
                    'score' => $score,
                    'message' => (string) ($wpResult['message'] ?? __('seo-content-ai::filament.article_list.main_keyword_wp_sync_failed')),
                ]))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.main_keyword_synced'))
            ->body(__('seo-content-ai::filament.article_list.main_keyword_synced_wp_with_score', [
                'score' => $score,
                'keyword' => $phrase !== '' ? $phrase : __('seo-content-ai::filament.article_list.seo_keyword_empty'),
            ]))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create_from_keywords')
                ->label('Create new articles')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->form([
                    Forms\Components\Placeholder::make('main_domain')
                        ->label('Main domain')
                        ->content(fn (SeoMainDomainService $mainDomain): string => $mainDomain->resolveMainSiteLabel()),
                    Forms\Components\Textarea::make('keywords')
                        ->label('Keywords')
                        ->placeholder("One keyword per line\nExample:\nmen leather backpack\nnon-woven bags")
                        ->rows(8)
                        ->required()
                        ->columnSpanFull(),
                ])
                ->action(function (array $data, CreateArticlesFromTaskService $service): void {
                    try {
                        $result = $service->runFromKeywords(
                            (string) ($data['keywords'] ?? ''),
                        );

                        $body = sprintf(
                            'Success: %d · Failed: %d',
                            $result['created'],
                            $result['failed'],
                        );

                        if ($result['messages'] !== []) {
                            $body .= "\n".implode("\n", array_slice($result['messages'], 0, 8));
                            if (count($result['messages']) > 8) {
                                $body .= "\n…";
                            }
                        }

                        $notification = Notification::make()
                            ->title('Keywords processed')
                            ->body($body);

                        if ($result['failed'] > 0 && $result['created'] === 0) {
                            $notification->danger();
                        } elseif ($result['failed'] > 0) {
                            $notification->warning();
                        } else {
                            $notification->success();
                        }

                        $notification->send();
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title('Unable to create articles')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->modalHeading('Auto create articles')
                ->modalDescription('Enter keyword list. System will run configured "Publish article" workflow in SEO -> Settings.')
                ->modalSubmitActionLabel('Run workflow & create'),
            Actions\Action::make('trash')
                ->label('Trash')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->url(fn (): string => ArticleResource::getUrl('trash')),
        ];
    }
}
