<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages\Concerns\PersistsDomainPromptContext;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages\Concerns\PersistsSeoDomainMetas;
use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoCreateRecord;
use App\Addons\SeoContentAi\Services\SeoDomainCtaGlobalSettingsService;
use App\Models\Site;

class CreateDomain extends SeoCreateRecord
{
    use PersistsDomainPromptContext;
    use PersistsSeoDomainMetas;

    protected static string $resource = DomainResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->form->fill([
            ...$this->defaultSeoMetaForCreateForm([]),
            'promptContext' => $this->preparePromptContextForForm([
                'tone' => '',
                'short_description' => '',
                'cta_intro' => app(SeoDomainCtaGlobalSettingsService::class)->getDefaultCtaIntro(),
                'cta' => [],
                'links' => [],
            ]),
        ]);
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

        return $this->stripSeoMetaKeys($this->stripPromptContextFromFormData($data));
    }

    protected function afterCreate(): void
    {
        /** @var Site $site */
        $site = $this->record;

        $state = $this->form->getState();
        $this->queuePromptContextFromFormState($state);
        $this->persistSeoMetaFormData($site, $this->stripPromptContextFromFormData($state));
        $this->persistPendingDomainPromptContext($site);
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
