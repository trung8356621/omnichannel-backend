<?php

declare(strict_types=1);

namespace App\Addons;

use Illuminate\Support\Facades\Config;

trait RegistersAddonDatabase
{
    /**
     * Đăng ký connection DB từ addon.json (+ database.local.php tùy chọn) và load migrations.
     *
     * @param  string  $addonPath  Thư mục addon (thường __DIR__)
     * @param  string  $connectionName  Tên connection runtime (vd: omi_seo_ai)
     * @param  string|null  $migrationsPath  Thư mục migrations addon
     */
    protected function registerAddonDatabase(string $addonPath, string $connectionName, ?string $migrationsPath = null): void
    {
        $meta = $this->getAddonMetaFromPath($addonPath);
        if ($meta === []) {
            return;
        }

        $meta['_addon_path'] = $addonPath;

        $connection = AddonDatabaseConfig::resolveConnection($meta, $connectionName);
        if ($connection === null) {
            return;
        }

        Config::set('database.connections.'.$connectionName, $connection);

        if ($migrationsPath !== null && is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getAddonMetaFromPath(string $addonPath): array
    {
        $path = rtrim($addonPath, '/\\').DIRECTORY_SEPARATOR.'addon.json';
        if (! is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
