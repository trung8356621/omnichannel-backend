<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages\Concerns\PersistsSeoDomainMetas;
use App\Models\Site;
use Filament\Resources\Pages\CreateRecord;

class CreateDomain extends CreateRecord
{
    use PersistsSeoDomainMetas;

    protected static string $resource = DomainResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->form->fill($this->defaultSeoMetaForCreateForm([]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        $data['status'] = $data['status'] ?? 'active';
        $data['ssl'] = array_key_exists('ssl', $data) ? (bool) $data['ssl'] : true;

        return $this->stripSeoMetaKeys($data);
    }

    protected function afterCreate(): void
    {
        /** @var Site $site */
        $site = $this->record;

        $this->persistSeoMetaFormData($site, $this->form->getState());
    }

    protected function getRedirectUrl(): string
    {
        return DomainResource::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripSeoMetaKeys(array $data): array
    {
        unset(
            $data['seo_platform'],
            $data['seo_domain_type'],
            $data['seo_read_token'],
            $data['seo_migration_token'],
        );

        return $data;
    }
}
