<?php

namespace App\Providers;

use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

        //Cấu hình Addons
        // Chỉ chạy nếu đang không trong môi trường Console (để tránh lỗi khi chạy migrate/seed)
        // Hoặc kiểm tra bảng trực tiếp
        try {
            if (Schema::hasTable('services')) {
                $activeServices = \App\Models\Service::where('is_active', true)->get();

                foreach ($activeServices as $service) {
                    if (class_exists($service->addon_namespace)) {
                        $this->app->register($service->addon_namespace);
                    }
                }
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
            // Bỏ qua lỗi nếu DB chưa sẵn sàng
        }
    }
}
