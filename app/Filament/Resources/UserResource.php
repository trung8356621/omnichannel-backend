<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('Full name'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\Select::make('role')
                    ->label(__('Rule'))
                    ->options([
                        'admin' => 'Administrator',
                        'owner' => 'Chủ sở hữu (Owner)',
                        'staff' => 'Nhân viên (Staff)'
                    ])
                    ->required()
                    ->native(false),
                Forms\Components\Select::make('status')
                    ->label(__('Status'))
                    ->options([
                        'normal' => 'Hoạt động',
                        'block' => 'Đã khóa',
                        'pending' => 'Chờ duyệt',
                    ])
                    ->required()
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\ImageColumn::make('avatar')
                    ->label(label: __('Avatar'))
                    ->circular()
                    ->defaultImageUrl(fn($record) => 'https://ui-avatars.com/api/?name=' . urlencode($record->name)),

                Tables\Columns\TextColumn::make('name')
                    ->label(__("Full name"))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('role')
                    ->label(__('Rule'))
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'admin' => 'danger',
                        'owner' => 'success',
                        'staff' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label(__("Status"))
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'normal' => 'success',
                        'block' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),

                // Hiển thị số dư ví từ quan hệ wallet
                Tables\Columns\TextColumn::make('wallet.balance')
                    ->label(__("Wallet balance"))
                    ->formatStateUsing(fn($state) => number_format($state, 0, ',', '.') . ' đ')->default(0)
                    ->sortable(),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label(__("Managed by"))
                    ->placeholder('N/A')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label(__("Filter by rule"))
                    ->options([
                        'admin' => 'Admin',
                        'owner' => 'Owner',
                        'staff' => 'Staff',
                    ]),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Nếu không phải admin, chỉ xem được Site của chính mình
        $query = parent::getEloquentQuery();

        if (auth()->user()->role !== 'admin') {
            return $query->where('id', auth()->id())->orWhere('parent_id', auth()->id());
        }

        return $query->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
