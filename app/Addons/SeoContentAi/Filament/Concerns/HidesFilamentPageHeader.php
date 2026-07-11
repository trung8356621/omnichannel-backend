<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Concerns;

use Illuminate\Contracts\View\View;

trait HidesFilamentPageHeader
{
    public function getHeader(): ?View
    {
        return null;
    }
}
