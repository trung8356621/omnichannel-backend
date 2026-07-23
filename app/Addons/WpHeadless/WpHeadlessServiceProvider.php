<?php

namespace App\Addons\WpHeadless;

use App\Addons\RegistersAddonDatabase;
use App\Addons\WpHeadless\Console\SyncWpSiteDataCommand;
use App\Addons\WpHeadless\Console\TemplateColumnToJsonCommand;
use App\Addons\WpHeadless\Filament\Pages\WpHeadlessConnect;
use App\Addons\WpHeadless\Filament\Pages\WpHeadlessSitePage;
use App\Addons\WpHeadless\Http\Controllers\SiteProxyController;
use App\Addons\WpHeadless\Http\Middleware\WpHeadlessCors;
use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Addons\WpHeadless\Observers\SiteServiceObserver;
use App\Addons\WpHeadless\Observers\WpHeadlessTemplateObserver;
use App\Contracts\DeclaresDatabaseTableOwnership;
use App\Models\SiteService;
use Illuminate\Support\ServiceProvider;
use Route;

class WpHeadlessServiceProvider extends ServiceProvider implements DeclaresDatabaseTableOwnership
{
    use RegistersAddonDatabase;

    /** Tên connection (trùng với addon slug, dùng trong Models/Migrations). */
    public const DB_CONNECTION = 'wp_headless';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/wp-headless.php', 'wp-headless');
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerApiRoutes();
        $this->registerCommands();
        $this->registerAddonDatabase(__DIR__, self::DB_CONNECTION, __DIR__.'/database/migrations');
        WpHeadlessTemplate::observe(WpHeadlessTemplateObserver::class);
        SiteService::observe(SiteServiceObserver::class);
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncWpSiteDataCommand::class,
                TemplateColumnToJsonCommand::class,
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
            ->group(__DIR__.'/routes/api.php');
    }

    /**
     * @return array{connection: string, tables: list<string>, patterns: list<string>}
     */
    public function databaseTableOwnership(): array
    {
        return [
            'connection' => self::DB_CONNECTION,
            'tables' => [
                'wp_headless_sites',
                'wp_headless_styles',
                'wp_headless_templates',
                'wp_headless_styles_optimized',
            ],
            'patterns' => [
                'wp_headless_*',
            ],
        ];
    }
}
