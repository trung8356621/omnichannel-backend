<?php
namespace App\Addons\WpHeadless;

use App\Addons\WpHeadless\Console\SyncWpSiteDataCommand;
use App\Addons\WpHeadless\Filament\Pages\WpHeadlessConnect;
use App\Addons\WpHeadless\Http\Middleware\WpHeadlessCors;
use Illuminate\Support\ServiceProvider;
use Route;

class WpHeadlessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncWpSiteDataCommand::class,
            ]);
        }
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
