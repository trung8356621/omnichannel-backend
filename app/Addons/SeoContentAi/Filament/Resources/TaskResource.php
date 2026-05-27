<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\TaskResource\Pages;
use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsOverview;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Services\AiModelsReadinessService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends Resource
{
    protected static ?string $model = SeoTask::class;

    protected static ?string $slug = 'tasks';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Quy trình nhiệm vụ';

    protected static ?string $modelLabel = 'Quy trình';

    protected static ?string $pluralModelLabel = 'Quy trình nhiệm vụ';

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
                Forms\Components\TextInput::make('name')
                    ->label('Tên quy trình')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên quy trình')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('test')
                    ->label(fn (): string => app(AiModelsReadinessService::class)->userHasReadyAiConnection()
                        ? 'Test'
                        : 'Đồng bộ model')
                    ->icon(fn (): string => app(AiModelsReadinessService::class)->userHasReadyAiConnection()
                        ? 'heroicon-o-play'
                        : 'heroicon-o-cpu-chip')
                    ->color(fn (): string => app(AiModelsReadinessService::class)->userHasReadyAiConnection()
                        ? 'success'
                        : 'warning')
                    ->url(fn (SeoTask $record): string => app(AiModelsReadinessService::class)->userHasReadyAiConnection()
                        ? static::getUrl('test', ['record' => $record])
                        : SeoSettingsOverview::getUrl()),
                Tables\Actions\Action::make('open_builder')
                    ->label('Mở Builder')
                    ->icon('heroicon-o-squares-2x2')
                    ->color('info')
                    ->url(fn (SeoTask $record): string => static::getUrl('builder', ['record' => $record])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\TaskWorkflowBuilder::route('/create'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
            'builder' => Pages\EditTaskWorkflow::route('/{record}/builder'),
            'test' => Pages\TestTask::route('/{record}/test'),
        ];
    }
}
