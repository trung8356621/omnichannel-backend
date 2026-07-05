<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ImageDriverResolver;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use PHPUnit\Framework\TestCase;

final class ImageDriverResolverTest extends TestCase
{
    public function test_intervention_driver_class_prefers_imagick_when_available(): void
    {
        $class = ImageDriverResolver::interventionDriverClass();

        if (ImageDriverResolver::supportsImagick()) {
            $this->assertSame(ImagickDriver::class, $class);
            $this->assertSame('imagick', ImageDriverResolver::driverName());
        } elseif (ImageDriverResolver::supportsGd()) {
            $this->assertSame(GdDriver::class, $class);
            $this->assertSame('gd', ImageDriverResolver::driverName());
        } else {
            $this->markTestSkipped('Host không có imagick/gd.');
        }
    }

    public function test_has_any_driver_when_gd_or_imagick_exists(): void
    {
        if (! extension_loaded('imagick') && ! extension_loaded('gd')) {
            $this->markTestSkipped('Host không có imagick/gd.');
        }

        $this->assertTrue(ImageDriverResolver::hasAnyDriver());
    }
}
