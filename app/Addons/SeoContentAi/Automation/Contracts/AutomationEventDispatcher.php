<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Contracts;

use App\Addons\SeoContentAi\Automation\Data\EventEnvelope;

interface AutomationEventDispatcher
{
    public function dispatch(EventEnvelope $event): void;
}
