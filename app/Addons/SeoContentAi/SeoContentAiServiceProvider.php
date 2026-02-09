<?php
namespace App\Addons\SeoContentAi;

use App\Models\Service;
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
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'seo-content-ai');

        // 2. Chỉ đăng ký vào giao diện nếu Addon đang Active trong DB
        Filament::serving(function () {
            $service = Service::where('slug', 'seo-content-ai')->first();

            if ($service && $service->is_active) {
                $panel = Filament::getCurrentPanel();

                // Tự động quét và nạp các thành phần cục bộ của Addon
                $panel->discoverPages(
                    in: __DIR__ . '/Filament/Pages',
                    for: 'App\\Addons\\SeoContentAi\\Filament\\Pages'
                )->discoverResources(
                        in: __DIR__ . '/Filament/Resources',
                        for: 'App\\Addons\\SeoContentAi\\Filament\\Resources'
                    );
            }
        });


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