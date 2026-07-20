<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Enums;

enum AutomationWorkflowMode: string
{
    case Linear = 'linear';
    case Graph = 'graph';
}
