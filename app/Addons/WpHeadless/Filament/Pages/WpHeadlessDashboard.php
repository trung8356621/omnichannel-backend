<?php
namespace App\Addons\WpHeadless\Filament\Pages;

use Filament\Pages\Page;

class WpHeadlessDashboard extends Page
{
    // URL: /admin/wp-headless/manage
    protected static ?string $slug = 'wp-headless/manage';
    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';

    // Đường dẫn View đã đăng ký trong Provider
    protected static string $view = 'wp-headless::filament.pages.wp-headless-dashboard';

    protected static ?string $navigationGroup = 'Site Management';

    public static function getNavigationLabel(): string
    {
        return 'WP Headless';
    }

    public function getHeading(): string
    {
        return 'Quản lý WP Headless Build';
    }
    /**
     * PHÂN QUYỀN HIỂN THỊ MENU (SIDEBAR):
     * Mặc định ẩn hết với mọi người, trừ Admin.
     */
    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return auth()->user()?->role === 'admin';
    }
}
