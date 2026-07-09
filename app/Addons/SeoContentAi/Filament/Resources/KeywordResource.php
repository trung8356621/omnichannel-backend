<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Enums\SeoLinkMapStatus;
use App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoLink;
use App\Addons\SeoContentAi\Models\SeoLinkMap;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\Tag;
use App\Addons\SeoContentAi\Services\DomainOverviewService;
use App\Addons\SeoContentAi\Services\KeywordDebugRescrapeService;
use App\Addons\SeoContentAi\Services\KeywordLinkTargetResolver;
use App\Addons\SeoContentAi\Services\KeywordMetaRepository;
use App\Addons\SeoContentAi\Services\SeoNotificationService;
use App\Addons\SeoContentAi\Services\TagPersistenceService;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;

class KeywordResource extends SeoPanelResource
{
    public const LINK_ROLE_MAIN = 'main';

    public const LINK_ROLE_INTERNAL_ANCHOR = 'internal_anchor';

    protected static ?string $model = Keyword::class;

    protected static ?string $slug = 'keywords';

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Keywords';

    protected static ?string $modelLabel = 'Keyword';

    protected static ?string $pluralModelLabel = 'Keywords';

    protected static ?int $navigationSort = 12;

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures()
            && $record instanceof Keyword
            && ! static::isKeywordLockedByActiveJobs($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures()
            && $record instanceof Keyword
            && ! static::isKeywordLockedByActiveJobs($record)
            && static::isUnused($record);
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.keywords');
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.keyword');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.keywords');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('site_id')
                    ->label(__('seo-content-ai::filament.keyword.domain'))
                    ->options(fn (): array => static::siteSelectOptions())
                    ->default(fn (): ?int => SeoAccessControl::globalSiteId())
                    ->hidden(fn (): bool => SeoAccessControl::hasGlobalSiteScope())
                    ->searchable()
                    ->preload()
                    ->required(fn (): bool => ! SeoAccessControl::hasGlobalSiteScope())
                    ->native(false),

