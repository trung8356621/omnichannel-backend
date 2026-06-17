<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Models\SiteService;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class SeoSiteServiceDatabaseConfigurator
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateBeforeSave(array $data, ?SiteService $existing): array
    {
        $serviceId = (int) ($data['service_id'] ?? 0);
        $db = app(SeoDatabaseConnectionService::class);

        if (! $db->isSeoContentAiService($serviceId)) {
            return $data;
        }

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $type = (string) ($settings['db_config_type'] ?? 'auto');

        if ($type === 'manual') {
            $plainPassword = trim((string) ($settings['db_password'] ?? ''));
            $existingEncrypted = is_array($existing?->settings)
                ? (string) ($existing->settings['db_password'] ?? '')
                : '';

            if ($plainPassword !== '') {
                $settings['db_password'] = $db->encryptPassword($plainPassword);
            } elseif ($existingEncrypted !== '') {
                $settings['db_password'] = $existingEncrypted;
            } else {
                unset($settings['db_password']);
            }
        } else {
            unset(
                $settings['db_host'],
                $settings['db_port'],
                $settings['db_name'],
                $settings['db_username'],
                $settings['db_password'],
            );
        }

        $data['settings'] = $settings;

        return $data;
    }

    public static function validateAndMigrate(SiteService $record): void
    {
        self::runMigrations($record);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function assertConnectionFromFormData(array $data, ?SiteService $existing): void
    {
        $db = app(SeoDatabaseConnectionService::class);
        $serviceId = (int) ($data['service_id'] ?? 0);

        if (! $db->isSeoContentAiService($serviceId)) {
            return;
        }

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $siteId = (int) ($data['site_id'] ?? $existing?->site_id ?? 0);

        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => 'Site là bắt buộc để cấu hình database SEO.',
            ]);
        }

        $type = (string) ($settings['db_config_type'] ?? 'auto');
        if ($type === 'manual' && blank($settings['db_password'] ?? null)) {
            $existingEncrypted = is_array($existing?->settings)
                ? (string) ($existing->settings['db_password'] ?? '')
                : '';
            if ($existingEncrypted === '' && $existing === null) {
                throw ValidationException::withMessages([
                    'settings.db_password' => 'Mật khẩu database là bắt buộc khi tạo cấu hình thủ công.',
                ]);
            }
        }

        try {
            $attributes = self::mapSettingsToConnectionAttributes($settings, $siteId);
            $plainPassword = trim((string) ($settings['db_password'] ?? ''));
            $db->testConnectionFromAttributes(
                $attributes,
                $plainPassword !== '' ? $plainPassword : null,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'settings.db_config_type' => $exception->getMessage(),
            ]);
        }
    }

    public static function runMigrations(SiteService $record): void
    {
        $db = app(SeoDatabaseConnectionService::class);

        if (! $db->isSeoContentAiService((int) $record->service_id)) {
            return;
        }

        $siteId = (int) $record->site_id;

        $connection = $db->bootstrapBySiteId($siteId);
        if ($connection === null) {
            throw ValidationException::withMessages([
                'settings.db_config_type' => 'Không tìm thấy SEO database connection cho site này.',
            ]);
        }

        try {
            $result = $db->runMigrationsForConnection($connection);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Lỗi migration database SEO')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'settings.db_config_type' => 'Migration thất bại: '.$exception->getMessage(),
            ]);
        }

        if (! ($result['executed'] ?? false)) {
            return;
        }

        Notification::make()
            ->title('Database SEO đã sẵn sàng')
            ->body(sprintf('Đã áp dụng %d migration còn thiếu.', (int) ($result['pending'] ?? 0)))
            ->success()
            ->send();
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\Section::make('Cấu hình Database SEO Content AI')
                ->description('Mỗi site có thể dùng database riêng. Chế độ Tự động dùng credentials từ .env core.')
                ->visible(fn (Get $get): bool => self::isSeoServiceSelected($get('service_id')))
                ->schema([
                    Forms\Components\Select::make('settings.db_config_type')
                        ->label('Chế độ cấu hình DB')
                        ->options([
                            'auto' => 'Tự động (Docker Production)',
                            'manual' => 'Thủ công (Hosting lẻ / Clone)',
                        ])
                        ->default('auto')
                        ->required()
                        ->live()
                        ->native(false),

                    Forms\Components\Placeholder::make('db_auto_hint')
                        ->label('Ghi chú chế độ Tự động')
                        ->visible(fn (Get $get): bool => ($get('settings.db_config_type') ?? 'auto') === 'auto')
                        ->content(function (Get $get): string {
                            $siteId = (int) ($get('site_id') ?? 0);
                            $perSite = (bool) config('seo-content-ai.auto_per_site_database', false);
                            $legacy = (string) config('seo-content-ai.legacy_shared_database', 'omi_seo_ai');

                            if ($perSite && $siteId > 0) {
                                $prefix = (string) config('seo-content-ai.auto_database_prefix', 'omi_seo_ai');

                                return "Sử dụng MySQL credentials từ .env, database: {$prefix}_{$siteId}";
                            }

                            return "Sử dụng MySQL credentials từ .env, database dùng chung: {$legacy}";
                        }),

                    Forms\Components\TextInput::make('settings.db_host')
                        ->label('DB Host')
                        ->default('127.0.0.1')
                        ->visible(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual')
                        ->required(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual'),

                    Forms\Components\TextInput::make('settings.db_port')
                        ->label('DB Port')
                        ->default('3306')
                        ->numeric()
                        ->visible(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual')
                        ->required(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual'),

                    Forms\Components\TextInput::make('settings.db_name')
                        ->label('Database Name')
                        ->visible(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual')
                        ->required(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual'),

                    Forms\Components\TextInput::make('settings.db_username')
                        ->label('DB Username')
                        ->visible(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual')
                        ->required(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual'),

                    Forms\Components\TextInput::make('settings.db_password')
                        ->label('DB Password')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText('Để trống nếu không đổi mật khẩu (chỉ khi sửa).')
                        ->visible(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual'),
                ])
                ->columns(2),
        ];
    }

    private static function isSeoServiceSelected(mixed $serviceId): bool
    {
        if (! is_numeric($serviceId)) {
            return false;
        }

        return app(SeoDatabaseConnectionService::class)->isSeoContentAiService((int) $serviceId);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function mapSettingsToConnectionAttributes(array $settings, int $siteId): array
    {
        $type = (string) ($settings['db_config_type'] ?? 'auto');

        if ($type === 'manual') {
            return [
                'type' => 'manual',
                'host' => $settings['db_host'] ?? '127.0.0.1',
                'port' => $settings['db_port'] ?? '3306',
                'database' => $settings['db_name'] ?? '',
                'username' => $settings['db_username'] ?? '',
            ];
        }

        $legacy = (string) config('seo-content-ai.legacy_shared_database', 'omi_seo_ai');
        $perSite = (bool) config('seo-content-ai.auto_per_site_database', false);
        $database = $perSite && $siteId > 0
            ? config('seo-content-ai.auto_database_prefix', 'omi_seo_ai').'_'.$siteId
            : $legacy;

        return [
            'type' => 'auto',
            'database' => $database,
        ];
    }
}
