<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Addons\AddonDatabaseConfig;
use App\Models\Service;
use App\Services\AddonManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class ManageServices extends Page
{
    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 999;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string $view = 'filament.pages.manage-services';

    public static function getNavigationGroup(): ?string
    {
        return __('site-service.system_nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('site-service.manage_services_nav');
    }

    public function getTitle(): string
    {
        return __('site-service.manage_services_title');
    }

    public $services = [];

    public function mount()
    {
        AddonManager::discover(); // Tự động quét khi truy cập
        $this->services = Service::all()->toArray();
    }

    public function toggleService($id)
    {
        $service = Service::find($id);
        if (! $service) {
            Notification::make()->title(__('site-service.service_not_found'))->danger()->send();

            return;
        }

        $willActivate = ! $service->is_active;
        if ($willActivate) {
            $dbName = AddonDatabaseConfig::databaseNameFromMeta(
                AddonDatabaseConfig::enrichMetaWithAddonPath($service->config ?? [], (string) $service->slug)
            );
            if (! empty($dbName)) {
                $exists = DB::selectOne(
                    'SELECT 1 FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                    [$dbName]
                );
                if (! $exists) {
                    Notification::make()
                        ->title(__('site-service.cannot_activate_addon'))
                        ->body(__('site-service.database_not_created', ['name' => $dbName]))
                        ->danger()
                        ->send();

                    return;
                }
            }
        }

        $service->update(['is_active' => ! $service->is_active]);
        $this->services = Service::all()->toArray();
        Notification::make()->title(__('site-service.status_updated'))->success()->send();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return auth()->user()?->role === 'admin';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role === 'admin';
    }
}
