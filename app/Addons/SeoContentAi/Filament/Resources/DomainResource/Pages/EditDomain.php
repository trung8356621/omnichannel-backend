<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditDomain extends EditRecord
{
    protected static string $resource = DomainResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('metas');

        $data['seo_platform'] = $this->record->getMeta('seo_platform') ?? 'wordpress';
        $data['seo_domain_type'] = $this->record->getMeta('seo_domain_type') ?? 'news';
        $data['seo_read_token'] = $this->record->getMeta('seo_read_token') ?? '';
        $data['seo_migration_token'] = $this->record->getMeta('seo_migration_token') ?? '';

        if (($data['seo_platform'] ?? '') === 'wordpress') {
            if ($data['seo_read_token'] === '' || $data['seo_read_token'] === null) {
                $data['seo_read_token'] = Str::random(60);
                $this->record->metas()->updateOrCreate(
                    ['meta_key' => 'seo_read_token'],
                    ['meta_value' => $data['seo_read_token']]
                );
            }
            if ($data['seo_migration_token'] === '' || $data['seo_migration_token'] === null) {
                $data['seo_migration_token'] = Str::random(60);
                $this->record->metas()->updateOrCreate(
                    ['meta_key' => 'seo_migration_token'],
                    ['meta_value' => $data['seo_migration_token']]
                );
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $platform = isset($data['seo_platform']) ? (string) $data['seo_platform'] : 'custom';
        $domainType = isset($data['seo_domain_type']) ? (string) $data['seo_domain_type'] : 'news';
        $readToken = isset($data['seo_read_token']) ? (string) $data['seo_read_token'] : '';
        $migrationToken = isset($data['seo_migration_token']) ? (string) $data['seo_migration_token'] : '';

        unset(
            $data['seo_platform'],
            $data['seo_domain_type'],
            $data['seo_read_token'],
            $data['seo_migration_token']
        );

        $this->record->metas()->updateOrCreate(
            ['meta_key' => 'seo_platform'],
            ['meta_value' => $platform]
        );
        $this->record->metas()->updateOrCreate(
            ['meta_key' => 'seo_domain_type'],
            ['meta_value' => $domainType]
        );

        if ($platform === 'wordpress') {
            $this->record->metas()->updateOrCreate(
                ['meta_key' => 'seo_read_token'],
                ['meta_value' => $readToken]
            );
            $this->record->metas()->updateOrCreate(
                ['meta_key' => 'seo_migration_token'],
                ['meta_value' => $migrationToken]
            );
        } else {
            $this->record->metas()->updateOrCreate(
                ['meta_key' => 'seo_read_token'],
                ['meta_value' => '']
            );
            $this->record->metas()->updateOrCreate(
                ['meta_key' => 'seo_migration_token'],
                ['meta_value' => '']
            );
        }

        return $data;
    }
}
