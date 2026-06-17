<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\TaskResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoCreateRecord;
use App\Addons\SeoContentAi\Filament\Resources\TaskResource;

class CreateTask extends SeoCreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['flow_data'] = $data['flow_data'] ?? [
            'nodes' => [],
            'edges' => [],
        ];

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return TaskResource::getUrl('builder', ['record' => $this->record]);
    }
}
