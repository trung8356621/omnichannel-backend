<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
use App\Addons\SeoContentAi\Services\KeywordPersistenceService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Resources\Pages\CreateRecord;

class CreateKeyword extends CreateRecord
{
    protected static string $resource = KeywordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['site_id']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $siteId = (int) ($this->form->getState()['site_id'] ?? SeoAccessControl::globalSiteId() ?? 0);
        if ($siteId <= 0 || ! $this->record) {
            return;
        }

        app(KeywordPersistenceService::class)->upsertMeta($this->record, $siteId);
    }

    protected function getRedirectUrl(): string
    {
        return KeywordResource::getUrl('index');
    }
}
