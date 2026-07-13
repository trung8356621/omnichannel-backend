<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\SeoProjectArchiveService;
use App\Addons\SeoContentAi\Services\SeoProjectKeywordAiGeneratorService;
use App\Addons\SeoContentAi\Services\SeoProjectKeywordListParser;
use App\Addons\SeoContentAi\Services\SeoProjectRunPreflightService;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
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
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class SeoProjectResource extends SeoPanelResource
{
    protected static ?string $model = SeoProject::class;

    public const PROJECT_WORKSPACE_TABS_ID = 'project_workspace';

    protected static ?string $slug = 'content-projects';

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Content projects';

    protected static ?string $modelLabel = 'Content project';

    protected static ?string $pluralModelLabel = 'Content projects';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessContentFeatures();
    }

    public static function canCreate(): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return SeoAccessControl::canMutateContentProjects();
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        if (! $record instanceof SeoProject) {
            return false;
        }

        if (SeoAccessControl::isContentManager()) {
            return (int) $record->user_id === (int) auth()->id();
        }

        return SeoAccessControl::canAccessPlannerFeatures()
            && static::getEloquentQuery()->whereKey($record->getKey())->exists();
    }

    public static function projectRecordUrl(SeoProject $record): string
    {
        if (static::canEdit($record)) {
            return static::getUrl('edit', ['record' => $record]);
        }

        return static::getUrl('view', ['record' => $record]);
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
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
                Forms\Components\Tabs::make('project_workspace_tabs')
                    ->id(self::PROJECT_WORKSPACE_TABS_ID)
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('current')
                            ->label(__('seo-content-ai::filament.projects.tab_current_articles'))
                            ->schema(static::currentArticlesFormSchema()),
                        Forms\Components\Tabs\Tab::make('archives')
                            ->label(fn (?SeoProject $record): string => $record instanceof SeoProject
                                ? __('seo-content-ai::filament.projects.tab_archives_count', [
                                    'count' => static::archivesCountFor($record),
                                ])
                                : __('seo-content-ai::filament.projects.tab_archives'))
                            ->visible(fn (?SeoProject $record): bool => $record instanceof SeoProject
                                && SeoAccessControl::canViewProjectArchives())
                            ->schema([
                                Forms\Components\View::make('seo-content-ai::filament.resources.seo-project-resource.partials.archives-tab')
                                    ->viewData(fn (?SeoProject $record): array => [
                                        'batches' => $record instanceof SeoProject
                                            ? app(SeoProjectArchiveService::class)->batchesForProject($record)
                                            : collect(),
                                    ]),
                            ]),
                    ])
                    ->activeTab(fn (): int => static::resolveWorkspaceTabIndex())
                    ->persistTabInQueryString('tab')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function currentArticlesFormSchema(): array
    {
        return [
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
                        ->disabled(fn (): bool => SeoAccessControl::isContentManager())
                        ->dehydrated()
                        ->native(false),

                    Forms\Components\Select::make('site_id')
                        ->label(__('seo-content-ai::filament.projects.domain'))
                        ->options(fn (): array => static::siteSelectOptions())
                        ->default(fn (): ?int => SeoAccessControl::globalSiteId())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->live()
                        ->dehydrateStateUsing(fn (mixed $state): ?int => $state !== null && $state !== ''
                            ? (int) $state
                            : null),

                    Forms\Components\DatePicker::make('month')
                        ->label(__('seo-content-ai::filament.projects.execution_month'))
                        ->native(false)
                        ->displayFormat('m/Y')
                        ->format('Y-m-d')
                        ->default(fn (): string => now()->startOfMonth()->format('Y-m-d'))
                        ->required()
                        ->live(),

                    Forms\Components\Hidden::make('status')
                        ->default(SeoProject::STATUS_MANUAL)
                        ->dehydrated(),

                    Forms\Components\Placeholder::make('status_display')
                        ->label(__('seo-content-ai::filament.projects.status'))
                        ->content(fn (?SeoProject $record): string => $record instanceof SeoProject
                            ? (SeoProject::statusOptions()[(string) $record->status] ?? (string) $record->status)
                            : __('seo-content-ai::filament.projects.status_manual_fixed')),

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
                            $count = app(SeoProjectTaskSyncService::class)
                                ->countEffectiveTasks(is_array($get('tasks_data')) ? $get('tasks_data') : []);
                            $monthOpen = now()->lte($carbon->copy()->endOfMonth()->endOfDay());

                            return __('seo-content-ai::filament.projects.month_limit_hint', [
                                'month' => $carbon->format('m/Y'),
                                'max' => $max,
                                'count' => $count,
                            ]).($monthOpen
                                ? ''
                                : ' '.__('seo-content-ai::filament.projects.execution_month_closed_short'));
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
                            Forms\Components\Select::make('type')
                                ->label(__('seo-content-ai::filament.projects.article_type'))
                                ->options(SeoProjectTask::typeOptions())
                                ->default(SeoProjectTask::TYPE_NEW_KEYWORD)
                                ->required()
                                ->native(false)
                                ->live(),

                            Forms\Components\TextInput::make('source_content')
                                ->label(fn (Get $get): string => $get('type') === SeoProjectTask::TYPE_NEW_TITLE
                                    ? 'Tiêu đề'
                                    : __('seo-content-ai::filament.projects.keyword'))
                                ->placeholder(fn (Get $get): string => $get('type') === SeoProjectTask::TYPE_NEW_TITLE
                                    ? 'VD: Cách chọn balo laptop 14 inch...'
                                    : __('seo-content-ai::filament.projects.keyword_placeholder'))
                                ->required()
                                ->maxLength(500)
                                ->visible(fn (Get $get): bool => SeoProjectTask::isNewArticleType($get('type'))),

                            Forms\Components\Select::make('post_type')
                                ->label(__('seo-content-ai::filament.article_list.post_type'))
                                ->options(static::postTypeSelectOptions())
                                ->default(SeoProjectTask::POST_TYPE_ARTICLE)
                                ->required()
                                ->native(false)
                                ->live()
                                ->visible(fn (Get $get): bool => SeoProjectTask::isNewArticleType($get('type'))),

                            Forms\Components\Select::make('source_content')
                                ->label(fn (Get $get): string => $get('type') === SeoProjectTask::TYPE_IMPROVE
                                    ? __('seo-content-ai::filament.projects.title_of_article_to_improve')
                                    : __('seo-content-ai::filament.projects.title_of_article_to_rewrite'))
                                ->placeholder(__('seo-content-ai::filament.projects.title_to_rewrite_placeholder'))
                                ->searchable()
                                ->searchPrompt(__('seo-content-ai::filament.projects.rewrite_article_search_prompt'))
                                ->searchDebounce(300)
                                ->native(false)
                                ->required()
                                ->visible(fn (Get $get): bool => in_array(
                                    (string) $get('type'),
                                    SeoProjectTask::articlePickerTypes(),
                                    true,
                                ))
                                ->getSearchResultsUsing(
                                    fn (string $search, Get $get): array => static::searchArticlesForRewriteTitle(
                                        $search,
                                        static::resolveRepeaterSiteId($get),
                                    ),
                                )
                                ->getOptionLabelUsing(
                                    fn ($value): ?string => is_string($value) && trim($value) !== ''
                                        ? trim($value)
                                        : null,
                                )
                                ->helperText(
                                    fn (Get $get): ?HtmlString => static::rewriteArticleWpLinkHelper(
                                        $get('source_content'),
                                        static::resolveRepeaterSiteId($get),
                                    ),
                                )
                                ->live()
                                ->afterStateUpdated(function (Forms\Components\Select $component, ?string $state, Get $get): void {
                                    if ($state === null || trim($state) === '') {
                                        $component->suffixAction(null);

                                        return;
                                    }

                                    $permalink = static::resolveArticlePermalinkByTitle(trim($state), static::resolveRepeaterSiteId($get));
                                    if ($permalink !== null) {
                                        $component->suffixAction(
                                            Forms\Components\Actions\Action::make('view_wp_link')
                                                ->icon('heroicon-o-link')
                                                ->color('info')
                                                ->url($permalink, shouldOpenInNewTab: true),
                                        );
                                    }
                                }),

                            Forms\Components\Select::make('rewrite_mode')
                                ->label(__('seo-content-ai::filament.projects.rewrite_mode'))
                                ->options(SeoProjectTask::rewriteModeOptions())
                                ->default(SeoProjectTask::REWRITE_MODE_KEYWORD)
                                ->required()
                                ->native(false)
                                ->live()
                                ->visible(fn (Get $get): bool => $get('type') === SeoProjectTask::TYPE_REWRITE),

                            Forms\Components\Textarea::make('rewrite_notes')
                                ->label(__('seo-content-ai::filament.projects.rewrite_notes'))
                                ->placeholder(__('seo-content-ai::filament.projects.rewrite_notes_placeholder'))
                                ->rows(3)
                                ->visible(fn (Get $get): bool => $get('type') === SeoProjectTask::TYPE_REWRITE
                                    && SeoProjectTask::normalizeRewriteMode($get('rewrite_mode')) === SeoProjectTask::REWRITE_MODE_CONTENT)
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('loai_san_pham')
                                ->label(__('seo-content-ai::filament.projects.loai_san_pham'))
                                ->placeholder(__('seo-content-ai::filament.projects.loai_san_pham_placeholder'))
                                ->maxLength(500)
                                ->visible(fn (Get $get): bool => SeoProjectTask::isNewArticleType($get('type'))
                                    && $get('post_type') === SeoProjectTask::POST_TYPE_PRODUCT)
                                ->columnSpanFull(),

                            Forms\Components\Textarea::make('description')
                                ->label(__('seo-content-ai::filament.projects.gallery_description'))
                                ->placeholder(__('seo-content-ai::filament.projects.gallery_description_placeholder'))
                                ->rows(3)
                                ->visible(fn (Get $get): bool => SeoProjectTask::isNewArticleType($get('type'))
                                    && $get('post_type') === SeoProjectTask::POST_TYPE_PRODUCT)
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->addActionLabel(__('seo-content-ai::filament.projects.add_article'))
                        ->reorderable()
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(function (array $state): ?string {
                            $type = $state['type'] ?? SeoProjectTask::TYPE_NEW_KEYWORD;
                            $content = trim((string) ($state['source_content'] ?? ''));

                            if ($type === SeoProjectTask::TYPE_IMPROVE) {
                                $prefix = '[Tối ưu]';
                            } elseif ($type === SeoProjectTask::TYPE_REWRITE) {
                                $prefix = '[Viết lại]';
                                if (SeoProjectTask::normalizeRewriteMode($state['rewrite_mode'] ?? null) === SeoProjectTask::REWRITE_MODE_CONTENT) {
                                    $prefix = '[Viết lại/nội dung]';
                                }
                            } elseif ($type === SeoProjectTask::TYPE_NEW_TITLE) {
                                $prefix = '[Viết mới - tiêu đề]';
                            } else {
                                $postTypeLabels = [
                                    SeoProjectTask::POST_TYPE_ARTICLE => 'Article',
                                    SeoProjectTask::POST_TYPE_PRODUCT => 'Product',
                                    SeoProjectTask::POST_TYPE_CATEGORY => 'Category',
                                    SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => 'Product Category',
                                ];
                                $postType = SeoProjectTask::normalizePostType($state['post_type'] ?? null);
                                $prefix = '['.($postTypeLabels[$postType] ?? 'Article').']';
                            }

                            return $content !== '' ? "{$prefix} {$content}" : __('seo-content-ai::filament.projects.article_items');
                        })
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
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(
                fn (SeoProject $record): string => static::projectRecordUrl($record),
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.projects.project_name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->url(
                        fn (SeoProject $record): string => static::projectRecordUrl($record),
                    ),

                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('seo-content-ai::filament.projects.owner'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('seo-content-ai::filament.projects.domain'))
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('month')
                    ->label(__('seo-content-ai::filament.projects.month'))
                    ->date('m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('active_tasks_count')
                    ->label(__('seo-content-ai::filament.projects.total_items'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('active_completed_count')
                    ->label(__('seo-content-ai::filament.projects.completed'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.projects.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SeoProject::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        SeoProject::STATUS_PENDING => 'gray',
                        SeoProject::STATUS_MANUAL => 'info',
                        SeoProject::STATUS_RUNNING => 'warning',
                        SeoProject::STATUS_COMPLETED => 'success',
                        SeoProject::STATUS_PAUSED => 'danger',
                        SeoProject::STATUS_APPROVED => 'success',
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
                    ->options(fn (): array => static::userSelectOptions())
                    ->searchable()
                    ->preload()
                    ->native(false),

                Tables\Filters\SelectFilter::make('site_id')
                    ->label(__('seo-content-ai::filament.projects.domain'))
                    ->options(fn (): array => static::siteSelectOptions())
                    ->searchable()
                    ->preload()
                    ->native(false),

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
                Tables\Actions\Action::make('view_runs')
                    ->label(__('seo-content-ai::filament.projects.view_runs'))
                    ->icon('heroicon-o-queue-list')
                    ->color('gray')
                    ->visible(fn (SeoProject $record): bool => SeoAccessControl::canAccessContentProjectRun($record))
                    ->url(fn (SeoProject $record): string => static::getRunHistoryUrl($record)),
                Tables\Actions\Action::make('view_archives')
                    ->label(fn (SeoProject $record): string => __('seo-content-ai::filament.projects.view_archives', [
                        'count' => (int) ($record->archives_count ?? static::archivesCountFor($record)),
                    ]))
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->visible(fn (): bool => SeoAccessControl::canViewProjectArchives())
                    ->url(fn (SeoProject $record): string => static::projectArchivesUrl($record)),
                Tables\Actions\Action::make('archive_project_articles')
                    ->label(__('seo-content-ai::filament.projects.archive_project'))
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->visible(fn (SeoProject $record): bool => SeoAccessControl::canArchiveContentProjects()
                        && (int) ($record->active_articles_count ?? 0) > 0)
                    ->modalHeading(__('seo-content-ai::filament.projects.archive_project_heading'))
                    ->modalDescription(__('seo-content-ai::filament.projects.archive_project_description'))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.archive_project_submit'))
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label(__('seo-content-ai::filament.projects.archive_note'))
                            ->placeholder(__('seo-content-ai::filament.projects.archive_note_placeholder'))
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (SeoProject $record, array $data): void {
                        try {
                            $result = app(SeoProjectArchiveService::class)
                                ->archiveProject(
                                    $record,
                                    (int) auth()->id(),
                                    isset($data['note']) ? (string) $data['note'] : null,
                                );

                            Notification::make()
                                ->title(__('seo-content-ai::filament.projects.archive_completed'))
                                ->body(__('seo-content-ai::filament.projects.archive_completed_body', $result))
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.projects.archive_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\ViewAction::make()
                    ->visible(fn (SeoProject $record): bool => static::canView($record) && ! static::canEdit($record)),
                Tables\Actions\EditAction::make()
                    ->visible(fn (SeoProject $record): bool => static::canEdit($record)),
            ])
            ->bulkActions(static::seoPanelBulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => SeoAccessControl::canMutateContentProjects()),
                ]),
            ]));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = static::applyGlobalSiteScopeToProjectQuery(
            parent::getEloquentQuery()
                ->with(['user', 'site'])
                ->withCount([
                    'tasks as active_tasks_count',
                    'tasks as active_completed_count' => static fn (Builder $sub): Builder => $sub
                        ->where('status', SeoProjectTask::STATUS_COMPLETED),
                    'tasks as active_articles_count' => static fn (Builder $sub): Builder => $sub
                        ->whereNotNull('article_id')
                        ->where('article_id', '>', 0),
                    'archives as archives_count',
                ]),
        );

        if (SeoAccessControl::isContentManager()) {
            $query->where('user_id', (int) auth()->id());
        }

        return $query;
    }

    public static function applyGlobalSiteScopeToProjectQuery(Builder $query): Builder
    {
        if (! SeoAccessControl::shouldApplyGlobalSiteScope()) {
            return $query;
        }

        return $query->where('site_id', (int) SeoAccessControl::globalSiteId());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeProjectSiteId(array $data): array
    {
        // Nếu form chưa có site_id (trường hợp ẩn cũ), fallback về global site
        if (empty($data['site_id'])) {
            $globalSiteId = SeoAccessControl::globalSiteId();
            if ($globalSiteId !== null) {
                $data['site_id'] = $globalSiteId;
            }
        }

        return $data;
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
            'run-history' => Pages\ListSeoProjectRuns::route('/{record}/runs'),
            'view-run-step' => Pages\ViewSeoProjectRunStep::route('/runs/{run}/items/{article}'),
            'view-run' => Pages\ViewSeoProjectRun::route('/runs/{run}'),
            'view' => Pages\ViewSeoProject::route('/{record}'),
            'edit' => Pages\EditSeoProject::route('/{record}/edit'),
        ];
    }

    public static function getRunHistoryUrl(SeoProject $project): string
    {
        return static::getUrl('run-history', ['record' => $project]);
    }

    public static function workspaceTabQueryValue(string $tabKey): string
    {
        return self::PROJECT_WORKSPACE_TABS_ID.'-'.$tabKey.'-tab';
    }

    public static function resolveWorkspaceTabIndex(): int
    {
        $requested = (string) request()->query('tab', '');

        if (in_array($requested, [
            static::workspaceTabQueryValue('archives'),
            'archives',
            '-archives-tab',
        ], true)) {
            return 2;
        }

        return 1;
    }

    public static function projectArchivesUrl(SeoProject $project): string
    {
        return static::projectRecordUrl($project).'?'.http_build_query([
            'tab' => static::workspaceTabQueryValue('archives'),
        ]);
    }

    public static function archivesCountFor(SeoProject $project): int
    {
        if (array_key_exists('archives_count', $project->getAttributes())) {
            return (int) $project->archives_count;
        }

        if ($project->relationLoaded('archives')) {
            return $project->archives->count();
        }

        return (int) $project->archives()->count();
    }

    public static function makeViewProjectArchivesPageAction(SeoProject $project): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('view_project_archives')
            ->label(__('seo-content-ai::filament.projects.view_archives', [
                'count' => static::archivesCountFor($project),
            ]))
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->visible(fn (): bool => SeoAccessControl::canViewProjectArchives())
            ->url(static::projectArchivesUrl($project));
    }

    public static function makeArchiveProjectPageAction(SeoProject $project): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('archive_project_articles')
            ->label(__('seo-content-ai::filament.projects.archive_project'))
            ->icon('heroicon-o-archive-box')
            ->color('warning')
            ->visible(fn (): bool => SeoAccessControl::canArchiveContentProjects()
                && $project->activeArticleCount() > 0)
            ->modalHeading(__('seo-content-ai::filament.projects.archive_project_heading'))
            ->modalDescription(__('seo-content-ai::filament.projects.archive_project_description'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.archive_project_submit'))
            ->form([
                Forms\Components\Textarea::make('note')
                    ->label(__('seo-content-ai::filament.projects.archive_note'))
                    ->placeholder(__('seo-content-ai::filament.projects.archive_note_placeholder'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data) use ($project): void {
                try {
                    $result = app(SeoProjectArchiveService::class)
                        ->archiveProject(
                            $project,
                            (int) auth()->id(),
                            isset($data['note']) ? (string) $data['note'] : null,
                        );

                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.archive_completed'))
                        ->body(__('seo-content-ai::filament.projects.archive_completed_body', $result))
                        ->success()
                        ->send();
                } catch (\Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.archive_failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getLatestRunUrl(SeoProject $project): ?string
    {
        $latestRunId = (int) $project->runs()->latest('id')->value('id');
        if ($latestRunId <= 0) {
            return null;
        }

        return static::getUrl('view-run', ['run' => $latestRunId]);
    }

    public static function runWorkflowModalDescription(SeoProject $project, ?int $pendingLimit = null): HtmlString
    {
        $base = $pendingLimit !== null
            ? __('seo-content-ai::filament.projects.test_run_workflow_description', [
                'limit' => $pendingLimit,
            ])
            : __('seo-content-ai::filament.projects.run_workflow_description');

        $warnings = app(SeoProjectRunPreflightService::class)
            ->formatWarningsForModal($project, $pendingLimit);

        return new HtmlString('<p>'.e($base).'</p>'.$warnings->toHtml());
    }

    public static function createProjectWorkflowRun(SeoProject $project, string $mode): SeoProjectRun
    {
        $runner = app(SeoProjectWorkflowRunService::class);
        $limit = $mode === SeoProjectRun::MODE_TEST ? SeoProjectWorkflowRunService::TEST_RUN_LIMIT : null;
        $run = $runner->startRun($project, $mode);

        return $runner->prepareRunQueue($project, $run, $limit);
    }

    public static function dispatchProjectWorkflowRun(SeoProject $project, string $mode): mixed
    {
        try {
            $run = static::createProjectWorkflowRun($project, $mode);
            $url = static::getUrl('view-run', ['run' => $run->id]).'?autorun=1';

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_started'))
                ->body(__('seo-content-ai::filament.projects.run_started_new_tab'))
                ->success()
                ->send();

            return redirect($url);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        } catch (\Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    public static function userSelectOptions(): array
    {
        $query = User::query()
            ->where('seo_role', User::SEO_ROLE_CONTENT_MANAGER)
            ->where('status', User::STATUS_NORMAL);

        if (auth()->user()?->role !== User::ROLE_ADMIN) {
            $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
            $query->where(function (Builder $users) use ($ownerId): void {
                $users->whereKey($ownerId)->orWhere('parent_id', $ownerId);
            });
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(static fn (User $user): array => [
                (int) $user->id => static::formatUserSelectLabel($user),
            ])
            ->all();
    }

    public static function formatUserSelectLabel(User $user): string
    {
        $name = trim((string) ($user->display_name ?? ''));
        $email = trim((string) ($user->email ?? ''));

        if ($name !== '' && $email !== '') {
            return sprintf('%s(%s)', $name, $email);
        }

        if ($name !== '') {
            return $name;
        }

        if ($email !== '') {
            return $email;
        }

        return (string) $user->id;
    }

    /**
     * @return array<string, string> title => label
     */
    public static function searchArticlesForRewriteTitle(string $search, ?int $siteId): array
    {
        $search = trim($search);

        $query = ArticleResource::getEloquentQuery()
            ->with(['site', 'articleMetas']);

        if ($siteId !== null && $siteId > 0) {
            $query->where('site_id', $siteId);
        }

        if ($search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);

            $query->where(function (Builder $inner) use ($search, $escaped): void {
                $inner->where('title', 'like', '%'.$escaped.'%');

                if (ctype_digit($search)) {
                    $inner->orWhere('id', (int) $search);
                }
            });
        }

        $options = $query
            ->orderByDesc('updated_at')
            ->limit($search === '' ? 20 : 50)
            ->get()
            ->mapWithKeys(function (SeoArticle $article): array {
                $title = trim((string) $article->title);

                if ($title === '') {
                    return [];
                }

                return [$title => static::formatRewriteArticleOptionLabel($article)];
            })
            ->all();

        if ($search !== '' && ! array_key_exists($search, $options)) {
            $options = [
                $search => __('seo-content-ai::filament.projects.rewrite_article_use_typed_title', [
                    'title' => $search,
                ]),
            ] + $options;
        }

        return $options;
    }

    public static function formatRewriteArticleOptionLabel(SeoArticle $article): string
    {
        $domain = trim((string) ($article->site?->domain ?? ''));

        $permalink = '';
        if ($article->relationLoaded('articleMetas')) {
            $meta = $article->articleMetas->firstWhere('meta_key', 'wp_permalink');
            $permalink = trim((string) ($meta?->meta_value ?? ''));
        }

        $base = sprintf(
            '#%d · %s (%s)',
            $article->id,
            (string) $article->title,
            $domain !== '' ? $domain : '—',
        );

        if ($permalink !== '') {
            return $base.' — '.$permalink;
        }

        return $base;
    }

    public static function rewriteArticleWpLinkHelper(mixed $title, ?int $siteId = null): ?HtmlString
    {
        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        $permalink = static::resolveArticlePermalinkByTitle(trim($title), $siteId);

        if ($permalink === null) {
            return null;
        }

        $url = e($permalink);

        return new HtmlString(
            '<a href="'.$url.'" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">View WP article</a>'
        );
    }

    public static function resolveArticlePermalinkByTitle(string $title, ?int $siteId = null): ?string
    {
        $query = SeoArticle::query()->with('articleMetas')
            ->where('title', $title);

        if ($siteId !== null && $siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $article = $query->orderByDesc('updated_at')->first();

        if ($article === null) {
            return null;
        }

        $meta = $article->articleMetas->firstWhere('meta_key', 'wp_permalink');

        $permalink = trim((string) ($meta?->meta_value ?? ''));

        return $permalink !== '' ? $permalink : null;
    }

    public static function resolveRepeaterSiteId(Get $get): ?int
    {
        foreach (['../../site_id', '../site_id', 'site_id'] as $path) {
            $siteId = $get($path);

            if ($siteId !== null && $siteId !== '') {
                return (int) $siteId;
            }
        }

        return SeoAccessControl::globalSiteId();
    }

    /**
     * @return array<string, string>
     */
    public static function postTypeSelectOptions(): array
    {
        return [
            SeoProjectTask::POST_TYPE_ARTICLE => __('seo-content-ai::filament.article_list.post_type_article'),
            SeoProjectTask::POST_TYPE_PRODUCT => __('seo-content-ai::filament.article_list.post_type_product'),
            SeoProjectTask::POST_TYPE_CATEGORY => __('seo-content-ai::filament.article_list.post_type_category'),
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => __('seo-content-ai::filament.article_list.post_type_product_category'),
        ];
    }

    public static function siteSelectOptions(): array
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
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
        $syncService = app(SeoProjectTaskSyncService::class);
        $maxMonth = $syncService->maxTasksForMonth($month);
        $remaining = max(0, $maxMonth - $syncService->countEffectiveTasks($existing));

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
