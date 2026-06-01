<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi;

use App\Addons\RegistersAddonDatabase;
use App\Addons\SeoContentAi\Services\PromptMediaStorageService;
use Illuminate\Support\ServiceProvider;

class SeoContentAiServiceProvider extends ServiceProvider
{
    use RegistersAddonDatabase;

    public const DB_CONNECTION = 'omi_seo_ai';

    public function register(): void
    {
        // Shared persistTarget for usingTargetMedia() across PromptRunner / GeminiMediaGenerationService.
        $this->app->singleton(PromptMediaStorageService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'seo-content-ai');
        $this->registerAddonDatabase(__DIR__, self::DB_CONNECTION, __DIR__ . '/database/migrations');

        \App\Addons\SeoContentAi\Models\Keyword::observe(
            \App\Addons\SeoContentAi\Observers\KeywordLinkListSyncObserver::class,
        );
    }
}