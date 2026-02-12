<?php

declare(strict_types=1);

namespace App\Addons;

use Illuminate\Support\Facades\Config;

trait RegistersAddonDatabase
{
    /**
     * Đọc addon.json; nếu có key "database" thì đăng ký connection và (tùy chọn) load migrations.
     *
     * @param string $addonPath Đường dẫn thư mục addon (thường __DIR__)
     * @param string $connectionName Tên connection (vd: wp_headless)
     * @param string|null $migrationsPath Đường dẫn thư mục migrations (null = không load)
     */
    protected function registerAddonDatabase(string $addonPath, string $connectionName, ?string $migrationsPath = null): void
    {
        $meta = $this->getAddonMetaFromPath($addonPath);
        $database = $meta['database'] ?? null;

        if ($database === null || $database === '') {
            return;
        }

        $base = Config::get('database.connections.mysql', []);
        Config::set('database.connections.' . $connectionName, array_merge($base, [
            'database' => $database,
        ]));

        if ($migrationsPath !== null && is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    private function getAddonMetaFromPath(string $addonPath): array
    {
        $path = rtrim($addonPath, '/\\') . DIRECTORY_SEPARATOR . 'addon.json';
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
