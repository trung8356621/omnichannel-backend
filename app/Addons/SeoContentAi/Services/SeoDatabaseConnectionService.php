<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use App\Addons\SeoContentAi\Support\SeoMigrationReconciler;
use App\Models\SeoDatabaseConnection;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;

final class SeoDatabaseConnectionService
{
    public const CONNECTION_NAME = 'omi_seo_ai';

    /** @var array<string, string> hash => config fingerprint */
    private static array $bootstrappedHashes = [];

    public function connectionName(): string
    {
        return (string) config('seo-content-ai.connection', self::CONNECTION_NAME);
    }

    public function serviceSlug(): string
    {
        return (string) config('seo-content-ai.service_slug', 'seo-content-ai');
    }

    public function bootstrapByHash(string $hashId): SeoDatabaseConnection
    {
        if (! SeoConnectionContext::isValidHashFormat($hashId)) {
            throw new RuntimeException('Mã định danh kết nối SEO không hợp lệ.');
        }

        $connection = SeoDatabaseConnection::query()
            ->where('hash_id', $hashId)
            ->where('is_active', true)
            ->first();

        if ($connection === null) {
            throw new RuntimeException('Không tìm thấy kết nối cơ sở dữ liệu SEO hợp lệ.');
        }

        $this->bootstrapFromConnection($connection);

        return $connection;
    }

    public function bootstrapFromConnection(SeoDatabaseConnection $connection): void
    {
        $config = $this->resolveConnectionArrayFromModel($connection);
        $fingerprint = md5(json_encode($config));
        $hash = (string) $connection->hash_id;

        if ((self::$bootstrappedHashes[$hash] ?? null) === $fingerprint) {
            SeoConnectionContext::set($connection);

            return;
        }

        Config::set('database.connections.'.$this->connectionName(), $config);
        DB::purge($this->connectionName());

        self::$bootstrappedHashes[$hash] = $fingerprint;
        SeoConnectionContext::set($connection);
    }

    public function bootstrapBySiteId(int $siteId): ?SeoDatabaseConnection
    {
        if ($siteId <= 0) {
            return null;
        }

        $site = Site::query()->find($siteId);
        if ($site === null) {
            $this->bootstrapLegacySharedConnection();

            return null;
        }

        $ownerId = (int) $site->user_id;
        if ($ownerId <= 0) {
            $this->bootstrapLegacySharedConnection();

            return null;
        }

        $connection = SeoDatabaseConnection::query()
            ->where('is_active', true)
            ->whereHas('users', fn (Builder $query): Builder => $query->where('users.id', $ownerId))
            ->orderBy('id')
            ->first();

        if ($connection === null) {
            $siteService = $this->findSiteService($siteId);
            if ($siteService !== null) {
                $fromService = $this->connectionFromSiteService($siteService);
                if ($fromService !== null) {
                    $this->bootstrapFromConnection($fromService);

                    return $fromService;
                }
            }

            $this->bootstrapLegacySharedConnection();

            return null;
        }

        $this->bootstrapFromConnection($connection);

        return $connection;
    }

    public function bootstrapSeoDatabaseConnection(int $siteId): void
    {
        $this->bootstrapBySiteId($siteId);
    }

    public function bootstrapByConnectionId(int $connectionId): SeoDatabaseConnection
    {
        $connection = SeoDatabaseConnection::query()
            ->whereKey($connectionId)
            ->first();

        if ($connection === null) {
            throw new RuntimeException('Không tìm thấy kết nối database SEO.');
        }

        $this->bootstrapFromConnection($connection);

        return $connection;
    }

