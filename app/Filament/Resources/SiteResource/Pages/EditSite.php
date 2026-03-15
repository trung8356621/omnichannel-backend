<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Addons\WpHeadless\Models\WpHeadlessSite;
use App\Filament\Resources\SiteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    public function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->record && $this->record->hasActiveWpHeadless()) {
            try {
                $wh = WpHeadlessSite::on('wp_headless')->find($this->record->id);
                if ($wh) {
                    $data['wp_headless'] = [
                        'type' => $wh->type,
                        'public_url' => $wh->public_url,
                        'headless_next_dev' => $wh->headless_next_dev,
                        'is_dev' => (bool) $wh->is_dev,
                    ];
                }
            } catch (\Throwable $e) {
                // Connection hoặc bảng chưa có
            }
        }
        return $data;
    }

    protected function afterSave(): void
    {
        $data = $this->form->getState();
        $whData = $data['wp_headless'] ?? null;
        if (!$this->record || !is_array($whData)) {
            return;
        }
        if (!$this->record->hasActiveWpHeadless()) {
            return;
        }
        try {
            WpHeadlessSite::on('wp_headless')->updateOrCreate(
                ['id' => $this->record->id],
                [
                    'type' => trim((string) ($whData['type'] ?? '')) !== '' ? (string) $whData['type'] : 'unknown',
                    'public_url' => $whData['public_url'] ?? null,
                    'headless_next_dev' => $whData['headless_next_dev'] ?? null,
                    'is_dev' => (bool) ($whData['is_dev'] ?? false),
                ]
            );
        } catch (\Throwable $e) {
            // Bỏ qua nếu connection/bảng lỗi
        }
    }
}
