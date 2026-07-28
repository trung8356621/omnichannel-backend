<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\Serp;

enum SerpQueryStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
