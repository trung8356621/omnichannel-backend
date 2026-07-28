<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\Gsc;

enum GscOpportunityStatus: string
{
    case Open = 'open';
    case Reviewed = 'reviewed';
    case Accepted = 'accepted';
    case Ignored = 'ignored';
    case Resolved = 'resolved';
    case Stale = 'stale';
}
