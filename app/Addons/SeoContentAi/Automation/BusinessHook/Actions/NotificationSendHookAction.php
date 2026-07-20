<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use Illuminate\Support\Facades\Log;

final class NotificationSendHookAction implements AutomationActionHandler
{
    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $message = trim((string) ($input['message'] ?? $settings['message'] ?? ''));
        if ($message === '') {
            $message = sprintf(
                'Automation [%s] for event [%s]',
                $context->rule->code,
                $context->businessEvent->event_name,
            );
        }

        Log::info('automation.notification', [
            'message' => $message,
            'site_id' => $context->siteId,
            'project_id' => $context->projectId,
            'actor_id' => $context->actorId,
            'correlation_id' => $context->correlationId,
            'event_uuid' => $context->businessEvent->event_uuid,
        ]);

        return AutomationActionResult::success(
            output: ['delivered' => true, 'channel' => 'log'],
            message: 'Notification logged.',
        );
    }
}
