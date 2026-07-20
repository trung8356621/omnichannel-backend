<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;

/**
 * Delay thực tế nằm ở delay_seconds của rule action / job delay.
 * Action này là no-op marker khi rule dùng action_code=delay.
 */
final class DelayHookAction implements AutomationActionHandler
{
    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $seconds = (int) ($settings['seconds'] ?? $input['seconds'] ?? 0);

        return AutomationActionResult::success(
            output: ['seconds' => max(0, $seconds)],
            message: 'Delay acknowledged (job-level delay handles wait).',
        );
    }
}
