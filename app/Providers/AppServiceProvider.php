<?php

namespace App\Providers;

use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Schema;
use Illuminate\Support\Facades\Http;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerActiveAddonProviders();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        // Nếu đang ở môi trường local, tắt kiểm tra SSL cho mọi request outbound
        if (app()->environment('local')) {
            Http::globalOptions([
                'verify' => false,
            ]);
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url') . "/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        //Cấu hình language switch BezhanSalleh
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['vi', 'en']) // Danh sách ngôn ngữ hỗ trợ
                ->labels([
                    'vi' => 'Tiếng Việt',
                    'en' => 'English',
                ]);
        });

    }

    private function registerActiveAddonProviders(): void
    {
        try {
            if (!Schema::hasTable('services')) {
                return;
            }

            $activeServices = \App\Models\Service::where('is_active', true)->get();

            foreach ($activeServices as $service) {
                if (class_exists($service->addon_namespace)) {
                    $this->app->register($service->addon_namespace);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
