<?php
namespace App\Addons\WpHeadless;

use App\Addons\WpHeadless\Filament\Pages\WpHeadlessConnect;
use Illuminate\Support\ServiceProvider;
use App\Addons\WpHeadless\Http\Middleware\WpHeadlessCors;
use Route;

class WpHeadlessServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }


    public function boot(): void
    {
        // 1. Đăng ký Route và gắn Middleware CORS vào
        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        /**
         * QUAN TRỌNG:
         * Chúng ta phải dùng middleware 'web' để Laravel khởi tạo Session,
         * và dùng WpHeadlessCors để cho phép WordPress truy cập.
         */
        Route::middleware([
            'web',
            WpHeadlessCors::class
        ])
            ->group(function () {
                // Route xử lý kết nối: /admin/wp-headless/connect
                // Route này sẽ được dùng trong WordPress Plugin của bạn
                Route::get('/admin/wp-headless/connect', WpHeadlessConnect::class)
                    ->name('wp-headless.wp-connect');
            });
    }


}
