<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\CreateArticlesFromTaskService;
use App\Addons\SeoContentAi\Services\DomainOverviewService;
use App\Addons\SeoContentAi\Support\CreateArticleWorkflowNotification;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Keyword::TYPE_FOCUS => __('seo-content-ai::filament.keyword.focus_short'),
                        Keyword::TYPE_INTERNAL => __('seo-content-ai::filament.keyword.internal_short'),
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('phrase')
                    ->label(__('seo-content-ai::filament.keyword.phrase_short'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color(fn (?string $state): ?string => mb_strlen(trim((string) $state)) > 10 ? 'danger' : null)
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
                    ]),
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
                    ->form(fn (Keyword $record): array => [
                        Forms\Components\TextInput::make('phrase')
                            ->label(__('seo-content-ai::filament.keyword.phrase'))
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: Keyword::class,
                                column: 'phrase',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule
                                    ->where('site_id', $record->site_id)
                                    ->where('type', $record->type),
                            )
                            ->rule($record->type === Keyword::TYPE_INTERNAL
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
                            ->visible($record->type === Keyword::TYPE_FOCUS)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                    ]),
                Tables\Actions\Action::make('write_article')
                    ->label(__('seo-content-ai::filament.keyword.write_article'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('success')
                    ->visible(fn (Keyword $record): bool => $record->type === Keyword::TYPE_INTERNAL
                        && (int) ($record->main_articles_count ?? 0) < 1)
                    ->requiresConfirmation()
                    ->modalHeading(__('seo-content-ai::filament.keyword.write_article_from_keyword'))
                    ->modalDescription(fn (Keyword $record): string => sprintf(
                        __('seo-content-ai::filament.keyword.write_article_description'),
                        $record->phrase,
                    ))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.keyword.run_workflow_create_article'))
                    ->action(function (Keyword $record, CreateArticlesFromTaskService $service): void {
                        try {
                            $result = $service->runFromSingleKeyword(
                                (string) $record->phrase,
                                (int) $record->site_id,
                            );

                            CreateArticleWorkflowNotification::send(
                                $result,
                                __('seo-content-ai::filament.keyword.write_article'),
                            );
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title(__('seo-content-ai::filament.keyword.cannot_create_article'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
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
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Keyword $record): bool => static::isUnused($record)),
            ])
            ->bulkActions([]);
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['site'])
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

        return InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases($query);
    }

    public static function isUnused(Keyword $keyword): bool
    {
        if ($keyword->parent_id !== null) {
            return false;
        }

        $attributes = $keyword->getAttributes();
        if (
            ! array_key_exists('articles_count', $attributes)
            || ! array_key_exists('inbound_links_count', $attributes)
            || ! array_key_exists('children_count', $attributes)
        ) {
            $keyword->loadCount(['articles', 'inboundLinks', 'children']);
        }

        return (int) $keyword->articles_count === 0
            && (int) $keyword->inbound_links_count === 0
            && (int) $keyword->children_count === 0;
    }

    private static function wordCountExpression(): string
    {
        return "CASE WHEN TRIM(phrase) = '' THEN 0 ELSE "
            ."LENGTH(REGEXP_REPLACE(TRIM(phrase), '[[:space:]]+', ' ')) "
            ."- LENGTH(REPLACE(REGEXP_REPLACE(TRIM(phrase), '[[:space:]]+', ' '), ' ', '')) + 1 END";
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKeywords::route('/'),
        ];
    }
}
