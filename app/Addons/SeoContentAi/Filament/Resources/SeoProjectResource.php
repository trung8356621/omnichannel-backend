<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\SeoProjectKeywordAiGeneratorService;
use App\Addons\SeoContentAi\Services\SeoProjectKeywordListParser;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SeoProjectResource extends Resource
{
    protected static ?string $model = SeoProject::class;

    protected static ?string $slug = 'content-projects';

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Content projects';

    protected static ?string $modelLabel = 'Content project';

    protected static ?string $pluralModelLabel = 'Content projects';

    protected static ?int $navigationSort = 8;

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

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.content_projects');
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.content_project');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.content_projects');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.projects.project_info'))
                    ->schema([
                        Forms\Components\Placeholder::make('project_name_preview')
                            ->label(__('seo-content-ai::filament.projects.project_name'))
                            ->content(
                                fn (Get $get): string => $get('month')
                                    ? SeoProject::defaultNameFromMonth($get('month'))
                                    : __('seo-content-ai::filament.projects.project_name_placeholder'),
                            )
                            ->columnSpanFull(),

                        Forms\Components\Select::make('user_id')
                            ->label(__('seo-content-ai::filament.projects.assign_writer'))
                            ->options(fn (): array => static::userSelectOptions())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),

                        Forms\Components\DatePicker::make('month')
                            ->label(__('seo-content-ai::filament.projects.execution_month'))
                            ->native(false)
                            ->displayFormat('m/Y')
                            ->format('Y-m-d')
                            ->default(fn (): string => now()->startOfMonth()->format('Y-m-d'))
                            ->required()
                            ->live(),

                        Forms\Components\Select::make('status')
                            ->label(__('seo-content-ai::filament.projects.status'))
                            ->options(SeoProject::statusOptions())
                            ->default(SeoProject::STATUS_RUNNING)
                            ->required()
                            ->native(false),

                        Forms\Components\Textarea::make('description')
                            ->label(__('seo-content-ai::filament.projects.description'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('seo-content-ai::filament.projects.article_keyword_list'))
                    ->description(__('seo-content-ai::filament.projects.article_keyword_list_description'))
                    ->schema([
                        Forms\Components\Placeholder::make('month_limit_hint')
                            ->label(__('seo-content-ai::filament.projects.month_limit'))
                            ->content(function (Get $get): string {
                                $month = $get('month');
                                if (! $month) {
                                    return __('seo-content-ai::filament.projects.choose_month_to_view_limit');
                                }

                                $carbon = Carbon::parse($month)->startOfMonth();
                                $max = $carbon->daysInMonth;
                                $count = count($get('tasks_data') ?? []);

                                return __('seo-content-ai::filament.projects.month_limit_hint', [
                                    'month' => $carbon->format('m/Y'),
                                    'max' => $max,
                                    'count' => $count,
                                ]);
                            })
                            ->columnSpanFull(),

                        Forms\Components\Actions::make([
                            Action::make('import_keywords')
                                ->label(__('seo-content-ai::filament.projects.keyword_list'))
                                ->icon('heroicon-o-queue-list')
                                ->iconButton()
                                ->tooltip(__('seo-content-ai::filament.projects.keyword_list_tooltip'))
                                ->color('gray')
                                ->modalHeading(__('seo-content-ai::filament.projects.import_keyword_list'))
                                ->modalDescription(__('seo-content-ai::filament.projects.import_keyword_list_description'))
                                ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.add_to_project'))
                                ->form([
                                    Forms\Components\Textarea::make('keywords_text')
                                        ->label(__('seo-content-ai::filament.projects.keywords'))
                                        ->placeholder("non-woven bags\nhow to sew fabric bags\n- canvas bags")
                                        ->rows(12)
                                        ->required(),
                                ])
                                ->action(function (array $data, Get $get, Set $set): void {
                                    static::appendKeywordsToFormState($get, $set, $data['keywords_text'] ?? '');
                                }),

                            Action::make('ai_generate_keywords')
                                ->label(__('seo-content-ai::filament.projects.ai_generator'))
                                ->icon('heroicon-o-sparkles')
                                ->iconButton()
                                ->tooltip(__('seo-content-ai::filament.projects.ai_generator_tooltip'))
                                ->color('primary')
                                ->modalHeading(__('seo-content-ai::filament.projects.ai_generator_heading'))
                                ->modalDescription(__('seo-content-ai::filament.projects.ai_generator_description'))
                                ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.generate_keywords'))
                                ->form([
                                    Forms\Components\TextInput::make('count')
                                        ->label(__('seo-content-ai::filament.projects.number_of_keywords'))
                                        ->numeric()
                                        ->minValue(1)
                                        ->maxValue(31)
                                        ->default(10)
                                        ->required(),
                                    Forms\Components\Textarea::make('brief')
                                        ->label(__('seo-content-ai::filament.projects.additional_ai_brief'))
                                        ->placeholder(__('seo-content-ai::filament.projects.additional_ai_brief_placeholder'))
                                        ->rows(4),
                                ])
                                ->action(function (array $data, Get $get, Set $set): void {
                                    static::generateKeywordsWithAi($get, $set, $data);
                                }),
                        ])
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('tasks_data')
                            ->label(__('seo-content-ai::filament.projects.article_items'))
                            ->schema([
                                Forms\Components\Select::make('site_id')
                                    ->label(__('seo-content-ai::filament.projects.domain'))
                                    ->options(fn (): array => static::siteSelectOptions())
                                    ->default(fn (): ?int => SeoAccessControl::globalSiteId())
                                    ->hidden(fn (): bool => SeoAccessControl::hasGlobalSiteScope())
                                    ->searchable()
                                    ->preload()
                                    ->required(fn (): bool => ! SeoAccessControl::hasGlobalSiteScope())
                                    ->native(false)
                                    ->dehydrateStateUsing(fn (mixed $state): ?int => $state !== null && $state !== ''
                                        ? (int) $state
                                        : null),

                                Forms\Components\Select::make('type')
                                    ->label(__('seo-content-ai::filament.projects.article_type'))
                                    ->options(SeoProjectTask::typeOptions())
                                    ->default(SeoProjectTask::TYPE_NEW_KEYWORD)
                                    ->required()
                                    ->native(false)
                                    ->live(),

                                Forms\Components\TextInput::make('source_content')
                                    ->label(fn (Get $get): string => $get('type') === SeoProjectTask::TYPE_REWRITE
                                        ? __('seo-content-ai::filament.projects.title_of_article_to_rewrite')
                                        : __('seo-content-ai::filament.projects.keyword'))
                                    ->placeholder(fn (Get $get): string => $get('type') === SeoProjectTask::TYPE_REWRITE
                                        ? __('seo-content-ai::filament.projects.title_to_rewrite_placeholder')
                                        : __('seo-content-ai::filament.projects.keyword_placeholder'))
                                    ->required()
                                    ->maxLength(500),

                                Forms\Components\Textarea::make('description')
                                    ->label(__('seo-content-ai::filament.projects.description'))
                                    ->placeholder(__('seo-content-ai::filament.projects.description_placeholder'))
                                    ->rows(3)
                                    ->visible(fn (Get $get): bool => $get('type') === SeoProjectTask::TYPE_NEW_KEYWORD)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->addActionLabel(__('seo-content-ai::filament.projects.add_article'))
                            ->reorderable()
                            ->live()
                            ->columnSpanFull()
                            ->rules([
                                fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    $month = $get('month');
                                    if (! $month) {
                                        return;
                                    }

                                    try {
                                        app(SeoProjectTaskSyncService::class)
                                            ->assertWithinMonthlyLimit($month, is_array($value) ? $value : []);
                                    } catch (\Illuminate\Validation\ValidationException $e) {
                                        $fail($e->validator->errors()->first('tasks_data') ?? __('seo-content-ai::filament.projects.exceeded_monthly_limit'));
                                    }
                                },
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.projects.project_name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('seo-content-ai::filament.projects.owner'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('month')
                    ->label(__('seo-content-ai::filament.projects.month'))
                    ->date('m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_tasks')
                    ->label(__('seo-content-ai::filament.projects.total_items'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tasks_completed')
                    ->label(__('seo-content-ai::filament.projects.completed'))
                    ->getStateUsing(
                        fn (SeoProject $record): string => (string) $record->tasks()
                            ->where('status', SeoProjectTask::STATUS_COMPLETED)
                            ->count(),
                    )
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.projects.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SeoProject::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        SeoProject::STATUS_PENDING => 'gray',
                        SeoProject::STATUS_RUNNING => 'warning',
                        SeoProject::STATUS_COMPLETED => 'success',
                        SeoProject::STATUS_PAUSED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('seo-content-ai::filament.projects.updated'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('month', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('seo-content-ai::filament.projects.status'))
                    ->options(SeoProject::statusOptions()),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label(__('seo-content-ai::filament.projects.writer'))
                    ->options(fn (): array => static::userSelectOptions()),

                Tables\Filters\Filter::make('month')
                    ->form([
                        Forms\Components\DatePicker::make('month')
                            ->label(__('seo-content-ai::filament.projects.month'))
                            ->native(false)
                            ->displayFormat('m/Y')
                            ->format('Y-m-d'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['month'])) {
                            return $query;
                        }

                        $start = Carbon::parse($data['month'])->startOfMonth();

                        return $query->whereDate('month', $start->format('Y-m-d'));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');

        if (($globalSiteId = SeoAccessControl::globalSiteId()) !== null) {
            $query->whereHas('tasks', fn (Builder $taskQuery): Builder => $taskQuery->where('site_id', $globalSiteId));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeoProjects::route('/'),
            'create' => Pages\CreateSeoProject::route('/create'),
            'edit' => Pages\EditSeoProject::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function userSelectOptions(): array
    {
        return User::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
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

    public static function appendKeywordsToFormState(Get $get, Set $set, string $rawText): void
    {
        $month = $get('month');
        if (! $month) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.select_month_first'))
                ->warning()
                ->send();

            return;
        }

        $keywords = app(SeoProjectKeywordListParser::class)->parse($rawText);
        if ($keywords === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.no_valid_keywords'))
                ->warning()
                ->send();

            return;
        }

        $merged = app(SeoProjectKeywordListParser::class)->appendKeywordsToTasks(
            is_array($get('tasks_data')) ? $get('tasks_data') : [],
            $keywords,
        );

        try {
            app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($month, $merged);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.monthly_limit_exceeded'))
                ->body($exception->validator->errors()->first('tasks_data') ?? '')
                ->danger()
                ->send();

            return;
        }

        $set('tasks_data', $merged);

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.added_keywords', ['count' => count($keywords)]))
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function generateKeywordsWithAi(Get $get, Set $set, array $data): void
    {
        $month = $get('month');
        if (! $month) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.select_month_first'))
                ->warning()
                ->send();

            return;
        }

        $existing = is_array($get('tasks_data')) ? $get('tasks_data') : [];
        $maxMonth = app(SeoProjectTaskSyncService::class)->maxTasksForMonth($month);
        $remaining = max(0, $maxMonth - count($existing));

        if ($remaining === 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.monthly_capacity_reached'))
                ->body(__('seo-content-ai::filament.projects.maximum_items', ['max' => $maxMonth]))
                ->warning()
                ->send();

            return;
        }

        $requested = min($remaining, max(1, (int) ($data['count'] ?? 10)));

        try {
            $keywords = app(SeoProjectKeywordAiGeneratorService::class)->generate(
                month: $month,
                count: $requested,
                brief: (string) ($data['brief'] ?? ''),
                description: (string) ($get('description') ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.unable_to_generate_keywords'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $merged = app(SeoProjectKeywordListParser::class)->appendKeywordsToTasks($existing, $keywords);

        try {
            app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($month, $merged);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.monthly_limit_exceeded'))
                ->body($exception->validator->errors()->first('tasks_data') ?? '')
                ->danger()
                ->send();

            return;
        }

        $set('tasks_data', $merged);

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.ai_added_keywords', ['count' => count($keywords)]))
            ->success()
            ->send();
    }
}
