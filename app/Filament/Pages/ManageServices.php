<?php

namespace App\Filament\Pages;

use App\Addons\AddonDatabaseConfig;
use App\Models\Service;
use App\Services\AddonManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class ManageServices extends Page
{
    protected static ?string $navigationGroup = 'Hệ thống'; // Khớp với nhãn trong Provider

    protected static ?int $navigationSort = 999; // Nằm cuối

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string $view = 'filament.pages.manage-services';

    protected static ?string $navigationLabel = 'Quản lý Service';

    protected static ?string $title = 'Hệ thống Addon & Dịch vụ';

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
            Notification::make()->title('Không tìm thấy service')->danger()->send();

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
                        ->title('Không thể kích hoạt addon')
                        ->body("Database chưa được tạo. Vui lòng tạo database \"{$dbName}\" (và chạy migration cho addon) trước khi kích hoạt.")
                        ->danger()
                        ->send();

                    return;
                }
            }
        }

        $service->update(['is_active' => ! $service->is_active]);
        $this->services = Service::all()->toArray();
        Notification::make()->title('Cập nhật trạng thái thành công')->success()->send();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->role === 'admin';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role === 'admin';
    }
}
