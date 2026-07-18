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

        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Entities\PromptHookEntityResolverRegistry::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\PromptHookManifestLoader::class, function (): \App\Addons\SeoContentAi\PromptHooks\PromptHookManifestLoader {
            $failFast = (bool) $this->app->environment(['local', 'testing']);

            return new \App\Addons\SeoContentAi\PromptHooks\PromptHookManifestLoader(
                \App\Addons\SeoContentAi\PromptHooks\PromptHookManifestLoader::defaultDirectory(),
                $failFast,
            );
        });
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\PromptHookRegistry::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\PromptHookExecutionService::class);

        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Contracts\AutomationEventDispatcher::class, \App\Addons\SeoContentAi\Automation\Events\LoggingAutomationEventDispatcher::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Support\SensitivePayloadRedactor::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Runtime\ActionExecutionLogger::class);
        $this->app->bind(
            \App\Addons\SeoContentAi\Automation\Contracts\ActionExecutionLoggerContract::class,
            \App\Addons\SeoContentAi\Automation\Runtime\ActionExecutionLogger::class,
        );
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Runtime\AutomationSiteContextResolver::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Registry\ActionCatalogBootstrap::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Registry\ActionHandlerRegistrar::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Registry\ActionRegistry::class, function ($app): \App\Addons\SeoContentAi\Automation\Registry\ActionRegistry {
            $registry = new \App\Addons\SeoContentAi\Automation\Registry\ActionRegistry($app);
            $app->make(\App\Addons\SeoContentAi\Automation\Registry\ActionCatalogBootstrap::class)->register($registry);
            $app->make(\App\Addons\SeoContentAi\Automation\Registry\ActionHandlerRegistrar::class)->register($registry);

            return $registry;
        });
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Runtime\ActionRunner::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\AutomationMigrationFlags::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\AutomationParitySampleRecorder::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\AutomationParityLogger::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\AutomationCallerMigrator::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\ParitySnapshotNormalizer::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\ArticleActionOutputNormalizer::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\AutomationActionPromotionGate::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\AssignmentCallerBridge::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\ProjectTaskCallerBridge::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\Planners\ArticleCreateParityPlanner::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\Planners\ArticleContentUpdateParityPlanner::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\Planners\ArticleSeoMetaUpdateParityPlanner::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\ProjectArticleCreateCallerBridge::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\ProjectArticleContentCallerBridge::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Migration\ProjectArticleSeoMetaCallerBridge::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Support\ArticleCreateOriginResolver::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Support\ArticleContentConflictGuard::class);
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
