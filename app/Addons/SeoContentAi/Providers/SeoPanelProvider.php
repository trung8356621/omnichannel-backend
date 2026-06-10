<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Providers;

use App\Addons\RegistersAddonDatabase;
use App\Addons\SeoContentAi\Filament\Widgets\WordPressPluginWidget;
use App\Addons\SeoContentAi\Http\Controllers\ArticleMediaPickerController;
use App\Addons\SeoContentAi\Http\Controllers\ArticlePreviewController;
use App\Addons\SeoContentAi\Http\Controllers\ArticleSeoPreviewController;
use App\Addons\SeoContentAi\Http\Controllers\ArticleWpEditRedirectController;
use App\Addons\SeoContentAi\Http\Controllers\GlobalAiChatController;
use App\Addons\SeoContentAi\Http\Controllers\PluginUpdateController;
use App\Addons\SeoContentAi\Http\Controllers\SeoMediaController;
use App\Addons\SeoContentAi\Http\Controllers\SeoWatermarkController;
use App\Addons\SeoContentAi\Http\Middleware\CheckMainRole;
use App\Addons\SeoContentAi\Services\PromptMediaStorageService;
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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

class SeoPanelProvider extends PanelProvider
{
    use RegistersAddonDatabase;

    public function register(): void
    {
        parent::register();

        // Shared persistTarget for usingTargetMedia() across PromptRunner / GeminiMediaGenerationService.
        $this->app->singleton(PromptMediaStorageService::class);
    }

    public function boot(): void
    {
        $addonRoot = dirname(__DIR__);

        Livewire::component('global-seo-bar', \App\Addons\SeoContentAi\Livewire\GlobalSeoBar::class);

        $this->loadViewsFrom($addonRoot.'/resources/views', 'seo-content-ai');
        $this->loadTranslationsFrom($addonRoot.'/lang', 'seo-content-ai');
        $this->registerAddonDatabase($addonRoot, 'omi_seo_ai', $addonRoot.'/database/migrations');

        FilamentView::registerRenderHook(
            'panels::global-search.after',
            fn (): string => Blade::render('@livewire(\'global-seo-bar\')'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): HtmlString => new HtmlString(
                view('seo-content-ai::filament.prompt-variable-insert')->render()
            ),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): HtmlString {
                if (! auth()->check() || ! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                if (
                    request()->routeIs('filament.seo.resources.articles.edit')
                    || preg_match('#^seo/articles/\d+/edit/?$#', request()->path()) === 1
                ) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::components.global-ai-chat')->render(),
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): HtmlString => new HtmlString(
                '<script>'
                .'window.__SEO_I18N_LOCALE__ = '.json_encode(app()->getLocale()).';'
                .'document.documentElement.setAttribute("lang", '.json_encode(str_replace('_', '-', app()->getLocale())).');'
                .'</script>'
            ),
        );

        Route::middleware('api')
            ->prefix('api')
            ->group(dirname(__DIR__).'/routes/api.php');

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
                Route::post('/rename-by-url', [SeoMediaController::class, 'renameByUrl'])
                    ->name('seo.media.rename-by-url');
                Route::get('/splitter-source', [SeoMediaController::class, 'splitterSource'])
                    ->name('seo.media.splitter-source');
                Route::post('/save-split', [SeoMediaController::class, 'saveSplit'])
                    ->name('seo.media.save-split');
                Route::post('/prepare-editor', [SeoMediaController::class, 'prepareEditor'])
                    ->name('seo.media.prepare-editor');
                Route::post('/apply-watermark', [SeoMediaController::class, 'applyWatermark'])
                    ->name('seo.media.apply-watermark');
                Route::get('/article/{article}/ai-jobs', [SeoMediaController::class, 'articleAiJobs'])
                    ->whereNumber('article')
                    ->name('seo.media.article-ai-jobs');
                Route::get('/{media}/status', [SeoMediaController::class, 'status'])
                    ->whereNumber('media')
                    ->name('seo.media.status');
                Route::post('/{media}/retry-generation', [SeoMediaController::class, 'retryGeneration'])
                    ->whereNumber('media')
                    ->name('seo.media.retry-generation');
                Route::delete('/{media}/ai-job', [SeoMediaController::class, 'deleteAiJob'])
                    ->whereNumber('media')
                    ->name('seo.media.delete-ai-job');
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
            ->prefix('api/ai')
            ->group(function (): void {
                Route::get('/chat/models', [GlobalAiChatController::class, 'models'])
                    ->name('seo.global-ai-chat.models');
                Route::post('/chat', [GlobalAiChatController::class, 'store'])
                    ->name('seo.global-ai-chat.store');
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
                Route::get('/articles/{article}/media-picker', ArticleMediaPickerController::class)
                    ->whereNumber('article')
                    ->name('seo.articles.media-picker');
                Route::get('/articles/{article}/seo-preview', ArticleSeoPreviewController::class)
                    ->name('seo.articles.seo-preview');
                Route::get('/articles/{article}/preview', ArticlePreviewController::class)
                    ->name('seo.articles.preview');
                Route::get('/articles/wp-edit-redirect', ArticleWpEditRedirectController::class)
                    ->name('seo.articles.wp-edit-redirect');
                Route::get('/wp-plugin/download/{version}', [PluginUpdateController::class, 'downloadForPanel'])
                    ->name('seo.wp-plugin.download');
            });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('seo')
            ->path('seo')
            ->login()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverResources(
                in: __DIR__.'/../Filament/Resources',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Resources'
            )
            ->discoverPages(
                in: __DIR__.'/../Filament/Pages',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Pages'
            )
            ->pages([
                Pages\Dashboard::class,
            ])
            ->widgets([
                WordPressPluginWidget::class,
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
