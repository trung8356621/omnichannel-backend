<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Enums\SeoLinkMapType;
use App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoMediaProcessingHistory;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\SeoNotificationService;
use App\Addons\SeoContentAi\Services\SeoProjectApprovalService;
use App\Addons\SeoContentAi\Services\SeoProjectArticleOwnerSyncService;
use App\Addons\SeoContentAi\Services\SitePolylangService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoDisplayTimezone;
use App\Addons\SeoContentAi\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArticleResource extends SeoPanelResource
{
    protected static ?string $model = SeoArticle::class;

    protected static ?string $slug = 'articles';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Articles';

    protected static ?string $modelLabel = 'Article';

    protected static ?string $pluralModelLabel = 'Articles';

    /** article_meta: bỏ qua lọc Article SEO Audit (Laravel only — không đụng WordPress). */
    public const META_SKIP_SEO_AUDIT = 'skip_seo_audit';

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessContentFeatures();
    }

    public static function panelId(): string
    {
        return 'seo';
    }

    /**
     * URL resource trong panel SEO (dùng khi gọi ngoài ngữ cảnh Filament, VD: API preview).
     */
    public static function panelUrl(string $name = 'index', array $parameters = [], bool $isAbsolute = true): string
    {
        return static::getUrl($name, $parameters, $isAbsolute, panel: static::panelId());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label(__('seo-content-ai::filament.article_list.title'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->label(__('seo-content-ai::filament.article_list.status'))
                    ->options([
                        'draft' => __('seo-content-ai::filament.article_list.status_draft'),
                        'published' => __('seo-content-ai::filament.article_list.status_published'),
                        'scheduled' => __('seo-content-ai::filament.article_list.status_scheduled'),
                        'private' => __('seo-content-ai::filament.article_list.status_private'),
                    ])
                    ->default('draft')
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordAction('edit')
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail')
                    ->label(__('seo-content-ai::filament.article_list.thumb'))
                    ->square()
                    ->height(46)
                    ->width(46)
                    ->defaultImageUrl(url('/assets/images/placeholder-loading.svg'))
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::resolveThumbnailUrl($record))
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('wp_data_out_of_sync')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->getStateUsing(function (SeoArticle $record): ?string {
                        return app(ArticleWordPressSyncFlagService::class)->hasDataOutOfSync($record)
                            ? __('seo-content-ai::filament.article_list.data_out_of_sync')
                            : null;
                    })
                    ->placeholder('')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('seo-content-ai::filament.article_list.title'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(function (SeoArticle $record): ?string {
                        if (filled($record->slug)) {
                            return '/'.ltrim((string) $record->slug, '/');
                        }

                        if ($record->wp_post_id) {
                            return 'WP ID: '.$record->wp_post_id;
                        }

                        return null;
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('seo-content-ai::filament.article_list.domain'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.article_list.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ((string) $state) {
                        'published' => __('seo-content-ai::filament.article_list.status_published'),
                        'scheduled' => __('seo-content-ai::filament.article_list.status_scheduled'),
                        'private' => __('seo-content-ai::filament.article_list.status_private'),
                        'draft' => __('seo-content-ai::filament.article_list.status_draft'),
                        default => $state ?: '—',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('seo-content-ai::filament.article_list.type'))
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state, SeoArticle $record): string => static::resolveWordPressPostTypeLabel($record))
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('articles_in_category_count')
                    ->label(__('seo-content-ai::filament.article_list.articles_in_category'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->url(function (SeoArticle $record): ?string {
                        $wpId = (int) ($record->wp_post_id ?? 0);
                        if ($wpId <= 0) {
                            return null;
                        }

                        return app(\App\Addons\SeoContentAi\Services\DomainOverviewService::class)
                            ->buildArticlesFilterUrlForCategory($wpId, (int) ($record->site_id ?? 0) ?: null);
                    })
                    ->color('primary')
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_CATEGORIES)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('language')
                    ->label(__('seo-content-ai::filament.article_list.language'))
                    ->visible(fn (): bool => app(SitePolylangService::class)->anyAccessibleSiteHasPolylang())
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(function (?string $state, SeoArticle $record): string {
                        $record->loadMissing('site');

                        return app(SitePolylangService::class)->languageLabel(
                            (string) ($state ?? ''),
                            $record->site,
                        );
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('author')
                    ->label(__('seo-content-ai::filament.article_list.author'))
                    ->badge()
                    ->getStateUsing(function (SeoArticle $record): string {
                        if ($record->user_id === null) {
                            return __('seo-content-ai::filament.article_list.system');
                        }

                        $record->loadMissing('user');

                        return (string) ($record->user?->display_name ?? $record->user?->email ?? __('seo-content-ai::filament.article_list.system'));
                    })
                    ->color(fn (string $state): string => $state === __('seo-content-ai::filament.article_list.system') ? 'gray' : 'primary')
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('seo-content-ai::filament.article_list.updated'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('published_at')
                    ->label(__('seo-content-ai::filament.article_list.published'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('reviewed_at')
                    ->label(__('seo-content-ai::filament.article_list.reviewed_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('wp_sync_queue_status')
                    ->label(__('seo-content-ai::filament.article_list.queue_status'))
                    ->badge()
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::resolveWpSyncQueueStatus($record))
                    ->formatStateUsing(fn (?string $state): string => match ((string) $state) {
                        ArticleWpSyncQueueService::STATUS_PENDING => __('seo-content-ai::filament.article_list.queue_status_pending'),
                        ArticleWpSyncQueueService::STATUS_PROCESSING => __('seo-content-ai::filament.article_list.queue_status_processing'),
                        ArticleWpSyncQueueService::STATUS_COMPLETED => __('seo-content-ai::filament.article_list.queue_status_completed'),
                        ArticleWpSyncQueueService::STATUS_FAILED => __('seo-content-ai::filament.article_list.queue_status_failed'),
                        default => $state ?: '—',
                    })
                    ->color(fn (?string $state): string => match ((string) $state) {
                        ArticleWpSyncQueueService::STATUS_PENDING => 'warning',
                        ArticleWpSyncQueueService::STATUS_PROCESSING => 'info',
                        ArticleWpSyncQueueService::STATUS_COMPLETED => 'success',
                        ArticleWpSyncQueueService::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('wp_sync_queue_queued_at')
                    ->label(__('seo-content-ai::filament.article_list.queue_queued_at'))
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::formatWpSyncQueueDateTime(
                        static::resolveWpSyncQueueField($record, 'queued_at'),
                    ))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('wp_sync_queue_error')
                    ->label(__('seo-content-ai::filament.article_list.queue_error'))
                    ->wrap()
                    ->limit(80)
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::resolveWpSyncQueueField($record, 'error'))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\ViewColumn::make('seo_details')
                    ->label(fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                        view('seo-content-ai::filament.tables.columns.article-seo-details-header')->render(),
                    ))
                    ->view('seo-content-ai::filament.tables.columns.article-seo-details')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->orderByRaw('CASE WHEN skip_seo_score = 1 THEN 1 ELSE 0 END ASC')
                            ->orderBy('seo_score', $direction);
                    })
                    ->disabledClick()
                    ->visible(fn ($livewire): bool => ! ($livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE))
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('updated_at', 'desc')
            ->defaultKeySort()
            ->filters([
                SelectFilter::make('site_id')
                    ->label(__('seo-content-ai::filament.article_list.domain'))
                    ->visible(fn (): bool => ! SeoAccessControl::hasGlobalSiteScope())
                    ->options(function (): array {
                        $query = Site::query()->orderBy('domain');

                        if (SeoAccessControl::shouldScopeToAccountOwner()) {
                            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
                        }

                        return $query->pluck('domain', 'id')->all();
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_domains'))
                    ->indicator(__('seo-content-ai::filament.article_list.domain'))
                    ->query(function (Builder $query, array $data): void {
                        $siteId = $data['value'] ?? null;
                        if ($siteId === null || $siteId === '') {
                            return;
                        }

                        $query->where('site_id', $siteId);
                    }),
                SelectFilter::make('language')
                    ->label('Ngôn ngữ')
                    ->visible(fn (): bool => app(SitePolylangService::class)->anyAccessibleSiteHasPolylang())
                    ->options(fn (): array => app(SitePolylangService::class)->defaultLanguageOptions())
                    ->default('vi')
                    ->native(false)
                    ->placeholder('Tất cả ngôn ngữ')
                    ->indicator('Ngôn ngữ')
                    ->query(function (Builder $query, array $data): void {
                        $lang = trim((string) ($data['value'] ?? ''));
                        if ($lang === '') {
                            return;
                        }

                        $query->where('language', $lang);
                    }),
                SelectFilter::make('post_type')
                    ->label(__('seo-content-ai::filament.article_list.post_type'))
                    ->options([
                        'post' => __('seo-content-ai::filament.article_list.post_type_post'),
                        'page' => __('seo-content-ai::filament.article_list.post_type_page'),
                        'product' => __('seo-content-ai::filament.article_list.post_type_product'),
                    ])
                    ->default('post')
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_post_types'))
                    ->indicator(__('seo-content-ai::filament.article_list.post_type'))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_POSTS)
                    ->query(function (Builder $query, array $data, $livewire): void {
                        if (! $livewire instanceof Pages\ListArticles
                            || $livewire->contentTab !== Pages\ListArticles::TAB_POSTS) {
                            return;
                        }

                        $postType = $data['value'] ?? null;
                        if (! is_string($postType) || $postType === '') {
                            return;
                        }

                        static::applyPostTypeFilterScope($query, $postType);
                    }),
                SelectFilter::make('taxonomy')
                    ->label(__('seo-content-ai::filament.article_list.taxonomy'))
                    ->options([
                        'category' => __('seo-content-ai::filament.article_list.post_type_category'),
                        'product_category' => __('seo-content-ai::filament.article_list.post_type_product_category'),
                    ])
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_taxonomies'))
                    ->indicator(__('seo-content-ai::filament.article_list.taxonomy'))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_CATEGORIES)
                    ->query(function (Builder $query, array $data, $livewire): void {
                        if (! $livewire instanceof Pages\ListArticles
                            || $livewire->contentTab !== Pages\ListArticles::TAB_CATEGORIES) {
                            return;
                        }

                        $taxonomy = $data['value'] ?? null;
                        if (! is_string($taxonomy) || $taxonomy === '') {
                            return;
                        }

                        $query->where('type', $taxonomy);
                    }),
                SelectFilter::make('category_id')
                    ->label(__('seo-content-ai::filament.article_list.category_filter'))
                    ->options(fn ($livewire): array => $livewire instanceof Pages\ListArticles
                        ? static::buildCategoryFilterOptions($livewire)
                        : [])
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_categories'))
                    ->indicator(__('seo-content-ai::filament.article_list.category_filter'))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_POSTS
                        && trim((string) ($livewire->tableFilters['post_type']['value'] ?? '')) !== 'page')
                    ->query(function (Builder $query, array $data, $livewire): void {
                        if (! $livewire instanceof Pages\ListArticles
                            || $livewire->contentTab !== Pages\ListArticles::TAB_POSTS) {
                            return;
                        }

                        $categoryWpId = (int) ($data['value'] ?? 0);
                        if ($categoryWpId <= 0) {
                            return;
                        }

                        static::applyCategoryMembershipScope($query, $categoryWpId);
                    }),
                SelectFilter::make('seo_score_band')
                    ->label(__('seo-content-ai::filament.article_list.seo_score'))
                    ->visible(fn (): bool => false)
                    ->options([
                        'poor' => __('seo-content-ai::filament.article_list.seo_score_poor'),
                        'fair' => __('seo-content-ai::filament.article_list.seo_score_fair'),
                        'good' => __('seo-content-ai::filament.article_list.seo_score_good'),
                        'excellent' => __('seo-content-ai::filament.article_list.seo_score_excellent'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $band = $data['value'] ?? null;
                        if (! is_string($band) || $band === '') {
                            return;
                        }

                        $query->countsTowardSeoScore()->whereNotNull('seo_score');

                        match ($band) {
                            'poor' => $query->where('seo_score', '<', 50),
                            'fair' => $query->whereBetween('seo_score', [50, 69.99]),
                            'good' => $query->whereBetween('seo_score', [70, 89.99]),
                            'excellent' => $query->where('seo_score', '>=', 90),
                            default => null,
                        };
                    })
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_scores'))
                    ->indicator(__('seo-content-ai::filament.article_list.seo_score')),
                Filter::make('seo_link')
                    ->label(__('seo-content-ai::filament.article_list.links_in_article'))
                    ->form([
                        Forms\Components\Hidden::make('url'),
                        Forms\Components\Hidden::make('type'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $url = $data['url'] ?? null;
                        if (! is_string($url) || trim($url) === '') {
                            return;
                        }

                        $type = $data['type'] ?? null;

                        $query->whereHas('linkMaps', function (Builder $linkQuery) use ($url, $type): void {
                            $linkQuery->where('target_external_url', trim($url));

                            if ($type === 'internal') {
                                $linkQuery->where('link_type', SeoLinkMapType::Internal);
                            } elseif ($type === 'external') {
                                $linkQuery->whereIn('link_type', [
                                    SeoLinkMapType::External,
                                    SeoLinkMapType::WikiTrust,
                                ]);
                            }
                        });
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $url = $data['url'] ?? null;
                        if (! is_string($url) || trim($url) === '') {
                            return null;
                        }

                        $type = $data['type'] ?? null;
                        $typeLabel = $type === 'internal' ? 'internal' : ($type === 'external' ? 'external' : '');

                        return __('seo-content-ai::filament.article_list.link').($typeLabel !== '' ? ' '.$typeLabel : '').': '.Str::limit($url, 48);
                    }),
                Filter::make('keyword')
                    ->label(__('seo-content-ai::filament.article_list.keyword'))
                    ->form([
                        Forms\Components\Hidden::make('keyword_id'),
                        Forms\Components\Hidden::make('usage'),
                        Forms\Components\Hidden::make('internal_link_only'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $keywordId = $data['keyword_id'] ?? null;
                        if ($keywordId === null || $keywordId === '') {
                            return;
                        }

                        $usage = (string) ($data['usage'] ?? '');

                        if ($usage === 'main') {
                            $query->whereIn('articles.id', function ($subQuery) use ($keywordId): void {
                                $subQuery->selectRaw('CAST(meta_value AS UNSIGNED)')
                                    ->from('keyword_meta')
                                    ->where('keyword_id', $keywordId)
                                    ->where('meta_key', \App\Addons\SeoContentAi\Enums\KeywordMetaKey::MainArticleId->value);
                            });

                            return;
                        }

                        if ($usage === 'internal_link' || ($data['internal_link_only'] ?? '') === '1') {
                            $query->whereHas('linkMaps', function (Builder $linkQuery) use ($keywordId): void {
                                $linkQuery
                                    ->where('keyword_id', $keywordId)
                                    ->where('link_type', SeoLinkMapType::Internal);
                            });

                            return;
                        }

                        $query->where(function (Builder $scopeQuery) use ($keywordId): void {
                            $scopeQuery
                                ->whereIn('articles.id', function ($subQuery) use ($keywordId): void {
                                    $subQuery->selectRaw('CAST(meta_value AS UNSIGNED)')
                                        ->from('keyword_meta')
                                        ->where('keyword_id', $keywordId)
                                        ->where('meta_key', \App\Addons\SeoContentAi\Enums\KeywordMetaKey::MainArticleId->value);
                                })
                                ->orWhereIn('articles.id', function ($subQuery) use ($keywordId): void {
                                    $subQuery->select('source_article_id')
                                        ->from('seo_link_maps')
                                        ->where('keyword_id', $keywordId)
                                        ->whereNotNull('source_article_id');
                                });
                        });
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $keywordId = $data['keyword_id'] ?? null;
                        if ($keywordId === null || $keywordId === '') {
                            return null;
                        }

                        $phrase = Keyword::query()
                            ->whereKey($keywordId)
                            ->value('phrase');

                        if (! is_string($phrase) || $phrase === '') {
                            return __('seo-content-ai::filament.article_list.keyword').' #'.$keywordId;
                        }

                        $usage = (string) ($data['usage'] ?? '');
                        $suffix = match (true) {
                            $usage === 'main' => ' ('.__('seo-content-ai::filament.article_list.main_article').')',
                            $usage === 'internal_link', ($data['internal_link_only'] ?? '') === '1' => ' ('.__('seo-content-ai::filament.article_list.has_internal_link').')',
                            default => '',
                        };

                        return __('seo-content-ai::filament.article_list.keyword').': '.$phrase.$suffix;
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'lg' => 5,
            ])
            ->persistFiltersInSession()
            ->actionsAlignment('start')
            ->actions(static::getArticleTableRowActionsMerged())
            ->bulkActions(static::seoPanelBulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve_articles')
                        ->label(__('seo-content-ai::filament.article_list.review_articles'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn (): bool => ! SeoAccessControl::isContentManager())
                        ->extraAttributes([
                            'wire:confirm' => __('seo-content-ai::filament.article_list.review_article_description'),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $approvedCount = 0;
                            $deletedMediaCount = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof SeoArticle) {
                                    continue;
                                }

                                $deletedMediaCount += static::markArticleReviewed($record);

                                $approvedCount++;
                            }

                            Notification::make()
                                ->title(__('seo-content-ai::filament.article_list.bulk_review_completed'))
                                ->body(__('seo-content-ai::filament.article_list.bulk_review_body', [
                                    'approved' => $approvedCount,
                                    'deleted' => $deletedMediaCount,
                                ]))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('assign_to_content_project')
                        ->label(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                        ->icon('heroicon-o-folder-plus')
                        ->color('warning')
                        ->visible(fn (): bool => SeoAccessControl::canMutateInSeoPanel())
                        ->deselectRecordsAfterCompletion()
                        ->form(function (Collection $records): array {
                            $siteId = static::resolveBulkArticlesSiteId($records);

                            if (static::resolveDirectAssignContentProjectId($siteId) !== null) {
                                return static::assignArticleTaskFormFields();
                            }

                            return static::assignContentProjectFormFields(
                                fn (): ?int => $siteId,
                                fn (): ?string => $siteId === null
                                    ? __('seo-content-ai::filament.article_list.assign_projects_mixed_domains')
                                    : null,
                            );
                        })
                        ->requiresConfirmation(false)
                        ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                        ->modalDescription(__('seo-content-ai::filament.article_list.assign_to_content_project_description'))
                        ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
                        ->action(function (Collection $records, array $data): void {
                            $siteId = static::resolveBulkArticlesSiteId($records);
                            $projectId = static::resolveDirectAssignContentProjectId($siteId)
                                ?? (int) ($data['project_id'] ?? 0);
                            $summary = static::assignArticlesFromFormData($records, $projectId, $data);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.article_list.assign_completed'))
                                ->body(static::buildAssignContentProjectBody($summary))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]));
    }

    public static function applyPostTypeFilterScope(Builder $query, string $wpPostType): void
    {
        $wpPostType = strtolower(trim($wpPostType));

        match ($wpPostType) {
            'product' => $query->where('type', 'product'),
            'page' => static::applyArticlesWithWpPostTypeMetaScope($query, 'page'),
            'post' => $query->where(function (Builder $scopeQuery): void {
                $scopeQuery
                    ->where(function (Builder $typeQuery): void {
                        $typeQuery
                            ->whereIn('type', ['article'])
                            ->orWhereNull('type')
                            ->orWhere('type', '');
                    })
                    ->whereNotIn('articles.id', function ($subQuery): void {
                        $subQuery->select('article_id')
                            ->from('article_meta')
                            ->where('meta_key', 'wp_post_type')
                            ->where('meta_value', 'page');
                    });
            }),
            default => null,
        };
    }

    public static function resolveWordPressPostTypeLabel(SeoArticle $record): string
    {
        $record->loadMissing('articleMetas');

        $wpPostType = strtolower(trim((string) (
            $record->articleMetas->firstWhere('meta_key', 'wp_post_type')?->meta_value ?? ''
        )));

        if ($wpPostType !== '') {
            return match ($wpPostType) {
                'post' => __('seo-content-ai::filament.article_list.post_type_post'),
                'page' => __('seo-content-ai::filament.article_list.post_type_page'),
                'product' => __('seo-content-ai::filament.article_list.post_type_product'),
                'category' => __('seo-content-ai::filament.article_list.post_type_category'),
                'product_cat', 'product_category' => __('seo-content-ai::filament.article_list.post_type_product_category'),
                default => Str::ucfirst(str_replace('_', ' ', $wpPostType)),
            };
        }

        return match ((string) ($record->type ?? 'article')) {
            'product' => __('seo-content-ai::filament.article_list.post_type_product'),
            'category' => __('seo-content-ai::filament.article_list.post_type_category'),
            'product_category' => __('seo-content-ai::filament.article_list.post_type_product_category'),
            default => __('seo-content-ai::filament.article_list.post_type_post'),
        };
    }

    private static function applyArticlesWithWpPostTypeMetaScope(Builder $query, string $wpPostType): void
    {
        $query->whereIn('articles.id', function ($subQuery) use ($wpPostType): void {
            $subQuery->select('article_id')
                ->from('article_meta')
                ->where('meta_key', 'wp_post_type')
                ->where('meta_value', $wpPostType);
        });
    }

    public static function applyContentTabScope(Builder $query, string $contentTab): Builder
    {
        if ($contentTab === Pages\ListArticles::TAB_QUEUE) {
            return static::applyWpSyncQueueScope($query);
        }

        if ($contentTab === Pages\ListArticles::TAB_CATEGORIES) {
            return $query->whereIn('type', ['category', 'product_category']);
        }

        return $query->where(function (Builder $scopeQuery): void {
            $scopeQuery
                ->whereIn('type', ['article', 'product'])
                ->orWhere(function (Builder $sub): void {
                    $sub->whereNull('type')->orWhere('type', '');
                });
        });
    }

    public static function applyCategoryMembershipScope(Builder $query, int $categoryWpId): void
    {
        $query->whereIn('articles.id', function ($subQuery) use ($categoryWpId): void {
            $subQuery->select('article_id')
                ->from('article_meta')
                ->where('meta_key', 'category_ids')
                ->whereRaw(static::articleMetaContainsCategoryWpIdSql(), [$categoryWpId]);
        });
    }

    public static function appendArticlesInCategoryCountSelect(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('articles.*');
        }

        return $query->selectSub(function ($subQuery): void {
            $subQuery->from('articles as post_articles')
                ->selectRaw('count(*)')
                ->whereColumn('post_articles.site_id', 'articles.site_id')
                ->where(function ($typeQuery): void {
                    $typeQuery
                        ->whereIn('post_articles.type', ['article', 'product'])
                        ->orWhere(function ($nullTypeQuery): void {
                            $nullTypeQuery
                                ->whereNull('post_articles.type')
                                ->orWhere('post_articles.type', '');
                        });
                })
                ->whereIn('post_articles.id', function ($metaQuery): void {
                    $metaQuery->select('article_id')
                        ->from('article_meta')
                        ->where('meta_key', 'category_ids')
                        ->whereRaw(static::articleMetaContainsCategoryWpIdSql('articles.wp_post_id'));
                });
        }, 'articles_in_category_count');
    }

    public static function appendWpSyncQueueMetaSelect(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('articles.*');
        }

        return $query->selectSub(function ($subQuery): void {
            $subQuery->from('article_meta')
                ->select('meta_value')
                ->whereColumn('article_meta.article_id', 'articles.id')
                ->where('meta_key', ArticleWpSyncQueueService::META_KEY)
                ->limit(1);
        }, 'wp_sync_queue_meta');
    }

    public static function applyWpSyncQueueScope(Builder $query): Builder
    {
        return static::applyWpSyncQueueUnreviewedScope($query)->whereIn('articles.id', function ($subQuery): void {
            $subQuery->select('article_id')
                ->from('article_meta')
                ->where('meta_key', ArticleWpSyncQueueService::META_KEY)
                ->where(function ($statusQuery): void {
                    $statusQuery
                        ->where('meta_value', 'like', '%"status":"'.ArticleWpSyncQueueService::STATUS_PENDING.'"%')
                        ->orWhere('meta_value', 'like', '%"status":"'.ArticleWpSyncQueueService::STATUS_PROCESSING.'"%')
                        ->orWhere('meta_value', 'like', '%"status":"'.ArticleWpSyncQueueService::STATUS_FAILED.'"%')
                        ->orWhere('meta_value', 'like', '%"status":"'.ArticleWpSyncQueueService::STATUS_COMPLETED.'"%');
                });
        });
    }

    public static function applyWpSyncQueueListScope(Builder $query): Builder
    {
        return static::applyWpSyncQueueUnreviewedScope($query)->whereIn('articles.id', function ($subQuery): void {
            $subQuery->select('article_id')
                ->from('article_meta')
                ->where('meta_key', ArticleWpSyncQueueService::META_KEY);
        });
    }

    public static function applyWpSyncQueueUnreviewedScope(Builder $query): Builder
    {
        return $query->where(function (Builder $sub): void {
            $sub->where('is_reviewed', false)->orWhereNull('is_reviewed');
        });
    }

    public static function formatWpSyncQueueDateTime(?string $iso): ?string
    {
        return SeoDisplayTimezone::format($iso);
    }

    public static function queueTable(Table $table): Table
    {
        return $table
            ->recordAction('edit')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(__('seo-content-ai::filament.article_list.title'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (SeoArticle $record): ?string => filled($record->slug)
                        ? '/'.ltrim((string) $record->slug, '/')
                        : ($record->wp_post_id ? 'WP ID: '.$record->wp_post_id : null)),
                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('seo-content-ai::filament.article_list.domain'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('wp_sync_queue_status')
                    ->label(__('seo-content-ai::filament.article_list.queue_status'))
                    ->badge()
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::resolveWpSyncQueueStatus($record))
                    ->formatStateUsing(fn (?string $state): string => match ((string) $state) {
                        ArticleWpSyncQueueService::STATUS_PENDING => __('seo-content-ai::filament.article_list.queue_status_pending'),
                        ArticleWpSyncQueueService::STATUS_PROCESSING => __('seo-content-ai::filament.article_list.queue_status_processing'),
                        ArticleWpSyncQueueService::STATUS_COMPLETED => __('seo-content-ai::filament.article_list.queue_status_completed'),
                        ArticleWpSyncQueueService::STATUS_FAILED => __('seo-content-ai::filament.article_list.queue_status_failed'),
                        default => $state ?: '—',
                    })
                    ->color(fn (?string $state): string => match ((string) $state) {
                        ArticleWpSyncQueueService::STATUS_PENDING => 'warning',
                        ArticleWpSyncQueueService::STATUS_PROCESSING => 'info',
                        ArticleWpSyncQueueService::STATUS_COMPLETED => 'success',
                        ArticleWpSyncQueueService::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('wp_sync_queue_queued_at')
                    ->label(__('seo-content-ai::filament.article_list.queue_queued_at'))
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::formatWpSyncQueueDateTime(
                        static::resolveWpSyncQueueField($record, 'queued_at'),
                    )),
                Tables\Columns\TextColumn::make('wp_sync_queue_started_at')
                    ->label(__('seo-content-ai::filament.article_list.queue_started_at'))
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::formatWpSyncQueueDateTime(
                        static::resolveWpSyncQueueField($record, 'started_at'),
                    )),
                Tables\Columns\TextColumn::make('wp_sync_queue_finished_at')
                    ->label(__('seo-content-ai::filament.article_list.queue_finished_at'))
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::formatWpSyncQueueDateTime(
                        static::resolveWpSyncQueueField($record, 'finished_at'),
                    )),
                Tables\Columns\TextColumn::make('wp_sync_queue_error')
                    ->label(__('seo-content-ai::filament.article_list.queue_error'))
                    ->wrap()
                    ->limit(60)
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::resolveWpSyncQueueField($record, 'error'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('queue_status')
                    ->label(__('seo-content-ai::filament.article_list.queue_status'))
                    ->options([
                        ArticleWpSyncQueueService::STATUS_PENDING => __('seo-content-ai::filament.article_list.queue_status_pending'),
                        ArticleWpSyncQueueService::STATUS_PROCESSING => __('seo-content-ai::filament.article_list.queue_status_processing'),
                        ArticleWpSyncQueueService::STATUS_COMPLETED => __('seo-content-ai::filament.article_list.queue_status_completed'),
                        ArticleWpSyncQueueService::STATUS_FAILED => __('seo-content-ai::filament.article_list.queue_status_failed'),
                    ])
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.queue_all_statuses'))
                    ->query(function (Builder $query, array $data): void {
                        $status = (string) ($data['value'] ?? '');
                        if ($status === '') {
                            return;
                        }

                        $query->whereIn('articles.id', function ($subQuery) use ($status): void {
                            $subQuery->select('article_id')
                                ->from('article_meta')
                                ->where('meta_key', ArticleWpSyncQueueService::META_KEY)
                                ->where('meta_value', 'like', '%"status":"'.$status.'"%');
                        });
                    }),
            ])
            ->actions(static::getArticleQueueTableRowActions())
            ->bulkActions([]);
    }

    public static function resolveWpSyncQueuePayload(SeoArticle $record): array
    {
        $raw = $record->wp_sync_queue_meta ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            $record->loadMissing('articleMetas');
            $raw = (string) ($record->articleMetas
                ->firstWhere('meta_key', ArticleWpSyncQueueService::META_KEY)?->meta_value ?? '');
        }

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function resolveWpSyncQueueStatus(SeoArticle $record): ?string
    {
        $status = (string) (static::resolveWpSyncQueuePayload($record)['status'] ?? '');

        return $status !== '' ? $status : null;
    }

    public static function resolveWpSyncQueueField(SeoArticle $record, string $field): ?string
    {
        $value = static::resolveWpSyncQueuePayload($record)[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<array{
     *     date: string,
     *     date_label: string,
     *     count: int,
     *     articles: list<array{id: int, title: string, reviewed_time: string, edit_url: string, view_url: string|null}>
     * }>
     */
    public static function buildReviewedArticlesGrouped(): array
    {
        $query = SeoArticle::query()
            ->where('is_reviewed', true)
            ->whereNotNull('reviewed_at')
            ->whereNotIn('type', ['category', 'product_category'])
            ->where('status', '!=', 'trash')
            ->with(['site', 'articleMetas'])
            ->orderByDesc('reviewed_at');

        static::applyExcludeSkipSeoAuditScope($query);

        if (SeoAccessControl::shouldScopeToAccountOwner() && ! SeoAccessControl::isContentManager()) {
            SeoAccessControl::applyAccessibleSiteScope($query);
        }

        if (SeoAccessControl::shouldApplyGlobalSiteScope()) {
            $query->where('site_id', (int) SeoAccessControl::globalSiteId());
        }

        if (SeoAccessControl::isContentManager()) {
            static::applyContentManagerOwnershipScope($query);
        }

        /** @var array<string, array{date: string, date_label: string, count: int, articles: list<array{id: int, title: string, reviewed_time: string, edit_url: string, view_url: string|null}>}> $grouped */
        $grouped = [];

        foreach ($query->get() as $article) {
            if (! $article instanceof SeoArticle || $article->reviewed_at === null) {
                continue;
            }

            $reviewedAt = $article->reviewed_at instanceof Carbon
                ? $article->reviewed_at
                : Carbon::parse((string) $article->reviewed_at);

            $dateKey = $reviewedAt->toDateString();

            if (! isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [
                    'date' => $dateKey,
                    'date_label' => $reviewedAt->translatedFormat('d/m/Y'),
                    'count' => 0,
                    'articles' => [],
                ];
            }

            $grouped[$dateKey]['articles'][] = [
                'id' => (int) $article->id,
                'title' => (string) ($article->title ?? ''),
                'reviewed_time' => $reviewedAt->format('H:i'),
                'edit_url' => static::getUrl('edit', ['record' => $article]),
                'view_url' => static::resolveWordPressPermalink($article),
            ];
            $grouped[$dateKey]['count']++;
        }

        return array_values($grouped);
    }

    /**
     * @return array<int, Tables\Actions\Action>
     */
    public static function getArticleTableRowActionsMerged(): array
    {
        if (static::isArticlesQueueTab()) {
            return static::getArticleQueueTableRowActions();
        }

        return static::getArticleTableRowActions();
    }

    public static function isArticlesQueueTab(): bool
    {
        if (request()->query('tab') === Pages\ListArticles::TAB_QUEUE) {
            return true;
        }

        $livewire = \Livewire\Livewire::current();

        if ($livewire instanceof Pages\ListArticleSyncQueue) {
            return true;
        }

        return $livewire instanceof Pages\ListArticles
            && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE;
    }

    /**
     * @return array<int, Tables\Actions\Action>
     */
    public static function getArticleQueueTableRowActions(): array
    {
        return [
            static::makeApproveArticleTableAction(),
            Tables\Actions\Action::make('resync_sync_queue')
                ->icon('heroicon-o-arrow-path')
                ->iconButton()
                ->color('warning')
                ->tooltip(__('seo-content-ai::filament.article_list.queue_resync'))
                ->visible(fn (SeoArticle $record): bool => in_array(
                    static::resolveWpSyncQueueStatus($record),
                    [ArticleWpSyncQueueService::STATUS_PENDING, ArticleWpSyncQueueService::STATUS_FAILED, ArticleWpSyncQueueService::STATUS_PROCESSING],
                    true,
                ))
                ->action(function (SeoArticle $record, Pages\ListArticles|Pages\ListArticleSyncQueue $livewire): void {
                    $livewire->resyncArticleSyncQueue((int) $record->getKey());
                }),
            Tables\Actions\Action::make('cancel_sync_queue')
                ->icon('heroicon-o-x-circle')
                ->iconButton()
                ->color('danger')
                ->tooltip(__('seo-content-ai::filament.article_list.queue_cancel'))
                ->visible(fn (SeoArticle $record): bool => in_array(
                    static::resolveWpSyncQueueStatus($record),
                    [
                        ArticleWpSyncQueueService::STATUS_PENDING,
                        ArticleWpSyncQueueService::STATUS_FAILED,
                        ArticleWpSyncQueueService::STATUS_PROCESSING,
                        ArticleWpSyncQueueService::STATUS_COMPLETED,
                    ],
                    true,
                ))
                ->requiresConfirmation()
                ->action(function (SeoArticle $record, Pages\ListArticles|Pages\ListArticleSyncQueue $livewire): void {
                    $livewire->cancelArticleSyncQueue((int) $record->getKey());
                }),
            Tables\Actions\EditAction::make()
                ->iconButton(),
        ];
    }

    /**
     * MariaDB không hỗ trợ CAST(... AS JSON); dùng FIND_IN_SET trên mảng ID phẳng trong meta_value.
     */
    private static function articleMetaContainsCategoryWpIdSql(string $categoryWpIdExpression = '?'): string
    {
        return sprintf(
            'FIND_IN_SET(%s, REPLACE(REPLACE(REPLACE(`meta_value`, " ", ""), "[", ""), "]", "")) > 0',
            $categoryWpIdExpression,
        );
    }

    /**
     * @return array<int|string, string>
     */
    public static function buildCategoryFilterOptions(Pages\ListArticles $livewire): array
    {
        $siteId = (int) ($livewire->tableFilters['site_id']['value'] ?? SeoAccessControl::globalSiteId() ?? 0);

        $query = SeoArticle::query()
            ->whereIn('type', ['category', 'product_category'])
            ->where('wp_post_id', '>', 0)
            ->orderBy('title');

        $postType = trim((string) ($livewire->tableFilters['post_type']['value'] ?? ''));
        if ($postType === 'post') {
            $query->where('type', 'category');
        } elseif ($postType === 'product') {
            $query->where('type', 'product_category');
        } elseif ($postType === 'page') {
            return [];
        }

        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        } elseif (SeoAccessControl::shouldScopeToAccountOwner()) {
            SeoAccessControl::applyAccessibleSiteScope($query);
        }

        return $query
            ->get(['wp_post_id', 'title', 'type'])
            ->mapWithKeys(function (SeoArticle $term): array {
                $wpId = (int) ($term->wp_post_id ?? 0);
                if ($wpId <= 0) {
                    return [];
                }

                $title = trim((string) ($term->title ?? ''));
                $label = $title !== '' ? $title : __('seo-content-ai::filament.article_list.category_fallback', ['id' => $wpId]);

                if ($term->type === 'product_category') {
                    $label = '[SP] '.$label;
                }

                return [$wpId => $label];
            })
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::applyArticleAccessScopes(
            parent::getEloquentQuery()->with(static::articleEagerLoads()),
            includeGlobalSiteScope: true,
            includeReviewScope: true,
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        // Trang edit/view cần ĐẦY ĐỦ articleMetas (seo_meta_description, wp_product_gallery...).
        // Không dùng whitelist articleEagerLoads() ở đây — whitelist đó chỉ dành cho trang list,
        // vì relation đã loaded sẽ khiến mọi loadMissing('articleMetas') sau đó bị bỏ qua.
        return static::applyArticleAccessScopes(
            parent::getEloquentQuery()->with(['user', 'site', 'articleMetas']),
            includeGlobalSiteScope: false,
            includeReviewScope: false,
            includeContentManagerOwnershipScope: false,
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function articleEagerLoads(): array
    {
        return [
            'user',
            'site',
            'articleMetas' => static fn ($query) => $query->whereIn('meta_key', [
                'seo_focus_keyword',
                'seo_rule_violations',
                self::META_SKIP_SEO_AUDIT,
                'wp_post_images',
                'wp_featured_image_url',
                'wp_permalink',
                'wp_post_type',
            ]),
        ];
    }

    public static function applyArticleAccessScopes(
        Builder $query,
        bool $includeGlobalSiteScope = true,
        bool $includeReviewScope = true,
        bool $includeContentManagerOwnershipScope = true,
    ): Builder {
        if (SeoAccessControl::shouldScopeToAccountOwner() && ! SeoAccessControl::isContentManager()) {
            SeoAccessControl::applyAccessibleSiteScope($query);
        }

        if ($includeGlobalSiteScope && SeoAccessControl::shouldApplyGlobalSiteScope()) {
            $query->where('site_id', (int) SeoAccessControl::globalSiteId());
        }

        if ($includeContentManagerOwnershipScope && SeoAccessControl::isContentManager()) {
            static::applyContentManagerOwnershipScope($query);
        }

        if ($includeReviewScope
            && ! SeoAccessControl::isContentManager()
            && ! SeoAccessControl::canAccessManagerFeatures()) {
            $query->where(function (Builder $sub): void {
                $sub->where('is_reviewed', false)->orWhereNull('is_reviewed');
            });
        }

        return $query;
    }

    public static function canContentManagerAccessArticle(SeoArticle $article): bool
    {
        return static::canContentManagerAccessArticleId((int) $article->getKey());
    }

    public static function canContentManagerAccessArticleId(int $articleId): bool
    {
        if (! SeoAccessControl::isContentManager()) {
            return true;
        }

        return static::applyContentManagerOwnershipScope(
            SeoArticle::query()->whereKey($articleId),
        )->exists();
    }

    private static function applyContentManagerOwnershipScope(Builder $query): Builder
    {
        $userId = (int) auth()->id();

        return $query->where(function (Builder $ownership) use ($userId): void {
            $ownership
                ->where('user_id', $userId)
                ->orWhereIn('id', SeoProjectTask::query()
                    ->whereNotNull('article_id')
                    ->whereIn('project_id', SeoProject::query()
                        ->where('user_id', $userId)
                        ->select('id'))
                    ->select('article_id'));
        });
    }

    public static function syncGlobalSiteForArticle(SeoArticle $article): void
    {
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return;
        }

        if (SeoAccessControl::globalSiteId() === $siteId) {
            return;
        }

        SeoAccessControl::setGlobalSiteId($siteId);
    }

    private static function resolveThumbnailUrl(SeoArticle $record): ?string
    {
        $record->loadMissing('articleMetas');

        $featured = trim((string) ($record->articleMetas->firstWhere('meta_key', 'wp_featured_image_url')?->meta_value ?? ''));
        if ($featured !== '') {
            return $featured;
        }

        $rawImages = $record->articleMetas->firstWhere('meta_key', 'wp_post_images')?->meta_value ?? '';
        if (! is_string($rawImages) || trim($rawImages) === '') {
            return null;
        }

        $decoded = json_decode($rawImages, true);
        if (! is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $src = trim((string) ($row['src'] ?? ''));
            if ($src !== '') {
                return $src;
            }
        }

        return null;
    }

    private static function resolveWordPressPermalink(SeoArticle $record): ?string
    {
        $record->loadMissing('site', 'articleMetas');

        $cached = trim((string) ($record->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
        $slug = trim((string) ($record->slug ?? ''));

        $resolved = app(WordPressPermalinkBuilder::class)->resolve($record, $cached, $slug !== '' ? $slug : null);
        if ($resolved !== '') {
            return $resolved;
        }

        $site = $record->site;
        if (! $site instanceof Site) {
            return null;
        }

        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '' || $slug === '') {
            return null;
        }

        return rtrim($base, '/').'/'.ltrim($slug, '/');
    }

    /**
     * Hàng 1: xem WP · skip SEO · duyệt — Hàng 2: gán dự án · sửa · xóa (lưới 3 cột trên list).
     *
     * @return array<int, Tables\Actions\Action>
     */
    public static function getArticleTableRowActions(): array
    {
        return [
            Tables\Actions\Action::make('quick_view_wp')
                ->icon('heroicon-o-eye')
                ->iconButton()
                ->tooltip(__('seo-content-ai::filament.article_list.view_on_wordpress'))
                ->url(fn (SeoArticle $record): string => static::resolveWordPressPermalink($record) ?? '#')
                ->openUrlInNewTab()
                ->disabled(fn (SeoArticle $record): bool => blank(static::resolveWordPressPermalink($record))),
            Tables\Actions\Action::make('toggle_skip_seo_audit')
                ->icon(fn (SeoArticle $record): string => static::articleIsSkipSeoAudit($record)
                    ? 'heroicon-o-eye'
                    : 'heroicon-o-eye-slash')
                ->iconButton()
                ->color(fn (SeoArticle $record): string => static::articleIsSkipSeoAudit($record) ? 'warning' : 'gray')
                ->tooltip(fn (SeoArticle $record): string => static::articleIsSkipSeoAudit($record)
                    ? __('seo-content-ai::filament.article_list.unskip_seo_audit')
                    : __('seo-content-ai::filament.article_list.skip_seo_audit'))
                ->action(function (SeoArticle $record): void {
                    $skipped = static::toggleSkipSeoAudit($record);

                    Notification::make()
                        ->title(
                            $skipped
                                ? __('seo-content-ai::filament.article_list.seo_audit_skipped_on')
                                : __('seo-content-ai::filament.article_list.seo_audit_skipped_off'),
                        )
                        ->success()
                        ->send();
                }),
            static::makeApproveArticleTableAction(),
            Tables\Actions\Action::make('view_content_project_runs')
                ->icon('heroicon-o-queue-list')
                ->iconButton()
                ->color('info')
                ->tooltip('View content project runs')
                ->visible(function (SeoArticle $record): bool {
                    return static::articleAssignedContentProjectId($record) !== null;
                })
                ->url(function (SeoArticle $record): ?string {
                    $projectId = static::articleAssignedContentProjectId($record);
                    if ($projectId === null) {
                        return null;
                    }

                    $project = SeoProject::query()->find($projectId);

                    return $project instanceof SeoProject
                        ? SeoProjectResource::getRunHistoryUrl($project)
                        : null;
                }),
            Tables\Actions\Action::make('assign_to_content_project')
                ->icon('heroicon-o-folder-plus')
                ->iconButton()
                ->color('warning')
                ->tooltip(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                ->visible(fn (SeoArticle $record): bool => SeoAccessControl::canMutateInSeoPanel()
                    && ! static::articleIsInContentProject($record))
                ->form(function (SeoArticle $record): array {
                    $siteId = static::resolveArticleSiteId($record);

                    if (static::resolveDirectAssignContentProjectId($siteId) !== null) {
                        return static::assignArticleTaskFormFields();
                    }

                    return static::assignContentProjectFormFields(
                        fn (): ?int => $siteId,
                    );
                })
                ->requiresConfirmation(false)
                ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                ->modalDescription(__('seo-content-ai::filament.article_list.assign_to_content_project_description'))
                ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
                ->action(function (SeoArticle $record, array $data): void {
                    $siteId = static::resolveArticleSiteId($record);
                    $projectId = static::resolveDirectAssignContentProjectId($siteId)
                        ?? (int) ($data['project_id'] ?? 0);
                    $summary = static::assignArticlesFromFormData(
                        Collection::make([$record]),
                        $projectId,
                        $data,
                    );

                    Notification::make()
                        ->title(__('seo-content-ai::filament.article_list.assign_completed'))
                        ->body(static::buildAssignContentProjectBody($summary))
                        ->success()
                        ->send();
                }),
            Tables\Actions\EditAction::make()
                ->iconButton(),
            Tables\Actions\DeleteAction::make()
                ->iconButton(),
        ];
    }

    public static function markArticleReviewed(SeoArticle $article): int
    {
        $deletedCount = static::deleteLocalMediaForArticle($article);

        $article->forceFill([
            'is_reviewed' => true,
            'reviewed_at' => Carbon::now(),
        ])->save();

        app(ArticleWpSyncQueueService::class)->clearQueueEntry($article->fresh() ?? $article);

        return $deletedCount;
    }

    public static function runApproveArticleAction(SeoArticle $record): void
    {
        if (SeoAccessControl::isContentManager()) {
            static::submitStaffEditingComplete($record);

            return;
        }

        $deletedCount = static::markArticleReviewed($record);

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.article_reviewed'))
            ->body(__('seo-content-ai::filament.article_list.deleted_local_images', ['count' => $deletedCount]))
            ->success()
            ->send();
    }

    public static function makeApproveArticleTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve_article')
            ->icon('heroicon-o-check-badge')
            ->iconButton()
            ->color(fn (SeoArticle $record): string => (bool) $record->is_reviewed ? 'success' : 'gray')
            ->tooltip(fn (SeoArticle $record): string => (bool) $record->is_reviewed
                ? __('seo-content-ai::filament.article_list.already_reviewed')
                : (SeoAccessControl::isContentManager()
                    ? __('seo-content-ai::filament.article_list.staff_mark_editing_done')
                    : __('seo-content-ai::filament.article_list.mark_reviewed')))
            ->visible(function (SeoArticle $record): bool {
                if (SeoAccessControl::canAccessPlannerFeatures()) {
                    return true;
                }

                if (! SeoAccessControl::isContentManager() || ! static::articleIsInContentProject($record)) {
                    return false;
                }

                $user = auth()->user();

                return $user instanceof User
                    && ! app(SeoProjectApprovalService::class)->contentManagerHasSubmitted($record, $user);
            })
            ->requiresConfirmation()
            ->modalDescription(fn (SeoArticle $record): string => SeoAccessControl::isContentManager()
                ? __('seo-content-ai::filament.article_list.staff_mark_editing_done_confirm')
                : __('seo-content-ai::filament.article_list.review_article_description'))
            ->action(function (SeoArticle $record, Pages\ListArticles|Pages\ListArticleSyncQueue|null $livewire = null): void {
                static::runApproveArticleAction($record);

                if ($livewire instanceof Pages\ListArticles || $livewire instanceof Pages\ListArticleSyncQueue) {
                    $livewire->resetTable();
                }
            });
    }

    public static function submitStaffEditingComplete(SeoArticle $article, ?User $user = null): void
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $service = app(SeoProjectApprovalService::class);
        $alreadySubmitted = $service->contentManagerHasSubmitted($article, $user);

        try {
            $project = $service->approveLinkedProject($article, $user);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.staff_submit_failed'))
                ->body(collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($alreadySubmitted) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.staff_mark_editing_done_already'))
                ->info()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.staff_mark_editing_done_success'))
            ->body(__('seo-content-ai::filament.article_list.staff_mark_editing_done_success_body', [
                'title' => (string) $article->title,
                'project' => (string) $project->name,
            ]))
            ->success()
            ->send();
    }

    public static function markArticleUnreviewed(SeoArticle $article): void
    {
        $article->forceFill([
            'is_reviewed' => false,
            'reviewed_at' => null,
        ])->save();
    }

    /**
     * Bật/tắt bỏ qua SEO Audit + ẩn khỏi Article list. Trả về true nếu sau thao tác đang skip.
     */
    public static function toggleSkipSeoAudit(SeoArticle $article): bool
    {
        if (static::articleIsSkipSeoAudit($article)) {
            $article->articleMetas()
                ->where('meta_key', self::META_SKIP_SEO_AUDIT)
                ->delete();
            $article->unsetRelation('articleMetas');

            return false;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_SKIP_SEO_AUDIT],
            ['meta_value' => '1'],
        );
        $article->unsetRelation('articleMetas');

        return true;
    }

    public static function articleIsSkipSeoAudit(SeoArticle $article): bool
    {
        if ($article->relationLoaded('articleMetas')) {
            return $article->articleMetas
                ->contains(static function ($meta): bool {
                    if ((string) ($meta->meta_key ?? '') !== self::META_SKIP_SEO_AUDIT) {
                        return false;
                    }

                    $value = strtolower(trim((string) ($meta->meta_value ?? '')));

                    return in_array($value, ['1', 'true'], true);
                });
        }

        return $article->articleMetas()
            ->where('meta_key', self::META_SKIP_SEO_AUDIT)
            ->where(function (Builder $valueQuery): void {
                $valueQuery
                    ->where('meta_value', '1')
                    ->orWhere('meta_value', 1)
                    ->orWhere('meta_value', 'true');
            })
            ->exists();
    }

    /**
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public static function applyExcludeSkipSeoAuditScope(Builder $query): Builder
    {
        return $query->whereDoesntHave('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', self::META_SKIP_SEO_AUDIT)
                ->where(function (Builder $valueQuery): void {
                    $valueQuery
                        ->where('meta_value', '1')
                        ->orWhere('meta_value', 1)
                        ->orWhere('meta_value', 'true');
                });
        });
    }

    /**
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public static function applyOnlySkipSeoAuditScope(Builder $query): Builder
    {
        return $query->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', self::META_SKIP_SEO_AUDIT)
                ->where(function (Builder $valueQuery): void {
                    $valueQuery
                        ->where('meta_value', '1')
                        ->orWhere('meta_value', 1)
                        ->orWhere('meta_value', 'true');
                });
        });
    }

    /**
     * @deprecated Dùng toggleSkipSeoAudit — giữ để tương thích chỗ còn gọi cũ.
     */
    public static function toggleSkipSeoScore(SeoArticle $article): bool
    {
        return static::toggleSkipSeoAudit($article);
    }

    private static function deleteLocalMediaForArticle(SeoArticle $article): int
    {
        $mediaRows = SeoMedia::query()
            ->where('article_id', (int) $article->id)
            ->get(['id', 'path']);

        if ($mediaRows->isEmpty()) {
            return 0;
        }

        $mediaIds = [];
        foreach ($mediaRows as $media) {
            $mediaIds[] = (int) $media->id;
            $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
            if ($path !== '' && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }

        if ($mediaIds !== []) {
            SeoMediaProcessingHistory::query()
                ->whereIn('media_ref_id', $mediaIds)
                ->where('source', SeoMediaProcessingHistory::SOURCE_LOCAL)
                ->delete();

            SeoMedia::query()->whereIn('id', $mediaIds)->delete();
        }

        return count($mediaIds);
    }

    public static function resolveArticleSiteId(SeoArticle $article): ?int
    {
        $siteId = (int) ($article->site_id ?? 0);

        if ($siteId > 0) {
            return $siteId;
        }

        return SeoAccessControl::globalSiteId();
    }

    /**
     * @param  Collection<int, mixed>  $records
     */
    public static function resolveBulkArticlesSiteId(Collection $records): ?int
    {
        $siteIds = $records
            ->filter(static fn (mixed $record): bool => $record instanceof SeoArticle)
            ->map(static fn (SeoArticle $article): ?int => static::resolveArticleSiteId($article))
            ->filter(static fn (?int $siteId): bool => $siteId !== null && $siteId > 0)
            ->unique()
            ->values();

        return $siteIds->count() === 1 ? (int) $siteIds->first() : null;
    }

    public static function resolveDirectAssignContentProjectId(?int $recordSiteId = null): ?int
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        $projectId = SeoAccessControl::globalContentProjectId();

        if ($globalSiteId === null || $projectId === null) {
            return null;
        }

        if ($recordSiteId !== null && $recordSiteId > 0 && $recordSiteId !== $globalSiteId) {
            return null;
        }

        return $projectId;
    }

    /**
     * @param  callable(Get=): ?int  $resolveSiteId
     * @param  (callable(): ?string)|null  $resolveHelperText
     */
    public static function assignContentProjectSelectField(
        callable $resolveSiteId,
        ?callable $resolveHelperText = null,
        string $fieldName = 'project_id',
    ): Forms\Components\Select {
        $select = Forms\Components\Select::make($fieldName)
            ->label(__('seo-content-ai::filament.article_list.content_project'))
            ->options(fn (Get $get): array => static::contentProjectOptions(
                static::resolveAssignContentProjectSiteId($resolveSiteId, $get),
            ))
            ->required()
            ->searchable()
            ->preload()
            ->native(false)
            ->suffixAction(
                FormAction::make('quick_create_content_project')
                    ->label(__('seo-content-ai::filament.article_list.quick_create_content_project'))
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label(__('seo-content-ai::filament.projects.assign_writer'))
                            ->options(fn (): array => SeoProjectResource::userSelectOptions())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn (): ?int => SeoAccessControl::isContentManager() ? (int) auth()->id() : null)
                            ->disabled(fn (): bool => SeoAccessControl::isContentManager())
                            ->dehydrated()
                            ->native(false),
                    ])
                    ->action(function (array $data, Set $set, Get $get) use ($resolveSiteId, $fieldName): void {
                        $siteId = (int) (static::resolveAssignContentProjectSiteId($resolveSiteId, $get) ?? 0);
                        if ($siteId <= 0) {
                            Notification::make()
                                ->title(__('seo-content-ai::filament.article_list.quick_create_content_project_failed'))
                                ->body(__('seo-content-ai::filament.article_list.assign_projects_mixed_domains'))
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $project = static::quickCreateContentProject($siteId, (int) ($data['user_id'] ?? 0));
                            $set($fieldName, $project->id);

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
                    }),
            );

        if ($resolveHelperText !== null) {
            $select->helperText(fn (): ?string => $resolveHelperText());
        }

        return $select;
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function assignTaskTypeSelectField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('type')
            ->label(__('seo-content-ai::filament.projects.article_type'))
            ->options(SeoProjectTask::typeOptions())
            ->default(SeoProjectTask::TYPE_REWRITE)
            ->required()
            ->native(false)
            ->live();
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function assignRewriteModeFormFields(): array
    {
        return [
            Forms\Components\Select::make('rewrite_mode')
                ->label(__('seo-content-ai::filament.projects.rewrite_mode'))
                ->options(SeoProjectTask::rewriteModeOptions())
                ->default(SeoProjectTask::REWRITE_MODE_KEYWORD)
                ->required()
                ->native(false)
                ->live()
                ->visible(fn (Get $get): bool => static::normalizeAssignTaskType($get('type')) === SeoProjectTask::TYPE_REWRITE),
            Forms\Components\Textarea::make('rewrite_notes')
                ->label(__('seo-content-ai::filament.projects.rewrite_notes'))
                ->placeholder(__('seo-content-ai::filament.projects.rewrite_notes_placeholder'))
                ->rows(3)
                ->visible(fn (Get $get): bool => static::normalizeAssignTaskType($get('type')) === SeoProjectTask::TYPE_REWRITE
                    && SeoProjectTask::normalizeRewriteMode($get('rewrite_mode')) === SeoProjectTask::REWRITE_MODE_CONTENT)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function assignArticleTaskFormFields(): array
    {
        return array_merge(
            [static::assignTaskTypeSelectField()],
            static::assignRewriteModeFormFields(),
        );
    }

    /**
     * @param  callable(Get=): ?int  $resolveSiteId
     * @param  (callable(): ?string)|null  $resolveHelperText
     * @return list<Forms\Components\Component>
     */
    public static function assignContentProjectFormFields(
        callable $resolveSiteId,
        ?callable $resolveHelperText = null,
    ): array {
        return array_merge(
            [static::assignContentProjectSelectField($resolveSiteId, $resolveHelperText)],
            static::assignArticleTaskFormFields(),
        );
    }

    public static function normalizeAssignTaskType(mixed $value): string
    {
        $type = trim((string) $value);

        return in_array($type, [
            SeoProjectTask::TYPE_REWRITE,
            SeoProjectTask::TYPE_NEW_KEYWORD,
            SeoProjectTask::TYPE_NEW_TITLE,
            SeoProjectTask::TYPE_IMPROVE,
        ], true)
            ? $type
            : SeoProjectTask::TYPE_REWRITE;
    }

    /**
     * @param  SupportCollection<int, SeoArticle>|Collection<int, SeoArticle>  $records
     * @param  array<string, mixed>  $data
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function assignArticlesFromFormData(
        SupportCollection $records,
        int $projectId,
        array $data,
    ): array {
        return static::assignArticlesToContentProject(
            $records,
            $projectId,
            static::normalizeAssignTaskType($data['type'] ?? null),
            is_string($data['rewrite_mode'] ?? null) ? $data['rewrite_mode'] : null,
            is_string($data['rewrite_notes'] ?? null) ? $data['rewrite_notes'] : null,
        );
    }

    /**
     * @param  callable(Get=): ?int  $resolveSiteId
     */
    private static function resolveAssignContentProjectSiteId(callable $resolveSiteId, ?Get $get = null): ?int
    {
        if ($get instanceof Get && $resolveSiteId instanceof \Closure) {
            $reflection = new \ReflectionFunction($resolveSiteId);
            $firstParameter = $reflection->getParameters()[0] ?? null;

            if ($firstParameter !== null) {
                $type = $firstParameter->getType();

                if ($type instanceof \ReflectionNamedType && $type->getName() === Get::class) {
                    return $resolveSiteId($get);
                }
            }
        }

        return $resolveSiteId();
    }

    public static function quickCreateContentProject(int $siteId, ?int $userId = null): SeoProject
    {
        if ($siteId <= 0) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.article_list.quick_create_content_project_no_domain'));
        }

        $userId = (int) ($userId ?: auth()->id());
        if ($userId <= 0) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.article_list.quick_create_content_project_no_user'));
        }

        $currentMonth = Carbon::now()->startOfMonth();
        $targetMonth = $currentMonth->copy();

        $existingProject = SeoProject::query()
            ->where('site_id', $siteId)
            ->where('user_id', $userId)
            ->whereDate('month', $currentMonth->format('Y-m-d'))
            ->exists();

        if ($existingProject) {
            $targetMonth = $currentMonth->copy()->addMonth();
        }

        return SeoProject::query()->create([
            'name' => SeoProject::defaultNameFromMonth($targetMonth),
            'user_id' => $userId,
            'site_id' => $siteId,
            'month' => $targetMonth->format('Y-m-d'),
            'status' => SeoProject::STATUS_MANUAL,
            'total_tasks' => 0,
            'description' => null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function contentProjectOptions(?int $siteId = null): array
    {
        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return [];
        }

        $query = SeoProject::query()
            ->with(['site', 'user'])
            ->orderByDesc('month')
            ->orderBy('id')
            ->where('site_id', $siteId);

        if (SeoAccessControl::isContentManager()) {
            $query->where('user_id', (int) auth()->id());
        }

        return $query
            ->get()
            ->filter(fn (SeoProject $project): bool => $project->canRegisterMoreTasks())
            ->mapWithKeys(function (SeoProject $project): array {
                $remaining = $project->remainingTaskCapacity();
                $domain = trim((string) ($project->site?->domain ?? ''));
                $writer = $project->user instanceof User
                    ? SeoProjectResource::formatUserSelectLabel($project->user)
                    : '';

                return [
                    (int) $project->id => sprintf(
                        '%s · %s · %s (%s, còn %d)',
                        (string) $project->name,
                        $domain !== '' ? $domain : '—',
                        $writer !== '' ? $writer : '—',
                        $project->monthCarbon()->format('m/Y'),
                        $remaining,
                    ),
                ];
            })
            ->all();
    }

    public static function resolveArticleProjectSourceContent(SeoArticle $article): string
    {
        $sourceContent = trim((string) ($article->title ?? ''));
        if ($sourceContent === '') {
            return 'Article #'.(int) $article->id;
        }

        return $sourceContent;
    }

    public static function resolveAssignSourceContent(SeoArticle $article, string $taskType): string
    {
        if (static::normalizeAssignTaskType($taskType) === SeoProjectTask::TYPE_NEW_KEYWORD) {
            $keyword = trim((string) (app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? ''));
            if ($keyword !== '') {
                return $keyword;
            }
        }

        return static::resolveArticleProjectSourceContent($article);
    }

    /**
     * SEO Audit candidates only: exclude reviewed + bài đã gắn Content Project (`article_id`)
     * + bài có meta skip_seo_audit.
     *
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public static function applySeoAuditCandidateScope(Builder $query): Builder
    {
        $query->where(function (Builder $sub): void {
            $sub->where('is_reviewed', false)->orWhereNull('is_reviewed');
        });

        // Chỉ match article_id — assign rewrite/improve luôn set cột này.
        // Không OR theo title: correlated scan trên cả domain dễ timeout → scan_failed.
        $query->whereNotExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('seo_project_tasks')
                ->whereColumn('seo_project_tasks.article_id', 'articles.id');
        });

        return static::applyExcludeSkipSeoAuditScope($query);
    }

    public static function articleAssignedContentProjectId(SeoArticle $article): ?int
    {
        $directProjectId = SeoProjectTask::query()
            ->where('article_id', (int) $article->id)
            ->value('project_id');
        if ($directProjectId !== null) {
            return (int) $directProjectId;
        }

        $needle = mb_strtolower(trim(static::resolveArticleProjectSourceContent($article)));
        $articleSiteId = static::resolveArticleSiteId($article) ?? 0;

        $query = SeoProjectTask::query()
            ->whereIn('type', [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE])
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle]);

        if ($articleSiteId > 0) {
            $query->where(function (Builder $builder) use ($articleSiteId): void {
                $builder
                    ->where('site_id', $articleSiteId)
                    ->orWhereNull('site_id');
            });
        }

        $projectId = $query->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }

    public static function articleIsInContentProject(SeoArticle $article): bool
    {
        return static::articleAssignedContentProjectId($article) !== null;
    }

    public static function articleContentProjectUrl(SeoArticle $article): ?string
    {
        $projectId = static::articleAssignedContentProjectId($article);
        if ($projectId === null) {
            return null;
        }

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return null;
        }

        if (! SeoProjectResource::canView($project)) {
            return null;
        }

        return SeoProjectResource::projectRecordUrl($project);
    }

    /**
     * @param  SupportCollection<int, SeoArticle>|Collection<int, SeoArticle>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function assignArticlesToContentProject(
        SupportCollection $records,
        int $projectId,
        string $taskType = SeoProjectTask::TYPE_REWRITE,
        ?string $rewriteMode = null,
        ?string $rewriteNotes = null,
    ): array {
        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return [
                'added' => 0,
                'duplicate' => 0,
                'overflow' => $records->count(),
                'domain_mismatch' => 0,
                'already_in_project' => 0,
            ];
        }

        if (! $project->isExecutionMonthOpen()) {
            return [
                'added' => 0,
                'duplicate' => 0,
                'overflow' => $records->count(),
                'domain_mismatch' => 0,
                'already_in_project' => 0,
            ];
        }

        $records = $records
            ->filter(fn (mixed $record): bool => $record instanceof SeoArticle)
            ->values();

        $added = 0;
        $duplicate = 0;
        $overflow = 0;
        $domainMismatch = 0;
        $alreadyInProject = 0;
        $projectSiteId = (int) ($project->site_id ?? 0);
        $targetProjectId = (int) $project->id;
        $normalizedTaskType = static::normalizeAssignTaskType($taskType);
        $normalizedRewriteMode = SeoProjectTask::normalizeRewriteMode($rewriteMode);
        $normalizedRewriteNotes = $normalizedRewriteMode === SeoProjectTask::REWRITE_MODE_CONTENT
            ? (trim((string) ($rewriteNotes ?? '')) !== '' ? trim((string) $rewriteNotes) : null)
            : null;

        DB::connection($project->getConnectionName())->transaction(function () use ($project, $records, $projectSiteId, $targetProjectId, $normalizedTaskType, $normalizedRewriteMode, $normalizedRewriteNotes, &$added, &$duplicate, &$overflow, &$domainMismatch, &$alreadyInProject): void {
            $project->refresh();
            $max = $project->maxTasksAllowed();
            $currentTotal = $project->registeredTaskCount();

            $existingKeys = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->get(['site_id', 'type', 'source_content'])
                ->map(static fn (SeoProjectTask $task): string => (int) $task->site_id.'|'.(string) $task->type.'|'.mb_strtolower(trim((string) $task->source_content)))
                ->all();
            $existingMap = array_fill_keys($existingKeys, true);

            foreach ($records as $record) {
                if ($currentTotal >= $max) {
                    $overflow++;

                    continue;
                }

                $assignedProjectId = static::articleAssignedContentProjectId($record);
                if ($assignedProjectId !== null) {
                    if ($assignedProjectId === $targetProjectId) {
                        $duplicate++;
                    } else {
                        $alreadyInProject++;
                    }

                    continue;
                }

                $articleSiteId = static::resolveArticleSiteId($record) ?? 0;
                if ($projectSiteId > 0 && $articleSiteId !== $projectSiteId) {
                    $domainMismatch++;

                    continue;
                }

                $sourceContent = static::resolveAssignSourceContent($record, $normalizedTaskType);

                $siteId = $projectSiteId > 0 ? $projectSiteId : $articleSiteId;
                $key = $siteId.'|'.$normalizedTaskType.'|'.mb_strtolower($sourceContent);
                if (isset($existingMap[$key])) {
                    $duplicate++;

                    continue;
                }

                $payload = [
                    'project_id' => (int) $project->id,
                    'site_id' => $siteId > 0 ? $siteId : null,
                    'type' => $normalizedTaskType,
                    'source_content' => $sourceContent,
                    'description' => null,
                    'target_date' => $project->monthCarbon()->copy()->addDays($currentTotal)->format('Y-m-d'),
                    'status' => SeoProjectTask::STATUS_PENDING,
                ];

                if (SeoProjectTask::isNewArticleType($normalizedTaskType)) {
                    $payload['post_type'] = SeoProjectTask::POST_TYPE_ARTICLE;
                    $payload['article_id'] = null;
                } else {
                    $payload['article_id'] = (int) $record->id;
                }

                if ($normalizedTaskType === SeoProjectTask::TYPE_REWRITE) {
                    $payload['rewrite_mode'] = $normalizedRewriteMode;
                    $payload['rewrite_notes'] = $normalizedRewriteNotes;
                }

                SeoProjectTask::query()->create($payload);

                $existingMap[$key] = true;
                $currentTotal++;
                $added++;
            }

            $project->syncTotalTasksCounter();
        });

        if ($added > 0) {
            $project = $project->fresh();
            app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($project);
            app(SeoNotificationService::class)->notifyProjectOwnerTasksAdded($project, $added);
        }

        return [
            'added' => $added,
            'duplicate' => $duplicate,
            'overflow' => $overflow,
            'domain_mismatch' => $domainMismatch,
            'already_in_project' => $alreadyInProject,
        ];
    }

    /**
     * @param  array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project?:int}  $summary
     */
    public static function buildAssignContentProjectBody(array $summary): string
    {
        return __('seo-content-ai::filament.article_list.assign_completed_body', [
            'added' => (int) ($summary['added'] ?? 0),
            'duplicate' => (int) ($summary['duplicate'] ?? 0),
            'overflow' => (int) ($summary['overflow'] ?? 0),
            'domain_mismatch' => (int) ($summary['domain_mismatch'] ?? 0),
            'already_in_project' => (int) ($summary['already_in_project'] ?? 0),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessContentFeatures();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessContentFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.articles');
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.article');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.articles');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticles::route('/'),
            'queue' => Pages\ListArticleSyncQueue::route('/queue'),
            'trash' => Pages\ListArticlesTrash::route('/trash'),
            'domain-mismatch' => Pages\ArticleDomainMismatch::route('/{record}/domain-mismatch'),
            'access-denied' => Pages\ArticleAccessDenied::route('/{record}/access-denied'),
            'prompts' => Pages\ViewArticlePrompts::route('/{record}/prompts'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
