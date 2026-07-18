<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Enums;

enum ActionRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
