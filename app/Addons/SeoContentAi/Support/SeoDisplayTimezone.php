<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use Carbon\Carbon;

/**
 * Múi giờ hiển thị / lên lịch publish trong panel SEO (khác app.timezone UTC).
 */
final class SeoDisplayTimezone
{
    public static function name(): string
    {
        $configured = trim((string) config('seo-content-ai.display_timezone', 'Asia/Ho_Chi_Minh'));

        return $configured !== '' ? $configured : 'Asia/Ho_Chi_Minh';
    }

    public static function now(): Carbon
    {
        return now(self::name());
    }

    public static function parse(?string $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone(self::name());
        } catch (\Throwable) {
            return null;
        }
    }

    public static function format(?string $value, string $format = 'd/m/Y H:i'): ?string
    {
        $parsed = self::parse($value);

        return $parsed?->format($format);
    }

    public static function formatScheduleLabel(Carbon $dateTime): string
    {
        $dt = $dateTime->copy()->timezone(self::name());
        $weekdayMap = [
            0 => 'CN',
            1 => 'Th2',
            2 => 'Th3',
            3 => 'Th4',
            4 => 'Th5',
            5 => 'Th6',
            6 => 'Th7',
        ];

        $weekday = $weekdayMap[(int) $dt->dayOfWeek] ?? 'Th';

        return sprintf(
            '%s %d, %d at %02d:%02d',
            $weekday,
            (int) $dt->day,
            (int) $dt->year,
            (int) $dt->hour,
            (int) $dt->minute,
        );
    }
}
