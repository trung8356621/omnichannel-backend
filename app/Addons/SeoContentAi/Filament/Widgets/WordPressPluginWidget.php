<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Widgets;

use App\Addons\SeoContentAi\Services\WordPressPluginReleaseService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Widgets\Widget;

class WordPressPluginWidget extends Widget
{
    protected static string $view = 'seo-content-ai::filament.widgets.wordpress-plugin';

    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    public bool $showOlderVersions = false;

    public static function canView(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(WordPressPluginReleaseService::class)->overview();
    }
}
