<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Addon\AddonDependencyValidator;
use App\Core\Addon\AddonDiscovery;
use App\Core\Addon\AddonEntitlementGate;
use App\Core\Addon\AddonRegistry;
use App\Core\Addon\AddonUiRegistry;
use App\Core\Api\AddonApiRegistry;
use App\Core\Automation\ActionRegistry;
use App\Core\Automation\ConditionEngine;
use App\Core\Automation\TriggerRegistry;
use App\Core\Capability\CapabilityRegistry;
use App\Core\Command\CommandBus;
use App\Core\Event\EventBus;
use App\Core\Operations\OperationLogger;
use App\Core\Queue\ScheduleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Client Core — protocol/runtime only. No SEO/WP/Publishing business logic.
 */
final class ClientCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/addons.php', 'addons');
        $this->mergeConfigFrom(__DIR__.'/../../config/addon_migration_ownership.php', 'addon_migration_ownership');

        $this->app->singleton(AddonDiscovery::class);
        $this->app->singleton(AddonRegistry::class);
        $this->app->singleton(AddonUiRegistry::class);
        $this->app->singleton(CapabilityRegistry::class);
        $this->app->singleton(AddonDependencyValidator::class);
        $this->app->singleton(CommandBus::class);
        $this->app->singleton(EventBus::class);
        $this->app->singleton(AddonApiRegistry::class);
        $this->app->singleton(TriggerRegistry::class);
        $this->app->singleton(ActionRegistry::class);
        $this->app->singleton(ConditionEngine::class);
        $this->app->singleton(ScheduleRegistry::class);
        $this->app->singleton(OperationLogger::class);
        $this->app->singleton(AddonEntitlementGate::class);
        $this->app->singleton(\App\Core\Database\AddonMigrationRegistrar::class);
        $this->app->singleton(\App\Core\Database\DestructiveMigrationGuard::class);
        $this->app->singleton(\App\Core\Database\RefactorMigrationRunner::class);
        $this->app->singleton(\App\Core\Database\LegacySchemaMigrationReconciler::class);
        $this->app->singleton(\App\Core\Database\LegacyTolerantMigrationRunner::class);
        $this->app->singleton(\App\Core\Database\SeoCliConnectionFixer::class);
    }

    public function boot(): void
    {
        $this->loadOwnedMigrations();
    }

    private function loadOwnedMigrations(): void
    {
        /** @var \App\Core\Database\AddonMigrationRegistrar $registrar */
        $registrar = $this->app->make(\App\Core\Database\AddonMigrationRegistrar::class);
        foreach ($registrar->migrationPaths() as $path) {
            // Skip default database/migrations — Laravel already loads it.
            if (realpath($path) === realpath(database_path('migrations'))) {
                continue;
            }
            $this->loadMigrationsFrom($path);
        }
    }
}
