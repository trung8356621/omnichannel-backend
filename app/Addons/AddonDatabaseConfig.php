<?php

declare(strict_types=1);

namespace App\Addons;

use Illuminate\Support\Facades\Config;

/**
 * Cấu hình DB tự chứa trong từng addon — không cần sửa .env core.
 *
 * - addon.json → "database": { connection, name, host, port, username } (commit được, không ghi password)
 * - database.local.php → override host/user/password/name (gitignore, dùng trên hosting)
 * - Legacy: "database": "omi_seo_ai" (chuỗi) → clone mysql + đổi tên DB
 */
final class AddonDatabaseConfig
{
    private const LOCAL_FILE = 'database.local.php';

    /**
     * @param  array<string, mixed>  $meta  Nội dung addon.json
     * @return array<string, mixed>|null Laravel connection array hoặc null nếu addon không dùng DB riêng
     */
    public static function resolveConnection(array $meta, string $fallbackConnectionName): ?array
    {
        $parsed = self::parseDatabaseMeta($meta);
        if ($parsed === null) {
            return null;
        }

        $connectionName = $parsed['connection'] !== ''
            ? $parsed['connection']
            : $fallbackConnectionName;

        if ($parsed['legacy_string_only']) {
            $base = Config::get('database.connections.mysql', []);

            return array_merge($base, [
                'database' => $parsed['name'],
            ]);
        }

        $base = Config::get('database.connections.mysql', []);
        $merged = array_merge($base, array_filter([
            'driver' => 'mysql',
            'host' => $parsed['host'],
            'port' => $parsed['port'],
            'database' => $parsed['name'],
            'username' => $parsed['username'],
            'password' => $parsed['password'],
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $localPath = self::localConfigPath((string) ($meta['_addon_path'] ?? ''));
        if ($localPath !== null) {
            $local = self::loadLocalConfig($localPath);
            $merged = self::applyLocalOverrides($merged, $local);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function databaseNameFromMeta(array $meta): ?string
    {
        $parsed = self::parseDatabaseMeta($meta);
        if ($parsed === null) {
            return null;
        }

        $name = $parsed['name'];
        $addonPath = (string) ($meta['_addon_path'] ?? '');
        $localPath = self::localConfigPath($addonPath);
        if ($localPath !== null) {
            $local = self::loadLocalConfig($localPath);
            $localName = trim((string) ($local['name'] ?? $local['database'] ?? ''));
            if ($localName !== '') {
                $name = $localName;
            }
        }

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function enrichMetaWithAddonPath(array $meta, string $slug): array
    {
        $path = self::addonPathFromSlug($slug);
        if ($path !== null) {
            $meta['_addon_path'] = $path;
        }

        return $meta;
    }

    public static function addonPathFromSlug(string $slug): ?string
    {
        $base = app_path('Addons');
        if (! is_dir($base)) {
            return null;
        }

        foreach (glob($base.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $dir) {
            $jsonPath = $dir.DIRECTORY_SEPARATOR.'addon.json';
            if (! is_file($jsonPath)) {
                continue;
            }

            $raw = file_get_contents($jsonPath);
            if ($raw === false) {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }

            if (($decoded['slug'] ?? '') === $slug) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{connection: string, name: string, host: ?string, port: ?string, username: ?string, password: ?string, legacy_string_only: bool}|null
     */
    private static function parseDatabaseMeta(array $meta): ?array
    {
        $raw = $meta['database'] ?? null;

        if (is_string($raw) && trim($raw) !== '') {
            $connection = trim((string) ($meta['database_connection'] ?? $raw));

            return [
                'connection' => $connection,
                'name' => trim($raw),
                'host' => null,
                'port' => null,
                'username' => null,
                'password' => null,
                'legacy_string_only' => true,
            ];
        }

        if (! is_array($raw)) {
            return null;
        }

        $name = trim((string) ($raw['name'] ?? $raw['database'] ?? ''));
        if ($name === '') {
            return null;
        }

        $connection = trim((string) ($raw['connection'] ?? $meta['database_connection'] ?? $name));
        $password = array_key_exists('password', $raw) ? (string) $raw['password'] : null;

        return [
            'connection' => $connection,
            'name' => $name,
            'host' => self::nullableString($raw['host'] ?? null),
            'port' => self::nullableString($raw['port'] ?? null),
            'username' => self::nullableString($raw['username'] ?? null),
            'password' => $password,
            'legacy_string_only' => false,
        ];
    }

    private static function localConfigPath(string $addonPath): ?string
    {
        $addonPath = rtrim($addonPath, '/\\');
        if ($addonPath === '') {
            return null;
        }

        $path = $addonPath.DIRECTORY_SEPARATOR.self::LOCAL_FILE;

        return is_file($path) ? $path : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadLocalConfig(string $path): array
    {
        $config = require $path;

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>
     */
    private static function applyLocalOverrides(array $connection, array $local): array
    {
        $map = [
            'host' => 'host',
            'port' => 'port',
            'username' => 'username',
            'password' => 'password',
            'name' => 'database',
            'database' => 'database',
            'unix_socket' => 'unix_socket',
        ];

        foreach ($map as $localKey => $connectionKey) {
            if (! array_key_exists($localKey, $local)) {
                continue;
            }

            $value = $local[$localKey];
            if ($value === null || $value === '') {
                continue;
            }

            $connection[$connectionKey] = $value;
        }

        return $connection;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
