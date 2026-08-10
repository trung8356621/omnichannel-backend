<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SearchFoundation\Support\SeoSiteServiceDatabaseConfigurator;
use App\Filament\Resources\SiteServiceResource\Pages;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SiteServiceResource extends Resource
{
    protected static ?string $model = SiteService::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    // Đặt vào nhóm Site Management để quản lý tập trung
    protected static ?string $navigationGroup = 'Site Management';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Activated Services');
    }

    public static function getModelLabel(): string
    {
        return __('Site Service');
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role === User::ROLE_ADMIN;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === User::ROLE_ADMIN;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === User::ROLE_ADMIN;
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        if (! $record instanceof SiteService) {
            return false;
        }

        if (auth()->user()?->role === User::ROLE_ADMIN) {
            return true;
        }

        return static::getEloquentQuery()->whereKey($record->getKey())->exists();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Service Activation'))
                    ->description(__('Connect an addon service to a site or directly to an owner account.'))
                    ->schema([
                        Forms\Components\Select::make('bound_type')
                            ->label(__('Bound to'))
                            ->options([
                                SiteServiceBindingService::BOUND_SITE => __('Site (domain)'),
                                SiteServiceBindingService::BOUND_USER => __('User (owner)'),
                            ])
                            ->default(SiteServiceBindingService::BOUND_SITE)
                            ->required()
                            ->live()
                            ->native(false)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state === SiteServiceBindingService::BOUND_USER) {
                                    $set('site_id', null);

                                    return;
                                }

                                $set('user_id', null);
                            }),

                        Forms\Components\Select::make('site_id')
                            ->label(__('Select Site'))
                            ->options(fn (): array => static::siteSelectOptions())
                            ->required(fn (Get $get): bool => ($get('bound_type') ?? SiteServiceBindingService::BOUND_SITE) === SiteServiceBindingService::BOUND_SITE)
                            ->visible(fn (Get $get): bool => ($get('bound_type') ?? SiteServiceBindingService::BOUND_SITE) === SiteServiceBindingService::BOUND_SITE)
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('user_id')
                            ->label(__('Select Owner'))
                            ->options(fn (): array => static::ownerSelectOptions())
                            ->required(fn (Get $get): bool => ($get('bound_type') ?? '') === SiteServiceBindingService::BOUND_USER)
                            ->visible(fn (Get $get): bool => ($get('bound_type') ?? '') === SiteServiceBindingService::BOUND_USER)
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('service_id')
                            ->label(__('Select Service'))
                            ->options(fn () => Service::where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->live() // Kích hoạt tương tác thời gian thực
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if (! $state) {
                                    $set('settings', []);

                                    return;
                                }

                                // 1. Tìm thông tin Service được chọn
                                $service = Service::find($state);
                                if (! $service) {
                                    return;
                                }

                                // 2. Xác định Class Settings của Addon dựa trên namespace
                                // Ví dụ: App\Addons\SeoContentAi\Settings
                                $providerNamespace = $service->addon_namespace;
                                $settingsClass = str_replace(
                                    class_basename($providerNamespace),
                                    'Settings',
                                    $providerNamespace
                                );

                                // 3. Nếu class tồn tại, gọi getDefaults() và đổ vào trường settings
                                if (class_exists($settingsClass) && method_exists($settingsClass, 'getDefaults')) {
                                    $defaults = (new $settingsClass)->getDefaults();
                                    $set('settings', $defaults);
                                    $set('seo_db_config_type', (string) ($defaults['db_config_type'] ?? 'auto'));
                                } else {
                                    $set('settings', []);
                                    $set('seo_db_config_type', 'auto');
                                }
                            }),

                        Forms\Components\Select::make('status')
                            ->label(__('Status'))
                            ->options([
                                'active' => __('Active'),
                                'inactive' => __('Inactive'),
                                'maintenance' => __('Maintenance'),
                            ])
                            ->default('active')
                            ->required(),
                    ])->columns(2),

                ...SeoSiteServiceDatabaseConfigurator::formSchema(),

                Forms\Components\Section::make(__('Service Settings'))
                    ->description(__('Configure specific parameters for this service instance.'))
                    ->schema([
                        Forms\Components\KeyValue::make('settings')
                            ->label(__('Custom Configuration'))
                            ->keyLabel(__('Parameter Name'))
                            ->valueLabel(__('Value'))
                            ->addActionLabel(__('Add Parameter'))
                            ->helperText(__('Example: api_key, webhook_url, target_language, etc.'))
                            ->formatStateUsing(function (?array $state): array {
                                if (! is_array($state)) {
                                    return [];
                                }

                                $filtered = $state;
                                unset($filtered['db_config_type']);

                                return $filtered;
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $isAdmin = auth()->user()?->role === User::ROLE_ADMIN;

        $actions = $isAdmin
            ? [
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]
            : [
                Tables\Actions\ViewAction::make(),
            ];

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('service'))
            ->columns([
                Tables\Columns\TextColumn::make('bound_type')
                    ->label(__('Bound'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === SiteServiceBindingService::BOUND_USER
                        ? __('User')
                        : __('Site')),

                Tables\Columns\TextColumn::make('bound_target')
                    ->label(__('Bound to'))
                    ->state(fn (?SiteService $record): string => $record instanceof SiteService ? $record->boundLabel() : '')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $inner) use ($search): void {
                            $inner->whereHas('site', fn (Builder $site): Builder => $site->where('domain', 'like', "%{$search}%"))
                                ->orWhereHas('user', fn (Builder $user): Builder => $user->where('email', 'like', "%{$search}%"));
                        });
                    }),

                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('Website'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('service.name')
                    ->label(__('Service Name'))
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('wp_plugin_release')
                    ->label(__('WP Plugin'))
                    ->alignCenter()
                    ->state(fn (): string => __('Release'))
                    ->color('warning')
                    ->badge()
                    ->icon('heroicon-m-arrow-up-tray')
                    ->iconPosition(\Filament\Support\Enums\IconPosition::Before)
                    ->url(fn (?SiteService $record): ?string => $record instanceof SiteService
                        ? static::wpPluginReleaseUrlFor($record)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (?SiteService $record): bool => $record instanceof SiteService
                        && static::shouldShowWpPluginReleaseAction($record)),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        'maintenance' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __($state)),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Last Updated'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('site_id')
                    ->label(__('Filter by Site'))
                    ->options(fn (): array => static::siteSelectOptions()),

                Tables\Filters\SelectFilter::make('service_id')
                    ->label(__('Filter by Service'))
                    ->relationship('service', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('wp_plugin_release')
                    ->label(__('WP Plugin Release'))
                    ->icon('heroicon-m-arrow-up-tray')
                    ->color('warning')
                    ->button()
                    ->outlined()
                    ->url(fn (?SiteService $record): ?string => $record instanceof SiteService
                        ? static::wpPluginReleaseUrlFor($record)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (?SiteService $record): bool => $record instanceof SiteService
                        && static::shouldShowWpPluginReleaseAction($record)),
                ...$actions,
            ])
            ->bulkActions(
                $isAdmin
                    ? [
                        Tables\Actions\BulkActionGroup::make([
                            Tables\Actions\DeleteBulkAction::make(),
                        ]),
                    ]
                    : [],
            );
    }

    /**
     * @return array<int|string, string>
     */
    public static function siteSelectOptions(): array
    {
        $query = Site::query()->orderBy('domain');

        if (auth()->user()?->role !== User::ROLE_ADMIN) {
            $query->where('user_id', auth()->id());
        }

        return $query->pluck('domain', 'id')->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function ownerSelectOptions(): array
    {
        return User::query()
            ->where('role', User::ROLE_OWNER)
            ->orderBy('email')
            ->pluck('email', 'id')
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Chỉ cho phép user thấy các dịch vụ đã kích hoạt cho Site của mình
        if (auth()->check() && auth()->user()->role !== User::ROLE_ADMIN) {
            $ownerId = (int) auth()->id();

            return $query->where(function (Builder $inner) use ($ownerId): void {
                $inner->where(function (Builder $userBound) use ($ownerId): void {
                    $userBound->where('bound_type', SiteServiceBindingService::BOUND_USER)
                        ->where('user_id', $ownerId);
                })->orWhereHas('site', function (Builder $siteQuery) use ($ownerId): void {
                    $siteQuery->where('user_id', $ownerId);
                });
            });
        }

        return $query;
    }

    public static function shouldShowWpPluginReleaseAction(SiteService $record): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if (! in_array((string) $user->role, [User::ROLE_ADMIN, User::ROLE_OWNER], true)) {
            return false;
        }

        return static::isSeoContentAiRecord($record);
    }

    public static function wpPluginReleaseUrlFor(SiteService $record): ?string
    {
        if (! static::shouldShowWpPluginReleaseAction($record)) {
            return null;
        }

        return static::wpPluginReleaseUrl();
    }

    public static function wpPluginReleaseUrl(): string
    {
        return url('/wp-plugin-release?name=omi-seo-ai-bridge');
    }

    public static function isSeoContentAiRecord(SiteService $record): bool
    {
        $record->loadMissing('service');

        if (app(SeoDatabaseConnectionService::class)->isSeoContentAiService((int) $record->service_id)) {
            return true;
        }

        $addonNamespace = (string) ($record->service?->addon_namespace ?? '');

        return str_contains($addonNamespace, 'SeoContentAi');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteServices::route('/'),
            'create' => Pages\CreateSiteService::route('/create'),
            'view' => Pages\ViewSiteService::route('/{record}'),
            'edit' => Pages\EditSiteService::route('/{record}/edit'),
        ];
    }
}
