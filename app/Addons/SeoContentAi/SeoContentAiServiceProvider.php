<?php
namespace App\Addons\SeoContentAi;

use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Illuminate\Support\ServiceProvider;
use Filament\Facades\Filament;
use App\Addons\SeoContentAi\Filament\Pages\SeoDashboard;
use Illuminate\Support\Facades\Route;
use SeoContentAiPlugin;

class SeoContentAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // Đăng ký views cho addon
        // Tự động thêm trang của Addon vào Filament Panel nếu addon đang active

        Filament::serving(function () {
            $panel = Filament::getCurrentPanel();
            if ($panel && $panel->getId() === 'admin') {
                $panel->pages([
                    SeoDashboard::class,
                ]);
            }
        });
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'seo-content-ai');
        $this->registerRoutes();
    }

    /**
     * Phương thức bổ trợ để đăng ký routes thủ công
     */
    protected function registerRoutes(): void
    {
        if (file_exists(__DIR__ . '/routes/web.php')) {
            Route::middleware([
                'web',
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ])
                ->prefix('admin') // Bạn có thể tùy chỉnh prefix cho addon này
                ->name('filament.admin.pages.') // Đặt name prefix để khớp với cách gọi của Filament
                ->group(__DIR__ . '/routes/web.php');
        }
    }
}