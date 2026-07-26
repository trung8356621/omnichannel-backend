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
use App\Contracts\DeclaresDatabaseTableOwnership;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class SeoContentAiServiceProvider extends ServiceProvider implements DeclaresDatabaseTableOwnership
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
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookDefinitionLoader::class, function () {
            return new \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookDefinitionLoader(
                \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookDefinitionLoader::defaultV01Directory(),
                \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookDefinitionLoader::defaultPhase1Directory(),
            );
        });
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookRuntimeRegistry::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookMigrationFlags::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptBudgetStore::class, \App\Addons\SeoContentAi\PromptHooks\Runtime\InMemoryPromptBudgetStore::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookBudgetGuard::class, function ($app): \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookBudgetGuard {
            return new \App\Addons\SeoContentAi\PromptHooks\Runtime\InMemoryPromptHookBudgetGuard(
                $app->make(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptBudgetStore::class),
            );
        });
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Provider\PromptCostEstimator::class, \App\Addons\SeoContentAi\PromptHooks\Provider\ConfigPromptCostEstimator::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Provider\PromptProviderUsageNormalizer::class);
        $this->app->singleton(
            \App\Addons\SeoContentAi\PromptHooks\Provider\PromptProviderAdapter::class,
            \App\Addons\SeoContentAi\PromptHooks\Provider\PromptRunnerProviderAdapter::class,
        );
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Provider\PromptProviderCapabilityResolver::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Output\PromptHookRuntimeOutputPipeline::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookAuditRecorder::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookEnvelopeValidator::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookRuntimeLocaleResolver::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookShadowParityRecorder::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookLiveShadowGate::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookPromotionThresholds::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookModeTransitionPolicy::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookRollbackPolicy::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookPromotionGate::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookRuntimeEngine::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookCallerBridge::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookEditorCatalog::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookExplicitBindingExecutor::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookUiFailureMapper::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Contracts\PromptResultAttacher::class, \App\Addons\SeoContentAi\Services\PromptResultAttachService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Services\PromptResultAttachService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\PromptHooks\PromptHookExecutionService::class);

        $this->app->singleton(\App\Addons\SeoContentAi\Support\RunEngine\ContentProjectRunStatusMapper::class);
        $this->app->singleton(
            \App\Addons\SeoContentAi\Services\RunEngine\ContentProjectRunEventPublisher::class,
            \App\Addons\SeoContentAi\Services\RunEngine\LoggingContentProjectRunEventPublisher::class,
        );
        $this->app->singleton(\App\Addons\SeoContentAi\Services\RunEngine\RunCancellationGuard::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Services\RunEngine\ContentProjectTaskExecutionService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Services\RunEngine\ContentProjectArticleRunner::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Services\RunEngine\ContentProjectRunEngine::class);

        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Contracts\AutomationEventDispatcher::class, \App\Addons\SeoContentAi\Automation\BusinessHook\Events\BridgingAutomationEventDispatcher::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Support\SensitivePayloadRedactor::class);

        // Business Hook / Automation Rule Engine
        $this->mergeConfigFrom(__DIR__.'/config/automation-modules.php', 'seo-content-ai.automation_modules');
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationInputMapper::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationConditionRegistry::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationHealthCheckRegistry::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationMenuRegistry::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationPermissionRegistry::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationSettingsRegistry::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Platform\AutomationModuleRegistry::class, function ($app): \App\Addons\SeoContentAi\Automation\Platform\AutomationModuleRegistry {
            return \App\Addons\SeoContentAi\Automation\Platform\AutomationModuleRegistry::fromConfig($app);
        });
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Platform\AutomationPlatformKernel::class, function ($app): \App\Addons\SeoContentAi\Automation\Platform\AutomationPlatformKernel {
            return \App\Addons\SeoContentAi\Automation\Platform\AutomationPlatformKernel::bootOnce($app);
        });
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Registry\BusinessEventRegistry::class, function ($app): \App\Addons\SeoContentAi\Automation\BusinessHook\Registry\BusinessEventRegistry {
            return $app->make(\App\Addons\SeoContentAi\Automation\Platform\AutomationPlatformKernel::class)->context->events;
        });
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Registry\AutomationActionRegistry::class, function ($app): \App\Addons\SeoContentAi\Automation\BusinessHook\Registry\AutomationActionRegistry {
            return $app->make(\App\Addons\SeoContentAi\Automation\Platform\AutomationPlatformKernel::class)->context->actions;
        });
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationConditionEngine::class, function ($app): \App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationConditionEngine {
            return new \App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationConditionEngine(
                $app->make(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationInputMapper::class),
                $app->make(\App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationConditionRegistry::class),
            );
        });
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Registry\BusinessEventBootstrap::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Registry\AutomationActionBootstrap::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationLoopGuard::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationSnapshotSanitizer::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationSnapshotRedactor::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationSubjectLoader::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Services\WordPress\SideEffect\WordPressSideEffectGuard::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Services\WordPress\SideEffect\WordPressSideEffectLedger::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Services\WordPress\SideEffect\WordPressGateway::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService::class);

        config([
            'logging.channels.wordpress-side-effect' => [
                'driver' => 'single',
                'path' => storage_path('logs/wordpress-side-effect.log'),
                'level' => 'info',
                'replace_placeholders' => true,
            ],
        ]);

        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationRuleMatcher::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationExecutionService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationSettingsService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\ExecutionCleanupService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\BusinessEventDispatcher::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationRuleService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationGraphValidator::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationGraphEdgeResolver::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationConcurrencyGuard::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationRateLimitGuard::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\LinearRuleGraphAdapter::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationGraphRuleService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationGraphExecutionService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationVersionService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationWorkflowTestService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationImportExportService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationHealthService::class, function ($app): \App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationHealthService {
            return new \App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationHealthService(
                $app->make(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationSchedulerHeartbeatService::class),
                $app->make(\App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationHealthCheckRegistry::class),
            );
        });
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationSchedulerHeartbeatService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationRuleVersionMigrationService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationSchedulerService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationStaleRecoveryService::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\BusinessHook\Seed\AutomationDefaultRulesSeeder::class);
        $this->app->singleton(\App\Addons\SeoContentAi\Automation\Runtime\ActionExecutionLogger::class);
        $this->app->bind(
            \App\Addons\SeoContentAi\Automation\Contracts\ActionExecutionLoggerContract::class,
            \App\Addons\SeoContentAi\Automation\Runtime\ActionExecutionLogger::class,
        );
        $this->app->bind(
            \App\Addons\SeoContentAi\Contracts\SeoCreateArticleSettingsReader::class,
            \App\Addons\SeoContentAi\Services\SeoCreateArticleSettingsService::class,
        );
        $this->app->bind(
            \App\Addons\SeoContentAi\Contracts\ResolvesSettingsPromptHook::class,
            \App\Addons\SeoContentAi\Services\PromptOwnership\PromptBindingResolver::class,
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
        $this->app->singleton(
            \App\Addons\SeoContentAi\Automation\Contracts\BusinessActionDispatcher::class,
            \App\Addons\SeoContentAi\Automation\Runtime\CatalogBusinessActionDispatcher::class,
        );
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

        // Đăng ký console ở register() — không phụ thuộc $booted guard trong boot().
        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillPromptResultLinksCommand::class,
                CleanCtaKeywordsCommand::class,
                ExtractOldArticleTocsCommand::class,
                PublishScheduledArticlesCommand::class,
                \App\Addons\SeoContentAi\Console\ClearPromptHookDefinitionCacheCommand::class,
                \App\Addons\SeoContentAi\Console\PromptHookStatusCommand::class,
                \App\Addons\SeoContentAi\Console\PromptHookParityReportCommand::class,
                \App\Addons\SeoContentAi\Console\BackfillContentProjectRunItemsCommand::class,
                \App\Addons\SeoContentAi\Console\DiagnoseContentProjectArchiveCommand::class,
                \App\Addons\SeoContentAi\Console\DiagnoseContentProjectSyncCommand::class,
                \App\Addons\SeoContentAi\Console\DiagnoseContentProjectCommand::class,
                \App\Addons\SeoContentAi\Console\ContentProjectRunStatusCommand::class,
                \App\Addons\SeoContentAi\Console\ContentProjectRunRecoverCommand::class,
                \App\Addons\SeoContentAi\Console\RepairContentProjectCommand::class,
                \App\Addons\SeoContentAi\Console\RepairContentProjectMonthDriftCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationListEventsCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationListActionsCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationDispatchCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationRunRuleCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationRetryCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationDiagnoseCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationAuditWordpressCouplingCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationAuditCouplingCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationAuditDirectBusinessActionsCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationAuditEntryPointsCommand::class,
                \App\Addons\SeoContentAi\Console\QueueInspectWordpressCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationSeedRulesCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationDisableAllRulesCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationRepairWordpressExecutionsCommand::class,
                \App\Addons\SeoContentAi\Console\NormalizeArticleInlineLinksCommand::class,
                \App\Addons\SeoContentAi\Console\ProductReviewsAuditWordpressStatusCommand::class,
                \App\Addons\SeoContentAi\Console\ProductReviewsQueuePendingCommand::class,
                \App\Addons\SeoContentAi\Console\ProductReviewsReconcilePendingCommand::class,
                \App\Addons\SeoContentAi\Console\ProductReviewsDiagnoseStuckCommand::class,
                \App\Addons\SeoContentAi\Console\ProductReviewsMigrateLegacyMetaCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationMigrateCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationDispatchScheduledCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationRecoverStaleCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationCleanupExecutionLogsCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationMigrateLinearToGraphCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationMigrateRuleVersionsCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationExportCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationImportCommand::class,
                \App\Addons\SeoContentAi\Console\AutomationHealthCommand::class,
                \App\Addons\SeoContentAi\Console\WordpressSyncLeaseWatchdogCommand::class,
                \App\Addons\SeoContentAi\Console\MigrateSeoArticleReviewsCommand::class,
                \App\Addons\SeoContentAi\Console\RepairArchivedArticleActiveTasksCommand::class,
                \App\Addons\SeoContentAi\Console\AssignWorkflowExecutionRolesCommand::class,
                \App\Addons\SeoContentAi\Console\WorkflowDoctorCommand::class,
                \App\Addons\SeoContentAi\Console\InstallDefaultImprovePromptCommand::class,
            ]);
        }
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

            $automationScheduleName = 'seo-content-ai:automation-dispatch-scheduled';
            $automationScheduleRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $automationScheduleName);
            if (! $automationScheduleRegistered) {
                $schedule
                    ->command(\App\Addons\SeoContentAi\Console\AutomationDispatchScheduledCommand::class)
                    ->everyMinute()
                    ->name($automationScheduleName)
                    ->withoutOverlapping();
            }

            $automationRecoverName = 'seo-content-ai:automation-recover-stale';
            $automationRecoverRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $automationRecoverName);
            if (! $automationRecoverRegistered) {
                $schedule
                    ->command(\App\Addons\SeoContentAi\Console\AutomationRecoverStaleCommand::class)
                    ->everyFiveMinutes()
                    ->name($automationRecoverName)
                    ->withoutOverlapping();
            }

            $automationCleanupName = 'seo-content-ai:automation-cleanup-execution-logs';
            $automationCleanupRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $automationCleanupName);
            if (! $automationCleanupRegistered) {
                $schedule
                    ->command(\App\Addons\SeoContentAi\Console\AutomationCleanupExecutionLogsCommand::class)
                    ->dailyAt('02:20')
                    ->name($automationCleanupName)
                    ->withoutOverlapping();
            }

            $wpSyncWatchdogName = 'seo-content-ai:wordpress-sync-lease-watchdog';
            $wpSyncWatchdogRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $wpSyncWatchdogName);
            if (! $wpSyncWatchdogRegistered) {
                $schedule
                    ->command(\App\Addons\SeoContentAi\Console\WordpressSyncLeaseWatchdogCommand::class)
                    ->everyMinute()
                    ->name($wpSyncWatchdogName)
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

    /**
     * Table thuộc connection `omi_seo_ai` (không gồm GSC/SERP credentials — những bảng đó ở core/mysql).
     *
     * @return array{connection: string, tables: list<string>, patterns: list<string>}
     */
    public function databaseTableOwnership(): array
    {
        return [
            'connection' => self::DB_CONNECTION,
            'tables' => [
                'articles',
                'article_keyword',
                'article_meta',
                'article_product_reviews',
                // automation_* + business_events: ownership core (config/database_table_ownership.php)
                'entities',
                'entity_results',
                'keyword_group_metric_snapshots',
                'keyword_link',
                'keyword_meta',
                'keyword_rank_check_runs',
                'keyword_rank_snapshots',
                'keyword_review_histories',
                'keyword_review_reasons',
                'keyword_site_meta',
                'keyword_tag',
                'keyword_tags',
                'keywords',
                'domain_global_cta_settings',
                'prompt_parts',
                'prompt_results',
                'prompts',
                'seo_article_headings',
                'seo_article_links',
                'seo_article_reviews',
                'seo_article_revisions',
                'seo_article_wp_sync_jobs',
                'seo_content_archive_items',
                'seo_domain_metas',
                'seo_faqs',
                'seo_generated_images',
                'seo_image_optimization_settings',
                'seo_link_audits',
                'seo_link_maps',
                'seo_links',
                'seo_media',
                'seo_media_meta',
                'seo_media_processing_histories',
                'seo_pending_internal_links',
                'seo_project_archive_items',
                'seo_project_archives',
                'seo_project_run_items',
                'seo_project_runs',
                'seo_project_task_events',
                'seo_project_tasks',
                'seo_projects',
                'seo_prompt_result_links',
                'seo_prompt_resultables',
                'seo_prompt_templates',
                'seo_rank_keyword_group_items',
                'seo_rank_keyword_groups',
                'seo_settings',
                'seo_tasks',
                'seo_watermark_settings',
                'seo_wp_media_backups',
                'seo_wp_media_edited_pending',
                'tags',
                'task_test_results',
                'user_workspace_settings',
                'wordpress_side_effect_attempts',
            ],
            'patterns' => [
                'seo_domain_*',
                'domain_global_*',
                'user_workspace_*',
            ],
        ];
    }
}
