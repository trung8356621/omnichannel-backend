<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Events;

use App\Addons\SeoContentAi\Automation\Contracts\AutomationEventDispatcher;
use App\Addons\SeoContentAi\Automation\Data\EventEnvelope;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2: log-only dispatcher. Không migrate event bus cũ.
 */
final class LoggingAutomationEventDispatcher implements AutomationEventDispatcher
{
    public function dispatch(EventEnvelope $event): void
    {
        Log::info('automation.event', $event->toArray());
    }
}
