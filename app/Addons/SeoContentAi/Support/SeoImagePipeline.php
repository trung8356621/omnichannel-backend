<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Support\ImageDriverResolver;
use Intervention\Image\Format;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Pipeline resize/encode chất lượng cao: native Imagick (Lanczos) → Intervention (Imagick/GD).
 */
final class SeoImagePipeline
{
    private const UPSCALE_SHARPEN_LEVEL = 8;

    private const DOWNSCALE_SHARPEN_LEVEL = 4;

    private string $lastDriver = 'unknown';

    public function lastDriver(): string
    {
        return $this->lastDriver;
    }

    /**
     * Giới hạn một chiều theo cấu hình tối ưu ảnh (width hoặc height, không ép cả hai).
     */
    public function applyMaxDimensions(
        string $absolutePath,
        int $maxWidth,
        int $maxHeight,
        bool $limitByWidth,
        bool $limitByHeight,
    ): bool {
        if (! is_file($absolutePath)) {
            return false;
        }

        [$origWidth, $origHeight] = $this->readImageDimensions($absolutePath);
        if ($origWidth <= 0 || $origHeight <= 0) {
            return false;
        }

        $targetWidth = null;
        $targetHeight = null;

        if ($limitByWidth && $maxWidth > 0 && $origWidth > $maxWidth) {
            $targetWidth = $maxWidth;
        }

        if ($limitByHeight && $maxHeight > 0 && $origHeight > $maxHeight) {
            $targetHeight = $maxHeight;
        }

        if ($targetWidth === null && $targetHeight === null) {
            return false;
        }

        $result = $this->resizeFile($absolutePath, $targetWidth, $targetHeight);

        return $result['applied'];
    }

    /**
     * @return array{applied: bool, width: int, height: int, driver: string}
     */
    public function resizeFile(string $absolutePath, ?int $targetWidth, ?int $targetHeight): array
    {
        $failed = [
            'applied' => false,
            'width' => 0,
            'height' => 0,
            'driver' => $this->lastDriver,
        ];

        if (! is_file($absolutePath)) {
            return $failed;
        }

        $width = $targetWidth !== null && $targetWidth > 0 ? $targetWidth : null;
        $height = $targetHeight !== null && $targetHeight > 0 ? $targetHeight : null;

        if ($width === null && $height === null) {
            return $failed;
        }

        $extension = $this->normalizeExtension(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'png');

        if ($this->tryResizeWithImagick($absolutePath, $extension, $width, $height)) {
            [$outWidth, $outHeight] = $this->readImageDimensions($absolutePath);

            return [
                'applied' => true,
                'width' => $outWidth,
                'height' => $outHeight,
                'driver' => $this->lastDriver,
            ];
        }

        return $this->resizeWithIntervention($absolutePath, $extension, $width, $height);
    }

    public function encodeFile(string $absolutePath, string $extension, int $quality): bool
    {
        return $this->encodeSourceToPath($absolutePath, $absolutePath, $extension, $quality);
    }

