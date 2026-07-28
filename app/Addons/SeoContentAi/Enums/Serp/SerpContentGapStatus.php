<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\Serp;

enum SerpContentGapStatus: string
{
    case Open = 'open';
    case Reviewed = 'reviewed';
    case Accepted = 'accepted';
    case Ignored = 'ignored';
    case Resolved = 'resolved';
    case Stale = 'stale';
}
