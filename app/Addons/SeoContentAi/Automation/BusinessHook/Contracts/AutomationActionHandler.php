<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Contracts;

use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;

interface AutomationActionHandler
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $settings
     */
    public function handle(
        AutomationActionContext $context,
        array $input,
        array $settings,
    ): AutomationActionResult;
}