    public function bootstrapLegacySharedConnection(): void
    {
        $mysql = Config::get('database.connections.mysql', []);
        if ($mysql === []) {
            return;
        }

        $legacyDatabase = (string) config('seo-content-ai.legacy_shared_database', 'omi_seo_ai');
        $config = $this->mergeMysqlBase($mysql, ['database' => $legacyDatabase]);
        $fingerprint = md5(json_encode($config));

        if ((self::$bootstrappedHashes['_legacy'] ?? null) === $fingerprint) {
            return;
        }

        Config::set('database.connections.'.$this->connectionName(), $config);
        DB::purge($this->connectionName());
        self::$bootstrappedHashes['_legacy'] = $fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConnectionArrayFromModel(SeoDatabaseConnection $connection): array
    {
        if ($connection->isManual()) {
            return $this->buildManualConnectionFromModel($connection);
        }

        return $this->buildAutoConnectionFromModel($connection);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function testConnectionFromAttributes(array $attributes, ?string $plainPasswordOverride = null): void
    {
        $connection = new SeoDatabaseConnection($attributes);
        $connection->id = (int) ($attributes['id'] ?? 0);

        if ($plainPasswordOverride !== null && $plainPasswordOverride !== '') {
            $connection->password = $plainPasswordOverride;
        }

        $this->assertConnectionWorks($this->resolveConnectionArrayFromModel($connection));
    }

    public function testConnectionForModel(SeoDatabaseConnection $connection, ?string $plainPasswordOverride = null): void
    {
        if ($plainPasswordOverride !== null && $plainPasswordOverride !== '') {
            $clone = clone $connection;
            $clone->password = $plainPasswordOverride;
            $this->assertConnectionWorks($this->resolveConnectionArrayFromModel($clone));

            return;
        }

        $this->assertConnectionWorks($this->resolveConnectionArrayFromModel($connection));
    }

    /**
     * @return array{pending: int, executed: bool, reconciled: int}
     */
    public function runMigrationsForConnection(SeoDatabaseConnection $connection): array
    {
        $this->bootstrapFromConnection($connection);

        $relativePath = (string) config('seo-content-ai.migrations_path', 'app/Addons/SeoContentAi/database/migrations');
        $absolutePath = base_path(str_replace('\\', '/', $relativePath));

        /** @var Migrator $migrator */
        $migrator = app(Migrator::class);
        $migrator->setConnection($this->connectionName());

        $files = $migrator->getMigrationFiles($absolutePath);
        $reconciled = app(SeoMigrationReconciler::class)->reconcileExistingCreateTables(
            $migrator,
            $this->connectionName(),
            $files,
        );

        $pending = array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

        if ($pending === []) {
            return ['pending' => 0, 'executed' => false, 'reconciled' => $reconciled];
        }

        Artisan::call('migrate', [
            '--database' => $this->connectionName(),
            '--path' => $relativePath,
            '--force' => true,
        ]);

        return ['pending' => count($pending), 'executed' => true, 'reconciled' => $reconciled];
    }

    public function countPendingMigrations(SeoDatabaseConnection $connection): int
    {
        $this->bootstrapFromConnection($connection);

        $relativePath = (string) config('seo-content-ai.migrations_path', 'app/Addons/SeoContentAi/database/migrations');
        $absolutePath = base_path(str_replace('\\', '/', $relativePath));

        /** @var Migrator $migrator */
        $migrator = app(Migrator::class);
        $migrator->setConnection($this->connectionName());

        $files = $migrator->getMigrationFiles($absolutePath);

        return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }

    public function runMigrationsForSite(int $siteId): void
    {
        $connection = $this->bootstrapBySiteId($siteId);
        if ($connection === null) {
            return;
        }

        $this->runMigrationsForConnection($connection);
    }

    public function resolveRedirectHash(?User $user = null): ?string
    {
        $query = SeoDatabaseConnection::query()
            ->where('is_active', true)
            ->orderBy('id');

        if ($user instanceof User && $user->role !== User::ROLE_ADMIN) {
            $ownerId = $this->resolveOwnerIdForUser($user);
            if ($ownerId <= 0) {
                return null;
            }

            $query->whereHas('users', fn (Builder $builder): Builder => $builder->where('users.id', $ownerId));
        }

        return $query->value('hash_id');
    }

    public function userCanAccessConnection(?User $user, SeoDatabaseConnection $connection): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->role === User::ROLE_ADMIN) {
            return true;
        }

        $ownerId = $this->resolveOwnerIdForUser($user);
        if ($ownerId <= 0) {
            return false;
        }

        return $connection->users()->where('users.id', $ownerId)->exists();
    }

