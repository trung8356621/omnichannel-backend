<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use Filament\Pages\Page;

class SeoTeam extends Page
{
    protected static ?string $slug = 'team';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Quản lý thành viên';

    protected static ?string $title = 'Quản lý thành viên';

    protected static string $view = 'seo-content-ai::filament.pages.seo-team';
}