    public function encodeSourceToPath(
        string $sourcePath,
        string $destinationPath,
        string $extension,
        int $quality,
    ): bool {
        if (! is_file($sourcePath)) {
            return false;
        }

        $extension = $this->normalizeExtension($extension);
        $quality = max(10, min(100, $quality));

        if ($this->tryEncodeImagickSourceToPath($sourcePath, $destinationPath, $extension, $quality)) {
            return $this->isEncodedOutputValid($destinationPath);
        }

        try {
            $image = Image::read($sourcePath);
            $encoded = $this->encodeImage($image, $extension, $quality);
            if (@file_put_contents($destinationPath, $encoded) === false) {
                return false;
            }
            $this->lastDriver = 'intervention-'.ImageDriverResolver::driverName();

            return $this->isEncodedOutputValid($destinationPath);
        } catch (\Throwable $exception) {
            logger()->error('Intervention encode failed.', [
                'source' => $sourcePath,
                'destination' => $destinationPath,
                'driver' => ImageDriverResolver::driverName(),
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function isEncodedOutputValid(string $destinationPath): bool
    {
        return is_file($destinationPath) && (int) filesize($destinationPath) > 0;
    }

    private function tryResizeWithImagick(
        string $absolutePath,
        string $extension,
        ?int $width,
        ?int $height,
    ): bool {
        if (! ImageDriverResolver::shouldUseNativeImagickPipeline()) {
            return false;
        }

        try {
            $imagick = new \Imagick($absolutePath);
            $imagick->setImageColorspace(\Imagick::COLORSPACE_SRGB);

            $origWidth = $imagick->getImageWidth();
            $origHeight = $imagick->getImageHeight();
            $dimensions = SeoImageResizeMath::outputDimensions($origWidth, $origHeight, $width, $height);
            $outWidth = $dimensions['width'];
            $outHeight = $dimensions['height'];

            if ($outWidth === $origWidth && $outHeight === $origHeight) {
                $imagick->clear();
                $imagick->destroy();
                $this->lastDriver = 'imagick-native';

                return true;
            }

            if ($extension === 'png') {
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
            }

            $steps = SeoImageResizeMath::progressiveScaleSteps($origWidth, $origHeight, $outWidth, $outHeight);
            foreach ($steps as $step) {
                $imagick->resizeImage(
                    $step['width'],
                    $step['height'],
                    \Imagick::FILTER_LANCZOS,
                    1,
                );
            }

            $this->applyImagickSharpen($imagick, $origWidth, $origHeight, $outWidth, $outHeight);
            $this->writeImagickToPath($imagick, $absolutePath, $extension, ImageDriverResolver::ENCODE_QUALITY);
            $imagick->clear();
            $imagick->destroy();

            $this->lastDriver = 'imagick-native';

            return true;
        } catch (\Throwable $exception) {
            logger()->warning('Imagick resize failed, falling back to Intervention Image.', [
                'path' => $absolutePath,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function tryEncodeWithImagick(string $absolutePath, string $extension, int $quality): bool
    {
        return $this->tryEncodeImagickSourceToPath($absolutePath, $absolutePath, $extension, $quality);
    }

    private function tryEncodeImagickSourceToPath(
        string $sourcePath,
        string $destinationPath,
        string $extension,
        int $quality,
    ): bool {
        if (! ImageDriverResolver::shouldUseNativeImagickPipeline()) {
            return false;
        }

        try {
            $imagick = new \Imagick($sourcePath);
            $imagick->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            $this->writeImagickToPath($imagick, $destinationPath, $extension, $quality);
            $imagick->clear();
            $imagick->destroy();
            $this->lastDriver = 'imagick-native';

            return true;
        } catch (\Throwable $exception) {
            logger()->warning('Imagick encode failed, falling back to Intervention Image.', [
                'source' => $sourcePath,
                'destination' => $destinationPath,
                'extension' => $extension,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{applied: bool, width: int, height: int, driver: string}
     */
    private function resizeWithIntervention(
        string $absolutePath,
        string $extension,
        ?int $width,
        ?int $height,
    ): array {
        $failed = [
            'applied' => false,
            'width' => 0,
            'height' => 0,
            'driver' => $this->lastDriver,
        ];

        try {
            $image = Image::read($absolutePath);
            $origWidth = $image->width();
            $origHeight = $image->height();
            $dimensions = SeoImageResizeMath::outputDimensions($origWidth, $origHeight, $width, $height);
            $outWidth = $dimensions['width'];
            $outHeight = $dimensions['height'];

            $steps = SeoImageResizeMath::progressiveScaleSteps($origWidth, $origHeight, $outWidth, $outHeight);
            foreach ($steps as $step) {
                $image->resize(width: $step['width'], height: $step['height']);
            }

            if (SeoImageResizeMath::isUpscale($origWidth, $origHeight, $outWidth, $outHeight)) {
                $image->sharpen(self::UPSCALE_SHARPEN_LEVEL);
            } elseif ($outWidth < $origWidth || $outHeight < $origHeight) {
                $image->sharpen(self::DOWNSCALE_SHARPEN_LEVEL);
            }

            $encoded = $this->encodeImage($image, $extension, ImageDriverResolver::ENCODE_QUALITY);
            file_put_contents($absolutePath, $encoded);

            $this->lastDriver = 'intervention-'.ImageDriverResolver::driverName();

            return [
                'applied' => true,
                'width' => $image->width(),
                'height' => $image->height(),
                'driver' => $this->lastDriver,
            ];
        } catch (\Throwable $exception) {
            logger()->error('Intervention Image resize failed.', [
                'path' => $absolutePath,
                'driver' => ImageDriverResolver::driverName(),
                'message' => $exception->getMessage(),
            ]);

            return $failed;
        }
    }

    private function applyImagickSharpen(
        \Imagick $imagick,
        int $origWidth,
        int $origHeight,
        int $outWidth,
        int $outHeight,
    ): void {
        if (SeoImageResizeMath::isUpscale($origWidth, $origHeight, $outWidth, $outHeight)) {
            $imagick->unsharpMaskImage(1, 0.5, 1.0, 0.05);

            return;
        }

        if ($outWidth < $origWidth || $outHeight < $origHeight) {
            $imagick->unsharpMaskImage(0.8, 0.4, 0.8, 0.03);
        }
    }

    private function writeImagickToPath(\Imagick $imagick, string $absolutePath, string $extension, int $quality): void
    {
        if ($extension === 'png') {
            $imagick->setImageFormat('png');
            $imagick->setOption('png:compression-level', '3');
            $imagick->setImageCompressionQuality(100);

            $imagick->writeImage($absolutePath);

            return;
        }

        if ($extension === 'webp') {
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality(max(10, min(100, $quality)));
            $imagick->writeImage($absolutePath);

            return;
        }

        if ($extension === 'gif') {
            $imagick->setImageFormat('gif');
            $imagick->writeImage($absolutePath);

            return;
        }

        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(max(10, min(100, $quality)));
        $imagick->writeImage($absolutePath);
    }

    private function encodeImage(ImageInterface $image, string $extension, int $quality): string
    {
        $format = match ($extension) {
            'webp' => Format::WEBP,
            'png' => Format::PNG,
            'gif' => Format::GIF,
            default => Format::JPEG,
        };

        if ($extension === 'png') {
            return (string) $image->encodeUsingFormat($format);
        }

        return (string) $image->encodeUsingFormat($format, quality: $quality);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function readImageDimensions(string $absolutePath): array
    {
        $size = @getimagesize($absolutePath);
        if (! is_array($size)) {
            return [0, 0];
        }

        return [(int) ($size[0] ?? 0), (int) ($size[1] ?? 0)];
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower($extension);
        if ($extension === 'jpeg') {
            return 'jpg';
        }

        if (! in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
            return 'png';
        }

        return $extension;
    }
}
