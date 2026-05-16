<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use Filament\Pages\Page;

class SeoSettings extends Page
{
    protected static ?string $slug = 'settings';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Tùy chỉnh';

    protected static ?string $title = 'Tùy chỉnh';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings';
}
