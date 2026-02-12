<?php

namespace App\Addons\WpHeadless;

use App\Addons\RegistersAddonDatabase;
use App\Addons\WpHeadless\Console\SyncWpSiteDataCommand;
use App\Addons\WpHeadless\Filament\Pages\WpHeadlessConnect;
use App\Addons\WpHeadless\Http\Middleware\WpHeadlessCors;
use Illuminate\Support\ServiceProvider;
use Route;

class WpHeadlessServiceProvider extends ServiceProvider
{
    use RegistersAddonDatabase;

    /** Tên connection (trùng với addon slug, dùng trong Models/Migrations). */
    public const DB_CONNECTION = 'wp_headless';

    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerApiRoutes();
        $this->registerCommands();
        $this->registerAddonDatabase(__DIR__, self::DB_CONNECTION, __DIR__ . '/database/migrations');
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
        Route::middleware(['web', WpHeadlessCors::class])
            ->group(function () {
                Route::get('/admin/wp-headless/connect', WpHeadlessConnect::class)
                    ->name('wp-headless.wp-connect');
            });
    }

    protected function registerApiRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');
    }
}
