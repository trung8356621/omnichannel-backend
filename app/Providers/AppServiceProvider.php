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
    public function register(): void
    {
        $this->registerInterventionImageManager();

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
            logger()->warning('Không có extension imagick/gd — xử lý ảnh sẽ thất bại khi resize/upload.');

            return;
        }

        $requested = env('IMAGE_DRIVER');
        if (
            is_string($requested)
            && strtolower(trim($requested)) === ImageDriverResolver::DRIVER_IMAGICK
            && ! ImageDriverResolver::supportsImagick()
            && ImageDriverResolver::supportsGd()
        ) {
            logger()->info('IMAGE_DRIVER=imagick nhưng host không có imagick — tự fallback sang GD.');
        }
    }

    private function registerActiveAddonProviders(): void
    {
        try {
            if (! Schema::hasTable('services')) {
                return;
            }

            $activeServices = \App\Models\Service::where('is_active', true)->get();

            foreach ($activeServices as $service) {
                if (in_array((string) $service->slug, config('addons.skip_slugs', []), true)) {
                    continue;
                }

                if (class_exists($service->addon_namespace)) {
                    $this->app->register($service->addon_namespace);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
