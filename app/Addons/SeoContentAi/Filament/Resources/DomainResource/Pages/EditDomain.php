<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages\Concerns\PersistsSeoDomainMetas;
use App\Models\Site;
use Filament\Resources\Pages\EditRecord;

class EditDomain extends EditRecord
{
    use PersistsSeoDomainMetas;

    protected static string $resource = DomainResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Site $record */
        $record = $this->record;

        return $this->fillSeoMetaFormData($record, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Site $record */
        $record = $this->record;

        return $this->persistSeoMetaFormData($record, $data);
    }
}
