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
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\SeoNotificationService;
use App\Addons\SeoContentAi\Services\SeoProjectApprovalService;
use App\Addons\SeoContentAi\Services\SeoProjectArticleOwnerSyncService;
use App\Addons\SeoContentAi\Services\SitePolylangService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
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
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? Str::ucfirst(str_replace('_', ' ', $state))
                        : '—')
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
                'lg' => 4,
            ])
            ->persistFiltersInSession()
            ->actionsAlignment('start')
            ->actions(static::getArticleTableRowActions())
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
            parent::getEloquentQuery()->with(['keywords', 'user', 'site', 'articleMetas']),
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
                ->action(function (SeoArticle $record): void {
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
                }),
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

        return $deletedCount;
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
    ): Forms\Components\Select {
        $select = Forms\Components\Select::make('project_id')
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
                    ->action(function (Set $set, Get $get) use ($resolveSiteId): void {
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
                            $project = static::quickCreateContentProject($siteId);
                            $set('project_id', $project->id);

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

    public static function quickCreateContentProject(int $siteId): SeoProject
    {
        if ($siteId <= 0) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.article_list.quick_create_content_project_no_domain'));
        }

        $userId = (int) auth()->id();
        if ($userId <= 0) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.article_list.quick_create_content_project_no_user'));
        }

        $currentMonth = Carbon::now()->startOfMonth();
        $targetMonth = $currentMonth->copy();

        $projectQuery = SeoProject::query()->where('site_id', $siteId);
        if (SeoAccessControl::isContentManager()) {
            $projectQuery->where('user_id', $userId);
        }

        if ((clone $projectQuery)->whereDate('month', $currentMonth->format('Y-m-d'))->exists()) {
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
        $query = SeoProject::query()
            ->with('site')
            ->orderByDesc('month')
            ->orderBy('id');

        if (SeoAccessControl::isContentManager()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        $query->where('site_id', $siteId);

        return $query
            ->get()
            ->filter(fn (SeoProject $project): bool => $project->canRegisterMoreTasks())
            ->mapWithKeys(function (SeoProject $project): array {
                $remaining = $project->remainingTaskCapacity();
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

                if ($normalizedTaskType === SeoProjectTask::TYPE_NEW_KEYWORD) {
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
            'trash' => Pages\ListArticlesTrash::route('/trash'),
            'domain-mismatch' => Pages\ArticleDomainMismatch::route('/{record}/domain-mismatch'),
            'access-denied' => Pages\ArticleAccessDenied::route('/{record}/access-denied'),
            'prompts' => Pages\ViewArticlePrompts::route('/{record}/prompts'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
