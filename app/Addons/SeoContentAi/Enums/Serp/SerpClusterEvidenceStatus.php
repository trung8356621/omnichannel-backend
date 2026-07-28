<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\Serp;

enum SerpClusterEvidenceStatus: string
{
    case Draft = 'draft';
    case NeedsReview = 'needs_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Stale = 'stale';
    case Applied = 'applied';
}
