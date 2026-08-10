<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseBackupService;
use App\Models\SeoDatabaseConnection;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class SeoDatabaseConnectionBackupActions
{
    public static function exportTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('exportSql')
            ->label('Export SQL')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn (SeoDatabaseConnection $record): bool => (bool) $record->is_active)
            ->action(fn (SeoDatabaseConnection $record): BinaryFileResponse => app(SeoDatabaseBackupService::class)->downloadResponse($record))
            ->successNotificationTitle('Đang tải file backup SQL...');
    }

    public static function importTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('importSql')
            ->label('Import SQL')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('danger')
            ->modalHeading('Khôi phục database từ SQL')
            ->modalSubmitActionLabel('Bắt đầu khôi phục')
            ->requiresConfirmation()
            ->form(self::importFormSchema())
            ->action(fn (array $data, SeoDatabaseConnection $record): mixed => self::runImport($data, $record));
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function importFormSchema(): array
    {
        return [
            Forms\Components\Placeholder::make('import_warning')
                ->label('')
                ->content(new HtmlString(
                    '<div class="rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">'
                    .'<strong>Cảnh báo:</strong> Hành động này sẽ ghi đè và thay đổi cấu trúc dữ liệu hiện tại trong database đích. '
                    .'Vui lòng chắc chắn bạn đã sao lưu dữ liệu trước khi thực hiện!'
                    .'</div>',
                )),

            Forms\Components\FileUpload::make('backup_file')
                ->label('File backup (.sql hoặc .sql.gz)')
                ->disk('local')
                ->directory('seo-db-imports')
                ->required()
                ->maxSize((int) config('seo-content-ai.db_import_max_upload_kb', 512000))
                ->acceptedFileTypes([
                    'application/sql',
                    'text/plain',
                    'application/gzip',
                    'application/x-gzip',
                    'application/octet-stream',
                ])
                ->helperText('File lớn sẽ được xử lý qua queue (nếu queue worker đang chạy).'),

            Forms\Components\Toggle::make('force_queue')
                ->label('Luôn chạy import qua queue (khuyến nghị cho file lớn)')
                ->default(false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function runImport(array $data, SeoDatabaseConnection $record): void
    {
        $relativePath = Arr::first((array) ($data['backup_file'] ?? []));
        if (! is_string($relativePath) || $relativePath === '') {
            Notification::make()
                ->title('Import thất bại')
                ->body('Vui lòng chọn file SQL backup.')
                ->danger()
                ->send();

            return;
        }

        $backupService = app(SeoDatabaseBackupService::class);

        try {
            $absolutePath = $backupService->resolveStoredImportPath($relativePath);
            $result = $backupService->importConnection(
                $record,
                $absolutePath,
                forceQueue: (bool) ($data['force_queue'] ?? false),
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Import thất bại')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($result['queued']) {
            Notification::make()
                ->title('Import đã được đưa vào hàng đợi')
                ->body('Theo dõi tiến trình qua task_jobs #'.($result['task_job_id'] ?? '—').'.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Import hoàn tất')
            ->body('Đã thực thi '.$result['statements'].' câu lệnh SQL.')
            ->success()
            ->send();
    }
}
