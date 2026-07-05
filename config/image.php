<?php

declare(strict_types=1);

use App\Support\ImageDriverResolver;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

/*
|--------------------------------------------------------------------------
| Intervention Image v4 — driver tự fallback theo extension PHP trên host
|--------------------------------------------------------------------------
|
| - Có imagick  → dùng Imagick (Lanczos, chất lượng cao qua SeoMediaResizeService)
| - Không imagick, có gd → dùng GD (imagecopyresampled nội bộ)
| - IMAGE_DRIVER trong .env có thể ép 'imagick' hoặc 'gd'; nếu ép imagick mà
|   host không có extension, hệ thống tự chuyển sang GD.
|
| Lưu ý: sau khi đổi extension trên server, chạy `php artisan config:clear`
| hoặc build lại config cache trên đúng host đích.
|
*/

return [

    'driver' => ImageDriverResolver::interventionDriverClass(),

    'driver_name' => ImageDriverResolver::driverName(),

    'supports' => [
        'imagick' => ImageDriverResolver::supportsImagick(),
        'gd' => ImageDriverResolver::supportsGd(),
    ],

    'encode_quality' => ImageDriverResolver::ENCODE_QUALITY,

    /*
    |--------------------------------------------------------------------------
    | Driver class constants (tham chiếu / override .env)
    |--------------------------------------------------------------------------
    */

    'drivers' => [
        'imagick' => ImagickDriver::class,
        'gd' => GdDriver::class,
    ],

    'options' => [
        'autoOrientation' => true,
        'decodeAnimation' => true,
        'backgroundColor' => 'ffffff',
        'strip' => false,
    ],
];
