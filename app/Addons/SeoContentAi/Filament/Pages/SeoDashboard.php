<?php
namespace App\Addons\SeoContentAi\Filament\Pages;

use Filament\Pages\Page;

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

    public static function getNavigationLabel(): string
    {
        return __('SEO AI Generator');
    }

    public function getHeading(): string
    {
        return 'Tạo nội dung chuẩn SEO bằng AI';
    }
}
