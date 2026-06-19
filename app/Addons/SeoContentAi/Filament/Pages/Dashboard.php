<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Filament\Concerns\InteractsWithSeoAllDomainsDashboard;
use App\Addons\SeoContentAi\Filament\Concerns\InteractsWithSeoConnectionRoutes;
use App\Addons\SeoContentAi\Filament\Widgets\AllDomainsListWidget;
use App\Addons\SeoContentAi\Filament\Widgets\AllDomainsProjectsWidget;
use App\Addons\SeoContentAi\Filament\Widgets\AllDomainsTeamWidget;
use App\Addons\SeoContentAi\Filament\Widgets\SeoOverviewStats;
use App\Addons\SeoContentAi\Filament\Widgets\SeoScoreChart;
use App\Addons\SeoContentAi\Filament\Widgets\WpPluginReleaseWidget;
use App\Addons\SeoContentAi\Filament\Widgets\WpSyncStatusTable;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    use InteractsWithSeoAllDomainsDashboard;
    use InteractsWithSeoConnectionRoutes;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    /** @var array<string, mixed> */
    protected $listeners = [
        'seoGlobalSiteChanged' => '$refresh',
    ];

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.dashboard.title');
    }

    /**
     * @return int | string | array<string, int | string>
     */
    public function getColumns(): int|string|array
    {
        return [
            'default' => 1,
            'md' => 12,
            'xl' => 12,
        ];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        if ($this->isAllDomainsDashboard()) {
            return [
                AllDomainsProjectsWidget::class,
                AllDomainsTeamWidget::class,
                AllDomainsListWidget::class,
                WpPluginReleaseWidget::class,
            ];
        }

        return [
            SeoOverviewStats::class,
            SeoScoreChart::class,
            WpSyncStatusTable::class,
        ];
    }
}
