<?php
namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Models\Service;
use App\Models\Site;
use Cache;
use Filament\Pages\Page;
use Filament\Panel;
use Route;

class SeoDashboard extends Page
{
    /**
     * Cấu hình tiền tố Router. 
     * URL sẽ là: /admin/seo/dashboard
     */
    protected static ?string $slug = 'seo/dashboard';

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static string $view = 'seo-content-ai::filament.pages.seo-dashboard';

    protected static ?string $navigationGroup = 'SEO Automation';

    protected static ?string $navigationLabel = 'SEO Automation';
    protected static ?string $title = 'SEO Automation';

    public static function getNavigationLabel(): string
    {
        return __('SEO AI Generator');
    }

    public function getHeading(): string
    {
        $siteId = request()->query('site_id');
        $site = Site::find($siteId);
        return $site ? "SEO AI Generator: {$site->domain}" : "SEO AI Generator";
    }

    /**
     * PHÂN QUYỀN TRUY CẬP ROUTER:
     * Chỉ cho phép truy cập nếu Service "seo-content-ai" đang Active trong Database.
     */
    public static function canAccess(): bool
    {
        // Sử dụng Cache để tối ưu hiệu năng, tránh query DB liên tục mỗi khi load trang
        return Cache::remember('addon_active_seo_content_ai', 3600, function () {
            return Service::where('slug', 'seo-content-ai')
                ->where('is_active', true)
                ->exists();
        });
    }

    /**
     * PHÂN QUYỀN HIỂN THỊ MENU (SIDEBAR):
     * Mặc định ẩn hết với mọi người, trừ Admin.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->role === 'admin';
    }
}
