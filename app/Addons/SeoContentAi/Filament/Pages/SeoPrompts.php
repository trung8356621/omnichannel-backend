<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use Filament\Pages\Page;

class SeoPrompts extends Page
{
    protected static ?string $slug = 'prompts';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Quản lý Prompts';

    protected static ?string $title = 'Quản lý Prompts';

    protected static string $view = 'seo-content-ai::filament.pages.seo-prompts';
}
