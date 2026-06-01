<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages\Concerns\PersistsDomainPromptContext;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages\Concerns\PersistsSeoDomainMetas;
use App\Models\Site;
use Filament\Resources\Pages\EditRecord;

class EditDomain extends EditRecord
{
    use PersistsDomainPromptContext;
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

        $data = $this->fillSeoMetaFormData($record, $data);

        return $this->fillDomainPromptContextFormData($record, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Site $record */
        $record = $this->record;

        $data = $this->stripPromptContextFromFormData($data);

        return $this->persistSeoMetaFormData($record, $data);
    }

    protected function afterSave(): void
    {
        /** @var Site $site */
        $site = $this->record;

        $this->queuePromptContextFromFormState($this->form->getState());
        $this->persistPendingDomainPromptContext($site);
    }
}
