<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSeoProjects extends ListRecords
{
    protected static string $resource = SeoProjectResource::class;

    protected function getTableQuery(): Builder
    {
        return SeoProjectResource::applyGlobalSiteScopeToProjectQuery(
            parent::getTableQuery()
                ->where(function (Builder $builder): void {
                    $builder
                        ->where('kind', SeoProject::KIND_MONTHLY)
                        ->orWhereNull('kind');
                }),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open_site_archive')
                ->label(__('seo-content-ai::filament.projects.open_site_archive'))
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->visible(fn (): bool => SeoAccessControl::canViewProjectArchives()
                    && SeoAccessControl::globalSiteId() !== null)
                ->url(fn (): string => SeoProjectResource::getUrl('archive')),
            Actions\CreateAction::make(),
        ];
    }
}
