<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bảng wp_options (database chính) – lưu tùy chỉnh/options kiểu WordPress.
 */
class WpOption extends Model
{
    protected $table = 'wp_options';

    protected $fillable = [
        'option_name',
        'option_value',
        'autoload',
    ];

    public static function get(string $name, mixed $default = null): mixed
    {
        $row = self::where('option_name', $name)->first();
        if ($row === null || $row->option_value === null) {
            return $default;
        }
        $v = $row->option_value;
        if (is_string($v) && ($v === '' || $v[0] !== '{')) {
            return $v;
        }
        $decoded = @json_decode($v, true);
        return $decoded !== null ? $decoded : $v;
    }

    public static function set(string $name, mixed $value, string $autoload = 'no'): void
    {
        $serialized = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        self::updateOrCreate(
            ['option_name' => $name],
            ['option_value' => $serialized, 'autoload' => $autoload]
        );
    }
}
