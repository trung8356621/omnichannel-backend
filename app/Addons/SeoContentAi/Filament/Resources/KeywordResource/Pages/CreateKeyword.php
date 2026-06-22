<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoCreateRecord;
use App\Addons\SeoContentAi\Services\KeywordMetaRepository;
use App\Addons\SeoContentAi\Services\KeywordPersistenceService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;

class CreateKeyword extends SeoCreateRecord
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

        $tagIds = collect($this->form->getState()['tags'] ?? [])
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($tagIds !== []) {
            app(KeywordMetaRepository::class)
                ->setTagIds((int) $this->record->id, $tagIds);
        }
    }

    protected function getRedirectUrl(): string
    {
        return KeywordResource::getUrl('index');
    }
}
