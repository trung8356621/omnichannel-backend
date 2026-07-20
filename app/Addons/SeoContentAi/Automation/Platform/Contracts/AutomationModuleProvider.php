<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Platform\Contracts;

use App\Addons\SeoContentAi\Automation\Platform\AutomationModuleContext;

/**
 * Automation platform module — đăng ký events, actions, conditions, menu, permissions, health, settings.
 */
interface AutomationModuleProvider
{
    public function id(): string;

    public function register(AutomationModuleContext $context): void;
}
