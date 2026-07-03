<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Providers;

use App\Addons\SeoContentAi\Filament\Pages\Auth\SeoChangePassword;
use App\Addons\SeoContentAi\Filament\Pages\Auth\SeoEditProfile;
use App\Addons\SeoContentAi\Http\Controllers\ArticleMediaPickerController;
use App\Addons\SeoContentAi\Http\Controllers\ArticleOutlineController;
use App\Addons\SeoContentAi\Http\Controllers\ArticlePreviewController;
use App\Addons\SeoContentAi\Http\Controllers\ArticleRevisionController;
use App\Addons\SeoContentAi\Http\Controllers\ArticleSeoPreviewController;
use App\Addons\SeoContentAi\Http\Controllers\ArticleWpEditRedirectController;
use App\Addons\SeoContentAi\Http\Controllers\GlobalAiChatController;
use App\Addons\SeoContentAi\Http\Controllers\SeoArticleRevisionController;
use App\Addons\SeoContentAi\Http\Controllers\SeoMediaController;
use App\Addons\SeoContentAi\Http\Controllers\SeoPanelRedirectController;
use App\Addons\SeoContentAi\Http\Controllers\SeoWatermarkController;
use App\Addons\SeoContentAi\Http\Controllers\TeamMessageController;
use App\Addons\SeoContentAi\Http\Controllers\WorkspaceMediaPickerController;
use App\Addons\SeoContentAi\Http\Middleware\CheckMainRole;
use App\Addons\SeoContentAi\Http\Middleware\SetDynamicSeoDatabase;
use App\Addons\SeoContentAi\Services\PromptMediaStorageService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use App\Http\Middleware\SetDynamicSeoDatabaseByHash;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Middleware\Authenticate as IlluminateAuthenticate;
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

        Route::pattern('connection_hash', '[a-zA-Z0-9]{32,64}');

        Route::middleware(['web'])
            ->get('/seo', SeoPanelRedirectController::class)
            ->name('seo.panel.redirect');

        Filament::serving(function (): void {
            if (filament()->getCurrentPanel()?->getId() !== 'seo') {
                return;
            }

            $hash = SeoConnectionContext::hash();
            if ($hash !== null) {
                try {
                    app(SeoDatabaseConnectionService::class)->bootstrapByHash($hash);
                } catch (\RuntimeException) {
                    $siteId = SeoAccessControl::globalSiteId();
                    if ($siteId !== null && $siteId > 0) {
                        app(SeoDatabaseConnectionService::class)->bootstrapBySiteId($siteId);
                    }
                }
            } elseif (($siteId = SeoAccessControl::globalSiteId()) !== null && $siteId > 0) {
                app(SeoDatabaseConnectionService::class)->bootstrapBySiteId($siteId);
            }

            $routeHash = request()->route('connection_hash');
            if (is_string($routeHash) && SeoConnectionContext::isValidHashFormat($routeHash)) {
                SeoConnectionContext::applyUrlDefaults($routeHash);

                return;
            }

            SeoConnectionContext::applyUrlDefaults();
        });

        FilamentView::registerRenderHook(
            'panels::global-search.after',
            function (): string {
                if (! request()->is('seo', 'seo/*')) {
                    return '';
                }

                if (! SeoAccessControl::shouldShowGlobalSeoBar()) {
                    return '';
                }

                if (request()->routeIs([
                    'filament.seo.resources.keywords.index',
                    'filament.seo.resources.keywords.focus',
                    'filament.seo.resources.keywords.anchor-audit',
                ])) {
                    return '';
                }

                return Blade::render('@livewire(\'global-seo-bar\')');
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): HtmlString {
                if (! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.prompt-variable-insert')->render()
                );
            },
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
                    || request()->routeIs([
                        'filament.seo.resources.keywords.index',
                        'filament.seo.resources.keywords.focus',
                        'filament.seo.resources.keywords.anchor-audit',
                    ])
                ) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::components.workspace-media-picker')->render()
                    .view('seo-content-ai::components.global-ai-chat')->render(),
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_FOOTER,
            function (): HtmlString {
                if (filament()->getCurrentPanel()?->getId() !== 'seo') {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.hooks.seo-sidebar-footer')->render()
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            function (): HtmlString {
                if (! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    '<script>'
                    .'window.__SEO_I18N_LOCALE__ = '.json_encode(app()->getLocale()).';'
                    .'window.__SEO_CONNECTION_HASH__ = '.json_encode(SeoConnectionContext::hash()).';'
                    .'document.documentElement.setAttribute("lang", '.json_encode(str_replace('_', '-', app()->getLocale())).');'
                    .'</script>'
                );
            },
        );

        Route::middleware('api')
            ->prefix('api')
            ->group(dirname(__DIR__).'/routes/api.php');

        $seoWebApiMiddleware = [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            IlluminateAuthenticate::class,
            CheckMainRole::class,
            SetDynamicSeoDatabase::class,
            SubstituteBindings::class,
        ];

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/seo/media')
            ->group(function (): void {
                Route::get('/workspace-picker', WorkspaceMediaPickerController::class)
                    ->name('seo.media.workspace-picker');
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
                Route::post('/update-meta', [SeoMediaController::class, 'updateMeta'])
                    ->name('seo.media.update-meta');
                Route::post('/{media}/save-edited', [SeoMediaController::class, 'saveEditedImage'])
                    ->whereNumber('media')
                    ->name('seo.media.save-edited');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/seo/articles')
            ->group(function (): void {
                Route::get('/{article}/outline', [ArticleOutlineController::class, 'index'])
                    ->whereNumber('article')
                    ->name('seo.articles.outline.index');
                Route::post('/{article}/outline', [ArticleOutlineController::class, 'store'])
                    ->whereNumber('article')
                    ->name('seo.articles.outline.store');
                Route::post('/{article}/outline/check-duplicates', [ArticleOutlineController::class, 'checkDuplicates'])
                    ->whereNumber('article')
                    ->name('seo.articles.outline.check-duplicates');
                Route::post('/{article}/outline/refresh', [ArticleOutlineController::class, 'refresh'])
                    ->whereNumber('article')
                    ->name('seo.articles.outline.refresh');
                Route::put('/{article}/outline/{heading}', [ArticleOutlineController::class, 'update'])
                    ->whereNumber('article')
                    ->whereNumber('heading')
                    ->name('seo.articles.outline.update');
                Route::delete('/{article}/outline/{heading}', [ArticleOutlineController::class, 'destroy'])
                    ->whereNumber('article')
                    ->whereNumber('heading')
                    ->name('seo.articles.outline.destroy');
                Route::post('/{article}/outline/{heading}/generate', [ArticleOutlineController::class, 'generate'])
                    ->whereNumber('article')
                    ->whereNumber('heading')
                    ->name('seo.articles.outline.generate');
                Route::get('/{article}/revisions', [ArticleRevisionController::class, 'index'])
                    ->whereNumber('article')
                    ->name('seo.articles.revisions.index');
                Route::get('/{article}/revisions/{revision}', [SeoArticleRevisionController::class, 'show'])
                    ->whereNumber('article')
                    ->whereNumber('revision')
                    ->name('seo.articles.revisions.show');
            });

        Route::middleware($seoWebApiMiddleware)
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

        $seoTeamApiMiddleware = [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            IlluminateAuthenticate::class,
            SetDynamicSeoDatabase::class,
            SubstituteBindings::class,
        ];

        Route::middleware($seoTeamApiMiddleware)
            ->prefix('api/seo/team')
            ->group(function (): void {
                Route::get('/messages', [TeamMessageController::class, 'index'])
                    ->name('seo.team-messages.index');
                Route::get('/config', [TeamMessageController::class, 'config'])
                    ->name('seo.team-messages.config');
                Route::post('/messages', [TeamMessageController::class, 'store'])
                    ->name('seo.team-messages.store');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/ai')
            ->group(function (): void {
                Route::get('/chat/models', [GlobalAiChatController::class, 'models'])
                    ->name('seo.global-ai-chat.models');
                Route::post('/chat', [GlobalAiChatController::class, 'store'])
                    ->name('seo.global-ai-chat.store');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('seo/{connection_hash}')
            ->where(['connection_hash' => '[a-zA-Z0-9]{32,64}'])
            ->group(function (): void {
                Route::get('/articles/wp-edit-redirect', ArticleWpEditRedirectController::class)
                    ->name('seo.articles.wp-edit-redirect');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('seo')
            ->group(function (): void {
                Route::get('/articles/{article}/media-picker', ArticleMediaPickerController::class)
                    ->whereNumber('article')
                    ->name('seo.articles.media-picker');
                Route::get('/articles/{article}/seo-preview', ArticleSeoPreviewController::class)
                    ->name('seo.articles.seo-preview');
                Route::get('/articles/{article}/preview', ArticlePreviewController::class)
                    ->name('seo.articles.preview');
                Route::get('/articles/{article}/revisions', [SeoArticleRevisionController::class, 'compare'])
                    ->whereNumber('article')
                    ->name('seo.articles.revisions.compare');
                Route::post('/articles/{article}/revisions/restore', [SeoArticleRevisionController::class, 'restore'])
                    ->whereNumber('article')
                    ->name('seo.articles.revisions.restore');
                Route::get('/articles/wp-edit-redirect', ArticleWpEditRedirectController::class)
                    ->name('seo.articles.wp-edit-redirect.legacy');
            });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('seo')
            ->path('seo/{connection_hash}')
            ->login(\App\Addons\SeoContentAi\Filament\Pages\Auth\SeoLogin::class)
            ->profile(SeoEditProfile::class, isSimple: false)
            ->userMenuItems([
                'profile' => MenuItem::make()
                    ->label(fn (): string => filament()->getUserName(Filament::auth()->user()))
                    ->url(fn (): string => SeoEditProfile::getUrl())
                    ->icon('heroicon-m-user-circle'),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16rem')
            ->collapsedSidebarWidth('4rem')
            ->maxContentWidth(MaxWidth::Full)
            ->discoverResources(
                in: __DIR__.'/../Filament/Resources',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Resources'
            )
            ->discoverPages(
                in: __DIR__.'/../Filament/Pages',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Pages'
            )
            ->pages([
                SeoChangePassword::class,
            ])
            ->discoverWidgets(
                in: __DIR__.'/../Filament/Widgets',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Widgets'
            )
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                SetDynamicSeoDatabaseByHash::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetDynamicSeoDatabase::class,
            ])
            ->authMiddleware([
                \Filament\Http\Middleware\Authenticate::class,
                CheckMainRole::class,
            ]);
    }
}
