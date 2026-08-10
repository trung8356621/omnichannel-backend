<?php

declare(strict_types=1);

namespace App\Core\Operations;

use Illuminate\Support\Str;

/**
 * Correlation ID for ops tracing across HTTP / queue / commands.
 */
final class CorrelationId
{
    private static ?string $current = null;

    public static function currentOrNew(): string
    {
        if (self::$current !== null && self::$current !== '') {
            return self::$current;
        }

        return self::set(self::generate());
    }

    public static function get(): ?string
    {
        return self::$current;
    }

    public static function set(string $id): string
    {
        self::$current = $id;

        return $id;
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    public static function generate(): string
    {
        if (class_exists(Str::class)) {
            return (string) Str::uuid();
        }

        return bin2hex(random_bytes(16));
    }
}
