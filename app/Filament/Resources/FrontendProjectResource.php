<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\FrontendProjectResource\Pages;
use App\Models\FrontendProject;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FrontendProjectResource extends Resource
{
    protected static ?string $model = FrontendProject::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'React/Next';

    protected static ?string $navigationLabel = 'Frontend (Next/React)';

    protected static ?string $modelLabel = 'Project frontend';

    protected static ?string $pluralModelLabel = 'Projects frontend';

    protected static ?string $slug = 'frontend-projects';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->role === 'admin';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Tên hiển thị')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->label('Loại')
                    ->options(FrontendProject::typeOptions())
                    ->default(FrontendProject::TYPE_NEXTJS)
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('package_json_path')
                    ->label('Đường dẫn thư mục chứa package.json')
                    ->helperText('Tương đối từ thư mục gốc project (vd: app/Addons/WpHeadless/wp-headless) hoặc đường dẫn tuyệt đối. Mọi addon đều có thể khai báo project con.')
                    ->required()
                    ->maxLength(1024)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => FrontendProject::typeOptions()[$state] ?? $state),
                Tables\Columns\TextColumn::make('package_json_path')
                    ->label('Đường dẫn')
                    ->limit(50)
                    ->tooltip(fn($record) => $record?->package_json_path),
                Tables\Columns\IconColumn::make('valid_path')
                    ->label('package.json')
                    ->getStateUsing(fn(FrontendProject $r): bool => $r->hasValidPath())
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('npm_commands')
                    ->label('Lệnh NPM')
                    ->icon('heroicon-o-command-line')
                    ->url(fn(FrontendProject $record): string => \App\Filament\Pages\FrontendNpmCommandsPage::getUrl() . '?project_id=' . $record->id),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFrontendProjects::route('/'),
            'create' => Pages\CreateFrontendProject::route('/create'),
            'edit' => Pages\EditFrontendProject::route('/{record}/edit'),
        ];
    }
}
