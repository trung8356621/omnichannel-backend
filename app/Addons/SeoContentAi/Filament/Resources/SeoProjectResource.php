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

    protected static ?string $navigationLabel = 'Dự án Content';

    protected static ?string $modelLabel = 'Dự án Content';

    protected static ?string $pluralModelLabel = 'Dự án Content';

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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Thông tin dự án')
                    ->schema([
                        Forms\Components\Placeholder::make('project_name_preview')
                            ->label('Tên dự án')
                            ->content(
                                fn (Get $get): string => $get('month')
                                    ? SeoProject::defaultNameFromMonth($get('month'))
                                    : 'project —/—',
                            )
                            ->columnSpanFull(),

                        Forms\Components\Select::make('user_id')
                            ->label('Chỉ định Writer')
                            ->options(fn (): array => static::userSelectOptions())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),

                        Forms\Components\DatePicker::make('month')
                            ->label('Tháng thực hiện')
                            ->native(false)
                            ->displayFormat('m/Y')
                            ->format('Y-m-d')
                            ->default(fn (): string => now()->startOfMonth()->format('Y-m-d'))
                            ->required()
                            ->live(),

                        Forms\Components\Select::make('status')
                            ->label('Trạng thái')
                            ->options(SeoProject::statusOptions())
                            ->default(SeoProject::STATUS_RUNNING)
                            ->required()
                            ->native(false),

                        Forms\Components\Textarea::make('description')
                            ->label('Mô tả')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Danh sách bài viết / Từ khóa')
                    ->description('Tổng số bài không được vượt quá số ngày thực tế trong tháng đã chọn. Hệ thống tự gán KPI theo ngày 1, 2, 3… trong tháng.')
                    ->schema([
                        Forms\Components\Placeholder::make('month_limit_hint')
                            ->label('Giới hạn tháng')
                            ->content(function (Get $get): string {
                                $month = $get('month');
                                if (! $month) {
                                    return 'Chọn tháng để xem giới hạn số bài.';
                                }

                                $carbon = Carbon::parse($month)->startOfMonth();
                                $max = $carbon->daysInMonth;
                                $count = count($get('tasks_data') ?? []);

                                return "Tháng {$carbon->format('m/Y')}: tối đa {$max} bài · đang có {$count} mục.";
                            })
                            ->columnSpanFull(),

                        Forms\Components\Actions::make([
                            Action::make('import_keywords')
                                ->label('Danh sách từ khóa')
                                ->icon('heroicon-o-queue-list')
                                ->iconButton()
                                ->tooltip('Danh sách từ khóa — dán mỗi dòng một từ khóa')
                                ->color('gray')
                                ->modalHeading('Nhập danh sách từ khóa')
                                ->modalDescription('Mỗi dòng một từ khóa (hoặc dòng bắt đầu bằng -). Các mục sẽ được thêm vào danh sách bên dưới.')
                                ->modalSubmitActionLabel('Thêm vào dự án')
                                ->form([
                                    Forms\Components\Textarea::make('keywords_text')
                                        ->label('Từ khóa')
                                        ->placeholder("túi vải không dệt\ncách may túi vải\n- túi canvas")
                                        ->rows(12)
                                        ->required(),
                                ])
                                ->action(function (array $data, Get $get, Set $set): void {
                                    static::appendKeywordsToFormState($get, $set, $data['keywords_text'] ?? '');
                                }),

                            Action::make('ai_generate_keywords')
                                ->label('AI Generator')
                                ->icon('heroicon-o-sparkles')
                                ->iconButton()
                                ->tooltip('AI Generator — sinh từ khóa theo prompt quy trình')
                                ->color('primary')
                                ->modalHeading('AI Generator — từ khóa dự án')
                                ->modalDescription('Sinh từ khóa theo prompt đã cấu hình tại SEO → Tùy chỉnh → Quy trình.')
                                ->modalSubmitActionLabel('Sinh từ khóa')
                                ->form([
                                    Forms\Components\TextInput::make('count')
                                        ->label('Số từ khóa cần sinh')
                                        ->numeric()
                                        ->minValue(1)
                                        ->maxValue(31)
                                        ->default(10)
                                        ->required(),
                                    Forms\Components\Textarea::make('brief')
                                        ->label('Gợi ý thêm cho AI')
                                        ->placeholder('Ngành hàng, đối tượng, chủ đề ưu tiên…')
                                        ->rows(4),
                                ])
                                ->action(function (array $data, Get $get, Set $set): void {
                                    static::generateKeywordsWithAi($get, $set, $data);
                                }),
                        ])
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('tasks_data')
                            ->label('Các hạng mục bài viết')
                            ->schema([
                                Forms\Components\Select::make('site_id')
                                    ->label('Tên miền')
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
                                    ->label('Loại bài')
                                    ->options(SeoProjectTask::typeOptions())
                                    ->default(SeoProjectTask::TYPE_NEW_KEYWORD)
                                    ->required()
                                    ->native(false)
                                    ->live(),

                                Forms\Components\TextInput::make('source_content')
                                    ->label(fn (Get $get): string => $get('type') === SeoProjectTask::TYPE_REWRITE
                                        ? 'Tiêu đề bài cần sửa'
                                        : 'Từ khóa')
                                    ->placeholder(fn (Get $get): string => $get('type') === SeoProjectTask::TYPE_REWRITE
                                        ? 'VD: Hướng dẫn may túi vải cũ…'
                                        : 'VD: cách may túi vải')
                                    ->required()
                                    ->maxLength(500),

                                Forms\Components\Textarea::make('description')
                                    ->label('Mô tả')
                                    ->placeholder('Gợi ý nội dung, góc bài, đối tượng đọc, CTA…')
                                    ->rows(3)
                                    ->visible(fn (Get $get): bool => $get('type') === SeoProjectTask::TYPE_NEW_KEYWORD)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->addActionLabel('Thêm bài viết')
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
                                        $fail($e->validator->errors()->first('tasks_data') ?? 'Vượt giới hạn số bài trong tháng.');
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
                    ->label('Tên dự án')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Người phụ trách')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('month')
                    ->label('Tháng')
                    ->date('m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_tasks')
                    ->label('Số bài')
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tasks_completed')
                    ->label('Hoàn thành')
                    ->getStateUsing(
                        fn (SeoProject $record): string => (string) $record->tasks()
                            ->where('status', SeoProjectTask::STATUS_COMPLETED)
                            ->count(),
                    )
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
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
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('month', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(SeoProject::statusOptions()),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Writer')
                    ->options(fn (): array => static::userSelectOptions()),

                Tables\Filters\Filter::make('month')
                    ->form([
                        Forms\Components\DatePicker::make('month')
                            ->label('Tháng')
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
                ->title('Chọn tháng thực hiện trước')
                ->warning()
                ->send();

            return;
        }

        $keywords = app(SeoProjectKeywordListParser::class)->parse($rawText);
        if ($keywords === []) {
            Notification::make()
                ->title('Không có từ khóa hợp lệ')
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
                ->title('Vượt giới hạn tháng')
                ->body($exception->validator->errors()->first('tasks_data') ?? '')
                ->danger()
                ->send();

            return;
        }

        $set('tasks_data', $merged);

        Notification::make()
            ->title('Đã thêm ' . count($keywords) . ' từ khóa')
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
                ->title('Chọn tháng thực hiện trước')
                ->warning()
                ->send();

            return;
        }

        $existing = is_array($get('tasks_data')) ? $get('tasks_data') : [];
        $maxMonth = app(SeoProjectTaskSyncService::class)->maxTasksForMonth($month);
        $remaining = max(0, $maxMonth - count($existing));

        if ($remaining === 0) {
            Notification::make()
                ->title('Đã đủ số bài trong tháng')
                ->body("Tối đa {$maxMonth} mục.")
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
                ->title('Không sinh được từ khóa')
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
                ->title('Vượt giới hạn tháng')
                ->body($exception->validator->errors()->first('tasks_data') ?? '')
                ->danger()
                ->send();

            return;
        }

        $set('tasks_data', $merged);

        Notification::make()
            ->title('AI đã thêm ' . count($keywords) . ' từ khóa')
            ->success()
            ->send();
    }
}
