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

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('site_id')
                    ->label('Domain')
                    ->options(fn (): array => static::siteSelectOptions())
                    ->default(fn (): ?int => SeoAccessControl::globalSiteId())
                    ->hidden(fn (): bool => SeoAccessControl::hasGlobalSiteScope())
                    ->searchable()
                    ->preload()
                    ->required(fn (): bool => ! SeoAccessControl::hasGlobalSiteScope())
                    ->native(false),

                Forms\Components\TextInput::make('phrase')
                    ->label('Phrase / Anchor text')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'keywords',
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
                                $fail('Anchor text không được là URL hoặc đường dẫn.');
                            }
                        }]
                        : [])
                    ->columnSpanFull(),

                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->options([
                        Keyword::TYPE_FOCUS => 'Focus (từ khóa SEO)',
                        Keyword::TYPE_INTERNAL => 'Internal (anchor link)',
                    ])
                    ->default(Keyword::TYPE_FOCUS)
                    ->required()
                    ->native(false)
                    ->live(),

                Forms\Components\TextInput::make('target_url')
                    ->label('URL đích (internal)')
                    ->maxLength(2000)
                    ->url()
                    ->visible(fn (Get $get): bool => $get('type') === Keyword::TYPE_INTERNAL)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        $overview = app(DomainOverviewService::class);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('site.domain')
                    ->label('Domain')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%' . addcslashes($search, '%_\\') . '%';
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
                    ->label('Type')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        Keyword::TYPE_FOCUS => 'success',
                        Keyword::TYPE_INTERNAL => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Keyword::TYPE_FOCUS => 'Focus',
                        Keyword::TYPE_INTERNAL => 'Internal',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('phrase')
                    ->label('Phrase')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap(),

                Tables\Columns\TextColumn::make('main_articles_count')
                    ->label('Bài viết chính')
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
                    ->label('Bài viết liên kết')
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
                    ->label('Domain')
                    ->options(fn (): array => static::siteSelectOptions())
                    ->visible(fn (): bool => ! SeoAccessControl::hasGlobalSiteScope())
                    ->searchable()
                    ->preload()
                    ->native(false),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        Keyword::TYPE_FOCUS => 'Focus',
                        Keyword::TYPE_INTERNAL => 'Internal',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('write_article')
                    ->label('Viết bài')
                    ->icon('heroicon-o-pencil-square')
                    ->color('success')
                    ->visible(fn (Keyword $record): bool => $record->type === Keyword::TYPE_INTERNAL
                        && (int) ($record->main_articles_count ?? 0) < 1)
                    ->requiresConfirmation()
                    ->modalHeading('Viết bài từ keyword')
                    ->modalDescription(fn (Keyword $record): string => sprintf(
                        'Chạy quy trình «Đăng bài viết» (SEO → Tùy chỉnh) với từ khóa «%s» — gán vào biến focus_keyword trong prompt.',
                        $record->phrase,
                    ))
                    ->modalSubmitActionLabel('Chạy quy trình & tạo bài')
                    ->action(function (Keyword $record, CreateArticlesFromTaskService $service): void {
                        try {
                            $result = $service->runFromSingleKeyword(
                                (string) $record->phrase,
                                (int) $record->site_id,
                            );

                            CreateArticleWorkflowNotification::send(
                                $result,
                                'Đã chạy quy trình tạo bài',
                            );
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title('Không thể tạo bài')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('view_main_articles')
                    ->label('Bài chính')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->visible(fn (Keyword $record): bool => (int) ($record->main_articles_count ?? 0) > 0)
                    ->url(fn (Keyword $record) => $overview->buildArticlesFilterUrlForMainKeyword(
                        (int) $record->site_id,
                        (int) $record->id,
                    )),
                Tables\Actions\Action::make('view_linked_articles')
                    ->label('Bài có link')
                    ->icon('heroicon-o-link')
                    ->color('primary')
                    ->visible(fn (Keyword $record): bool => (int) ($record->linked_articles_count ?? 0) > 0)
                    ->url(fn (Keyword $record) => $overview->buildArticlesFilterUrlForInternalAnchorKeyword(
                        (int) $record->site_id,
                        (int) $record->id,
                    )),
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
            ->withCount([
                'mainArticles as main_articles_count',
                'articlesViaInternalLink as linked_articles_count',
            ]);

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        if (($globalSiteId = SeoAccessControl::globalSiteId()) !== null) {
            $query->where('site_id', $globalSiteId);
        }

        return InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases($query);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKeywords::route('/'),
            'create' => Pages\CreateKeyword::route('/create'),
        ];
    }
}
