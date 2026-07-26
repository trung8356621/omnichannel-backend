<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Widgets;

use App\Addons\SeoContentAi\Services\ContentProjectStaffAvailabilityService;
use Filament\Widgets\Widget;

final class UnassignedContentProjectStaffWidget extends Widget
{
    protected static string $view = 'seo-content-ai::filament.widgets.unassigned-content-project-staff';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = [
        'default' => 12,
        'lg' => 4,
        'xl' => 3,
    ];

    public bool $showAll = false;

    public static function canView(): bool
    {
        return app(ContentProjectStaffAvailabilityService::class)->canViewUnassignedStaff();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(ContentProjectStaffAvailabilityService::class);
        $limit = $this->showAll ? 50 : ContentProjectStaffAvailabilityService::WIDGET_LIMIT;
        $payload = $service->widgetPayload($limit);

        return [
            'total' => $payload['total'],
            'staff' => $payload['staff'],
            'showAll' => $this->showAll,
            'limit' => $limit,
            'createUrl' => $service->createProjectUrl(0),
        ];
    }

    public function toggleShowAll(): void
    {
        $this->showAll = ! $this->showAll;
    }
}
