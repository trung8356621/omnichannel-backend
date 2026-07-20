<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Enums;

enum AutomationQueueName: string
{
    case Automation = 'automation';
    case External = 'automation-external';
    case Critical = 'automation-critical';
}
