<?php
namespace App\Filament\Resources;

use App\Filament\Resources\SiteServiceResource\Pages;
use App\Models\SiteService;
use App\Models\Service;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Service Activation'))
                    ->description(__('Connect an addon service to your website.'))
                    ->schema([
                        // Chọn Site (chỉ hiện site của user hiện tại)
                        Forms\Components\Select::make('site_id')
                            ->label(__('Select Site'))
                            ->options(fn() => Site::where('user_id', auth()->id())->pluck('domain', 'id'))
                            ->required()
                            ->searchable()
                            ->preload(),

                        /**
                         * Logic Xử lý khi Thay đổi Service
                         */
                        Forms\Components\Select::make('service_id')
                            ->label(__('Select Service'))
                            ->options(fn() => Service::where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->live() // Kích hoạt tương tác thời gian thực
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if (!$state) {
                                    $set('settings', []);
                                    return;
                                }

                                // 1. Tìm thông tin Service được chọn
                                $service = Service::find($state);
                                if (!$service)
                                    return;

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
                                    $defaults = (new $settingsClass())->getDefaults();
                                    $set('settings', $defaults);
                                } else {
                                    $set('settings', []);
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

                // Phần cấu hình JSON (Settings)
                Forms\Components\Section::make(__('Service Settings'))
                    ->description(__('Configure specific parameters for this service instance.'))
                    ->schema([
                        // Sử dụng KeyValue hoặc Repeater tùy vào loại service
                        // Ở đây dùng KeyValue cho linh hoạt nhất với JSON
                        Forms\Components\KeyValue::make('settings')
                            ->label(__('Custom Configuration'))
                            ->keyLabel(__('Parameter Name'))
                            ->valueLabel(__('Value'))
                            ->addActionLabel(__('Add Parameter'))
                            ->helperText(__('Example: api_key, webhook_url, target_language, etc.')),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        $actions = [];

        if (auth()->user()?->role === 'admin') {
            $actions = [
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ];
        }


        return $table
            ->columns([
                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('Website'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('service.name')
                    ->label(__('Service Name'))
                    ->badge()
                    ->color('info')
                    ->sortable(),

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

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Last Updated'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('site_id')
                    ->label(__('Filter by Site'))
                    ->options(fn() => Site::where('user_id', auth()->id())->pluck('domain', 'id')),

                Tables\Filters\SelectFilter::make('service_id')
                    ->label(__('Filter by Service'))
                    ->relationship('service', 'name'),
            ])
            ->actions([
                // Thêm nút đi đến trang Dashboard riêng của Addon nếu có
                // Tables\Actions\Action::make('open_addon')
                //     ->label(__('Open Addon'))
                //     ->icon('heroicon-m-arrow-top-right-on-square')
                //     ->color('success')
                //     ->url(fn(SiteService $record): string => "/admin/{$record->service->slug}/dashboard?site_id={$record->site_id}")
                //     ->visible(fn(SiteService $record) => $record->status === 'active'),
                ...$actions
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

        // Chỉ cho phép user thấy các dịch vụ đã kích hoạt cho Site của mình
        if (auth()->check() && auth()->user()->role !== 'admin') {
            return $query->whereHas('site', function ($q) {
                $q->where('user_id', auth()->id());
            });
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteServices::route('/'),
            'create' => Pages\CreateSiteService::route('/create'),
            'edit' => Pages\EditSiteService::route('/{record}/edit'),
        ];
    }
}