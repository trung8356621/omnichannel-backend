<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Concerns;

use App\Addons\SeoContentAi\Support\SeoAccessControl;

trait InteractsWithSeoAllDomainsDashboard
{
    protected function isAllDomainsDashboard(): bool
    {
        return ! SeoAccessControl::hasGlobalSiteScope();
    }
}
