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
use Illuminate\Validation\Rules\Unique;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    public static function canAccess(): bool
    {
        return auth()->user()?->role === 'admin';
    }

    /** Menu cấp cao, không gom chung nhóm khác */
    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Site Management');
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
                    ->unique(
                        table: Site::class,
                        column: 'domain',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->whereNull('deleted_at'),
                    )
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
                    ->relationship('user', 'name', fn (Builder $query) => $query->whereIn('role', ['admin', 'owner']))
                    ->searchable()
                    ->preload()
                    ->required(),

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

                Forms\Components\Section::make(__('WP Headless'))
                    ->description(__('Cấu hình Headless cho site (chỉ hiện khi Site đã kích hoạt service WP Headless).'))
                    ->schema([
                        Forms\Components\TextInput::make('wp_headless.type')
                            ->label(__('Type'))
                            ->placeholder('wordpress, elementor_based, ...')
                            ->maxLength(64),
                        Forms\Components\TextInput::make('wp_headless.public_url')
                            ->label(__('Public URL'))
                            ->placeholder('https://example.com')
                            ->url()
                            ->maxLength(512),
                        Forms\Components\TextInput::make('wp_headless.headless_next_dev')
                            ->label(__('Headless Next.js (dev)'))
                            ->placeholder('http://localhost:3000')
                            ->maxLength(255)
                            ->helperText(__('URL Next.js khi chạy dev.')),
                        Forms\Components\Toggle::make('wp_headless.is_dev')
                            ->label(__('Đang dùng môi trường dev'))
                            ->default(false),
                    ])
                    ->columns(2)
                    ->visible(fn ($livewire) => method_exists($livewire, 'getRecord') && $livewire->getRecord()?->hasActiveWpHeadless()),

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
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('Owner'))
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('domain')
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('settings')
                    ->label(__('Service settings'))
                    ->icon('heroicon-o-cog-8-tooth')
                    ->iconButton()
                    ->url(fn (Site $record): ?string => ($ss = $record->primarySiteServiceForSettings())
                        ? SiteServiceResource::getUrl('edit', ['record' => $ss])
                        : null)
                    ->visible(fn (Site $record): bool => (bool) $record->primarySiteServiceForSettings()),
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
        $query = parent::getEloquentQuery()->with(['user', 'siteServices.service']);

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
