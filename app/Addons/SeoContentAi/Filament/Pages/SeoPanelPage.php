<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Filament\Concerns\InteractsWithSeoConnectionRoutes;
use Filament\Pages\Page;

abstract class SeoPanelPage extends Page
{
    use InteractsWithSeoConnectionRoutes;

    protected static ?string $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return null;
    }
}
