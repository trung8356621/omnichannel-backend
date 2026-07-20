<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Platform;

use App\Addons\SeoContentAi\Automation\BusinessHook\Registry\AutomationActionRegistry;
use App\Addons\SeoContentAi\Automation\BusinessHook\Registry\BusinessEventRegistry;
use App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationConditionRegistry;
use App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationHealthCheckRegistry;
use App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationMenuRegistry;
use App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationPermissionRegistry;
use App\Addons\SeoContentAi\Automation\Platform\Registry\AutomationSettingsRegistry;
use Illuminate\Contracts\Container\Container;

final class AutomationModuleContext
{
    public function __construct(
        public readonly BusinessEventRegistry $events,
        public readonly AutomationActionRegistry $actions,
        public readonly AutomationConditionRegistry $conditions,
        public readonly AutomationHealthCheckRegistry $healthChecks,
        public readonly AutomationMenuRegistry $menus,
        public readonly AutomationPermissionRegistry $permissions,
        public readonly AutomationSettingsRegistry $settings,
        public readonly Container $container,
    ) {}

    public static function create(Container $container): self
    {
        return new self(
            events: new BusinessEventRegistry,
            actions: new AutomationActionRegistry($container),
            conditions: $container->make(AutomationConditionRegistry::class),
            healthChecks: $container->make(AutomationHealthCheckRegistry::class),
            menus: $container->make(AutomationMenuRegistry::class),
            permissions: $container->make(AutomationPermissionRegistry::class),
            settings: $container->make(AutomationSettingsRegistry::class),
            container: $container,
        );
    }
}
