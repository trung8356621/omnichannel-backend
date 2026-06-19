<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeoDatabaseConnectionResource\Pages;

use App\Addons\SeoContentAi\Services\SeoDatabaseBackupService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Filament\Support\SeoDatabaseConnectionBackupActions;
use App\Models\SeoDatabaseConnection;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class EditSeoDatabaseConnection extends EditRecord
{
    protected static string $resource = SeoDatabaseConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportSql')
                ->label('Export SQL')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(fn (): bool => (bool) $this->record?->is_active)
                ->action(fn (): BinaryFileResponse => app(SeoDatabaseBackupService::class)->downloadResponse($this->record)),

            Actions\Action::make('importSql')
                ->label('Import SQL')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('danger')
                ->modalHeading('Khôi phục database từ SQL')
                ->modalSubmitActionLabel('Bắt đầu khôi phục')
                ->requiresConfirmation()
                ->form(SeoDatabaseConnectionBackupActions::importFormSchema())
                ->action(fn (array $data): mixed => SeoDatabaseConnectionBackupActions::runImport($data, $this->record)),

            Actions\Action::make('runMigrations')
                ->label('Chạy migration addon')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Chỉ chạy các migration addon SEO còn thiếu trên database đích.')
                ->action(fn (): mixed => $this->runPendingMigrations()),

            Actions\Action::make('testConnection')
                ->label('Kiểm tra kết nối')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(fn (): mixed => $this->runConnectionTest()),

            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $userIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, is_array($data['users'] ?? null) ? $data['users'] : []),
            static fn (int $id): bool => $id > 0,
        ));

        if ($userIds === []) {
            throw ValidationException::withMessages([
                'users' => 'Phải chọn ít nhất một owner/admin được phép truy cập workspace SEO này.',
            ]);
        }

        $this->assertConnectionTest($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertConnectionTest(array $data): void
    {
        /** @var SeoDatabaseConnection $record */
        $record = $this->record;
        $service = app(SeoDatabaseConnectionService::class);
        $plainPassword = trim((string) ($data['password'] ?? ''));

        $attributes = array_merge($record->toArray(), $data);

        try {
            if ($plainPassword !== '') {
                $service->testConnectionFromAttributes($attributes, $plainPassword);
            } else {
                $merged = $record->replicate();
                $merged->fill($data);
                $service->testConnectionForModel($merged);
            }
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'type' => $exception->getMessage(),
            ]);
        }
    }

    private function runConnectionTest(): void
    {
        try {
            $this->assertConnectionTest($this->form->getState());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Kết nối thất bại')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Lỗi không xác định.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Kết nối thành công')
            ->success()
            ->send();
    }

    private function runPendingMigrations(): void
    {
        try {
            /** @var SeoDatabaseConnection $record */
            $record = $this->record->fresh(['users']);
            $result = app(SeoDatabaseConnectionService::class)->runMigrationsForConnection($record);

            if (! $result['executed']) {
                Notification::make()
                    ->title('Không có migration pending')
                    ->success()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Migration addon đã chạy')
                ->body('Đã áp dụng '.$result['pending'].' migration còn thiếu.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Migration thất bại')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
