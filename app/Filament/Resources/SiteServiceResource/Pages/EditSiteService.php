<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteServiceResource\Pages;

use App\Addons\SeoContentAi\Support\SeoSiteServiceDatabaseConfigurator;
use App\Filament\Resources\SiteServiceResource;
use App\Services\SiteServiceBindingService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSiteService extends EditRecord
{
    protected static string $resource = SiteServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => SiteServiceResource::canDelete($this->record)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $binding = app(SiteServiceBindingService::class);
        $data = $binding->normalizeBoundPayload($data);
        $binding->assertBoundPayload($data);

        SeoSiteServiceDatabaseConfigurator::assertConnectionFromFormData($data, $this->record);

        return SeoSiteServiceDatabaseConfigurator::mutateBeforeSave($data, $this->record);
    }

    protected function afterSave(): void
    {
        SeoSiteServiceDatabaseConfigurator::runMigrations($this->record->fresh());
    }
}
