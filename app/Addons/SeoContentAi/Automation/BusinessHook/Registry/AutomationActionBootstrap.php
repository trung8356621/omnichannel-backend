<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Registry;

use App\Addons\SeoContentAi\Automation\Platform\AutomationModuleContext;
use App\Addons\SeoContentAi\Automation\Platform\AutomationModuleRegistry;
use Illuminate\Contracts\Container\Container;

/**
 * @deprecated Use AutomationModuleRegistry via AutomationPlatformKernel.
 */
final class AutomationActionBootstrap
{
    public function register(AutomationActionRegistry $registry, ?Container $container = null): void
    {
        $container ??= app();
        $context = new AutomationModuleContext(
            events: new BusinessEventRegistry,
            actions: $registry,
            conditions: new \App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationConditionRegistry,
            healthChecks: new \App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationHealthCheckRegistry,
            menus: new \App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationMenuRegistry,
            permissions: new \App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationPermissionRegistry,
            settings: new \App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationSettingsRegistry,
            container: $container,
        );

        AutomationModuleRegistry::fromConfig($container)->boot($context);
    }
}
