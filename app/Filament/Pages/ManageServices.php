<?php
namespace App\Filament\Pages;

use App\Models\Service;
use App\Services\AddonManager;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Redirect;

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
        $service->update(['is_active' => !$service->is_active]);
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