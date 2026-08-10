<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Local CLI helper: keep SEO database name, reuse working mysql server credentials
 * when the dedicated SEO DB user uses mysql_native_password on servers that removed the plugin.
 */
final class SeoCliConnectionFixer
{
    /**
     * @param  callable(string):void|null  $notify
     */
    public function ensureReachable(string $seoConnection, bool $forceViaMysql = false, ?callable $notify = null): void
    {
        if ($forceViaMysql) {
            $this->pinViaMysql($seoConnection, $notify);
            $this->assertPdo($seoConnection);

            return;
        }

        try {
            $this->assertPdo($seoConnection);

            return;
        } catch (Throwable $e) {
            if (! $this->isNativePasswordPluginError($e)) {
                throw $e;
            }
            $this->notify(
                $notify,
                'SEO connection failed (mysql_native_password plugin missing). '
                .'Falling back to mysql credentials + SEO database name for this CLI run.'
            );
            $this->pinViaMysql($seoConnection, $notify);
            $this->assertPdo($seoConnection);
        }
    }

    /**
     * @param  callable(string):void|null  $notify
     */
    public function pinViaMysql(string $seoConnection, ?callable $notify = null): void
    {
        $mysql = config('database.connections.mysql', []);
        if (! is_array($mysql) || $mysql === []) {
            throw new \RuntimeException('mysql connection config is empty; cannot pin SEO CLI connection.');
        }

        $seoDb = (string) config('database.connections.'.$seoConnection.'.database', 'omi_seo_ai');
        $merged = $mysql;
        $merged['database'] = $seoDb;

        config(['database.connections.'.$seoConnection => $merged]);
        DB::purge($seoConnection);

        $user = (string) ($merged['username'] ?? '');
        $this->notify($notify, "Pinned [{$seoConnection}] → database [{$seoDb}] via mysql user [{$user}].");
    }

    public function isNativePasswordPluginError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'mysql_native_password')
            || str_contains($message, '1524');
    }

    /**
     * @param  callable(string):void|null  $notify
     */
    private function notify(?callable $notify, string $message): void
    {
        if ($notify !== null) {
            $notify($message);
        }
    }

    private function assertPdo(string $connection): void
    {
        DB::connection($connection)->getPdo();
    }
}
