<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * So sánh database vật lý giữa Laravel connections — không so password.
 */
final class DatabasePhysicalIdentity
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function fingerprint(array $config): string
    {
        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');

        if ($driver === 'sqlite') {
            $database = self::normalizeSqlitePath($database);
        }

        $parts = [
            'driver' => $driver,
            'host' => (string) ($config['host'] ?? ''),
            'port' => (string) ($config['port'] ?? ''),
            'database' => $database,
            'unix_socket' => (string) ($config['unix_socket'] ?? ''),
        ];

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{driver: string, host: string, port: string, database: string}
     */
    public static function safeSummary(array $config): array
    {
        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');
        if ($driver === 'sqlite') {
            $database = self::normalizeSqlitePath($database);
        }

        return [
            'driver' => $driver,
            'host' => (string) ($config['host'] ?? ''),
            'port' => (string) ($config['port'] ?? ''),
            'database' => $database,
        ];
    }

    public static function samePhysicalDatabase(array $a, array $b): bool
    {
        return self::fingerprint($a) === self::fingerprint($b);
    }

    private static function normalizeSqlitePath(string $database): string
    {
        if ($database === ':memory:' || $database === '') {
            return $database;
        }

        $real = realpath($database);

        return is_string($real) ? $real : $database;
    }
}
