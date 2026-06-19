<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Models\Site;
use App\Models\SiteService;
use App\Services\SiteServiceBindingService;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
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

        $defaults = (new \App\Addons\SeoContentAi\Settings)->getDefaults();
        $settings = array_merge(
            $defaults,
            is_array($data['settings'] ?? null) ? $data['settings'] : [],
        );

        unset(
            $settings['db_host'],
            $settings['db_port'],
            $settings['db_name'],
            $settings['db_username'],
            $settings['db_password'],
        );

        $settings['db_config_type'] = in_array(
            (string) ($settings['db_config_type'] ?? 'auto'),
            ['auto', 'manual'],
            true,
        ) ? (string) $settings['db_config_type'] : 'auto';

        $data['settings'] = $settings;

        return $data;
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
        $ownerId = self::resolveOwnerIdFromFormData($data, $existing);

        if ($ownerId <= 0) {
            throw ValidationException::withMessages([
                'bound_type' => 'Phải chọn site hoặc owner hợp lệ để cấu hình database SEO.',
            ]);
        }

        $siteId = (int) ($data['site_id'] ?? $existing?->site_id ?? 0);
        $type = (string) ($settings['db_config_type'] ?? 'auto');

        if ($type === 'manual') {
            self::assertManualConnectionExistsForOwner($ownerId);

            return;
        }

        try {
            $attributes = $db->mapSiteServiceSettingsToConnectionAttributes($settings, max(0, $siteId));
            $db->testConnectionFromAttributes($attributes);
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

        try {
            $connection = $db->syncConnectionFromSiteService($record);
            $result = $db->runMigrationsForConnection($connection);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Lỗi cấu hình database SEO')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if (! ($result['executed'] ?? false)) {
            $reconciled = (int) ($result['reconciled'] ?? 0);
            $body = $reconciled > 0
                ? sprintf('Database SEO đã kết nối. Đã đồng bộ %d migration CREATE có sẵn trên DB.', $reconciled)
                : 'Database SEO đã kết nối. Không có migration còn thiếu.';

            Notification::make()
                ->title('Đã kích hoạt SEO Content AI')
                ->body($body)
                ->success()
                ->send();

            return;
        }

        $reconciled = (int) ($result['reconciled'] ?? 0);
        $body = sprintf('Đã áp dụng %d migration còn thiếu.', (int) ($result['pending'] ?? 0));
        if ($reconciled > 0) {
            $body .= sprintf(' Đồng bộ thêm %d migration CREATE đã có bảng trên DB.', $reconciled);
        }

        Notification::make()
            ->title('Database SEO đã sẵn sàng')
            ->body($body)
            ->success()
            ->send();
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        $connectionsUrl = SeoDatabaseConnectionResource::getUrl('index');

        return [
            Forms\Components\Section::make('Cấu hình Database SEO Content AI')
                ->description('Site service chỉ chọn chế độ DB. Tạo kết nối cụ thể (host, user, password) tại SEO Database Connections.')
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

                                return "Dùng MySQL credentials từ .env core, database: {$prefix}_{$siteId}. Hệ thống tự đồng bộ bản ghi SEO Database Connection khi lưu.";
                            }

                            return "Dùng MySQL credentials từ .env core, database dùng chung: {$legacy}. Hệ thống tự đồng bộ bản ghi SEO Database Connection khi lưu.";
                        }),

                    Forms\Components\Placeholder::make('db_manual_hint')
                        ->label('Chế độ thủ công')
                        ->visible(fn (Get $get): bool => ($get('settings.db_config_type') ?? '') === 'manual')
                        ->content(fn (): HtmlString => new HtmlString(
                            'Trước khi lưu, tạo kết nối DB cho owner của site tại '
                            .'<a href="'.e($connectionsUrl).'" class="text-primary-600 underline font-medium" target="_blank" rel="noopener">SEO Database Connections</a>. '
                            .'Site service chỉ ghi nhận chế độ <strong>manual</strong>; host, port, database, user và password cấu hình riêng ở trang đó.'
                        )),
                ])
                ->columns(1),
        ];
    }

    private static function assertManualConnectionExistsForOwner(int $ownerId): void
    {
        if ($ownerId <= 0) {
            throw ValidationException::withMessages([
                'bound_type' => 'Owner không hợp lệ để dùng chế độ DB thủ công.',
            ]);
        }

        $connection = app(SeoDatabaseConnectionService::class)->resolveActiveConnectionForOwner($ownerId);

        if ($connection === null) {
            throw ValidationException::withMessages([
                'settings.db_config_type' => 'Chưa có SEO Database Connection cho owner này. Tạo tại SEO Database Connections trước khi lưu.',
            ]);
        }

        try {
            app(SeoDatabaseConnectionService::class)->testConnectionForModel($connection);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'settings.db_config_type' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveOwnerIdFromFormData(array $data, ?SiteService $existing): int
    {
        $boundType = (string) ($data['bound_type'] ?? $existing?->bound_type ?? SiteServiceBindingService::BOUND_SITE);

        if ($boundType === SiteServiceBindingService::BOUND_USER) {
            return (int) ($data['user_id'] ?? $existing?->user_id ?? 0);
        }

        $siteId = (int) ($data['site_id'] ?? $existing?->site_id ?? 0);
        if ($siteId <= 0) {
            return 0;
        }

        return (int) (Site::query()->whereKey($siteId)->value('user_id') ?? 0);
    }

    private static function isSeoServiceSelected(mixed $serviceId): bool
    {
        if (! is_numeric($serviceId)) {
            return false;
        }

        return app(SeoDatabaseConnectionService::class)->isSeoContentAiService((int) $serviceId);
    }
}
