<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Concerns;

use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use Filament\Pages\Page;

/**
 * @mixin Page
 */
trait InteractsWithSeoConnectionRoutes
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        return parent::getUrl(
            SeoConnectionContext::mergePanelRouteParameters($parameters),
            $isAbsolute,
            $panel,
            $tenant,
        );
    }
}
