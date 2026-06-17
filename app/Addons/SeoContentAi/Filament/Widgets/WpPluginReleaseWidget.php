<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Widgets;

use App\Addons\SeoContentAi\Filament\Concerns\InteractsWithSeoDashboardSite;
use App\Addons\SeoContentAi\Services\WordPressPluginReleaseService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Widgets\Widget;

class WpPluginReleaseWidget extends Widget
{
    use InteractsWithSeoDashboardSite;

    protected static string $view = 'seo-content-ai::filament.widgets.wp-plugin-release';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public bool $showOlderVersions = false;

    public static function canView(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures()
            && SeoAccessControl::hasGlobalSiteScope();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(WordPressPluginReleaseService::class)->overview();
    }
}
