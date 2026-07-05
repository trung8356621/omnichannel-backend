<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

final class SeoImageResizeMath
{
    private const PROGRESSIVE_SCALE_FACTOR = 1.5;

    /**
     * @return array{width: int, height: int}
     */
    public static function outputDimensions(
        int $originalWidth,
        int $originalHeight,
        ?int $targetWidth,
        ?int $targetHeight,
    ): array {
        if ($targetWidth !== null && $targetHeight !== null) {
            return [
                'width' => max(1, $targetWidth),
                'height' => max(1, $targetHeight),
            ];
        }

        if ($targetWidth !== null) {
            $ratio = $targetWidth / max(1, $originalWidth);

            return [
                'width' => max(1, $targetWidth),
                'height' => max(1, (int) round($originalHeight * $ratio)),
            ];
        }

        if ($targetHeight !== null) {
            $ratio = $targetHeight / max(1, $originalHeight);

            return [
                'width' => max(1, (int) round($originalWidth * $ratio)),
                'height' => max(1, $targetHeight),
            ];
        }

        return [
            'width' => max(1, $originalWidth),
            'height' => max(1, $originalHeight),
        ];
    }

    public static function isUpscale(int $originalWidth, int $originalHeight, int $targetWidth, int $targetHeight): bool
    {
        return $targetWidth > $originalWidth || $targetHeight > $originalHeight;
    }

    /**
     * @return list<array{width: int, height: int}>
     */
    public static function progressiveUpscaleSteps(
        int $originalWidth,
        int $originalHeight,
        int $targetWidth,
        int $targetHeight,
    ): array {
        if (! self::isUpscale($originalWidth, $originalHeight, $targetWidth, $targetHeight)) {
            return [
                [
                    'width' => $targetWidth,
                    'height' => $targetHeight,
                ],
            ];
        }

        $steps = [];
        $width = max(1, $originalWidth);
        $height = max(1, $originalHeight);

        while ($width < $targetWidth || $height < $targetHeight) {
            $nextWidth = min(
                $targetWidth,
                max($width + 1, (int) ceil($width * self::PROGRESSIVE_SCALE_FACTOR)),
            );
            $nextHeight = min(
                $targetHeight,
                max($height + 1, (int) ceil($height * self::PROGRESSIVE_SCALE_FACTOR)),
            );

            if ($nextWidth === $width && $nextHeight === $height) {
                break;
            }

            $steps[] = [
                'width' => $nextWidth,
                'height' => $nextHeight,
            ];

            $width = $nextWidth;
            $height = $nextHeight;
        }

        if ($width !== $targetWidth || $height !== $targetHeight) {
            $steps[] = [
                'width' => $targetWidth,
                'height' => $targetHeight,
            ];
        }

        return $steps;
    }
}