    public function resolveOwnerIdForUser(User $user): int
    {
        if ($user->isStaff() && (int) $user->parent_id > 0) {
            return (int) $user->parent_id;
        }

        return (int) $user->id;
    }

    /**
     * @return list<int>
     */
    public function accessibleSiteIdsForUser(User $user): array
    {
        if ($user->role === User::ROLE_ADMIN) {
            return Site::query()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        $ownerId = $user->isStaff() && (int) $user->parent_id > 0
            ? (int) $user->parent_id
            : (int) $user->id;

        return Site::query()
            ->where('user_id', $ownerId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function findSiteService(int $siteId): ?SiteService
    {
        if ($siteId <= 0) {
            return null;
        }

        return SiteService::query()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->whereHas('service', function ($query): void {
                $query->where('slug', $this->serviceSlug());
            })
            ->first();
    }

    public function isSeoContentAiService(?int $serviceId): bool
    {
        if ($serviceId === null || $serviceId <= 0) {
            return false;
        }

        return Service::query()
            ->whereKey($serviceId)
            ->where('slug', $this->serviceSlug())
            ->exists();
    }

    public function syncConnectionFromSiteService(SiteService $record): SeoDatabaseConnection
    {
        if (! $this->isSeoContentAiService((int) $record->service_id)) {
            throw new RuntimeException('Dịch vụ không phải SEO Content AI.');
        }

        $site = Site::query()->find((int) $record->site_id);
        $ownerId = app(SiteServiceBindingService::class)->resolveOwnerId($record);
        if ($ownerId <= 0) {
            throw new RuntimeException('Site service chưa gán owner hợp lệ.');
        }

        $settings = is_array($record->settings) ? $record->settings : [];
        $type = (string) ($settings['db_config_type'] ?? 'auto');

        if ($type === 'manual') {
            $connection = $this->resolveActiveConnectionForOwner($ownerId);
            if ($connection === null) {
                throw new RuntimeException(
                    'Chưa có SEO Database Connection cho owner của site này. Tạo tại SEO Database Connections.',
                );
            }

            return $connection;
        }

        $attributes = $this->mapSiteServiceSettingsToConnectionRecord(
            $settings,
            (int) ($site?->id ?? 0),
            (string) ($site?->domain ?? ('Owner #'.$ownerId)),
        );

        $connection = SeoDatabaseConnection::query()
            ->whereHas('users', fn (Builder $query): Builder => $query->where('users.id', $ownerId))
            ->orderBy('id')
            ->first();

        if ($connection === null) {
            $connection = new SeoDatabaseConnection($attributes);
            $connection->save();
            $connection->users()->sync([$ownerId]);

            return $connection->fresh() ?? $connection;
        }

        $connection->fill($attributes);
        $connection->save();

        if (! $connection->users()->where('users.id', $ownerId)->exists()) {
            $connection->users()->attach($ownerId);
        }

        return $connection->fresh() ?? $connection;
    }

    public function resolveActiveConnectionForOwner(int $ownerId): ?SeoDatabaseConnection
    {
        if ($ownerId <= 0) {
            return null;
        }

        return SeoDatabaseConnection::query()
            ->where('is_active', true)
            ->whereHas('users', fn (Builder $query): Builder => $query->where('users.id', $ownerId))
            ->orderBy('id')
            ->first();
    }

    public function connectionFromSiteService(SiteService $siteService): ?SeoDatabaseConnection
    {
        $settings = is_array($siteService->settings) ? $siteService->settings : [];
        if ($settings === []) {
            return null;
        }

        $siteId = (int) $siteService->site_id;
        $site = $siteId > 0 ? Site::query()->find($siteId) : null;
        if ($site === null && ! $siteService->isBoundToUser()) {
            return null;
        }

        $type = (string) ($settings['db_config_type'] ?? 'auto');

        if ($type === 'manual') {
            return $this->resolveActiveConnectionForOwner(app(SiteServiceBindingService::class)->resolveOwnerId($siteService));
        }

        $domain = $site !== null ? (string) $site->domain : ('Owner #'.(int) $siteService->user_id);
        $attributes = $this->mapSiteServiceSettingsToConnectionRecord($settings, $siteId, $domain);
        $connection = new SeoDatabaseConnection($attributes);
        $connection->id = 0;
        if (blank($connection->hash_id)) {
            $connection->hash_id = hash('sha256', 'site_service_auto:'.$siteId);
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function mapSiteServiceSettingsToConnectionRecord(array $settings, int $siteId, string $domainLabel = ''): array
    {
        $type = (string) ($settings['db_config_type'] ?? 'auto');
        $name = $domainLabel !== '' ? 'SEO DB — '.$domainLabel : 'SEO DB site #'.$siteId;

        if ($type === 'manual') {
            throw new RuntimeException(
                'Chế độ manual không cấu hình DB trên Site Service. Dùng SEO Database Connections.',
            );
        }

        $legacy = (string) config('seo-content-ai.legacy_shared_database', 'omi_seo_ai');
        $perSite = (bool) config('seo-content-ai.auto_per_site_database', false);
        $database = $perSite && $siteId > 0
            ? config('seo-content-ai.auto_database_prefix', 'omi_seo_ai').'_'.$siteId
            : $legacy;

        return [
            'name' => $name,
            'type' => 'auto',
            'host' => null,
            'port' => null,
            'database' => $database,
            'username' => null,
            'password' => null,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function mapSiteServiceSettingsToConnectionAttributes(array $settings, int $siteId): array
    {
        $record = $this->mapSiteServiceSettingsToConnectionRecord($settings, $siteId);

        return [
            'type' => $record['type'],
            'host' => $record['host'],
            'port' => $record['port'],
            'database' => $record['database'],
            'username' => $record['username'],
        ];
    }

    public function encryptPassword(?string $password): ?string
    {
        if ($password === null || trim($password) === '') {
            return null;
        }

        return Crypt::encryptString(trim($password));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAutoConnectionFromModel(SeoDatabaseConnection $connection): array
    {
        $mysql = Config::get('database.connections.mysql', []);
        if ($mysql === []) {
            throw new RuntimeException('Không tìm thấy cấu hình database.connections.mysql.');
        }

        $database = trim((string) ($connection->database ?? ''));
        if ($database === '') {
            $database = 'omi_seo_ai_auto_'.$connection->getKey();
        }

        return $this->mergeMysqlBase($mysql, ['database' => $database]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManualConnectionFromModel(SeoDatabaseConnection $connection): array
    {
        $database = trim((string) ($connection->database ?? ''));
        $username = trim((string) ($connection->username ?? ''));

        if ($database === '' || $username === '') {
            throw new RuntimeException('Cấu hình DB thủ công thiếu tên database hoặc username.');
        }

        $mysql = Config::get('database.connections.mysql', []);

        return $this->mergeMysqlBase($mysql, [
            'host' => filled($connection->host) ? (string) $connection->host : '127.0.0.1',
            'port' => filled($connection->port) ? (string) $connection->port : '3306',
            'database' => $database,
            'username' => $username,
            'password' => (string) ($connection->password ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $mysql
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeMysqlBase(array $mysql, array $overrides): array
    {
        return array_merge($mysql, [
            'driver' => 'mysql',
            'charset' => $mysql['charset'] ?? 'utf8mb4',
            'collation' => $mysql['collation'] ?? 'utf8mb4_unicode_ci',
            'prefix' => $mysql['prefix'] ?? '',
            'strict' => $mysql['strict'] ?? true,
            'engine' => $mysql['engine'] ?? null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function assertConnectionWorks(array $connection): void
    {
        $name = $this->connectionName().'_test_'.md5(json_encode($connection));

        Config::set('database.connections.'.$name, $connection);
        DB::purge($name);

        try {
            DB::connection($name)->getPdo();
        } catch (PDOException $exception) {
            DB::purge($name);

            throw new RuntimeException(
                'Không kết nối được tới database SEO: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        DB::purge($name);
    }
}
