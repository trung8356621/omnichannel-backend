<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Widgets;

use App\Addons\SeoContentAi\Filament\Concerns\InteractsWithSeoDashboardSite;
use App\Addons\SeoContentAi\Services\DomainOverviewService;
use Filament\Widgets\Widget;

class SeoScoreChart extends Widget
{
    use InteractsWithSeoDashboardSite;

    protected static string $view = 'seo-content-ai::filament.widgets.seo-score-chart';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 8,
    ];

    public static function canView(): bool
    {
        return \App\Addons\SeoContentAi\Support\SeoAccessControl::hasGlobalSiteScope();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $siteId = $this->resolveDashboardSiteId();
        if ($siteId === null) {
            return [
                'has_data' => false,
                'avg_score' => null,
                'segments' => [],
                'donut_gradient' => '',
            ];
        }

        $overview = app(DomainOverviewService::class);
        $distribution = $overview->getScoreDistribution($siteId);
        $scoring = $overview->getScoringStatistics($siteId);

        $segments = array_values(array_filter(
            $distribution['segments'],
            static fn (array $segment): bool => ($segment['count'] ?? 0) > 0,
        ));

        $donutTotal = array_sum(array_column($segments, 'count'));
        $donutGradient = '';

        if ($donutTotal > 0) {
            $cursor = 0.0;
            $parts = [];
            foreach ($segments as $segment) {
                $pct = ($segment['count'] / $donutTotal) * 100;
                $start = $cursor;
                $cursor += $pct;
                $parts[] = ($segment['color'] ?? '#94a3b8').' '.$start.'% '.$cursor.'%';
            }
            $donutGradient = 'conic-gradient('.implode(', ', $parts).')';
        }

        return [
            'has_data' => $donutTotal > 0,
            'avg_score' => $scoring['avg_score'],
            'scored' => $scoring['scored'],
            'segments' => $segments,
            'donut_gradient' => $donutGradient,
        ];
    }
}
