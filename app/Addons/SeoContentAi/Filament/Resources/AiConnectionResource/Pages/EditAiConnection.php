<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoEditRecord;
use App\Addons\SeoContentAi\Services\AiModelRouterService;
use Filament\Actions;
use Filament\Notifications\Notification;

class EditAiConnection extends SeoEditRecord
{
    protected static string $resource = AiConnectionResource::class;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-ai-form';

    public function getTitle(): string
    {
        return __('Edit AI connection');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync_models')
                ->label('Sync models from API')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $ok = app(AiModelRouterService::class)->syncModelsForConnection((int) $this->record->id);

                    if ($ok) {
                        Notification::make()
                            ->title('Models synced')
                            ->body('API model list has been updated in seo_ai_models.')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Sync failed')
                        ->body('Check API key and provider (Gemini / Claude).')
                        ->danger()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['default_model'] = null;

        return $data;
    }

    protected function afterSave(): void
    {
        app(AiModelRouterService::class)->syncModelsForConnection((int) $this->record->id);
    }
}
