<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi;

use App\Addons\SeoContentAi\Console\BackfillPromptResultLinksCommand;
use App\Addons\SeoContentAi\Console\CleanCtaKeywordsCommand;
use App\Addons\SeoContentAi\Console\ExtractOldArticleTocsCommand;
use App\Addons\SeoContentAi\Console\PublishScheduledArticlesCommand;
use App\Addons\SeoContentAi\Http\Middleware\SetDynamicSeoDatabase;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Observers\SeoArticleObserver;
use App\Addons\SeoContentAi\Observers\SeoProjectObserver;
use App\Addons\SeoContentAi\Services\PromptMediaStorageService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Services\TeamChatAttachmentService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class SeoContentAiServiceProvider extends ServiceProvider
{
    public const DB_CONNECTION = 'omi_seo_ai';

    private static bool $booted = false;

    public function register(): void
    {
        $this->app->singleton(PromptMediaStorageService::class);
        $this->app->singleton(SeoDatabaseConnectionService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Services\SeoDatabaseBackupService::class);
        $this->app->singleton(TeamChatAttachmentService::class);
    }

    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $this->loadViewsFrom(__DIR__.'/resources/views', 'seo-content-ai');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->bootstrapDefaultSeoConnection();

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
                PublishScheduledArticlesCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            /** @var Router $router */
            $router = $this->app->make(Router::class);
            $router->pushMiddlewareToGroup('web', SetDynamicSeoDatabase::class);

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

            $publishScheduledName = 'seo-content-ai:publish-scheduled-articles';
            $publishScheduledRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $publishScheduledName);
            if (! $publishScheduledRegistered) {
                $schedule
                    ->command(PublishScheduledArticlesCommand::class)
                    ->everyMinute()
                    ->name($publishScheduledName)
                    ->withoutOverlapping();
            }
        });
    }

    private function bootstrapDefaultSeoConnection(): void
    {
        try {
            app(SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
