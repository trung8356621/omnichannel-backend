<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoMediaProcessingHistory;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use App\Addons\SeoContentAi\Services\SeoProjectApprovalService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
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

class ArticleResource extends Resource
{
    protected static ?string $model = SeoArticle::class;

    protected static ?string $slug = 'articles';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Articles';

    protected static ?string $modelLabel = 'Article';

    protected static ?string $pluralModelLabel = 'Articles';

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
                            return '/' . ltrim((string) $record->slug, '/');
                        }

                        if ($record->wp_post_id) {
                            return 'WP ID: ' . $record->wp_post_id;
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
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? Str::ucfirst(str_replace('_', ' ', $state))
                        : '—')
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('author')
                    ->label(__('seo-content-ai::filament.article_list.author'))
                    ->badge()
                    ->getStateUsing(function (SeoArticle $record): string {
                        if ($record->user_id === null) {
                            return __('seo-content-ai::filament.article_list.system');
                        }

                        $record->loadMissing('user');

                        return (string) ($record->user?->name ?? $record->user?->email ?? __('seo-content-ai::filament.article_list.system'));
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
                Tables\Columns\IconColumn::make('is_reviewed')
                    ->label(__('seo-content-ai::filament.article_list.reviewed'))
                    ->boolean()
                    ->alignCenter()
                    ->sortable()
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
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('site_id')
                    ->label(__('seo-content-ai::filament.article_list.domain'))
                    ->visible(fn (): bool => ! SeoAccessControl::hasGlobalSiteScope())
                    ->options(function (): array {
                        $query = Site::query()->orderBy('domain');

                        if (auth()->user()?->role !== 'admin') {
                            $query->where('user_id', auth()->id());
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
                SelectFilter::make('type')
                    ->label(__('seo-content-ai::filament.article_list.post_type'))
                    ->options([
                        'article' => __('seo-content-ai::filament.article_list.post_type_article'),
                        'product' => __('seo-content-ai::filament.article_list.post_type_product'),
                        'category' => __('seo-content-ai::filament.article_list.post_type_category'),
                        'product_category' => __('seo-content-ai::filament.article_list.post_type_product_category'),
                    ])
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_types'))
                    ->indicator(__('seo-content-ai::filament.article_list.type'))
                    ->query(function (Builder $query, array $data): void {
                        $type = $data['value'] ?? null;
                        if (! is_string($type) || $type === '') {
                            return;
                        }

                        if ($type === 'article') {
                            $query->where(function (Builder $q): void {
                                $q->where('type', 'article')->orWhereNull('type');
                            });

                            return;
                        }

                        $query->where('type', $type);
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
                SelectFilter::make('is_reviewed')
                    ->label(__('seo-content-ai::filament.article_list.review'))
                    ->visible(fn (): bool => SeoAccessControl::canAccessManagerFeatures())
                    ->options([
                        '0' => __('seo-content-ai::filament.article_list.not_reviewed'),
                        '1' => __('seo-content-ai::filament.article_list.reviewed'),
                    ])
                    ->default('0')
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.not_reviewed'))
                    ->indicator(__('seo-content-ai::filament.article_list.review'))
                    ->query(function (Builder $query, array $data): void {
                        $value = (string) ($data['value'] ?? '0');
                        if ($value === '1') {
                            $query->where('is_reviewed', true);

                            return;
                        }

                        $query->where(function (Builder $sub): void {
                            $sub->where('is_reviewed', false)->orWhereNull('is_reviewed');
                        });
                    }),
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

                        $query->whereHas('links', function (Builder $linkQuery) use ($url, $type): void {
                            $linkQuery->where('url', $url);

                            if (is_string($type) && $type !== '') {
                                $linkQuery->where('type', $type);
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

                        return __('seo-content-ai::filament.article_list.link') . ($typeLabel !== '' ? ' ' . $typeLabel : '') . ': ' . Str::limit($url, 48);
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
                            $query->whereHas('keywords', function (Builder $keywordQuery) use ($keywordId): void {
                                $keywordQuery
                                    ->where('keywords.id', $keywordId)
                                    ->where('article_keyword.is_main', true);
                            });

                            return;
                        }

                        if ($usage === 'internal_link' || ($data['internal_link_only'] ?? '') === '1') {
                            $query->whereHas('links', function (Builder $linkQuery) use ($keywordId): void {
                                $linkQuery
                                    ->where('keyword_id', $keywordId)
                                    ->where('type', 'internal');
                            });

                            return;
                        }

                        $query->whereHas('keywords', function (Builder $keywordQuery) use ($keywordId): void {
                            $keywordQuery->where('keywords.id', $keywordId);
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
                            return __('seo-content-ai::filament.article_list.keyword') . ' #' . $keywordId;
                        }

                        $usage = (string) ($data['usage'] ?? '');
                        $suffix = match (true) {
                            $usage === 'main' => ' (' . __('seo-content-ai::filament.article_list.main_article') . ')',
                            $usage === 'internal_link', ($data['internal_link_only'] ?? '') === '1' => ' (' . __('seo-content-ai::filament.article_list.has_internal_link') . ')',
                            default => '',
                        };

                        return __('seo-content-ai::filament.article_list.keyword') . ': ' . $phrase . $suffix;
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'lg' => 4,
            ])
            ->persistFiltersInSession()
            ->actionsAlignment('start')
            ->actions(static::getArticleTableRowActions())
            ->bulkActions([
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
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->form([
                            Forms\Components\Select::make('project_id')
                                ->label(__('seo-content-ai::filament.article_list.content_project'))
                                ->options(
                                    fn (Collection $records): array => static::contentProjectOptions(
                                        static::resolveBulkArticlesSiteId($records),
                                    ),
                                )
                                ->helperText(
                                    fn (Collection $records): ?string => static::resolveBulkArticlesSiteId($records) === null
                                        ? __('seo-content-ai::filament.article_list.assign_projects_mixed_domains')
                                        : null,
                                )
                                ->required()
                                ->searchable()
                                ->preload()
                                ->native(false),
                        ])
                        ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                        ->modalDescription(__('seo-content-ai::filament.article_list.assign_to_content_project_description'))
                        ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
                        ->action(function (Collection $records, array $data): void {
                            $projectId = (int) ($data['project_id'] ?? 0);
                            $summary = static::assignArticlesToContentProject($records, $projectId);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.article_list.assign_completed'))
                                ->body(static::buildAssignContentProjectBody($summary))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
        return static::applyArticleAccessScopes(
            parent::getEloquentQuery()->with(static::articleEagerLoads()),
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
            'keywords',
            'user',
            'site',
            'articleMetas' => static fn ($query) => $query->whereIn('meta_key', [
                'seo_focus_keyword',
                'seo_rank_math_score',
                'wp_post_images',
                'wp_featured_image_url',
                'wp_permalink',
            ]),
        ];
    }

    public static function applyArticleAccessScopes(
        Builder $query,
        bool $includeGlobalSiteScope = true,
        bool $includeReviewScope = true,
        bool $includeContentManagerOwnershipScope = true,
    ): Builder {
        if (auth()->user()?->role !== 'admin' && ! SeoAccessControl::isContentManager()) {
            $siteOwnerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
            $query->whereIn(
                'site_id',
                Site::query()->where('user_id', $siteOwnerId)->select('id')
            );
        }

        if ($includeGlobalSiteScope && ($globalSiteId = SeoAccessControl::globalSiteId()) !== null) {
            $query->where('site_id', $globalSiteId);
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

        return rtrim($base, '/') . '/' . ltrim($slug, '/');
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
            Tables\Actions\Action::make('toggle_skip_seo_score')
                ->icon('heroicon-o-forward')
                ->iconButton()
                ->color(fn (SeoArticle $record): string => (bool) $record->skip_seo_score ? 'warning' : 'gray')
                ->tooltip(fn (SeoArticle $record): string => (bool) $record->skip_seo_score
                    ? __('seo-content-ai::filament.article_list.unskip_seo_score')
                    : __('seo-content-ai::filament.article_list.skip_seo_score'))
                ->action(function (SeoArticle $record): void {
                    $skipped = static::toggleSkipSeoScore($record);

                    Notification::make()
                        ->title(
                            $skipped
                                ? __('seo-content-ai::filament.article_list.seo_score_skipped_on')
                                : __('seo-content-ai::filament.article_list.seo_score_skipped_off'),
                        )
                        ->success()
                        ->send();
                }),
            Tables\Actions\Action::make('approve_article')
                ->icon('heroicon-o-check-badge')
                ->iconButton()
                ->color(fn (SeoArticle $record): string => (bool) $record->is_reviewed ? 'success' : 'gray')
                ->tooltip(fn (SeoArticle $record): string => (bool) $record->is_reviewed
                    ? __('seo-content-ai::filament.article_list.already_reviewed')
                    : (SeoAccessControl::isContentManager() ? 'Đánh dấu project đã duyệt' : __('seo-content-ai::filament.article_list.mark_reviewed')))
                ->visible(fn (SeoArticle $record): bool => SeoAccessControl::canAccessManagerFeatures()
                    || (SeoAccessControl::isContentManager()
                        && ! (bool) $record->is_reviewed
                        && static::articleIsInContentProject($record)))
                ->requiresConfirmation()
                ->action(function (SeoArticle $record): void {
                    if (SeoAccessControl::isContentManager()) {
                        app(SeoProjectApprovalService::class)->approveLinkedProject(
                            $record,
                            auth()->user(),
                        );
                    }

                    $deletedCount = static::markArticleReviewed($record);

                    Notification::make()
                        ->title(SeoAccessControl::isContentManager()
                            ? 'Project đã được đánh dấu Đã duyệt'
                            : __('seo-content-ai::filament.article_list.article_reviewed'))
                        ->body(__('seo-content-ai::filament.article_list.deleted_local_images', ['count' => $deletedCount]))
                        ->success()
                        ->send();
                }),
                Tables\Actions\Action::make('assign_to_content_project')
                    ->icon('heroicon-o-folder-plus')
                    ->iconButton()
                    ->color('warning')
                    ->tooltip(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                    ->visible(fn (SeoArticle $record): bool => ! static::articleIsInContentProject($record))
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Select::make('project_id')
                            ->label(__('seo-content-ai::filament.article_list.content_project'))
                            ->options(
                                fn (SeoArticle $record): array => static::contentProjectOptions(
                                    static::resolveArticleSiteId($record),
                                ),
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false),
                    ])
                ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                ->modalDescription(__('seo-content-ai::filament.article_list.assign_to_content_project_description'))
                ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
                ->action(function (SeoArticle $record, array $data): void {
                    $projectId = (int) ($data['project_id'] ?? 0);
                    $summary = static::assignArticlesToContentProject(
                        Collection::make([$record]),
                        $projectId,
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

        return $deletedCount;
    }

    /**
     * Bật/tắt bỏ qua chấm điểm SEO. Trả về true nếu sau thao tác bài đang được bỏ qua.
     */
    public static function toggleSkipSeoScore(SeoArticle $article): bool
    {
        $skip = ! (bool) $article->skip_seo_score;

        $payload = ['skip_seo_score' => $skip];
        if ($skip) {
            $payload['seo_score'] = null;
        }

        $article->forceFill($payload)->save();

        return $skip;
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

    /**
     * @return array<int, string>
     */
    public static function contentProjectOptions(?int $siteId = null): array
    {
        $query = SeoProject::query()
            ->with('site')
            ->orderByDesc('month')
            ->orderBy('id');

        if (SeoAccessControl::isContentManager()) {
            $query->where('user_id', auth()->id());
        }

        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        $query->where('site_id', $siteId);

        return $query
            ->get()
            ->filter(function (SeoProject $project): bool {
                return in_array((string) $project->status, [
                    SeoProject::STATUS_PENDING,
                    SeoProject::STATUS_MANUAL,
                    SeoProject::STATUS_RUNNING,
                ], true) && $project->maxTasksAllowed() > (int) ($project->total_tasks ?? 0);
            })
            ->mapWithKeys(function (SeoProject $project): array {
                $remaining = max(0, $project->maxTasksAllowed() - (int) ($project->total_tasks ?? 0));
                $domain = trim((string) ($project->site?->domain ?? ''));

                return [
                    (int) $project->id => sprintf(
                        '%s · %s (%s, còn %d)',
                        (string) $project->name,
                        $domain !== '' ? $domain : '—',
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
            return 'Article #' . (int) $article->id;
        }

        return $sourceContent;
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
            ->where('type', SeoProjectTask::TYPE_REWRITE)
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

    /**
     * @param  SupportCollection<int, SeoArticle>|Collection<int, SeoArticle>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function assignArticlesToContentProject(SupportCollection $records, int $projectId): array
    {
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

        if (! in_array((string) $project->status, [
            SeoProject::STATUS_PENDING,
            SeoProject::STATUS_MANUAL,
            SeoProject::STATUS_RUNNING,
        ], true)) {
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

        DB::connection($project->getConnectionName())->transaction(function () use ($project, $records, $projectSiteId, $targetProjectId, &$added, &$duplicate, &$overflow, &$domainMismatch, &$alreadyInProject): void {
            $project->refresh();
            $max = $project->maxTasksAllowed();
            $currentTotal = (int) ($project->total_tasks ?? 0);

            $existingKeys = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->get(['site_id', 'type', 'source_content'])
                ->map(static fn (SeoProjectTask $task): string => (int) $task->site_id . '|' . SeoProjectTask::TYPE_REWRITE . '|' . mb_strtolower(trim((string) $task->source_content)))
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

                $sourceContent = static::resolveArticleProjectSourceContent($record);

                $siteId = $projectSiteId > 0 ? $projectSiteId : $articleSiteId;
                $key = $siteId . '|' . SeoProjectTask::TYPE_REWRITE . '|' . mb_strtolower($sourceContent);
                if (isset($existingMap[$key])) {
                    $duplicate++;

                    continue;
                }

                SeoProjectTask::query()->create([
                    'project_id' => (int) $project->id,
                    'site_id' => $siteId > 0 ? $siteId : null,
                    'article_id' => (int) $record->id,
                    'type' => SeoProjectTask::TYPE_REWRITE,
                    'source_content' => $sourceContent,
                    'description' => null,
                    'target_date' => $project->monthCarbon()->copy()->addDays($currentTotal)->format('Y-m-d'),
                    'status' => SeoProjectTask::STATUS_PENDING,
                ]);

                $existingMap[$key] = true;
                $currentTotal++;
                $added++;
            }

            $project->update(['total_tasks' => $currentTotal]);
        });

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
        return SeoAccessControl::canAccessContentFeatures();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return SeoAccessControl::canAccessContentFeatures();
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
            'trash' => Pages\ListArticlesTrash::route('/trash'),
            'domain-mismatch' => Pages\ArticleDomainMismatch::route('/{record}/domain-mismatch'),
            'access-denied' => Pages\ArticleAccessDenied::route('/{record}/access-denied'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
