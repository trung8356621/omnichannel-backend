<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Concerns\InteractsWithSeoConnectionResourceRoutes;
use Filament\Resources\Resource;

abstract class SeoPanelResource extends Resource
{
    use InteractsWithSeoConnectionResourceRoutes;
}
