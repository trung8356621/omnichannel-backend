<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Resources\Pages\Page;

/**
 * Compatibility redirect — Publishing Queue gộp vào ViewSeoProject.
 * Canonical: /content-projects/{id}?lifecycle=waiting_publish,published
 */
final class ContentProjectPublishingQueue extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.redirect-placeholder';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Scalar route key — tránh Livewire Eloquent binding 404 trên omi_seo_ai.
     */
    public int|string $record = 0;

    public function mount(int|string $record): void
    {
        $this->record = $record;

        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()->find($record);
        abort_unless($project instanceof SeoProject, 404);
        abort_unless(SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0)), 403);

        $this->redirect(
            SeoProjectResource::getUrl('view', [
                'record' => $project,
                'lifecycle' => 'waiting_publish,published',
            ]),
            navigate: true,
        );
    }
}
