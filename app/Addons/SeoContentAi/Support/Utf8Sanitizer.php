<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

/**
 * Loại bỏ byte UTF-8 lỗi — tránh json_encode / Http::post báo "Malformed UTF-8".
 */
final class Utf8Sanitizer
{
    public static function string(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            } else {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        }

        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = is_string($cleaned) ? $cleaned : $value;

        // mb_check_encoding có thể pass trong khi json_encode (Http::post) vẫn báo Malformed UTF-8.
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded !== false) {
            $decoded = json_decode($encoded);
            if (is_string($decoded)) {
                $value = $decoded;
            }
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public static function variables(array $variables): array
    {
        $normalized = [];

        foreach ($variables as $key => $value) {
            $normalized[(string) $key] = self::string(is_string($value) ? $value : (string) $value);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function arrayDeep(array $payload): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $normalized[$key] = self::string($value);
            } elseif (is_array($value)) {
                $normalized[$key] = self::arrayDeep($value);
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
