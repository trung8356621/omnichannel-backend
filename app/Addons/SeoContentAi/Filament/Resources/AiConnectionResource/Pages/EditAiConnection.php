<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAiConnection extends EditRecord
{
    protected static string $resource = AiConnectionResource::class;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-ai-form';

    public function getTitle(): string
    {
        return __('Sửa kết nối AI');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
