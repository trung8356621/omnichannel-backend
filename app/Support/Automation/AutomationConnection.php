<?php

declare(strict_types=1);

namespace App\Support\Automation;

use Illuminate\Support\Facades\DB;

final class AutomationConnection
{
    public static function name(): string
    {
        $configured = config('automation.connection');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) config('database.core_connection', config('database.default', 'mysql'));
    }

    public static function source(): string
    {
        return (string) config('automation.source_connection', 'omi_seo_ai');
    }

    public static function target(): string
    {
        return (string) config(
            'automation.target_connection',
            config('database.core_connection', 'mysql')
        );
    }

    /**
     * @return \Illuminate\Database\Connection
     */
    public static function db(): \Illuminate\Database\Connection
    {
        return DB::connection(self::name());
    }
}
