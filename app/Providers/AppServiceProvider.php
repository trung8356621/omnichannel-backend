<?php

namespace App\Providers;

use App\Support\ImageDriverResolver;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;
use Intervention\Image\Laravel\Facades\Image as InterventionImage;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    /** @var array<string, true> */
    private array $registeredAddonProviders = [];

    public function register(): void
    {
        $this->registerInterventionImageManager();

        // Panel providers need early registration (Filament). Discover from filesystem
        // manifests marked register_early — Core never hard-codes business addon classes.
        $this->registerEarlyAddonProviders();

        // Active addons are stored in the database, so resolve them only after
        // Eloquent has received its connection resolver from the database provider.
        $this->app->booted(fn () => $this->registerActiveAddonProviders());
    }

    private function registerInterventionImageManager(): void
    {
        $this->app->singleton(InterventionImage::BINDING, function (): ImageManager {
            return new ImageManager(
                driver: ImageDriverResolver::interventionDriverClass(),
                autoOrientation: (bool) config('image.options.autoOrientation', true),
                decodeAnimation: (bool) config('image.options.decodeAnimation', true),
                backgroundColor: (string) config('image.options.backgroundColor', 'ffffff'),
                strip: (bool) config('image.options.strip', false),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerFallbackTestCommand();

        // HTTP/PHP-FPM → web_app only. Cron/queue giữ LOG_CHANNEL (laravel.log).
        // Phải set TRƯỚC mọi logger()/Log:: — tránh Permission denied trên laravel.log root-owned.
        // Skip when channel missing (stale config:cache) — else LogManager EMERGENCY spam.
        if (! $this->app->runningInConsole() && is_array(config('logging.channels.web_app'))) {
            config(['logging.default' => 'web_app']);
        }

        $this->logImageDriverSelection();

        // Nếu đang ở môi trường local, tắt kiểm tra SSL cho mọi request outbound
        if (app()->environment('local')) {
            Http::globalOptions([
                'verify' => false,
            ]);
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // Cấu hình language switch BezhanSalleh
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['vi', 'en']) // Danh sách ngôn ngữ hỗ trợ
                ->labels([
                    'vi' => 'Tiếng Việt',
                    'en' => 'English',
                ]);
        });

    }

    private function logImageDriverSelection(): void
    {
        if (! ImageDriverResolver::hasAnyDriver()) {
            \App\Support\RuntimeLogger::warning(
                'Không có extension imagick/gd — xử lý ảnh sẽ thất bại khi resize/upload.',
            );

            return;
        }

        $requested = env('IMAGE_DRIVER');
        if (
            is_string($requested)
            && strtolower(trim($requested)) === ImageDriverResolver::DRIVER_IMAGICK
            && ! ImageDriverResolver::supportsImagick()
            && ImageDriverResolver::supportsGd()
        ) {
            \App\Support\RuntimeLogger::info(
                'IMAGE_DRIVER=imagick nhưng host không có imagick — tự fallback sang GD.',
            );
        }
    }

    private function registerFallbackTestCommand(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            \Omnichannel\Addons\ContentProjects\Console\RepairLegacyContentProjectGenerationCommand::class,
        ]);

        // Collision owns `php artisan test` when require-dev is installed.
        if (class_exists(\NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand::class)) {
            return;
        }

        $this->commands([
            \App\Console\Commands\FallbackPhpUnitCommand::class,
        ]);
    }

    private function registerEarlyAddonProviders(): void
    {
        try {
            /** @var \App\Core\Addon\AddonDiscovery $discovery */
            $discovery = $this->app->make(\App\Core\Addon\AddonDiscovery::class);
            /** @var \App\Core\Addon\AddonRegistry $registry */
            $registry = $this->app->make(\App\Core\Addon\AddonRegistry::class);

            $roots = config('addons.discovery_roots', ['app/Addons', 'addons']);
            $skip = config('addons.skip_slugs', []);
            $manifests = $discovery->discover($roots, $skip);
            $registry->replaceAll($manifests);

            foreach ($manifests as $manifest) {
                if (! $manifest->registerEarly) {
                    continue;
                }

                // Early = Filament panels / route providers only. Main business provider stays DB-gated.
                if ($manifest->panelProvider !== null) {
                    $this->registerAddonProviderClass($manifest->panelProvider);
                }
                foreach ($manifest->extraProviders as $providerClass) {
                    $this->registerAddonProviderClass($providerClass);
                }
            }
        } catch (\Throwable $e) {
            \App\Support\RuntimeLogger::report($e);
        }
    }

    private function registerActiveAddonProviders(): void
    {
        try {
            if (! Schema::hasTable('services')) {
                return;
            }

            $activeServices = \App\Models\Service::where('is_active', true)->get();
            /** @var \App\Core\Addon\AddonRegistry $registry */
            $registry = $this->app->make(\App\Core\Addon\AddonRegistry::class);

            foreach ($activeServices as $service) {
                $slug = (string) $service->slug;
                if (in_array($slug, config('addons.skip_slugs', []), true)) {
                    continue;
                }

                $manifest = $registry->get($slug);
                if ($manifest !== null) {
                    foreach ($manifest->allProviderClasses() as $providerClass) {
                        $this->registerAddonProviderClass($providerClass);
                    }
                    $registry->markEnabled($slug);

                    continue;
                }

                // Legacy Service row without filesystem peer manifest.
                if (is_string($service->addon_namespace) && $service->addon_namespace !== '') {
                    $this->registerAddonProviderClass($service->addon_namespace);
                    $registry->markEnabled($slug);
                }
            }
        } catch (\Throwable $e) {
            \App\Support\RuntimeLogger::report($e);
        }
    }

    private function registerAddonProviderClass(string $providerClass): void
    {
        if (isset($this->registeredAddonProviders[$providerClass])) {
            return;
        }

        if (! class_exists($providerClass)) {
            return;
        }

        $this->app->register($providerClass);
        $this->registeredAddonProviders[$providerClass] = true;
    }
}
