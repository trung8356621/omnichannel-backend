<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi;

use App\Addons\RegistersAddonDatabase;
use Illuminate\Support\ServiceProvider;

class SeoContentAiServiceProvider extends ServiceProvider
{
    use RegistersAddonDatabase;

    public const DB_CONNECTION = 'omi_seo_ai';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'seo-content-ai');
        $this->registerAddonDatabase(__DIR__, self::DB_CONNECTION, __DIR__ . '/database/migrations');
    }
}