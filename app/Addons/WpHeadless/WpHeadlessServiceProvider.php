<?php

namespace App\Addons\WpHeadless;

use App\Addons\RegistersAddonDatabase;
use App\Addons\WpHeadless\Console\SyncWpSiteDataCommand;
use App\Addons\WpHeadless\Filament\Pages\WpHeadlessConnect;
use App\Addons\WpHeadless\Filament\Pages\WpHeadlessSitePage;
use App\Addons\WpHeadless\Http\Controllers\SiteProxyController;
use App\Addons\WpHeadless\Http\Middleware\WpHeadlessCors;
use App\Models\FrontendProject;
use Illuminate\Support\ServiceProvider;
use Route;

class WpHeadlessServiceProvider extends ServiceProvider
{
    use RegistersAddonDatabase;

    /** Tên connection (trùng với addon slug, dùng trong Models/Migrations). */
    public const DB_CONNECTION = 'wp_headless';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/wp-headless.php', 'wp-headless');
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerApiRoutes();
        $this->registerCommands();
        $this->registerAddonDatabase(__DIR__, self::DB_CONNECTION, __DIR__ . '/database/migrations');
        $this->registerFrontendProject();
    }

    /**
     * Đăng ký project Next.js vào bảng frontend_projects (chức năng quản lý NPM ở project chính).
     * Cấu hình trong addon.json: "frontend_project": { "name": "WP Headless", "path": "assets/wp-headless" }
     */
    private function registerFrontendProject(): void
    {
        $meta = $this->getAddonMetaFromPath(__DIR__);
        $frontend = $meta['frontend_project'] ?? null;
        if (!is_array($frontend) || empty($frontend['path'])) {
            return;
        }

        $pathFromAddon = str_replace('\\', '/', trim($frontend['path'], " \t\n\r\0\x0B/\\"));
        $pathFromBase = 'app/Addons/WpHeadless/' . $pathFromAddon;
        $name = $frontend['name'] ?? 'WP Headless';

        FrontendProject::updateOrCreate(
            ['package_json_path' => $pathFromBase],
            [
                'name' => $name,
                'type' => FrontendProject::TYPE_NEXTJS,
            ]
        );
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
        Route::middleware(['web', 'auth'])
            ->group(function () {
                Route::get('/admin/wp-headless/site', WpHeadlessSitePage::class)
                    ->name('wp-headless.site');
            });

        // Public proxy tới Next.js wp-headless: /site/{slug} và /site/{slug}/{path}
        Route::middleware('web')
            ->get('/site/{slug}/{path?}', SiteProxyController::class)
            ->where('path', '.*')
            ->name('wp-headless.site-proxy');
    }

    protected function registerApiRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');
    }
}
