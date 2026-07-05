<?php

declare(strict_types=1);

namespace App\Support;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

final class ImageDriverResolver
{
    public const DRIVER_IMAGICK = 'imagick';

    public const DRIVER_GD = 'gd';

    public const ENCODE_QUALITY = 95;

    public static function supportsImagick(): bool
    {
        return extension_loaded('imagick');
    }

    public static function supportsGd(): bool
    {
        return extension_loaded('gd');
    }

    public static function hasAnyDriver(): bool
    {
        return self::supportsImagick() || self::supportsGd();
    }

    public static function driverName(): string
    {
        return self::interventionDriverClass() === ImagickDriver::class
            ? self::DRIVER_IMAGICK
            : self::DRIVER_GD;
    }

    /**
     * Intervention Image v4 driver class — ưu tiên Imagick khi extension có sẵn.
     */
    public static function interventionDriverClass(): string
    {
        $requested = self::normalizeRequestedDriver(env('IMAGE_DRIVER'));

        if ($requested === self::DRIVER_IMAGICK) {
            return self::supportsImagick()
                ? ImagickDriver::class
                : self::resolveGdDriverClass();
        }

        if ($requested === self::DRIVER_GD) {
            return self::resolveGdDriverClass();
        }

        if (is_string($requested) && class_exists($requested)) {
            if ($requested === ImagickDriver::class && ! self::supportsImagick()) {
                return self::resolveGdDriverClass();
            }

            return $requested;
        }

        if (self::supportsImagick()) {
            return ImagickDriver::class;
        }

        return self::resolveGdDriverClass();
    }

    public static function shouldUseNativeImagickPipeline(): bool
    {
        return self::supportsImagick();
    }

    private static function normalizeRequestedDriver(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        return match ($value) {
            'imagick', 'imagemagick', str_replace('\\', '', strtolower(ImagickDriver::class)) => self::DRIVER_IMAGICK,
            'gd', 'gdlib', str_replace('\\', '', strtolower(GdDriver::class)) => self::DRIVER_GD,
            default => $value,
        };
    }

    private static function resolveGdDriverClass(): string
    {
        if (! self::supportsGd()) {
            throw new \RuntimeException(
                'Không tìm thấy extension xử lý ảnh PHP nào (imagick/gd). Hãy bật ít nhất một extension trên host.',
            );
        }

        return GdDriver::class;
    }
}
