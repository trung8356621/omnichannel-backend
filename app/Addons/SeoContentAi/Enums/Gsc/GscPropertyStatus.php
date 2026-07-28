<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\Gsc;

enum GscPropertyStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Error = 'error';
    case Archived = 'archived';
}
