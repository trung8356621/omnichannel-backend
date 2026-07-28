<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\Gsc;

enum GscOpportunityMaturity: string
{
    case New = 'new';
    case Early = 'early';
    case Mature = 'mature';
}
