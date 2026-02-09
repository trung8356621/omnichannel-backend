<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Sites');
    }

    public static function getModelLabel(): string
    {
        return __('Site');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sites');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\TextInput::make('domain')
                    ->label(__('Domain'))
                    ->placeholder('example.com')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    // Tự động bóc tách domain nếu khách nhập full URL
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set) {
                        if (filter_var($state, FILTER_VALIDATE_URL) || str_contains($state, '://')) {
                            $domain = parse_url($state, PHP_URL_HOST);
                            if ($domain) {
                                $set('domain', $domain);
                            }
                        }
                    }),

                Forms\Components\Select::make('user_id')
                    ->label(__('Owner'))
                    ->relationship('user', 'name', fn(Builder $query) => $query->whereIn('role', ['admin', 'owner']))
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\TextInput::make('url')
                    ->label(__('Admin url (use for headless)'))
                    ->placeholder('https://example.com')
                    ->url()
                    ->maxLength(255),

                // Bổ sung trường SSL
                Forms\Components\Toggle::make('ssl')
                    ->label('SSL (HTTPS)')
                    ->default(true)
                    ->helperText(__('Enable if the site uses HTTPS protocol.'))
                    ->required(),





                Forms\Components\Select::make('status')
                    ->label(__('Status'))
                    ->options([
                        'active' => __('Active'),
                        'inactive' => __('Inactive'),
                        'maintenance' => __('Maintenance'),
                    ])
                    ->default('active')
                    ->required()
                    ->native(false),


            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([


                Tables\Columns\TextColumn::make('domain')
                    ->label(__('Domain'))
                    ->searchable()
                    ->sortable(),

                // Hiển thị trạng thái SSL trên bảng
                Tables\Columns\IconColumn::make('ssl')
                    ->label('SSL')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('url')
                    ->label(__('URL'))
                    ->copyable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        'maintenance' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => __($state)),

                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('Owner'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'active' => __('Active'),
                        'inactive' => __('Inactive'),
                        'maintenance' => __('Maintenance'),
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
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        // Nếu không phải admin, chỉ xem được Site của chính mình
        $query = parent::getEloquentQuery();

        if (auth()->user()->role !== 'admin') {
            return $query->where('user_id', auth()->id());
        }

        return $query->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
