<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi;

use App\Addons\RegistersAddonDatabase;
use App\Addons\SeoContentAi\Console\BackfillPromptResultLinksCommand;
use App\Addons\SeoContentAi\Console\CleanCtaKeywordsCommand;
use App\Addons\SeoContentAi\Console\ExtractOldArticleTocsCommand;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Observers\SeoArticleObserver;
use App\Addons\SeoContentAi\Observers\SeoProjectObserver;
use App\Addons\SeoContentAi\Services\PromptMediaStorageService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\ServiceProvider;

class SeoContentAiServiceProvider extends ServiceProvider
{
    use RegistersAddonDatabase;

    public const DB_CONNECTION = 'omi_seo_ai';

    private static bool $booted = false;

    public function register(): void
    {
        // Shared persistTarget for usingTargetMedia() across PromptRunner / GeminiMediaGenerationService.
        $this->app->singleton(PromptMediaStorageService::class);
    }

    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $this->loadViewsFrom(__DIR__.'/resources/views', 'seo-content-ai');
        $this->registerAddonDatabase(__DIR__, self::DB_CONNECTION, __DIR__.'/database/migrations');

        \App\Addons\SeoContentAi\Models\Keyword::observe(
            \App\Addons\SeoContentAi\Observers\KeywordLinkListSyncObserver::class,
        );
        SeoProject::observe(SeoProjectObserver::class);
        SeoArticle::observe(SeoArticleObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillPromptResultLinksCommand::class,
                CleanCtaKeywordsCommand::class,
                ExtractOldArticleTocsCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            $schedule = app(Schedule::class);
            $name = 'seo-content-ai:cleanup-old-notifications';
            $alreadyRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $name);
            if ($alreadyRegistered) {
                return;
            }

            $schedule
                ->call(static fn (): int => DatabaseNotification::query()
                    ->where('created_at', '<', now()->startOfMonth())
                    ->delete())
                ->monthlyOn(1, '00:10')
                ->name($name)
                ->withoutOverlapping();
        });
    }
}
