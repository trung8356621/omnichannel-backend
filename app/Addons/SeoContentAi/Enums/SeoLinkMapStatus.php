<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

enum SeoLinkMapStatus: string
{
    case Active = 'active';
    case NeedsAudit = 'needs_audit';
    case Ignored = 'ignored';
    case Broken = 'broken';
}
