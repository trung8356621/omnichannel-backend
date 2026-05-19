<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Providers;

use App\Addons\RegistersAddonDatabase;
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
