<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Enums;

enum AutomationRunMode: string
{
    case Queued = 'queued';
    case Sync = 'sync';
}
