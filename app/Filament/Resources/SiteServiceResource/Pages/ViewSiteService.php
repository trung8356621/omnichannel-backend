<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteServiceResource\Pages;

use App\Filament\Resources\SiteServiceResource;
use App\Models\SiteService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSiteService extends ViewRecord
{
    protected static string $resource = SiteServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('wp_plugin_release')
                ->label(__('WP Plugin Release'))
                ->icon('heroicon-m-arrow-up-tray')
                ->color('warning')
                ->url(function (): ?string {
                    $record = $this->getRecord();
                    if (! $record instanceof SiteService) {
                        return null;
                    }

                    return SiteServiceResource::wpPluginReleaseUrlFor($record);
                })
                ->openUrlInNewTab()
                ->visible(function (): bool {
                    $record = $this->getRecord();
                    if (! $record instanceof SiteService) {
                        return false;
                    }

                    return SiteServiceResource::shouldShowWpPluginReleaseAction($record);
                }),
            Actions\EditAction::make()
                ->visible(fn (): bool => SiteServiceResource::canEdit($this->getRecord())),
        ];
    }
}
