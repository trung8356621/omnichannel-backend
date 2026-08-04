<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bảng wp_options trên DB Laravel (omi_channel / connection mặc định) — không phải DB WordPress site.
 */
class WpOption extends Model
{
    protected $table = 'wp_options';

    protected $fillable = [
        'option_name',
        'option_value',
        'autoload',
    ];

    /** @var array<string, mixed> Request-scoped memo (process-local; cleared on set). */
    private static array $requestCache = [];

    public static function get(string $name, mixed $default = null): mixed
    {
        if (array_key_exists($name, self::$requestCache)) {
            return self::$requestCache[$name];
        }

        $row = self::where('option_name', $name)->first();
        if ($row === null || $row->option_value === null) {
            return self::$requestCache[$name] = $default;
        }
        $v = $row->option_value;
        if (is_string($v) && ($v === '' || $v[0] !== '{')) {
            return self::$requestCache[$name] = $v;
        }
        $decoded = @json_decode($v, true);

        return self::$requestCache[$name] = ($decoded !== null ? $decoded : $v);
    }

    public static function set(string $name, mixed $value, string $autoload = 'no'): void
    {
        $serialized = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        self::updateOrCreate(
            ['option_name' => $name],
            ['option_value' => $serialized, 'autoload' => $autoload]
        );

        unset(self::$requestCache[$name]);
    }

    /**
     * Test/helper: clear request memo between isolated assertions.
     */
    public static function clearRequestCache(): void
    {
        self::$requestCache = [];
    }
}