                Forms\Components\TextInput::make('phrase')
                    ->label(__('seo-content-ai::filament.keyword.phrase'))
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: Keyword::class,
                        column: 'phrase',
                        ignoreRecord: true,
                    )
                    ->rule(fn (Get $get): array => $get('type') === Keyword::TYPE_NORMAL
                        ? [function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase((string) $value)) {
                                $fail(__('seo-content-ai::filament.keyword.anchor_text_invalid'));
                            }
                        }]
                        : [])
                    ->columnSpanFull(),

                Forms\Components\Select::make('parent_id')
                    ->label('Từ khóa cha')
                    ->options(function (Get $get, ?Keyword $record): array {
                        $siteId = (int) ($get('site_id') ?? ($record instanceof Keyword ? static::resolveKeywordSiteId($record) : null) ?? 0);
                        if ($siteId <= 0) {
                            return [];
                        }

                        return static::parentKeywordSelectOptions(
                            $siteId,
                            $record instanceof Keyword ? (int) $record->id : null,
                            $record instanceof Keyword && $record->parent_id ? (int) $record->parent_id : null,
                        );
                    })
                    ->getSearchResultsUsing(function (string $search, Get $get, ?Keyword $record): array {
                        $siteId = (int) ($get('site_id') ?? ($record instanceof Keyword ? static::resolveKeywordSiteId($record) : null) ?? 0);
                        if ($siteId <= 0) {
                            return [];
                        }

                        return static::parentKeywordSelectOptions(
                            $siteId,
                            $record instanceof Keyword ? (int) $record->id : null,
                            $record instanceof Keyword && $record->parent_id ? (int) $record->parent_id : null,
                            $search,
                        );
                    })
                    ->getOptionLabelUsing(fn (mixed $value): ?string => static::parentKeywordOptionLabel($value))
                    ->visible(fn (Get $get): bool => $get('type') === Keyword::TYPE_NORMAL)
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->nullable()
                    ->helperText('Chọn parent sẽ chuyển keyword sang tab Pillar / Cluster.'),

                Forms\Components\Select::make('type')
                    ->label(__('seo-content-ai::filament.keyword.type'))
                    ->options(static::keywordTypeSelectOptions())
                    ->default(Keyword::TYPE_NORMAL)
                    ->required()
                    ->native(false)
                    ->live(),

                static::tagsSelectField(
                    resolveSiteId: fn (Get $get, ?Keyword $record): int => (int) (
                        $get('site_id')
                        ?? ($record instanceof Keyword ? static::resolveKeywordSiteId($record) : null)
                        ?? SeoAccessControl::globalSiteId()
                        ?? 0
                    ),
                ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ViewColumn::make('phrase')
                    ->label(__('seo-content-ai::filament.keyword.phrase_short'))
                    ->view('seo-content-ai::filament.tables.columns.keyword-phrase')
                    ->disabledClick()
                    ->extraHeaderAttributes(['class' => 'max-w-[400px]', 'style' => 'max-width: 400px; width: 400px;'])
                    ->extraCellAttributes(['class' => 'max-w-[400px] whitespace-normal', 'style' => 'max-width: 400px; width: 400px;'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.addcslashes($search, '%_\\').'%';

                        return $query->where('phrase', 'like', $like);
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('seo-content-ai::filament.keyword.type'))
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => static::keywordTypeBadgeColor($state))
                    ->formatStateUsing(fn (string $state): string => static::keywordTypeShortLabel($state)),

                Tables\Columns\TextColumn::make('cluster_label')
                    ->label(__('seo-content-ai::filament.keyword.cluster_label'))
                    ->getStateUsing(function (Keyword $record): string {
                        if ($record->parent_id !== null && (int) $record->parent_id > 0) {
                            return (string) ($record->parent?->phrase ?? '—');
                        }

                        if ((int) ($record->children_count ?? 0) > 0) {
                            return __('seo-content-ai::filament.keyword.type_pillar_short');
                        }

                        return '—';
                    })
                    ->badge()
                    ->color(function (Keyword $record): string {
                        if ((int) ($record->children_count ?? 0) > 0 && $record->parent_id === null) {
                            return 'warning';
                        }

                        if ($record->parent_id !== null && (int) $record->parent_id > 0) {
                            return 'info';
                        }

                        return 'gray';
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('tag_labels')
                    ->label(__('seo-content-ai::filament.keyword.tags'))
                    ->getStateUsing(fn (Keyword $record): array => static::resolveTagLabelsForKeyword($record))
                    ->badge()
                    ->color('gray')
                    ->separator(' ')
                    ->wrap()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('quality_flags')
                    ->label(__('seo-content-ai::filament.keyword.quality_flags'))
                    ->getStateUsing(fn (Keyword $record): array => $record->getQualityFlagsList())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'danger' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'danger' => __('seo-content-ai::filament.keyword.quality_flag_danger'),
                        'warning' => __('seo-content-ai::filament.keyword.quality_flag_warning'),
                        default => $state,
                    })
                    ->separator(' ')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('linked_articles_count')
                    ->label(__('seo-content-ai::filament.keyword.linked_articles'))
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('success')
                    ->placeholder('—')
                    ->url(function (Keyword $record): ?string {
                        if ((int) ($record->linked_articles_count ?? 0) < 1) {
                            return null;
                        }

                        $siteId = (int) (static::resolveKeywordSiteId($record) ?? 0);
                        if ($siteId <= 0) {
                            return null;
                        }

                        return app(DomainOverviewService::class)->buildArticlesFilterUrlForInternalAnchorKeyword(
                            $siteId,
                            (int) $record->id,
                        );
                    }),

                Tables\Columns\TextColumn::make('site_links_count')
                    ->label(__('seo-content-ai::filament.keyword.internal_links_short'))
                    ->sortable()
                    ->alignCenter()
                    ->formatStateUsing(fn (mixed $state): string => (int) $state > 0
                        ? __('seo-content-ai::filament.keyword.internal_links_count', ['count' => (int) $state])
                        : '—')
                    ->extraAttributes(fn (Keyword $record): array => (int) ($record->site_links_count ?? 0) > 0
                        ? ['class' => 'ws-pill ws-pill--info']
                        : [])
                    ->url(fn (Keyword $record): ?string => (int) ($record->site_links_count ?? 0) > 0
                        ? static::resolveKeywordSiteLinkUrl($record)
                        : null)
                    ->openUrlInNewTab()
                    ->tooltip(fn (Keyword $record): ?string => static::resolveKeywordSiteLinkUrl($record)),

                Tables\Columns\TextColumn::make('dictionary_status')
                    ->label(__('seo-content-ai::filament.keyword.status'))
                    ->getStateUsing(fn (Keyword $record): string => static::resolveDictionaryStatusLabel(
                        static::resolveDictionaryStatusKey($record),
                    ))
                    ->badge()
                    ->color(fn (Keyword $record): string => static::resolveDictionaryStatusBadgeColor(
                        static::resolveDictionaryStatusKey($record),
                    )),

                Tables\Columns\TextColumn::make('word_count')
                    ->label(__('seo-content-ai::filament.keyword.word_count'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw(static::wordCountExpression().' '.$direction))
                    ->alignCenter()
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\ViewColumn::make('destinations')
                    ->label(__('seo-content-ai::filament.keyword.target_destinations'))
                    ->view('seo-content-ai::filament.resources.keywords.columns.destinations')
                    ->disabledClick()
                    ->extraCellAttributes(['class' => 'py-2 whitespace-normal'])
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('phrase')
            ->filters([
                Tables\Filters\SelectFilter::make('site_id')
                    ->label(__('seo-content-ai::filament.keyword.domain'))
                    ->options(fn (): array => static::siteSelectOptions())
                    ->hidden()
                    ->placeholder(__('seo-content-ai::filament.keyword.domain_filter_all'))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        $siteId = (int) ($data['value'] ?? 0);

                        return $siteId > 0 ? $query->forSite($siteId) : $query;
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $siteId = (int) ($data['value'] ?? 0);
                        if ($siteId <= 0) {
                            return null;
                        }

                        $domain = static::siteSelectOptions()[$siteId] ?? null;

                        return is_string($domain) && $domain !== ''
                            ? __('seo-content-ai::filament.keyword.domain').': '.$domain
                            : null;
                    }),
                Tables\Filters\Filter::make('keyword_type')
                    ->label(__('seo-content-ai::filament.keyword.type'))
                    ->form([
                        Forms\Components\Select::make('types')
                            ->label(__('seo-content-ai::filament.keyword.type'))
                            ->options(static::keywordTypeFilterOptions())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $types = collect($data['types'] ?? [])
                            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
                            ->filter(static fn (string $value): bool => in_array($value, Keyword::allowedTypes(), true))
                            ->values()
                            ->all();

                        if ($types === []) {
                            return $query;
                        }

                        return $query->whereIn('type', $types);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $labels = static::resolveKeywordTypeFilterLabels($data['types'] ?? []);

                        return $labels === []
                            ? null
                            : __('seo-content-ai::filament.keyword.type').': '.implode(', ', $labels);
                    }),
                Tables\Filters\Filter::make('article_presence')
                    ->label(__('seo-content-ai::filament.keyword.article_presence'))
                    ->form([
                        Forms\Components\Select::make('value')
                            ->label(__('seo-content-ai::filament.keyword.article_presence'))
                            ->options([
                                '' => __('seo-content-ai::filament.keyword.article_presence_all'),
                                'main' => __('seo-content-ai::filament.keyword.has_main_article'),
                                'linked' => __('seo-content-ai::filament.keyword.has_linked_article'),
                            ])
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? '') {
                            'main' => $query->whereHas('mainArticles'),
                            'linked' => $query->whereHas(
                                'linkMaps',
                                static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
                            ),
                            default => $query,
                        };
                    })
                    ->indicateUsing(function (array $data): ?string {
                        return match ($data['value'] ?? '') {
                            'main' => __('seo-content-ai::filament.keyword.has_main_article'),
                            'linked' => __('seo-content-ai::filament.keyword.has_linked_article'),
                            default => null,
                        };
                    }),
                Tables\Filters\Filter::make('quality_flags')
                    ->label(__('seo-content-ai::filament.keyword.quality_flags'))
                    ->form([
                        Forms\Components\Select::make('flags')
                            ->label(__('seo-content-ai::filament.keyword.quality_flags'))
                            ->options(static::qualityFlagFilterOptions())
                            ->multiple()
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $flags = collect($data['flags'] ?? [])
                            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
                            ->map(static fn (string $value): string => trim($value))
                            ->filter(static fn (string $value): bool => array_key_exists($value, static::qualityFlagFilterOptions()))
                            ->values()
                            ->all();

                        if ($flags === []) {
                            return $query;
                        }

                        $wantsClean = in_array('clean', $flags, true);
                        $issueFlags = array_values(array_filter(
                            $flags,
                            static fn (string $flag): bool => in_array($flag, ['danger', 'warning'], true),
                        ));

                        if ($wantsClean && $issueFlags === []) {
                            return $query->whereHasNoQualityFlags();
                        }

                        if ($wantsClean && $issueFlags !== []) {
                            return $query->where(function (Builder $scopeQuery) use ($issueFlags): void {
                                $scopeQuery
                                    ->whereHasNoQualityFlags()
                                    ->orWhere(function (Builder $flagQuery) use ($issueFlags): void {
                                        $flagQuery->whereHasAnyQualityFlag($issueFlags);
                                    });
                            });
                        }

                        return $query->whereHasAnyQualityFlag($issueFlags);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $labels = static::resolveQualityFlagFilterLabels($data['flags'] ?? []);

                        return $labels === []
                            ? null
                            : __('seo-content-ai::filament.keyword.quality_flags').': '.implode(', ', $labels);
                    }),
                Tables\Filters\Filter::make('tags_scope')
                    ->label(__('seo-content-ai::filament.keyword.tags'))
                    ->form([
                        Forms\Components\ViewField::make('tags_filter_display')
                            ->view('seo-content-ai::filament.resources.keywords.pages.partials.keyword-tags-filter-field')
                            ->viewData(fn (Get $get): array => [
                                'includeLabels' => static::resolveTagFilterLabels($get('include_tag_ids') ?? []),
                                'excludeLabels' => static::resolveTagFilterLabels($get('exclude_tag_ids') ?? []),
                            ])
                            ->columnSpanFull(),
                        Forms\Components\Hidden::make('include_tag_ids')
                            ->default([]),
                        Forms\Components\Hidden::make('exclude_tag_ids')
                            ->default([]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $includeIds = collect($data['include_tag_ids'] ?? [])
                            ->filter(static fn (mixed $id): bool => is_numeric($id))
                            ->map(static fn (mixed $id): int => (int) $id)
                            ->filter(static fn (int $id): bool => $id > 0)
                            ->values()
                            ->all();

                        if ($includeIds !== []) {
                            $query->whereHasAnyTagId($includeIds);
                        }

                        $excludeIds = collect($data['exclude_tag_ids'] ?? [])
                            ->filter(static fn (mixed $id): bool => is_numeric($id))
                            ->map(static fn (mixed $id): int => (int) $id)
                            ->filter(static fn (int $id): bool => $id > 0)
                            ->values()
                            ->all();

                        if ($excludeIds === []) {
                            return $query;
                        }

                        return $query->whereMissingAnyTagId($excludeIds);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $includeLabels = static::resolveTagFilterLabels($data['include_tag_ids'] ?? []);
                        $excludeLabels = static::resolveTagFilterLabels($data['exclude_tag_ids'] ?? []);
                        $parts = [];

                        if ($includeLabels !== []) {
                            $parts[] = __('seo-content-ai::filament.keyword.include_tags').': '.implode(', ', $includeLabels);
                        }

                        if ($excludeLabels !== []) {
                            $parts[] = __('seo-content-ai::filament.keyword.exclude_tags').': '.implode(', ', $excludeLabels);
                        }

                        return $parts === [] ? null : implode(' · ', $parts);
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'lg' => 3,
                'xl' => 4,
            ])
            ->persistFiltersInSession()
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip(fn (Keyword $record): string => static::isKeywordLockedByActiveJobs($record)
                        ? __('seo-content-ai::filament.keyword.keyword_locked_by_active_jobs')
                        : __('seo-content-ai::filament.keyword.edit'))
                    ->modalHeading(__('seo-content-ai::filament.keyword.edit'))
                    ->form(fn (Keyword $record): array => static::editKeywordFormSchema($record))
                    ->visible(fn (Keyword $record): bool => static::canEdit($record))
                    ->mutateFormDataUsing(fn (array $data, Keyword $record): array => static::mutateKeywordFormDataForFill($data, $record))
                    ->using(fn (Keyword $record, array $data): Keyword => static::saveKeywordFromFormData($record, $data)),
                Tables\Actions\Action::make('quick_copy')
                    ->label(__('seo-content-ai::filament.keyword.quick_copy'))
                    ->icon('heroicon-o-clipboard-document')
                    ->iconButton()
                    ->tooltip(__('seo-content-ai::filament.keyword.quick_copy'))
                    ->color('gray')
                    ->alpineClickHandler(function (Keyword $record): string {
                        $phrase = Js::from((string) $record->phrase);
                        $successTitle = Js::from(__('seo-content-ai::filament.keyword.quick_copy_success'));
                        $successBody = Js::from('“'.$record->phrase.'”');
                        $failedTitle = Js::from(__('seo-content-ai::filament.keyword.quick_copy_failed'));
                        $failedBody = Js::from(__('seo-content-ai::filament.keyword.quick_copy_failed_body'));

                        return <<<JS
                            (async () => {
                                const text = {$phrase};
                                let copied = false;

                                try {
                                    if (navigator.clipboard?.writeText) {
                                        await navigator.clipboard.writeText(text);
                                        copied = true;
                                    } else {
                                        const ta = document.createElement('textarea');
                                        ta.value = text;
                                        ta.setAttribute('readonly', '');
                                        ta.style.position = 'fixed';
                                        ta.style.top = '-1000px';
                                        document.body.appendChild(ta);
                                        ta.select();
                                        copied = document.execCommand('copy');
                                        document.body.removeChild(ta);
                                    }
                                } catch (error) {
                                    copied = false;
                                }

                                if (! window.FilamentNotification) {
                                    return;
                                }

                                if (copied) {
                                    new FilamentNotification()
                                        .title({$successTitle})
                                        .body({$successBody})
                                        .success()
                                        .send();

                                    return;
                                }

                                new FilamentNotification()
                                    .title({$failedTitle})
                                    .body({$failedBody})
                                    .warning()
                                    .send();
                            })()
                        JS;
                    }),
                Tables\Actions\Action::make('assign_to_content_project')
                    ->label(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                    ->icon('heroicon-o-folder-plus')
                    ->iconButton()
                    ->tooltip(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                    ->color('warning')
                    ->visible(fn (Keyword $record): bool => static::canAssignKeywordToContentProject($record))
                    ->form(function (Keyword $record): array {
                        $siteId = static::resolveKeywordSiteId($record);

                        if (static::resolveKeywordDirectAssignData($siteId) !== null) {
                            return [];
                        }

                        return static::assignKeywordContentProjectFormSchema(
                            $siteId !== null ? [(int) $siteId] : [],
                        );
                    })
                    ->requiresConfirmation(fn (Keyword $record): bool => static::resolveKeywordDirectAssignData(
                        static::resolveKeywordSiteId($record),
                    ) === null)
                    ->modalHidden(fn (Keyword $record): bool => static::resolveKeywordDirectAssignData(
                        static::resolveKeywordSiteId($record),
                    ) !== null)
                    ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                    ->modalDescription(__('seo-content-ai::filament.keyword.assign_to_content_project_description'))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
                    ->action(function (Keyword $record, array $data): void {
                        $siteId = static::resolveKeywordSiteId($record);
                        $assignData = static::resolveKeywordDirectAssignData($siteId) ?? $data;
                        $summary = static::executeAssignKeywordsToContentProjects(
                            Collection::make([$record]),
                            $assignData,
                        );

                        Notification::make()
                            ->title(__('seo-content-ai::filament.keyword.assign_completed'))
                            ->body(ArticleResource::buildAssignContentProjectBody($summary))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('debug_rescrape')
                    ->label(__('seo-content-ai::filament.keyword.debug_rescrape'))
                    ->icon('heroicon-o-bug-ant')
                    ->iconButton()
                    ->tooltip(__('seo-content-ai::filament.keyword.debug_rescrape'))
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('seo-content-ai::filament.keyword.debug_rescrape'))
                    ->modalDescription(fn (Keyword $record): string => __('seo-content-ai::filament.keyword.debug_rescrape_confirm', [
                        'phrase' => (string) $record->phrase,
                    ]))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.keyword.debug_rescrape_submit'))
                    ->action(function (Keyword $record): void {
                        $summary = app(KeywordDebugRescrapeService::class)->deleteAndRescrapeLinkedArticles($record);

                        $bodyKey = ($summary['content_still_contains_phrase'] ?? 0) > 0
                            ? 'debug_rescrape_body_stale_content'
                            : 'debug_rescrape_body';

                        Notification::make()
                            ->title(__('seo-content-ai::filament.keyword.debug_rescrape_completed'))
                            ->body(__('seo-content-ai::filament.keyword.'.$bodyKey, [
                                'phrase' => $summary['phrase'],
                                'articles' => count($summary['linked_article_ids']),
                                'rescanned' => $summary['rescanned'],
                                'skipped' => $summary['skipped'],
                                'stale_articles' => $summary['content_still_contains_phrase'],
                            ]))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip(fn (Keyword $record): string => static::isKeywordLockedByActiveJobs($record)
                        ? __('seo-content-ai::filament.keyword.keyword_locked_by_active_jobs')
                        : __('seo-content-ai::filament.keyword.delete'))
                    ->visible(fn (Keyword $record): bool => static::canDelete($record)),
            ])
            ->bulkActions(static::seoPanelBulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulk_tag')
                        ->label(__('seo-content-ai::filament.keyword.bulk_tag'))
                        ->icon('heroicon-o-tag')
                        ->form(fn (Collection $records): array => [
                            static::tagsSelectField(
                                resolveSiteId: fn (): int => (int) (
                                    static::resolveBulkKeywordsSiteId($records) ?? 0
                                ),
                                multiple: true,
                                fieldName: 'tag_ids',
                                required: true,
                                useRelationship: false,
                                helperTextResolver: fn (): ?string => static::resolveBulkKeywordsSiteId($records) === null
                                    ? __('seo-content-ai::filament.keyword.bulk_mixed_domains')
                                    : null,
                            ),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data): void {
                            $tagIds = collect($data['tag_ids'] ?? [])
                                ->filter(static fn (mixed $id): bool => is_numeric($id))
                                ->map(static fn (mixed $id): int => (int) $id)
                                ->filter(static fn (int $id): bool => $id > 0)
                                ->values()
                                ->all();

                            if ($tagIds === []) {
                                return;
                            }

                            $attached = 0;
                            $metaRepository = app(KeywordMetaRepository::class);
                            foreach ($records as $record) {
                                if (! $record instanceof Keyword) {
                                    continue;
                                }

                                if ($metaRepository->mergeTagIds((int) $record->id, $tagIds)) {
                                    $attached++;
                                }
                            }

                            Notification::make()
                                ->title(__('seo-content-ai::filament.keyword.bulk_tag_completed'))
                                ->body(__('seo-content-ai::filament.keyword.bulk_tag_body', [
                                    'count' => $attached,
                                ]))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('bulk_quick_parent')
                        ->label(__('seo-content-ai::filament.keyword.bulk_quick_parent'))
                        ->icon('heroicon-o-arrow-up-on-square')
                        ->form(fn (Collection $records): array => [
                            Forms\Components\Select::make('parent_id')
                                ->label(__('seo-content-ai::filament.keyword.parent_keyword'))
                                ->options(fn (): array => static::bulkParentOptions($records))
                                ->getSearchResultsUsing(
                                    fn (string $search): array => static::bulkParentOptions($records, $search),
                                )
                                ->getOptionLabelUsing(
                                    fn (mixed $value): ?string => static::parentKeywordOptionLabel($value),
                                )
                                ->required()
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->helperText(__('seo-content-ai::filament.keyword.bulk_quick_parent_hint')),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data): void {
                            $parentId = (int) ($data['parent_id'] ?? 0);
                            if ($parentId <= 0) {
                                return;
                            }

                            $parent = Keyword::query()->find($parentId);
                            if (! $parent instanceof Keyword || $parent->parent_id !== null) {
                                Notification::make()
                                    ->title(__('seo-content-ai::filament.keyword.bulk_quick_parent_failed'))
                                    ->body(__('seo-content-ai::filament.keyword.bulk_quick_parent_invalid_parent'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $updated = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof Keyword) {
                                    continue;
                                }

                                if (
                                    (int) $record->id === $parentId
                                    || static::resolveKeywordSiteId($record) !== static::resolveKeywordSiteId($parent)
                                ) {
                                    $skipped++;

                                    continue;
                                }

                                if ((int) $record->parent_id === $parentId) {
                                    continue;
                                }

                                $record->update(['parent_id' => $parentId]);
                                $updated++;
                            }

                            Notification::make()
                                ->title(__('seo-content-ai::filament.keyword.bulk_quick_parent_completed'))
                                ->body(__('seo-content-ai::filament.keyword.bulk_quick_parent_body', [
                                    'updated' => $updated,
                                    'skipped' => $skipped,
                                ]))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('switch_type')
                        ->label(__('seo-content-ai::filament.keyword.bulk_switch_type'))
                        ->icon('heroicon-o-arrows-right-left')
                        ->form([
                            Forms\Components\Select::make('type')
                                ->label(__('seo-content-ai::filament.keyword.type'))
                                ->options(static::keywordTypeSelectOptions())
                                ->required()
                                ->native(false),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data): void {
                            $type = (string) ($data['type'] ?? '');
                            $updated = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof Keyword || $type === '') {
                                    continue;
                                }

                                $updates = ['type' => $type];
                                if ($type !== Keyword::TYPE_NORMAL) {
                                    $updates['parent_id'] = null;
                                }

                                if ($record->type === $type && ($type === Keyword::TYPE_NORMAL || $record->parent_id === null)) {
                                    continue;
                                }

                                $record->update($updates);
                                $updated++;
                            }

                            Notification::make()
                                ->title(__('seo-content-ai::filament.keyword.bulk_switch_type_completed'))
                                ->body(__('seo-content-ai::filament.keyword.bulk_switch_type_body', [
                                    'count' => $updated,
                                ]))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('assign_to_content_project')
                        ->label(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                        ->icon('heroicon-o-folder-plus')
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->form(function (Collection $records): array {
                            if (static::resolveKeywordDirectAssignData() !== null) {
                                return [];
                            }

                            return static::assignKeywordContentProjectFormSchema(
                                ($siteIds = static::resolveBulkKeywordsSiteIds($records)) !== []
                                    ? $siteIds
                                    : (SeoAccessControl::globalSiteId() !== null ? [(int) SeoAccessControl::globalSiteId()] : []),
                            );
                        })
                        ->requiresConfirmation(fn (Collection $records): bool => static::resolveKeywordDirectAssignData() === null)
                        ->modalHidden(fn (Collection $records): bool => static::resolveKeywordDirectAssignData() !== null)
                        ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                        ->modalDescription(__('seo-content-ai::filament.keyword.assign_to_content_project_description'))
                        ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
                        ->action(function (Collection $records, array $data): void {
                            $assignData = static::resolveKeywordDirectAssignData() ?? $data;
                            $summary = static::executeAssignKeywordsToContentProjects($records, $assignData);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.keyword.assign_completed'))
                                ->body(ArticleResource::buildAssignContentProjectBody($summary))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make()
                        ->label(__('seo-content-ai::filament.keyword.bulk_delete'))
                        ->action(function (Collection $records): void {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof Keyword) {
                                    continue;
                                }

                                if (! static::canDelete($record)) {
                                    $skipped++;

                                    continue;
                                }

                                $record->delete();
                                $deleted++;
                            }

                            Notification::make()
                                ->title(__('seo-content-ai::filament.keyword.bulk_delete_completed'))
                                ->body(__('seo-content-ai::filament.keyword.bulk_delete_body', [
                                    'deleted' => $deleted,
                                    'skipped' => $skipped,
                                ]))
                                ->success()
                                ->send();
                        }),
                ]),
            ]));
    }

    public static function quickCopyTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('quick_copy')
            ->label(__('seo-content-ai::filament.keyword.quick_copy'))
            ->icon('heroicon-o-clipboard-document')
            ->iconButton()
            ->tooltip(__('seo-content-ai::filament.keyword.quick_copy'))
            ->color('gray')
            ->alpineClickHandler(function (Keyword $record): string {
                $phrase = Js::from((string) $record->phrase);
                $successTitle = Js::from(__('seo-content-ai::filament.keyword.quick_copy_success'));
                $successBody = Js::from('“'.$record->phrase.'”');
                $failedTitle = Js::from(__('seo-content-ai::filament.keyword.quick_copy_failed'));
                $failedBody = Js::from(__('seo-content-ai::filament.keyword.quick_copy_failed_body'));

                return <<<JS
                    (async () => {
                        const text = {$phrase};
                        let copied = false;

                        try {
                            if (navigator.clipboard?.writeText) {
                                await navigator.clipboard.writeText(text);
                                copied = true;
                            } else {
                                const ta = document.createElement('textarea');
                                ta.value = text;
                                ta.setAttribute('readonly', '');
                                ta.style.position = 'fixed';
                                ta.style.top = '-1000px';
                                document.body.appendChild(ta);
                                ta.select();
                                copied = document.execCommand('copy');
                                document.body.removeChild(ta);
                            }
                        } catch (error) {
                            copied = false;
                        }

                        if (! window.FilamentNotification) {
                            return;
                        }

                        if (copied) {
                            new FilamentNotification()
                                .title({$successTitle})
                                .body({$successBody})
                                .success()
                                .send();

                            return;
                        }

                        new FilamentNotification()
                            .title({$failedTitle})
                            .body({$failedBody})
                            .warning()
                            .send();
                    })()
                JS;
            });
    }

    /**
     * @return array<int|string, string>
     */
    public static function siteSelectOptions(): array
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query->pluck('domain', 'id')->all();
    }

    public static function keywordHasSiteLinks(Keyword $record): bool
    {
        if ((int) ($record->site_links_count ?? 0) > 0) {
            return true;
        }

        if ($record->relationLoaded('linkMaps')) {
            return $record->linkMaps->isNotEmpty();
        }

        return $record->linkMaps()->exists();
    }

    public static function resolvePrimarySiteLink(Keyword $record, ?int $preferredSiteId = null): ?SeoLinkMap
    {
        $preferredSiteId ??= SeoAccessControl::globalSiteId();

        $maps = $record->relationLoaded('linkMaps')
            ? $record->linkMaps
            : $record->linkMaps()->with('sourceArticle:id,site_id')->orderBy('id')->get();

        if ($preferredSiteId !== null && (int) $preferredSiteId > 0) {
            $scoped = $maps->first(
                static fn (SeoLinkMap $map): bool => (int) ($map->sourceArticle?->site_id ?? 0) === (int) $preferredSiteId,
            );

            if ($scoped instanceof SeoLinkMap) {
                return $scoped;
            }
        }

        return $maps->first();
    }

    public static function resolveKeywordSiteLinkUrl(Keyword $record, ?int $preferredSiteId = null): ?string
    {
        $map = static::resolvePrimarySiteLink($record, $preferredSiteId);
        if (! $map instanceof SeoLinkMap) {
            return null;
        }

        $siteId = (int) ($map->sourceArticle?->site_id ?? $preferredSiteId ?? 0);

        return static::resolveLinkMapDestinationUrl($map, $siteId);
    }

    public static function resolveLinkMapDestinationUrl(SeoLinkMap $map, int $siteId, ?string $domain = null): string
    {
        if ((int) ($map->target_article_id ?? 0) > 0) {
            $target = $map->relationLoaded('targetArticle')
                ? $map->targetArticle
                : $map->targetArticle()->first(['id', 'site_id', 'title', 'slug']);

            if ($target instanceof SeoArticle) {
                $url = app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($target);
                if (is_string($url) && trim($url) !== '') {
                    return trim($url);
                }
            }
        }

        $external = trim((string) ($map->target_external_url ?? ''));
        if ($external === '') {
            return '';
        }

        if ($domain === null) {
            $domain = trim((string) (static::siteSelectOptions()[$siteId] ?? ''));
        }

        return static::buildAbsoluteLinkUrl($external, $siteId, $domain !== '' ? $domain : null);
    }

    /**
     * @return array{domain: string, url: string}|null
     */
    public static function resolveFocusMainArticlePresentation(Keyword $record): ?array
    {
        if (! Keyword::isNormalType($record->type)) {
            return null;
        }

        if ((int) ($record->main_articles_count ?? 0) < 1 && ! $record->relationLoaded('mainArticles')) {
            if (! $record->mainArticles()->exists()) {
                return null;
            }
        }

        $articles = $record->relationLoaded('mainArticles')
            ? $record->mainArticles
            : $record->mainArticles()->with('site')->orderBy('articles.id')->get();

        if ($articles->isEmpty()) {
            return null;
        }

        $preferredSiteId = SeoAccessControl::globalSiteId();
        $article = null;

        if ($preferredSiteId !== null && (int) $preferredSiteId > 0) {
            $article = $articles->first(
                static fn (SeoArticle $item): bool => (int) ($item->site_id ?? 0) === (int) $preferredSiteId,
            );
        }

        $article ??= $articles->first();
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $url = trim((string) (app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($article) ?? ''));
        if ($url === '') {
            return null;
        }

        $article->loadMissing('site');
        $site = $article->site;
        $domain = $site instanceof Site ? trim((string) $site->domain) : '';
        if ($domain === '') {
            $host = parse_url($url, PHP_URL_HOST);

            $domain = is_string($host) && $host !== '' ? $host : $url;
        }

        return [
            'domain' => $domain,
            'url' => $url,
        ];
    }

    /**
     * @return array<int|string, string>
     */
    public static function clusterParentOptions(): array
    {
        $query = Keyword::query()
            ->where('type', Keyword::TYPE_NORMAL)
            ->whereNull('parent_id')
            ->whereHas('children')
            ->whereRaw(static::wordCountExpression().' >= 2')
            ->orderBy('phrase');

        return $query->pluck('phrase', 'id')->all();
    }

    public static function applyParentScopeToQuery(Builder $query, ?int $parentId): Builder
    {
        if ($parentId !== null && $parentId > 0) {
            return $query->where('parent_id', $parentId);
        }

        return $query->whereNull('parent_id');
    }

    public static function buildClusterChildrenBaseQuery(int $parentId): Builder
    {
        $query = Keyword::query()->where('parent_id', $parentId);

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $siteIds = SeoAccessControl::accessibleSiteIds();
            $query->forSites($siteIds);
        }

        return static::applyMinimumKeywordWordCount(
            InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases($query),
        );
    }

    public static function countClusterChildrenForSite(int $parentId, ?int $siteId): int
    {
        $query = static::buildClusterChildrenBaseQuery($parentId);

        if ($siteId !== null && $siteId > 0) {
            $query->forSite($siteId);
        }

        return $query->count();
    }

    /**
     * @return array{
     *     current_site_id: int,
     *     current_site_domain: string,
     *     target_site_id: int,
     *     target_site_domain: string,
     *     children_count: int,
     * }|null
     */
    public static function resolveClusterChildrenSiteMismatch(?int $parentId): ?array
    {
        if ($parentId === null || $parentId <= 0) {
            return null;
        }

        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId === null || $globalSiteId <= 0) {
            return null;
        }

        if (! Keyword::query()->whereKey($parentId)->exists()) {
            return null;
        }

        if (static::countClusterChildrenForSite($parentId, $globalSiteId) > 0) {
            return null;
        }

        if (static::countClusterChildrenForSite($parentId, null) <= 0) {
            return null;
        }

        $targetSiteId = null;
        $targetCount = 0;

        foreach (array_map('intval', array_keys(static::siteSelectOptions())) as $siteId) {
            if ($siteId === $globalSiteId) {
                continue;
            }

            $count = static::countClusterChildrenForSite($parentId, $siteId);
            if ($count > $targetCount) {
                $targetCount = $count;
                $targetSiteId = $siteId;
            }
        }

        if ($targetSiteId === null || $targetCount <= 0) {
            return null;
        }

        $currentSite = Site::query()->find($globalSiteId);
        $targetSite = Site::query()->find($targetSiteId);

        return [
            'current_site_id' => $globalSiteId,
            'current_site_domain' => (string) ($currentSite?->domain ?? '#'.$globalSiteId),
            'target_site_id' => $targetSiteId,
            'target_site_domain' => (string) ($targetSite?->domain ?? '#'.$targetSiteId),
            'children_count' => $targetCount,
        ];
    }

    public static function buildChildrenFilterUrl(int $parentId): string
    {
        return static::getUrl('index').'?parent_id='.$parentId;
    }

    public static function buildRootKeywordsUrl(): string
    {
        return static::getUrl('index');
    }

    public static function buildIncludeTagFilterUrl(int $tagId): string
    {
        if ($tagId <= 0) {
            return static::getUrl('index');
        }

        $base = static::getUrl('index');
        $query = http_build_query([
            'tableFilters' => [
                'include_tags' => [
                    'tag_ids' => [(string) $tagId],
                ],
            ],
        ]);

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }

    /**
     * @return array<int|string, string>
     */
    public static function tagFilterOptions(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function keywordTypeFilterOptions(): array
    {
        return [
            Keyword::TYPE_NORMAL => __('seo-content-ai::filament.keyword.normal_short'),
            Keyword::TYPE_SUGGEST => __('seo-content-ai::filament.keyword.suggest_short'),
            Keyword::TYPE_FREE => __('seo-content-ai::filament.keyword.free_short'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function qualityFlagFilterOptions(): array
    {
        return [
            'danger' => __('seo-content-ai::filament.keyword.quality_flag_danger'),
            'warning' => __('seo-content-ai::filament.keyword.quality_flag_warning'),
            'clean' => __('seo-content-ai::filament.keyword.quality_flag_clean'),
        ];
    }

    /**
     * @param  list<mixed>  $flags
     * @return list<string>
     */
    public static function resolveQualityFlagFilterLabels(array $flags): array
    {
        $options = static::qualityFlagFilterOptions();

        return collect($flags)
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->map(static fn (string $value): string => $options[$value] ?? $value)
            ->filter(static fn (string $label): bool => $label !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function keywordTypeSelectOptions(): array
    {
        return [
            Keyword::TYPE_NORMAL => __('seo-content-ai::filament.keyword.normal'),
            Keyword::TYPE_SUGGEST => __('seo-content-ai::filament.keyword.suggest'),
            Keyword::TYPE_FREE => __('seo-content-ai::filament.keyword.free'),
        ];
    }

    public static function keywordTypeBadgeColor(string $state): string
    {
        return match ($state) {
            Keyword::TYPE_NORMAL, 'focus', 'internal' => 'success',
            Keyword::TYPE_SUGGEST => 'info',
            Keyword::TYPE_FREE => 'gray',
            default => 'gray',
        };
    }

    public static function keywordTypeShortLabel(string $state): string
    {
        return match ($state) {
            Keyword::TYPE_NORMAL, 'focus', 'internal' => __('seo-content-ai::filament.keyword.normal_short'),
            Keyword::TYPE_SUGGEST => __('seo-content-ai::filament.keyword.suggest_short'),
            Keyword::TYPE_FREE => __('seo-content-ai::filament.keyword.free_short'),
            default => $state,
        };
    }

    public static function resolveDictionaryStatusKey(Keyword $record): string
    {
        $hasBroken = $record->relationLoaded('linkMaps')
            ? $record->linkMaps->contains(static fn (SeoLinkMap $map): bool => $map->status === SeoLinkMapStatus::Broken)
            : $record->linkMaps()->where('status', SeoLinkMapStatus::Broken)->exists();

        if ($hasBroken) {
            return 'error';
        }

        if ((int) ($record->main_articles_count ?? 0) > 0 || (int) ($record->linked_articles_count ?? 0) > 0) {
            return 'active';
        }

        return 'needs_optimization';
    }

    public static function resolveDictionaryStatusLabel(string $statusKey): string
    {
        return match ($statusKey) {
            'active' => __('seo-content-ai::filament.keyword.stat_active'),
            'needs_optimization' => __('seo-content-ai::filament.keyword.stat_needs_optimization'),
            'error' => __('seo-content-ai::filament.keyword.stat_errors'),
            default => $statusKey,
        };
    }

    public static function resolveDictionaryStatusBadgeColor(string $statusKey): string
    {
        return match ($statusKey) {
            'active' => 'success',
            'needs_optimization' => 'warning',
            'error' => 'danger',
            default => 'gray',
        };
    }

    public static function resolveDictionaryStatusBadgeClass(string $statusKey): string
    {
        return match ($statusKey) {
            'active' => 'ws-badge--success',
            'needs_optimization' => 'ws-badge--warning',
            'error' => 'ws-badge--danger',
            default => 'ws-badge--gray',
        };
    }

    public static function isKeywordLockedByActiveJobs(Keyword $keyword): bool
    {
        $needle = mb_strtolower(trim((string) $keyword->phrase));
        if ($needle === '') {
            return false;
        }

        return SeoProjectTask::query()
            ->where('type', SeoProjectTask::TYPE_NEW_KEYWORD)
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle])
            ->whereHas(
                'project',
                static fn (Builder $query): Builder => $query->whereIn('status', [
                    SeoProject::STATUS_PENDING,
                    SeoProject::STATUS_MANUAL,
                    SeoProject::STATUS_RUNNING,
                ]),
            )
            ->exists();
    }

    /**
     * @return list<array{domain: string, url: string, site_id: int, role: string}>
     */
    public static function resolveLinkDestinationPresentations(Keyword $record): array
    {
        return collect(static::resolveLinkDestinationGroups($record))
            ->flatMap(static function (array $group): Collection {
                $items = collect($group['main_links'] ?? [])
                    ->merge($group['internal_links'] ?? [])
                    ->map(static fn (array $link): array => [
                        'domain' => (string) $group['domain'],
                        'url' => (string) $link['url'],
                        'site_id' => (int) $group['site_id'],
                        'role' => (string) $link['role'],
                    ]);

                return $items;
            })
            ->unique(static fn (array $item): string => $item['site_id'].'|'.$item['url'].'|'.$item['role'])
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *     domain: string,
     *     site_id: int,
     *     main_links: list<array{url: string, role: string, link_id: int}>,
     *     internal_links: list<array{
     *         url: string,
     *         destination_url: string,
     *         source_url: string|null,
     *         role: string,
     *         link_id: int,
     *         source_label: string|null
     *     }>
     * }>
     */
    public static function resolveLinkDestinationGroups(Keyword $record): array
    {
        $domainMap = static::siteSelectOptions();
        $maps = $record->relationLoaded('linkMaps')
            ? $record->linkMaps
            : $record->linkMaps()
                ->orderBy('id')
                ->with([
                    'sourceArticle:id,site_id,title,slug',
                    'targetArticle:id,site_id,title,slug',
                ])
                ->get();

        /** @var array<int, array{domain: string, site_id: int, main_links: list<array<string, mixed>>, internal_links: list<array<string, mixed>>}> $groups */
        $groups = [];

        foreach ($maps as $map) {
            if (! $map instanceof SeoLinkMap) {
                continue;
            }

            $sourceArticle = $map->relationLoaded('sourceArticle')
                ? $map->sourceArticle
                : $map->sourceArticle()->first(['id', 'site_id', 'title', 'slug']);

            $siteId = (int) ($sourceArticle?->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $domain = trim((string) ($domainMap[$siteId] ?? ''));
            if ($domain === '') {
                $domain = '#'.$siteId;
            }

            if (! array_key_exists($siteId, $groups)) {
                $groups[$siteId] = [
                    'domain' => $domain,
                    'site_id' => $siteId,
                    'main_links' => [],
                    'internal_links' => [],
                ];
            }

            $destinationUrl = static::resolveLinkMapDestinationUrl($map, $siteId, $domain);
            if ($destinationUrl === '') {
                continue;
            }

            $sourceUrl = $sourceArticle instanceof SeoArticle
                ? app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($sourceArticle)
                : null;

            $linkPayload = [
                'url' => $destinationUrl,
                'role' => static::LINK_ROLE_INTERNAL_ANCHOR,
                'link_id' => (int) $map->id,
                'destination_url' => $destinationUrl,
                'source_url' => is_string($sourceUrl) ? trim($sourceUrl) : null,
                'source_label' => static::resolveLinkMapSourceLabel($sourceArticle),
                'source_article_id' => (int) ($map->source_article_id ?? 0),
            ];

            $dedupeKey = $destinationUrl.'|'.((int) ($map->source_article_id ?? 0));
            $existingKeys = collect($groups[$siteId]['internal_links'])
                ->map(static fn (array $item): string => ($item['destination_url'] ?? $item['url'] ?? '').'|'.((int) ($item['source_article_id'] ?? 0)))
                ->all();

            if (in_array($dedupeKey, $existingKeys, true)) {
                continue;
            }

            $groups[$siteId]['internal_links'][] = $linkPayload;
        }

        $mainArticles = $record->relationLoaded('mainArticles')
            ? $record->mainArticles
            : $record->mainArticles()->with('site')->get();

        foreach ($mainArticles as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $siteId = (int) ($article->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $domain = trim((string) ($domainMap[$siteId] ?? ''));
            if ($domain === '') {
                $domain = '#'.$siteId;
            }

            if (! array_key_exists($siteId, $groups)) {
                $groups[$siteId] = [
                    'domain' => $domain,
                    'site_id' => $siteId,
                    'main_links' => [],
                    'internal_links' => [],
                ];
            }

            $absoluteUrl = trim((string) (app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($article) ?? ''));
            if ($absoluteUrl === '') {
                continue;
            }

            $groups[$siteId]['main_links'][] = [
                'url' => $absoluteUrl,
                'role' => static::LINK_ROLE_MAIN,
                'link_id' => (int) $article->id,
                'target_article_id' => (int) $article->id,
            ];
        }

        return static::enrichLinkDestinationGroups(
            collect($groups)
                ->sortBy('domain')
                ->values()
                ->all(),
            $record,
        );
    }

    public static function resolveLinkMapSourceLabel(?SeoArticle $sourceArticle): ?string
    {
        if (! $sourceArticle instanceof SeoArticle) {
            return null;
        }

        $title = trim((string) ($sourceArticle->title ?? ''));
        if ($title !== '') {
            return $title;
        }

        $slug = trim((string) ($sourceArticle->slug ?? ''));

        return $slug !== '' ? $slug : null;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    public static function enrichLinkDestinationGroups(array $groups, ?Keyword $record = null): array
    {
        return array_map(static function (array $group) use ($record): array {
            $siteId = (int) ($group['site_id'] ?? 0);

            $group['main_links'] = array_map(static function (array $link) use ($record, $siteId): array {
                $url = (string) ($link['url'] ?? '');
                $link['shorthand'] = static::formatLinkShorthand($url);
                $link['display'] = $url;
                $articleId = (int) ($link['target_article_id'] ?? 0);

                if ($articleId <= 0 && $record instanceof Keyword) {
                    $articleId = (int) ($record->mainArticleId() ?? 0);

                    if ($articleId <= 0) {
                        $articleId = (int) ($record->mainArticles()
                            ->where('articles.site_id', $siteId)
                            ->orderBy('articles.id')
                            ->value('articles.id') ?? 0);
                    }
                }

                $link['edit_url'] = $articleId > 0
                    ? ArticleResource::getUrl('edit', ['record' => $articleId])
                    : $url;
                $link['is_edit_link'] = $articleId > 0;

                return $link;
            }, $group['main_links'] ?? []);

            $group['internal_links'] = array_map(static function (array $link): array {
                $destinationUrl = (string) ($link['destination_url'] ?? $link['url'] ?? '');
                $sourceUrl = (string) ($link['source_url'] ?? '');
                $sourceLabel = trim((string) ($link['source_label'] ?? ''));
                $sourceArticleId = (int) ($link['source_article_id'] ?? 0);

                $link['destination_shorthand'] = static::formatLinkShorthand($destinationUrl);
                $link['source_shorthand'] = $sourceUrl !== ''
                    ? static::formatLinkShorthand($sourceUrl)
                    : ($sourceLabel !== '' ? $sourceLabel : '—');
                $link['source_display'] = $sourceLabel !== '' ? $sourceLabel : $sourceUrl;
                $link['destination_display'] = $destinationUrl;
                $link['source_edit_url'] = $sourceArticleId > 0
                    ? ArticleResource::getUrl('edit', ['record' => $sourceArticleId])
                    : ($sourceUrl !== '' ? $sourceUrl : null);
                $link['source_is_edit_link'] = $sourceArticleId > 0;

                return $link;
            }, $group['internal_links'] ?? []);

            $mainCount = count($group['main_links'] ?? []);
            $internalCount = count($group['internal_links'] ?? []);
            $hasInternal = $internalCount > 0;

            $group['badge'] = [
                'variant' => $hasInternal ? 'gray' : 'success',
                'icon' => $hasInternal ? 'heroicon-m-link' : 'heroicon-m-bookmark-square',
                'emoji' => $hasInternal ? '🔗' : '🎯',
                'count' => $mainCount + $internalCount,
            ];

            return $group;
        }, $groups);
    }

    public static function formatLinkShorthand(string $url, int $maxLength = 36): string
    {
        $url = trim($url);
        if ($url === '') {
            return '—';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '' && $path !== '/') {
            $label = ltrim($path, '/');
        } else {
            $host = parse_url($url, PHP_URL_HOST);
            $label = is_string($host) && $host !== '' ? $host : $url;
        }

        if (mb_strlen($label) <= $maxLength) {
            return $label;
        }

        return rtrim(mb_substr($label, 0, max(1, $maxLength - 1))).'…';
    }

    /**
     * @return array{destination_url: string, source_url: string|null, source_label: string|null}
     */
    public static function resolveInternalLinkPresentation(SeoLink $link, int $siteId, string $domain): array
    {
        $destinationUrl = static::buildAbsoluteLinkUrl((string) $link->url, $siteId, $domain);
        $sourceUrl = null;
        $sourceLabel = static::resolveInternalLinkSourceLabel($link);

        $sourceArticle = $link->relationLoaded('sourceArticle')
            ? $link->sourceArticle
            : $link->sourceArticle()->first();

        if ($sourceArticle instanceof SeoArticle) {
            $resolvedSourceUrl = app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($sourceArticle);
            $sourceUrl = is_string($resolvedSourceUrl) && trim($resolvedSourceUrl) !== ''
                ? trim($resolvedSourceUrl)
                : null;
        }

        return [
            'destination_url' => $destinationUrl,
            'source_url' => $sourceUrl,
            'source_label' => $sourceLabel,
        ];
    }

    public static function resolveLinkRole(Keyword $keyword, SeoLink $link): string
    {
        if ($link->type === SeoLink::TYPE_EXTERNAL) {
            return static::LINK_ROLE_MAIN;
        }

        if (static::linkIsFocusDestination($keyword, $link)) {
            return static::LINK_ROLE_MAIN;
        }

        if ($link->type === SeoLink::TYPE_INTERNAL && $link->source_article_id === null) {
            return static::LINK_ROLE_MAIN;
        }

        if ($link->type === SeoLink::TYPE_INTERNAL && $link->source_article_id !== null) {
            return static::LINK_ROLE_INTERNAL_ANCHOR;
        }

        return static::LINK_ROLE_INTERNAL_ANCHOR;
    }

    public static function resolveInternalLinkSourceLabel(SeoLink $link): ?string
    {
        $sourceArticle = $link->relationLoaded('sourceArticle')
            ? $link->sourceArticle
            : $link->sourceArticle()->first(['id', 'title', 'slug']);

        if ($sourceArticle instanceof SeoArticle) {
            $title = trim((string) ($sourceArticle->title ?? ''));
            if ($title !== '') {
                return $title;
            }

            $slug = trim((string) ($sourceArticle->slug ?? ''));
            if ($slug !== '') {
                return $slug;
            }
        }

        return null;
    }

    public static function buildAbsoluteLinkUrl(string $url, int $siteId, ?string $domain = null): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $domain ??= trim((string) (static::siteSelectOptions()[$siteId] ?? ''));
        if ($domain === '') {
            return $url;
        }

        if (! str_starts_with($domain, 'http://') && ! str_starts_with($domain, 'https://')) {
            $domain = 'https://'.$domain;
        }

        $domain = rtrim($domain, '/');

        return str_starts_with($url, '/') ? $domain.$url : $domain.'/'.$url;
    }

    public static function linkIsFocusDestination(Keyword $keyword, SeoLink $link): bool
    {
        $siteId = (int) $link->site_id;
        if ($siteId <= 0) {
            return false;
        }

        $mainArticles = $keyword->relationLoaded('mainArticles')
            ? $keyword->mainArticles
            : $keyword->mainArticles()->get(['articles.id', 'articles.site_id']);

        $hasMainOnSite = $mainArticles->contains(
            static fn (SeoArticle $article): bool => (int) ($article->site_id ?? 0) === $siteId,
        );

        if (! $hasMainOnSite) {
            return false;
        }

        $primary = $keyword->resolvePrimaryLink($siteId);

        return $primary instanceof SeoLink && (int) $primary->id === (int) $link->id;
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return list<int>
     */
    public static function resolveBulkKeywordsSiteIds(Collection $records): array
    {
        return $records
            ->filter(static fn (mixed $record): bool => $record instanceof Keyword)
            ->flatMap(static function (Keyword $keyword): Collection {
                if ($keyword->relationLoaded('linkMaps')) {
                    return $keyword->linkMaps
                        ->map(static fn (SeoLinkMap $map): int => (int) ($map->sourceArticle?->site_id ?? 0));
                }

                return $keyword->linkMaps()
                    ->join('articles', 'articles.id', '=', 'seo_link_maps.source_article_id')
                    ->pluck('articles.site_id');
            })
            ->map(static fn (mixed $siteId): int => (int) $siteId)
            ->filter(static fn (int $siteId): bool => $siteId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>|list<mixed>  $types
     * @return list<string>
     */
    public static function resolveKeywordTypeFilterLabels(array $types): array
    {
        $options = static::keywordTypeFilterOptions();

        return collect($types)
            ->filter(static fn (mixed $type): bool => is_string($type) && $type !== '')
            ->map(static fn (string $type): string => $options[$type] ?? $type)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>|list<mixed>  $tagIds
     * @return list<string>
     */
    public static function resolveTagFilterLabels(array $tagIds): array
    {
        $ids = collect($tagIds)
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return Tag::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'linkMaps' => static fn ($mapQuery): mixed => $mapQuery
                    ->orderBy('id')
                    ->with([
                        'sourceArticle:id,site_id,title,slug',
                        'targetArticle:id,site_id,title,slug',
                    ]),
                'mainArticles.site',
                'parent:id,phrase',
                'children' => fn (HasMany $query): HasMany => $query->orderBy('phrase'),
            ])
            ->selectRaw('keywords.*, '.static::wordCountExpression().' as word_count')
            ->withCount([
                'mainArticles as main_articles_count',
                ...Keyword::linkMapCountRelations(),
                'linkMaps as inbound_links_count',
                'children as children_count',
            ]);

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $siteIds = SeoAccessControl::accessibleSiteIds();
            $query->forSites($siteIds);
        }

        return static::applyMinimumKeywordWordCount(
            InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases($query),
        );
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function editKeywordFormSchema(Keyword $record): array
    {
        return [
            Forms\Components\Select::make('type')
                ->label(__('seo-content-ai::filament.keyword.type'))
                ->options(static::keywordTypeSelectOptions())
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    if ($state !== Keyword::TYPE_NORMAL) {
                        $set('parent_id', null);
                    }
                }),

            Forms\Components\TextInput::make('phrase')
                ->label(__('seo-content-ai::filament.keyword.phrase'))
                ->required()
                ->maxLength(255)
                ->unique(
                    table: Keyword::class,
                    column: 'phrase',
                    ignoreRecord: true,
                )
                ->rule(fn (Get $get): array => ($get('type') ?? $record->type) === Keyword::TYPE_NORMAL
                    ? [function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase((string) $value)) {
                            $fail(__('seo-content-ai::filament.keyword.anchor_text_invalid'));
                        }
                    }]
                    : []),

            Forms\Components\Select::make('parent_id')
                ->label('Từ khóa cha')
                ->options(function () use ($record): array {
                    $siteId = (int) (static::resolveKeywordSiteId($record) ?? 0);
                    if ($siteId <= 0) {
                        return [];
                    }

                    return static::parentKeywordSelectOptions(
                        $siteId,
                        (int) $record->id,
                        $record->parent_id ? (int) $record->parent_id : null,
                    );
                })
                ->getSearchResultsUsing(function (string $search) use ($record): array {
                    $siteId = (int) (static::resolveKeywordSiteId($record) ?? 0);
                    if ($siteId <= 0) {
                        return [];
                    }

                    return static::parentKeywordSelectOptions(
                        $siteId,
                        (int) $record->id,
                        $record->parent_id ? (int) $record->parent_id : null,
                        $search,
                    );
                })
                ->getOptionLabelUsing(fn (mixed $value): ?string => static::parentKeywordOptionLabel($value))
                ->visible(fn (Get $get): bool => ($get('type') ?? $record->type) === Keyword::TYPE_NORMAL)
                ->searchable()
                ->preload()
                ->native(false)
                ->nullable()
                ->helperText('Chọn parent sẽ chuyển keyword sang tab Pillar / Cluster.'),

            static::tagsSelectField(
                resolveSiteId: fn (): int => (int) (static::resolveKeywordSiteId($record) ?? 0),
            ),
        ];
    }

    public static function isUnused(Keyword $keyword): bool
    {
        $attributes = $keyword->getAttributes();
        if (
            ! array_key_exists('inbound_links_count', $attributes)
            || ! array_key_exists('children_count', $attributes)
            || ! array_key_exists('main_articles_count', $attributes)
            || ! array_key_exists('linked_articles_count', $attributes)
        ) {
            $keyword->loadCount([
                'mainArticles',
                ...Keyword::linkMapCountRelations(),
                'linkMaps as inbound_links_count',
                'children',
            ]);
        }

        if ((int) $keyword->inbound_links_count > 0 || (int) $keyword->children_count > 0) {
            return false;
        }

        if ($keyword->type === Keyword::TYPE_SUGGEST) {
            return (int) $keyword->main_articles_count === 0;
        }

        if (Keyword::isNormalType($keyword->type)) {
            return (int) $keyword->main_articles_count === 0
                && (int) $keyword->linked_articles_count === 0;
        }

        return (int) $keyword->linked_articles_count === 0;
    }

    private static function wordCountExpression(): string
    {
        return "CASE WHEN TRIM(phrase) = '' THEN 0 ELSE "
            ."LENGTH(REGEXP_REPLACE(TRIM(phrase), '[[:space:]]+', ' ')) "
            ."- LENGTH(REPLACE(REGEXP_REPLACE(TRIM(phrase), '[[:space:]]+', ' '), ' ', '')) + 1 END";
    }

    public static function applyMinimumKeywordWordCount(Builder $query): Builder
    {
        return $query->whereRaw(static::wordCountExpression().' >= 2');
    }

    public static function canAssignKeywordToContentProject(Keyword $keyword): bool
    {
        return SeoAccessControl::canMutateInSeoPanel()
            && in_array($keyword->type, [Keyword::TYPE_NORMAL, Keyword::TYPE_SUGGEST], true)
            && (int) ($keyword->main_articles_count ?? 0) < 1
            && ! static::keywordIsInContentProject($keyword);
    }

    public static function resolveKeywordSiteId(Keyword $keyword): ?int
    {
        return $keyword->resolveSiteId(SeoAccessControl::globalSiteId());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveKeywordDirectAssignData(?int $siteId = null): ?array
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        $targetSiteId = $siteId ?? $globalSiteId;
        if ($targetSiteId === null || (int) $targetSiteId <= 0) {
            return null;
        }

        $projectId = ArticleResource::resolveDirectAssignContentProjectId((int) $targetSiteId);
        if ($projectId === null) {
            return null;
        }

        $targetSiteId = (int) $targetSiteId;

        return [
            'site_ids' => [$targetSiteId],
            'project_id_'.$targetSiteId => $projectId,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $records
     */
    public static function resolveBulkKeywordsSiteId(Collection $records): ?int
    {
        $siteIds = $records
            ->filter(static fn (mixed $record): bool => $record instanceof Keyword)
            ->map(static fn (Keyword $keyword): ?int => static::resolveKeywordSiteId($keyword))
            ->filter(static fn (?int $siteId): bool => $siteId !== null && $siteId > 0)
            ->unique()
            ->values();

        return $siteIds->count() === 1 ? (int) $siteIds->first() : null;
    }

    public static function keywordAssignedContentProjectId(Keyword $keyword): ?int
    {
        $needle = mb_strtolower(trim((string) $keyword->phrase));
        $siteId = static::resolveKeywordSiteId($keyword) ?? 0;

        $query = SeoProjectTask::query()
            ->where('type', SeoProjectTask::TYPE_NEW_KEYWORD)
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle]);

        if ($siteId > 0) {
            $query->where(function (Builder $builder) use ($siteId): void {
                $builder
                    ->where('site_id', $siteId)
                    ->orWhereNull('site_id');
            });
        }

        $projectId = $query->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }

    public static function keywordIsInContentProject(Keyword $keyword): bool
    {
        return static::keywordAssignedContentProjectId($keyword) !== null;
    }

    /**
     * @param  list<int>|null  $defaultSiteIds
     * @return list<Forms\Components\Component>
     */
    public static function assignKeywordContentProjectFormSchema(?array $defaultSiteIds = null): array
    {
        $defaultSiteIds = collect($defaultSiteIds ?? [])
            ->filter(static fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(static fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values()
            ->all();

        if ($defaultSiteIds === [] && ($globalSiteId = SeoAccessControl::globalSiteId()) !== null) {
            $defaultSiteIds = [(int) $globalSiteId];
        }

        $domainOptions = static::siteSelectOptions();

        return [
            Forms\Components\Select::make('site_ids')
                ->label(__('seo-content-ai::filament.keyword.domain'))
                ->options(fn (): array => $domainOptions)
                ->default($defaultSiteIds)
                ->required()
                ->multiple()
                ->searchable()
                ->preload()
                ->native(false)
                ->live()
                ->helperText(__('seo-content-ai::filament.keyword.assign_to_content_project_sites_hint')),
            Forms\Components\Group::make()
                ->schema(function (Get $get) use ($domainOptions): array {
                    $siteIds = collect($get('site_ids') ?? [])
                        ->filter(static fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
                        ->map(static fn (mixed $siteId): int => (int) $siteId)
                        ->unique()
                        ->values();

                    return $siteIds
                        ->map(function (int $siteId) use ($domainOptions): Forms\Components\Select {
                            $label = trim((string) ($domainOptions[$siteId] ?? ('#'.$siteId)));

                            return ArticleResource::assignContentProjectSelectField(
                                fn (): ?int => $siteId,
                            )
                                ->name('project_id_'.$siteId)
                                ->label(__('seo-content-ai::filament.article_list.content_project').' — '.$label);
                        })
                        ->all();
                })
                ->visible(fn (Get $get): bool => collect($get('site_ids') ?? [])
                    ->filter(static fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
                    ->isNotEmpty()),
        ];
    }

    /**
     * @param  Collection<int, Keyword>|Collection<int, mixed>  $records
     * @param  array<string, mixed>  $data
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function executeAssignKeywordsToContentProjects(Collection $records, array $data): array
    {
        $summary = [
            'added' => 0,
            'duplicate' => 0,
            'overflow' => 0,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
        ];

        $siteIds = collect($data['site_ids'] ?? [])
            ->filter(static fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(static fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values();

        foreach ($siteIds as $siteId) {
            $projectId = (int) ($data['project_id_'.$siteId] ?? 0);
            if ($projectId <= 0) {
                continue;
            }

            $result = static::assignKeywordsToContentProject($records, $projectId, $siteId);
            foreach ($summary as $key => $value) {
                $summary[$key] = $value + (int) ($result[$key] ?? 0);
            }
        }

        return $summary;
    }

    public static function keywordAssignedContentProjectIdForSite(Keyword $keyword, int $siteId): ?int
    {
        $needle = mb_strtolower(trim((string) $keyword->phrase));
        if ($needle === '' || $siteId <= 0) {
            return null;
        }

        $projectId = SeoProjectTask::query()
            ->where('type', SeoProjectTask::TYPE_NEW_KEYWORD)
            ->where('site_id', $siteId)
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle])
            ->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }

    /**
     * @param  Collection<int, Keyword>|Collection<int, mixed>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function assignKeywordsToContentProject(Collection $records, int $projectId, int $targetSiteId): array
    {
        if ($targetSiteId <= 0) {
            return [
                'added' => 0,
                'duplicate' => 0,
                'overflow' => $records->count(),
                'domain_mismatch' => 0,
                'already_in_project' => 0,
            ];
        }

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
            ->filter(fn (mixed $record): bool => $record instanceof Keyword && static::canAssignKeywordToContentProject($record))
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
            $currentTotal = $project->registeredTaskCount();

            $existingKeys = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->get(['site_id', 'type', 'source_content'])
                ->map(static fn (SeoProjectTask $task): string => (int) $task->site_id.'|'.SeoProjectTask::TYPE_NEW_KEYWORD.'|'.mb_strtolower(trim((string) $task->source_content)))
                ->all();
            $existingMap = array_fill_keys($existingKeys, true);

            foreach ($records as $record) {
                if ($currentTotal >= $max) {
                    $overflow++;

                    continue;
                }

                $assignedProjectId = static::keywordAssignedContentProjectIdForSite($record, $targetSiteId);
                if ($assignedProjectId !== null) {
                    if ($assignedProjectId === $targetProjectId) {
                        $duplicate++;
                    } else {
                        $alreadyInProject++;
                    }

                    continue;
                }

                if ($projectSiteId > 0 && $targetSiteId !== $projectSiteId) {
                    $domainMismatch++;

                    continue;
                }

                $sourceContent = trim((string) $record->phrase);
                $siteId = $targetSiteId;
                $key = $siteId.'|'.SeoProjectTask::TYPE_NEW_KEYWORD.'|'.mb_strtolower($sourceContent);
                if (isset($existingMap[$key])) {
                    $duplicate++;

                    continue;
                }

                SeoProjectTask::query()->create([
                    'project_id' => (int) $project->id,
                    'site_id' => $siteId > 0 ? $siteId : null,
                    'article_id' => null,
                    'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
                    'source_content' => $sourceContent,
                    'description' => null,
                    'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                    'target_date' => $project->monthCarbon()->copy()->addDays($currentTotal)->format('Y-m-d'),
                    'status' => SeoProjectTask::STATUS_PENDING,
                ]);

                $existingMap[$key] = true;
                $currentTotal++;
                $added++;
            }

            $project->syncTotalTasksCounter();
        });

        if ($added > 0) {
            app(SeoNotificationService::class)->notifyProjectOwnerTasksAdded($project->fresh(), $added);
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
     * @param  callable(Get, ?Keyword): int  $resolveSiteId
     */
    public static function tagsSelectField(
        callable $resolveSiteId,
        bool $multiple = true,
        string $fieldName = 'tags',
        bool $required = false,
        ?callable $helperTextResolver = null,
        bool $useRelationship = false,
    ): Forms\Components\Select {
        $select = Forms\Components\Select::make($fieldName)
            ->label(__('seo-content-ai::filament.keyword.tags'))
            ->multiple($multiple)
            ->searchable()
            ->preload()
            ->native(false)
            ->required($required)
            ->options(static fn (): array => Tag::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
            ->createOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label(__('seo-content-ai::filament.keyword.tag_name'))
                    ->required()
                    ->maxLength(255),
            ])
            ->createOptionUsing(function (array $data): int {
                $name = trim((string) ($data['name'] ?? ''));

                return (int) app(TagPersistenceService::class)
                    ->findOrCreate($name)
                    ->getKey();
            });

        if ($helperTextResolver !== null) {
            $select->helperText(fn (): ?string => $helperTextResolver());
        }

        return $select;
    }

    /**
     * @return list<string>
     */
    public static function resolveTagLabelsForKeyword(Keyword $keyword): array
    {
        $tagIds = $keyword->getTagIdsList();
        if ($tagIds === []) {
            return [];
        }

        return Tag::query()
            ->whereIn('id', $tagIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateKeywordFormDataForFill(array $data, Keyword $record): array
    {
        $data['tags'] = $record->getTagIdsList();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveKeywordFromFormData(Keyword $record, array $data): Keyword
    {
        $tagIds = collect($data['tags'] ?? [])
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        unset($data['tags']);

        $record->update($data);
        app(KeywordMetaRepository::class)->setTagIds((int) $record->id, $tagIds);

        return $record->fresh();
    }

    public static function resolveUniqueTagSlug(string $name): string
    {
        return app(TagPersistenceService::class)->resolveUniqueSlug($name);
    }

    /**
     * @return array<int|string, string>
     */
    public static function parentKeywordSelectOptions(
        int $siteId,
        ?int $excludeKeywordId = null,
        ?int $ensureIncludedId = null,
        ?string $search = null,
    ): array {
        if ($siteId <= 0) {
            return [];
        }

        $query = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_NORMAL)
            ->whereNull('parent_id')
            ->when(
                $excludeKeywordId !== null && $excludeKeywordId > 0,
                fn (Builder $query): Builder => $query->where('id', '!=', $excludeKeywordId),
            )
            ->orderBy('phrase');

        if ($search !== null && trim($search) !== '') {
            $like = '%'.addcslashes(trim($search), '%_\\').'%';
            $query->where('phrase', 'like', $like);
        }

        $options = $query
            ->limit($search !== null && trim($search) !== '' ? 50 : 100)
            ->pluck('phrase', 'id')
            ->all();

        if ($ensureIncludedId !== null && $ensureIncludedId > 0 && ! array_key_exists($ensureIncludedId, $options)) {
            $phrase = static::parentKeywordOptionLabel($ensureIncludedId);
            if ($phrase !== null) {
                $options[$ensureIncludedId] = $phrase;
            }
        }

        return $options;
    }

    public static function parentKeywordOptionLabel(mixed $value): ?string
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        $phrase = Keyword::query()->whereKey((int) $value)->value('phrase');

        return is_string($phrase) && $phrase !== '' ? $phrase : null;
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return array<int|string, string>
     */
    public static function bulkParentOptions(Collection $records, ?string $search = null): array
    {
        $siteId = static::resolveBulkKeywordsSiteId($records);
        if ($siteId === null) {
            return [];
        }

        $excludeIds = $records
            ->filter(static fn (mixed $record): bool => $record instanceof Keyword)
            ->map(static fn (Keyword $keyword): int => (int) $keyword->id)
            ->all();

        $query = Keyword::query()
            ->forSite($siteId)
            ->whereNull('parent_id')
            ->when($excludeIds !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $excludeIds))
            ->orderBy('phrase');

        if ($search !== null && trim($search) !== '') {
            $like = '%'.addcslashes(trim($search), '%_\\').'%';
            $query->where('phrase', 'like', $like);
        }

        return $query
            ->limit($search !== null && trim($search) !== '' ? 50 : 100)
            ->pluck('phrase', 'id')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKeywords::route('/'),
            'focus' => Pages\ListFocusKeywords::route('/focus'),
            'anchor-audit' => Pages\AnchorTextAuditWorkspace::route('/anchor-audit'),
            'workspace-2' => Pages\KeywordWorkspaceTwo::route('/workspace-2'),
        ];
    }
}
