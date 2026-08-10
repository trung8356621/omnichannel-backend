<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeoDatabaseConnectionResource\Pages;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Filament\Support\SeoDatabaseConnectionAccess;
use App\Filament\Support\SeoDatabaseConnectionOwnerSync;
use App\Models\SeoDatabaseConnection;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CreateSeoDatabaseConnection extends CreateRecord
{
    protected static string $resource = SeoDatabaseConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('testConnection')
                ->label('Kiểm tra kết nối')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(fn (): mixed => $this->runConnectionTest()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! SeoDatabaseConnectionAccess::isAdmin()) {
            if (SeoDatabaseConnectionAccess::ownerHasConnection()) {
                throw ValidationException::withMessages([
                    'name' => 'Mỗi owner chỉ được tạo một SEO Database Connection. Hãy chỉnh sửa connection hiện có.',
                ]);
            }

            $data['owner_id'] = auth()->id();
        }

        $ownerId = SeoDatabaseConnectionOwnerSync::resolveOwnerId(
            $data,
            null,
            data_get($this->form->getRawState(), 'owner_id'),
        );

        SeoDatabaseConnectionOwnerSync::assertOwnerEligible($ownerId);
        SeoDatabaseConnectionOwnerSync::assertOwnerSingleConnection($ownerId);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $this->assertConnectionTest($data);

        $ownerId = SeoDatabaseConnectionOwnerSync::resolveOwnerId(
            $data,
            null,
            data_get($this->form->getRawState(), 'owner_id'),
        );

        unset($data['owner_id']);

        /** @var SeoDatabaseConnection $record */
        $record = parent::handleRecordCreation($data);

        if ($ownerId > 0) {
            SeoDatabaseConnectionOwnerSync::syncOwner($record, $ownerId);
        }

        $this->runPendingMigrationsIfAny($record);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertConnectionTest(array $data): void
    {
        $service = app(SeoDatabaseConnectionService::class);
        $plainPassword = trim((string) ($data['password'] ?? ''));

        if (($data['type'] ?? '') === 'manual' && $plainPassword === '') {
            throw ValidationException::withMessages([
                'password' => 'Mật khẩu database là bắt buộc khi tạo kết nối thủ công.',
            ]);
        }

        try {
            $service->testConnectionFromAttributes($data, $plainPassword !== '' ? $plainPassword : null);
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

    private function runPendingMigrationsIfAny(SeoDatabaseConnection $record): void
    {
        try {
            $result = app(SeoDatabaseConnectionService::class)->runMigrationsForConnection($record->fresh(['users']));
            if ($result['executed']) {
                Notification::make()
                    ->title('Migration addon đã chạy')
                    ->body('Đã áp dụng '.$result['pending'].' migration còn thiếu.')
                    ->success()
                    ->send();
            }
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Migration thất bại')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
