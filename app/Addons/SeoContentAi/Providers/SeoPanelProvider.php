<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Providers;

use App\Addons\RegistersAddonDatabase;
use App\Addons\SeoContentAi\Http\Controllers\ArticlePreviewController;
use App\Addons\SeoContentAi\Http\Controllers\ArticleSeoPreviewController;
use App\Addons\SeoContentAi\Http\Controllers\SeoMediaController;
use App\Addons\SeoContentAi\Http\Controllers\SeoWatermarkController;
use App\Addons\SeoContentAi\Http\Middleware\CheckMainRole;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SeoPanelProvider extends PanelProvider
{
    use RegistersAddonDatabase;

    public function boot(): void
    {
        $addonRoot = dirname(__DIR__);

        $this->loadViewsFrom($addonRoot . '/resources/views', 'seo-content-ai');
        $this->registerAddonDatabase($addonRoot, 'omi_seo_ai', $addonRoot . '/database/migrations');

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): HtmlString => new HtmlString(
                view('seo-content-ai::filament.prompt-variable-insert')->render()
            ),
        );

        Route::middleware('api')
            ->prefix('api')
            ->group(dirname(__DIR__) . '/routes/api.php');

        Route::middleware([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            Authenticate::class,
            CheckMainRole::class,
        ])
            ->prefix('api/seo/media')
            ->group(function (): void {
                Route::post('/upload', [SeoMediaController::class, 'upload'])
                    ->name('seo.media.upload');
                Route::post('/import-url', [SeoMediaController::class, 'importFromUrl'])
                    ->name('seo.media.import-url');
                Route::get('/splitter-source', [SeoMediaController::class, 'splitterSource'])
                    ->name('seo.media.splitter-source');
                Route::post('/save-split', [SeoMediaController::class, 'saveSplit'])
                    ->name('seo.media.save-split');
                Route::post('/prepare-editor', [SeoMediaController::class, 'prepareEditor'])
                    ->name('seo.media.prepare-editor');
                Route::post('/apply-watermark', [SeoMediaController::class, 'applyWatermark'])
                    ->name('seo.media.apply-watermark');
                Route::post('/{media}/rename', [SeoMediaController::class, 'rename'])
                    ->whereNumber('media')
                    ->name('seo.media.rename');
                Route::post('/{media}/save-edited', [SeoMediaController::class, 'saveEditedImage'])
                    ->whereNumber('media')
                    ->name('seo.media.save-edited');
            });

        Route::middleware([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            Authenticate::class,
            CheckMainRole::class,
        ])
            ->prefix('api/seo/watermark')
            ->group(function (): void {
                Route::get('/settings', [SeoWatermarkController::class, 'showSettings'])
                    ->name('seo.watermark.settings.show');
                Route::post('/settings', [SeoWatermarkController::class, 'saveSettings'])
                    ->name('seo.watermark.settings.save');
                Route::post('/batch', [SeoWatermarkController::class, 'applyBatch'])
                    ->name('seo.watermark.batch');
                Route::post('/media/{media}/save', [SeoWatermarkController::class, 'saveMediaWatermark'])
                    ->whereNumber('media')
                    ->name('seo.watermark.media.save');
                Route::post('/save-new', [SeoWatermarkController::class, 'saveNewFromCanvas'])
                    ->name('seo.watermark.save-new');
            });

        Route::middleware([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            Authenticate::class,
            CheckMainRole::class,
        ])
            ->prefix('seo')
            ->group(function (): void {
                Route::get('/articles/{article}/seo-preview', ArticleSeoPreviewController::class)
                    ->name('seo.articles.seo-preview');
                Route::get('/articles/{article}/preview', ArticlePreviewController::class)
                    ->name('seo.articles.preview');
            });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('seo')
            ->path('seo')
            ->login()
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverResources(
                in: __DIR__ . '/../Filament/Resources',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Resources'
            )
            ->discoverPages(
                in: __DIR__ . '/../Filament/Pages',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Pages'
            )
            ->pages([
                Pages\Dashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                CheckMainRole::class,
            ]);
    }
}
