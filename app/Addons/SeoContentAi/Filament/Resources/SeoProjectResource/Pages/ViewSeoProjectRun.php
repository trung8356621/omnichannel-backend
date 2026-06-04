<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class ViewSeoProjectRun extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.view-project-run';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    public SeoProjectRun $run;

    public function mount(int|string $run): void
    {
        $this->run = SeoProjectRun::query()
            ->with(['project.site', 'user'])
            ->findOrFail((int) $run);
    }

    public function getTitle(): string|Htmlable
    {
        $projectName = (string) ($this->run->project?->name ?? '');

        return __('seo-content-ai::filament.projects.run_results_title', [
            'project' => $projectName,
            'id' => (int) $this->run->id,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getResultItems(): array
    {
        $items = is_array($this->run->items) ? $this->run->items : [];

        return array_values($items);
    }

    public function postTypeLabel(?string $postType): string
    {
        if ($postType === null || $postType === '') {
            return '—';
        }

        return SeoProjectResource::postTypeSelectOptions()[$postType] ?? $postType;
    }

    protected function getHeaderActions(): array
    {
        $project = $this->run->project;

        return [
            Actions\Action::make('back_to_project')
                ->label(__('seo-content-ai::filament.projects.back_to_project'))
                ->icon('heroicon-o-arrow-left')
                ->url(
                    $project !== null
                        ? SeoProjectResource::getUrl('edit', ['record' => $project])
                        : SeoProjectResource::getUrl('index'),
                ),
            Actions\Action::make('back_to_list')
                ->label(__('seo-content-ai::filament.projects.back_to_projects'))
                ->color('gray')
                ->url(SeoProjectResource::getUrl('index')),
        ];
    }
}
