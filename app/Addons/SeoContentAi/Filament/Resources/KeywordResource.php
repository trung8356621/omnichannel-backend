<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\DomainOverviewService;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rules\Unique;

class KeywordResource extends Resource
{
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

    public static function canCreate(): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canDelete(Model $record): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures()
            && $record instanceof Keyword
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
                        modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                            return $rule
                                ->where('site_id', $get('site_id'))
                                ->where('type', $get('type'));
                        },
                    )
                    ->rule(fn (Get $get): array => $get('type') === Keyword::TYPE_INTERNAL
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
                        $siteId = (int) ($get('site_id') ?? $record?->site_id ?? 0);
                        if ($siteId <= 0) {
                            return [];
                        }

                        return Keyword::query()
                            ->where('site_id', $siteId)
                            ->where('type', Keyword::TYPE_FOCUS)
                            ->whereNull('parent_id')
                            ->when($record, fn (Builder $query): Builder => $query->where('id', '!=', $record->id))
                            ->orderBy('phrase')
                            ->pluck('phrase', 'id')
                            ->all();
                    })
                    ->visible(fn (Get $get): bool => $get('type') === Keyword::TYPE_FOCUS)
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->nullable()
                    ->helperText('Chọn parent sẽ chuyển keyword sang tab Pillar / Cluster.'),

                Forms\Components\Select::make('type')
                    ->label(__('seo-content-ai::filament.keyword.type'))
                    ->options([
                        Keyword::TYPE_FOCUS => __('seo-content-ai::filament.keyword.focus'),
                        Keyword::TYPE_INTERNAL => __('seo-content-ai::filament.keyword.internal'),
                        Keyword::TYPE_SUGGEST => __('seo-content-ai::filament.keyword.suggest'),
                    ])
                    ->default(Keyword::TYPE_FOCUS)
                    ->required()
                    ->native(false)
                    ->live(),

            ]);
    }

    public static function table(Table $table): Table
    {
        $overview = app(DomainOverviewService::class);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('seo-content-ai::filament.keyword.domain'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.addcslashes($search, '%_\\').'%';
                        $siteIds = Site::query()->where('domain', 'like', $like)->pluck('id');

                        if ($siteIds->isEmpty()) {
                            return $query->whereRaw('0 = 1');
                        }

                        return $query->whereIn('site_id', $siteIds);
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy(
                            Site::query()
                                ->select('domain')
                                ->whereColumn('sites.id', 'keywords.site_id')
                                ->limit(1),
                            $direction,
                        );
                    }),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('seo-content-ai::filament.keyword.type'))
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        Keyword::TYPE_FOCUS => 'success',
                        Keyword::TYPE_INTERNAL => 'warning',
                        Keyword::TYPE_SUGGEST => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Keyword::TYPE_FOCUS => __('seo-content-ai::filament.keyword.focus_short'),
                        Keyword::TYPE_INTERNAL => __('seo-content-ai::filament.keyword.internal_short'),
                        Keyword::TYPE_SUGGEST => __('seo-content-ai::filament.keyword.suggest_short'),
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('phrase')
                    ->label(__('seo-content-ai::filament.keyword.phrase_short'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color(fn (?string $state): ?string => mb_strlen(trim((string) $state)) > 10 ? 'danger' : null)
                    ->wrap(),

                Tables\Columns\TextColumn::make('children.phrase')
                    ->label('Từ khóa con')
                    ->badge()
                    ->separator(',')
                    ->limitList(12)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('word_count')
                    ->label('Số từ')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw(static::wordCountExpression().' '.$direction))
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('main_articles_count')
                    ->label(__('seo-content-ai::filament.keyword.main_articles'))
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->url(function (Keyword $record) use ($overview): ?string {
                        if ((int) ($record->main_articles_count ?? 0) < 1) {
                            return null;
                        }

                        return $overview->buildArticlesFilterUrlForMainKeyword(
                            (int) $record->site_id,
                            (int) $record->id,
                        );
                    }),

                Tables\Columns\TextColumn::make('linked_articles_count')
                    ->label(__('seo-content-ai::filament.keyword.linked_articles'))
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('primary')
                    ->url(function (Keyword $record) use ($overview): ?string {
                        if ((int) ($record->linked_articles_count ?? 0) < 1) {
                            return null;
                        }

                        return $overview->buildArticlesFilterUrlForInternalAnchorKeyword(
                            (int) $record->site_id,
                            (int) $record->id,
                        );
                    }),
            ])
            ->defaultSort('phrase')
            ->filters([
                Tables\Filters\SelectFilter::make('site_id')
                    ->label(__('seo-content-ai::filament.keyword.domain'))
                    ->options(fn (): array => static::siteSelectOptions())
                    ->visible(fn (): bool => ! SeoAccessControl::hasGlobalSiteScope())
                    ->searchable()
                    ->preload()
                    ->native(false),
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('seo-content-ai::filament.keyword.type'))
                    ->options([
                        Keyword::TYPE_FOCUS => __('seo-content-ai::filament.keyword.focus_short'),
                        Keyword::TYPE_INTERNAL => __('seo-content-ai::filament.keyword.internal_short'),
                        Keyword::TYPE_SUGGEST => __('seo-content-ai::filament.keyword.suggest_short'),
                    ]),
                Tables\Filters\SelectFilter::make('parent_id')
                    ->label('Cụm cha')
                    ->options(fn (): array => static::clusterParentOptions())
                    ->searchable()
                    ->preload()
                    ->native(false),
                Tables\Filters\Filter::make('word_count')
                    ->label('Số từ')
                    ->form([
                        Forms\Components\TextInput::make('value')
                            ->label('Số từ')
                            ->numeric()
                            ->integer()
                            ->minValue(1),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $wordCount = (int) ($data['value'] ?? 0);

                        return $wordCount > 0
                            ? $query->whereRaw(static::wordCountExpression().' = ?', [$wordCount])
                            : $query;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->modalHeading('Sửa keyword')
                    ->form(fn (Keyword $record): array => static::editKeywordFormSchema($record)),
                Tables\Actions\Action::make('assign_to_content_project')
                    ->label(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                    ->icon('heroicon-o-folder-plus')
                    ->color('warning')
                    ->visible(fn (Keyword $record): bool => static::canAssignKeywordToContentProject($record))
                    ->requiresConfirmation()
                    ->form(fn (Keyword $record): array => [
                        ArticleResource::assignContentProjectSelectField(
                            fn (): ?int => static::resolveKeywordSiteId($record),
                        ),
                    ])
                    ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                    ->modalDescription(__('seo-content-ai::filament.keyword.assign_to_content_project_description'))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
                    ->action(function (Keyword $record, array $data): void {
                        $projectId = (int) ($data['project_id'] ?? 0);
                        $summary = static::assignKeywordsToContentProject(
                            Collection::make([$record]),
                            $projectId,
                        );

                        Notification::make()
                            ->title(__('seo-content-ai::filament.keyword.assign_completed'))
                            ->body(ArticleResource::buildAssignContentProjectBody($summary))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('view_main_articles')
                    ->label(__('seo-content-ai::filament.keyword.main_articles'))
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->visible(fn (Keyword $record): bool => (int) ($record->main_articles_count ?? 0) > 0)
                    ->url(fn (Keyword $record) => $overview->buildArticlesFilterUrlForMainKeyword(
                        (int) $record->site_id,
                        (int) $record->id,
                    )),
                Tables\Actions\Action::make('view_linked_articles')
                    ->label(__('seo-content-ai::filament.keyword.linked_articles'))
                    ->icon('heroicon-o-link')
                    ->color('primary')
                    ->visible(fn (Keyword $record): bool => (int) ($record->linked_articles_count ?? 0) > 0)
                    ->url(fn (Keyword $record) => $overview->buildArticlesFilterUrlForInternalAnchorKeyword(
                        (int) $record->site_id,
                        (int) $record->id,
                    )),
                Tables\Actions\Action::make('view_cluster_children')
                    ->label('Chi tiết cụm')
                    ->icon('heroicon-o-tag')
                    ->color('gray')
                    ->visible(fn (Keyword $record): bool => (int) ($record->children_count ?? 0) > 0)
                    ->url(fn (Keyword $record): string => static::getUrl('index', [
                        'activeTab' => 'all',
                        'tableFilters' => [
                            'parent_id' => ['value' => (string) $record->id],
                        ],
                    ])),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Keyword $record): bool => static::isUnused($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('switch_type')
                        ->label(__('seo-content-ai::filament.keyword.bulk_switch_type'))
                        ->icon('heroicon-o-arrows-right-left')
                        ->form([
                            Forms\Components\Select::make('type')
                                ->label(__('seo-content-ai::filament.keyword.type'))
                                ->options([
                                    Keyword::TYPE_FOCUS => __('seo-content-ai::filament.keyword.focus'),
                                    Keyword::TYPE_INTERNAL => __('seo-content-ai::filament.keyword.internal'),
                                    Keyword::TYPE_SUGGEST => __('seo-content-ai::filament.keyword.suggest'),
                                ])
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
                                if ($type !== Keyword::TYPE_FOCUS) {
                                    $updates['parent_id'] = null;
                                }

                                if ($record->type === $type && ($type === Keyword::TYPE_FOCUS || $record->parent_id === null)) {
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
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->form(fn (Collection $records): array => [
                            ArticleResource::assignContentProjectSelectField(
                                fn (): ?int => static::resolveBulkKeywordsSiteId($records),
                                fn (): ?string => static::resolveBulkKeywordsSiteId($records) === null
                                    ? __('seo-content-ai::filament.article_list.assign_projects_mixed_domains')
                                    : null,
                            ),
                        ])
                        ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                        ->modalDescription(__('seo-content-ai::filament.keyword.assign_to_content_project_description'))
                        ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
                        ->action(function (Collection $records, array $data): void {
                            $projectId = (int) ($data['project_id'] ?? 0);
                            $summary = static::assignKeywordsToContentProject($records, $projectId);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.keyword.assign_completed'))
                                ->body(ArticleResource::buildAssignContentProjectBody($summary))
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    public static function siteSelectOptions(): array
    {
        $query = Site::query()->orderBy('domain');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query->pluck('domain', 'id')->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function clusterParentOptions(): array
    {
        $query = Keyword::query()
            ->where('type', Keyword::TYPE_FOCUS)
            ->whereNull('parent_id')
            ->whereHas('children')
            ->whereRaw(static::wordCountExpression().' >= 2')
            ->orderBy('phrase');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        if (($globalSiteId = SeoAccessControl::globalSiteId()) !== null) {
            $query->where('site_id', $globalSiteId);
        }

        return $query->pluck('phrase', 'id')->all();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['site', 'children' => fn (HasMany $query): HasMany => $query->orderBy('phrase')])
            ->selectRaw('keywords.*, '.static::wordCountExpression().' as word_count')
            ->withCount([
                'articles as articles_count',
                'mainArticles as main_articles_count',
                'articlesViaInternalLink as linked_articles_count',
                'inboundLinks as inbound_links_count',
                'children as children_count',
            ]);

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        if (($globalSiteId = SeoAccessControl::globalSiteId()) !== null) {
            $query->where('site_id', $globalSiteId);
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
                ->options([
                    Keyword::TYPE_FOCUS => __('seo-content-ai::filament.keyword.focus'),
                    Keyword::TYPE_INTERNAL => __('seo-content-ai::filament.keyword.internal'),
                    Keyword::TYPE_SUGGEST => __('seo-content-ai::filament.keyword.suggest'),
                ])
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    if ($state !== Keyword::TYPE_FOCUS) {
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
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('site_id', $record->site_id)
                        ->where('type', $get('type') ?? $record->type),
                )
                ->rule(fn (Get $get): array => ($get('type') ?? $record->type) === Keyword::TYPE_INTERNAL
                    ? [function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase((string) $value)) {
                            $fail(__('seo-content-ai::filament.keyword.anchor_text_invalid'));
                        }
                    }]
                    : []),

            Forms\Components\Select::make('parent_id')
                ->label('Từ khóa cha')
                ->options(fn (): array => Keyword::query()
                    ->where('site_id', $record->site_id)
                    ->where('type', Keyword::TYPE_FOCUS)
                    ->whereNull('parent_id')
                    ->where('id', '!=', $record->id)
                    ->orderBy('phrase')
                    ->pluck('phrase', 'id')
                    ->all())
                ->visible(fn (Get $get): bool => ($get('type') ?? $record->type) === Keyword::TYPE_FOCUS)
                ->searchable()
                ->preload()
                ->native(false)
                ->nullable()
                ->helperText('Chọn parent sẽ chuyển keyword sang tab Pillar / Cluster.'),
        ];
    }

    public static function isUnused(Keyword $keyword): bool
    {
        $attributes = $keyword->getAttributes();
        if (
            ! array_key_exists('inbound_links_count', $attributes)
            || ! array_key_exists('children_count', $attributes)
            || (in_array($keyword->type, [Keyword::TYPE_FOCUS, Keyword::TYPE_SUGGEST], true) && ! array_key_exists('main_articles_count', $attributes))
            || ($keyword->type === Keyword::TYPE_INTERNAL && ! array_key_exists('articles_count', $attributes))
        ) {
            $keyword->loadCount([
                'articles',
                'mainArticles',
                'inboundLinks',
                'children',
            ]);
        }

        if ((int) $keyword->inbound_links_count > 0 || (int) $keyword->children_count > 0) {
            return false;
        }

        if (in_array($keyword->type, [Keyword::TYPE_FOCUS, Keyword::TYPE_SUGGEST], true)) {
            return (int) $keyword->main_articles_count === 0;
        }

        return (int) $keyword->articles_count === 0;
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
        return in_array($keyword->type, [Keyword::TYPE_INTERNAL, Keyword::TYPE_FOCUS, Keyword::TYPE_SUGGEST], true)
            && (int) ($keyword->main_articles_count ?? 0) < 1
            && ! static::keywordIsInContentProject($keyword);
    }

    public static function resolveKeywordSiteId(Keyword $keyword): ?int
    {
        $siteId = (int) ($keyword->site_id ?? 0);

        return $siteId > 0 ? $siteId : SeoAccessControl::globalSiteId();
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
     * @param  Collection<int, Keyword>|Collection<int, mixed>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function assignKeywordsToContentProject(Collection $records, int $projectId): array
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
            $currentTotal = (int) ($project->total_tasks ?? 0);

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

                $assignedProjectId = static::keywordAssignedContentProjectId($record);
                if ($assignedProjectId !== null) {
                    if ($assignedProjectId === $targetProjectId) {
                        $duplicate++;
                    } else {
                        $alreadyInProject++;
                    }

                    continue;
                }

                $keywordSiteId = static::resolveKeywordSiteId($record) ?? 0;
                if ($projectSiteId > 0 && $keywordSiteId !== $projectSiteId) {
                    $domainMismatch++;

                    continue;
                }

                $sourceContent = trim((string) $record->phrase);
                $siteId = $projectSiteId > 0 ? $projectSiteId : $keywordSiteId;
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKeywords::route('/'),
        ];
    }
}
