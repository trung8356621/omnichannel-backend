<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\Users\UserHierarchyService;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormInputAction;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canAccess(): bool
    {
        $role = (string) (auth()->user()?->role ?? '');

        return in_array($role, [User::ROLE_ADMIN, User::ROLE_OWNER], true);
    }

    public static function form(Form $form): Form
    {
        $hierarchy = app(UserHierarchyService::class);

        return $form
            ->schema([
                Forms\Components\Section::make(__('Account'))
                    ->extraAttributes(['class' => 'max-w-4xl'])
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
                        Forms\Components\TextInput::make('password')
                            ->label(__('Password'))
                            ->required(fn ($livewire): bool => $livewire instanceof Pages\CreateUser)
                            ->maxLength(255)
                            ->default(fn ($livewire): string => $livewire instanceof Pages\CreateUser
                                ? self::generateRandomPassword()
                                : '')
                            ->helperText(fn ($livewire): ?string => $livewire instanceof Pages\EditUser
                                ? __('Leave blank to keep the current password.')
                                : __('Random password by default. Use reload to generate a new one.'))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->suffixAction(
                                FormInputAction::make('regenerate_password')
                                    ->label(__('Reload'))
                                    ->icon('heroicon-o-arrow-path')
                                    ->action(fn (Set $set): mixed => $set('password', self::generateRandomPassword()))
                            ),
                        Forms\Components\Select::make('role')
                            ->label(__('Rule'))
                            ->options(self::roleOptionsForActor())
                            ->required()
                            ->live()
                            ->native(false)
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if (in_array($state, [User::ROLE_ADMIN, User::ROLE_OWNER], true)) {
                                    $set('parent_id', null);
                                    $set('manager_id', null);
                                }
                                if ($state === User::ROLE_MANAGER) {
                                    $set('manager_id', null);
                                }
                            }),
                        Forms\Components\Select::make('status')
                            ->label(__('Status'))
                            ->options([
                                'normal' => 'Hoạt động',
                                'block' => 'Đã khóa',
                                'pending' => 'Chờ duyệt',
                            ])
                            ->required()
                            ->native(false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('Organization'))
                    ->extraAttributes(['class' => 'max-w-4xl'])
                    ->schema([
                        Forms\Components\Select::make('parent_id')
                            ->label(__('Owner'))
                            ->options(fn (): array => $hierarchy->ownersForSelect()
                                ->mapWithKeys(fn (User $u): array => [$u->id => $u->name.' ('.$u->email.')'])
                                ->all())
                            ->default(fn (): ?int => (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER
                                ? (int) auth()->id()
                                : null)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->visible(fn (Get $get): bool => in_array((string) $get('role'), [User::ROLE_MANAGER, User::ROLE_STAFF], true))
                            ->required(fn (Get $get): bool => in_array((string) $get('role'), [User::ROLE_MANAGER, User::ROLE_STAFF], true))
                            ->disabled(fn (): bool => (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER)
                            ->dehydrated()
                            ->afterStateUpdated(fn (Set $set) => $set('manager_id', null)),

                        Forms\Components\Select::make('manager_id')
                            ->label(__('Manager'))
                            ->options(function (Get $get) use ($hierarchy): array {
                                $ownerId = $get('parent_id');
                                if ((string) (auth()->user()?->role ?? '') === User::ROLE_OWNER) {
                                    $ownerId = auth()->id();
                                }

                                return $hierarchy->managersForOwner($ownerId !== null && $ownerId !== '' ? (int) $ownerId : null)
                                    ->mapWithKeys(fn (User $u): array => [$u->id => $u->name.' ('.$u->email.')'])
                                    ->all();
                            })
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visible(fn (Get $get): bool => (string) $get('role') === User::ROLE_STAFF)
                            ->helperText(__('Optional. Leave empty for Staff managed directly by Owner.')),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => in_array(
                        (string) $get('role'),
                        [User::ROLE_MANAGER, User::ROLE_STAFF],
                        true,
                    )),
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
                    ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name='.urlencode($record->name)),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('Full name'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage(__('Đã copy email'))
                    ->copyMessageDuration(2000),

                Tables\Columns\TextColumn::make('role')
                    ->label(__('Rule'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'owner' => 'success',
                        'manager' => 'warning',
                        'staff' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label(__('Owner'))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('manager.name')
                    ->label(__('Manager'))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'normal' => 'success',
                        'block' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('wallet.balance')
                    ->label(__('Wallet balance'))
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', '.').' đ')->default(0)
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultGroup('owner.name')
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label(__('Filter by rule'))
                    ->options([
                        'admin' => 'Admin',
                        'owner' => 'Owner',
                        'manager' => 'Manager',
                        'staff' => 'Staff',
                    ]),
                Tables\Filters\SelectFilter::make('parent_id')
                    ->label(__('Owner'))
                    ->relationship('owner', 'name', fn (Builder $query) => $query->where('role', User::ROLE_OWNER))
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('manager_id')
                    ->label(__('Manager'))
                    ->relationship('manager', 'name', fn (Builder $query) => $query->where('role', User::ROLE_MANAGER))
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('unassigned_staff')
                    ->label(__('Unassigned staff'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('role', User::ROLE_STAFF)
                        ->whereNull('manager_id')),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (User $record): void {
                        app(UserHierarchyService::class)->assertCanDelete($record);
                        app(UserHierarchyService::class)->detachStaffFromManager($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['owner', 'manager']);
        $actor = auth()->user();

        if ($actor instanceof User && (string) $actor->role === User::ROLE_OWNER) {
            return $query->where(function (Builder $builder) use ($actor): void {
                $builder->whereKey($actor->id)
                    ->orWhere('parent_id', $actor->id);
            });
        }

        if ($actor instanceof User && (string) $actor->role === User::ROLE_ADMIN) {
            return $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function generateRandomPassword(int $length = 16): string
    {
        return Str::password($length, letters: true, numbers: true, symbols: false);
    }

    /**
     * @return array<string, string>
     */
    private static function roleOptionsForActor(): array
    {
        $all = [
            User::ROLE_ADMIN => 'Administrator',
            User::ROLE_OWNER => 'Chủ sở hữu (Owner)',
            User::ROLE_MANAGER => 'Quản lý (Manager)',
            User::ROLE_STAFF => 'Nhân viên (Staff)',
        ];

        if ((string) (auth()->user()?->role ?? '') === User::ROLE_OWNER) {
            unset($all[User::ROLE_ADMIN], $all[User::ROLE_OWNER]);
        }

        return $all;
    }
}
