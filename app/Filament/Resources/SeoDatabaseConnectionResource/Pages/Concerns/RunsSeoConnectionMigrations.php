<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeoDatabaseConnectionResource\Pages\Concerns;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Models\SeoDatabaseConnection;
use Filament\Actions;
use Filament\Notifications\Notification;
use Throwable;

trait RunsSeoConnectionMigrations
{
    protected function seoMigrationHeaderAction(): Actions\Action
    {
        return Actions\Action::make('runMigrations')
            ->label('Chạy migration addon')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Chạy migration SEO Content AI')
            ->modalDescription('Chỉ áp dụng các migration còn thiếu trên database của connection này. Không tạo lại bảng đã có trong bảng migrations.')
            ->action(fn (): mixed => $this->runSeoConnectionMigrations(manual: true));
    }

    protected function runSeoConnectionMigrations(bool $manual = false, ?SeoDatabaseConnection $record = null): void
    {
        /** @var SeoDatabaseConnection|null $record */
        $record ??= $this->record ?? null;
        if (! $record instanceof SeoDatabaseConnection) {
            return;
        }

        $service = app(SeoDatabaseConnectionService::class);

        try {
            $result = $service->runMigrationsForConnection($record->fresh());
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Migration thất bại')
                ->body($this->formatMigrationError($exception->getMessage()))
                ->danger()
                ->send();

            return;
        }

        if (! ($result['executed'] ?? false)) {
            if ($manual) {
                Notification::make()
                    ->title('Không có migration mới')
                    ->body('Database đã có đầy đủ migration addon (hoặc chưa có file migration nào pending).')
                    ->success()
                    ->send();
            }

            return;
        }

        Notification::make()
            ->title('Migration đã chạy')
            ->body(sprintf('Đã áp dụng %d migration còn thiếu.', (int) ($result['pending'] ?? 0)))
            ->success()
            ->send();
    }

    private function formatMigrationError(string $message): string
    {
        if (str_contains($message, 'already exists')) {
            return $message.' — Có thể bảng đã tồn tại nhưng chưa ghi vào bảng migrations của DB này. '
                .'Hãy đồng bộ bảng migrations thủ công hoặc liên hệ dev, không lưu lại form để tránh chạy lặp.';
        }

        return $message;
    }
}
