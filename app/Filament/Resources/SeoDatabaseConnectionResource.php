<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SeoDatabaseConnectionResource\Pages;
use App\Filament\Support\SeoDatabaseConnectionAccess;
use App\Filament\Support\SeoDatabaseConnectionBackupActions;
use App\Models\SeoDatabaseConnection;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SeoDatabaseConnectionResource extends Resource
{
    protected static ?string $model = SeoDatabaseConnection::class;

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationGroup = 'Site Management';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'seo-database-connections';

    public static function getNavigationLabel(): string
    {
        return 'SEO Database Connections';
    }

    public static function getModelLabel(): string
    {
        return 'SEO Database Connection';
    }

    public static function canAccess(): bool
    {
        return SeoDatabaseConnectionAccess::canAccessResource();
    }

    public static function canCreate(): bool
    {
        return SeoDatabaseConnectionAccess::canCreateConnection();
    }

    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof SeoDatabaseConnection) {
            return false;
        }

        return SeoDatabaseConnectionAccess::canEditConnection($record);
    }

    public static function canDelete(Model $record): bool
    {
        return SeoDatabaseConnectionAccess::canDeleteConnection();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (SeoDatabaseConnectionAccess::isAdmin()) {
            return $query;
        }

        $user = auth()->user();
        if ($user?->role === User::ROLE_OWNER) {
            return $query->whereHas(
                'users',
                fn (Builder $builder): Builder => $builder->whereKey($user->id),
            );
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Thông tin kết nối')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Tên gợi nhớ')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('hash_id')
                            ->label('Hash ID (URL)')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?SeoDatabaseConnection $record): bool => $record !== null)
                            ->helperText(fn (?SeoDatabaseConnection $record): ?string => $record
                                ? 'Panel URL: '.url($record->panelUrl())
                                : null),

                        Forms\Components\Select::make('type')
                            ->label('Loại cấu hình')
                            ->options([
                                'auto' => 'Tự động (Docker Production)',
                                'manual' => 'Thủ công (Hosting lẻ)',
                            ])
                            ->default(fn (): string => (string) config('seo-content-ai.default_connection_type', 'manual'))
                            ->required()
                            ->live()
                            ->native(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Kích hoạt')
                            ->default(true),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Cấu hình Database')
                    ->description('Thông tin kết nối MySQL cho workspace SEO.')
                    ->schema([
                        Forms\Components\TextInput::make('database')
                            ->label('Database name')
                            ->helperText(fn (Get $get): string => ($get('type') ?? 'auto') === 'auto'
                                ? 'Để trống sẽ tự sinh omi_seo_ai_auto_{id} sau khi lưu (chế độ auto).'
                                : 'Tên database MySQL (bắt buộc khi cấu hình thủ công).')
                            ->required(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),

                        Forms\Components\TextInput::make('host')
                            ->label('Host')
                            ->default('127.0.0.1')
                            ->visible(fn (Get $get): bool => ($get('type') ?? '') === 'manual')
                            ->required(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),

                        Forms\Components\TextInput::make('port')
                            ->label('Port')
                            ->default('3306')
                            ->visible(fn (Get $get): bool => ($get('type') ?? '') === 'manual')
                            ->required(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),

                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->visible(fn (Get $get): bool => ($get('type') ?? '') === 'manual')
                            ->required(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),

                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Để trống nếu không đổi (chỉ khi sửa).')
                            ->visible(fn (Get $get): bool => ($get('type') ?? '') === 'manual'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Owner')
                    ->description('Mỗi connection gắn với một owner đã kích hoạt Site Service SEO Content AI.')
                    ->visible(fn (): bool => SeoDatabaseConnectionAccess::isAdmin())
                    ->schema([
                        Forms\Components\Select::make('owner_id')
                            ->label('Owner')
                            ->options(fn (?SeoDatabaseConnection $record): array => app(SiteServiceBindingService::class)
                                ->eligibleOwnerSelectOptions(
                                    $record !== null
                                        ? [(int) ($record->users()->value('users.id') ?? 0)]
                                        : [],
                                ))
                            ->searchable()
                            ->required()
                            ->native(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hash_id')
                    ->label('Hash')
                    ->copyable()
                    ->limit(16)
                    ->tooltip(fn (SeoDatabaseConnection $record): string => $record->hash_id),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('database')
                    ->label('Database'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('owner_email')
                    ->label('Owner')
                    ->getStateUsing(fn (SeoDatabaseConnection $record): string => (string) ($record->users()->value('email') ?? '—')),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                SeoDatabaseConnectionBackupActions::exportTableAction()
                    ->visible(fn (): bool => SeoDatabaseConnectionAccess::isAdmin()),
                SeoDatabaseConnectionBackupActions::importTableAction()
                    ->visible(fn (): bool => SeoDatabaseConnectionAccess::isAdmin()),
                Tables\Actions\Action::make('open_panel')
                    ->label('Mở panel SEO')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (SeoDatabaseConnection $record): string => url($record->panelUrl()))
                    ->openUrlInNewTab()
                    ->visible(fn (SeoDatabaseConnection $record): bool => (bool) $record->is_active),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (): bool => SeoDatabaseConnectionAccess::canDeleteConnection()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeoDatabaseConnections::route('/'),
            'create' => Pages\CreateSeoDatabaseConnection::route('/create'),
            'edit' => Pages\EditSeoDatabaseConnection::route('/{record}/edit'),
        ];
    }
}
