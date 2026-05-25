<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use App\Addons\SeoContentAi\Services\AiModelRouterService;
use Filament\Actions;
use Filament\Notifications\Notification;
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
            Actions\Action::make('sync_models')
                ->label('Đồng bộ model từ API')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $ok = app(AiModelRouterService::class)->syncModelsForConnection((int) $this->record->id);

                    if ($ok) {
                        Notification::make()
                            ->title('Đã đồng bộ model')
                            ->body('Danh sách phiên bản API đã cập nhật vào seo_ai_models.')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Đồng bộ thất bại')
                        ->body('Kiểm tra API Key và nhà cung cấp (Gemini / Claude).')
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
