<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Concerns\InteractsWithSeoConnectionResourceRoutes;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Resources\Resource;

abstract class SeoPanelResource extends Resource
{
    use InteractsWithSeoConnectionResourceRoutes;

    protected static function allowsSeoPanelMutation(): bool
    {
        return SeoAccessControl::canMutateInSeoPanel();
    }

    /**
     * @param  array<int, mixed>  $actions
     * @return array<int, mixed>
     */
    protected static function seoPanelBulkActions(array $actions): array
    {
        return static::allowsSeoPanelMutation() ? $actions : [];
    }
}
