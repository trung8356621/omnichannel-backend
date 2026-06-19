<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteServiceResource\Pages;

use App\Addons\SeoContentAi\Support\SeoSiteServiceDatabaseConfigurator;
use App\Filament\Resources\SiteServiceResource;
use App\Services\SiteServiceBindingService;
use Filament\Resources\Pages\CreateRecord;

class CreateSiteService extends CreateRecord
{
    protected static string $resource = SiteServiceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $binding = app(SiteServiceBindingService::class);
        $data = $binding->normalizeBoundPayload($data);
        $binding->assertBoundPayload($data);

        SeoSiteServiceDatabaseConfigurator::assertConnectionFromFormData($data, null);

        return SeoSiteServiceDatabaseConfigurator::mutateBeforeSave($data, null);
    }

    protected function afterCreate(): void
    {
        SeoSiteServiceDatabaseConfigurator::runMigrations($this->record);
    }
}
