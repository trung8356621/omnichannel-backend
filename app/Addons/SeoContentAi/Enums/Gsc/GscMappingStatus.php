<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\Gsc;

enum GscMappingStatus: string
{
    case Candidate = 'candidate';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Stale = 'stale';
}
