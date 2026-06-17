<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Concerns;

use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use Filament\Resources\Resource;

/**
 * @mixin Resource
 */
trait InteractsWithSeoConnectionResourceRoutes
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        string $name = 'index',
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        return parent::getUrl(
            $name,
            SeoConnectionContext::mergePanelRouteParameters($parameters),
            $isAbsolute,
            $panel,
            $tenant,
        );
    }
}
