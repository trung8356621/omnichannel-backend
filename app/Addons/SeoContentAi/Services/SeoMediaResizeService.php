<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoMediaProcessingHistory;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Format;
use Intervention\Image\Laravel\Facades\Image;

final class SeoMediaResizeService
{
    public function __construct(
        private readonly SeoMediaProcessingHistoryService $processingHistory,
        private readonly WordPressMediaWatermarkService $wpMedia,
        private readonly SeoImageOptimizationService $optimization,
        private readonly SeoMediaStorageService $mediaStorage,
    ) {}

    /**
     * @return array{success: bool, url: string, message: string}
     */
    public function resizeLocal(SeoMedia $media, ?int $width, ?int $height): array
    {
        $url = $media->publicUrl();

        if (! $this->hasValidDimensionInput($width, $height)) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Nhập Width hoặc Height (px).',
            ];
        }

        $disk = Storage::disk('public');
        $relativePath = ltrim(str_replace('\\', '/', (string) $media->path), '/');

        if ($relativePath === '' || ! $disk->exists($relativePath)) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Không tìm thấy file ảnh trên server.',
            ];
        }

        $absolutePath = $disk->path($relativePath);
        $siteId = (int) ($media->site_id ?? 0);
        $mediaId = (int) $media->id;

        $this->processingHistory->ensureBackup(
            $siteId,
            SeoMediaProcessingHistory::SOURCE_LOCAL,
            $mediaId,
            $url,
            $absolutePath,
        );

        $resized = $this->resizeAbsolutePath($absolutePath, $width, $height);
        if (! $resized['applied']) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Không resize được ảnh.',
            ];
        }

        $newRelative = $this->optimization->absoluteToPublicRelative($resized['absolute_path']);
        $media->update([
            'path' => $newRelative,
            'url' => $this->mediaStorage->urlForPath($newRelative),
            'filename' => basename($newRelative),
        ]);

        if ((int) ($media->wp_attachment_id ?? 0) > 0 && $siteId > 0) {
            app(SeoWpMediaEditedPendingService::class)->recordPendingEdit($media->fresh());
            $media->update(['wp_synced_at' => null]);
        }

        return [
            'success' => true,
            'url' => $this->cacheBustUrl($media->fresh()->publicUrl()),
            'message' => sprintf(
                'Đã resize %dx%d px.',
                $resized['width'],
                $resized['height'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, url: string, message: string}
     */
    public function resizeWordPress(Site $site, array $imageRow, ?int $width, ?int $height): array
    {
        $url = trim((string) ($imageRow['url'] ?? ''));
        $attachmentId = (int) ($imageRow['wp_attachment_id'] ?? $imageRow['id'] ?? 0);

        if (! $this->hasValidDimensionInput($width, $height)) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Nhập Width hoặc Height (px).',
            ];
        }

        if ($attachmentId <= 0 || $url === '') {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Thiếu ID ảnh WordPress.',
            ];
        }

        $tempPath = $this->createTempImagePath($url);
        if ($tempPath === '') {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Không tạo được file tạm trên server.',
            ];
        }

        $workingPath = $tempPath;

        try {
            $response = Http::timeout(90)->get($url);
            if (! $response->successful()) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Không tải được ảnh từ WordPress (HTTP ' . $response->status() . ').',
                ];
            }

            $binary = $response->body();
            if ($binary === '') {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Ảnh WordPress trả về rỗng.',
                ];
            }

            file_put_contents($tempPath, $binary);

            $this->processingHistory->ensureBackup(
                (int) $site->id,
                SeoMediaProcessingHistory::SOURCE_WORDPRESS,
                $attachmentId,
                $url,
                $tempPath,
            );

            $resized = $this->resizeAbsolutePath($tempPath, $width, $height);
            if (! $resized['applied']) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Không resize được ảnh.',
                ];
            }

            $workingPath = $resized['absolute_path'];

            $mime = $this->optimization->mimeFromPath($workingPath);
            $outcome = $this->wpMedia->replaceAttachmentFromLocalFile(
                $site,
                $attachmentId,
                $workingPath,
                $mime,
            );

            if (! ($outcome['success'] ?? false)) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => (string) ($outcome['message'] ?? 'Không cập nhật được ảnh trên WordPress.'),
                ];
            }

            $newUrl = trim((string) ($outcome['url'] ?? ''));
            if ($newUrl === '') {
                $newUrl = $url;
            }

            return [
                'success' => true,
                'url' => $this->cacheBustUrl($newUrl),
                'message' => sprintf(
                    'Đã resize %dx%d px trên WordPress.',
                    $resized['width'],
                    $resized['height'],
                ),
            ];
        } finally {
            if ($workingPath !== '' && is_file($workingPath)) {
                @unlink($workingPath);
            }
        }
    }

    private function hasValidDimensionInput(?int $width, ?int $height): bool
    {
        return ($width !== null && $width > 0) || ($height !== null && $height > 0);
    }

    /**
     * @return array{applied: bool, absolute_path: string, width: int, height: int}
     */
    private function resizeAbsolutePath(string $absolutePath, ?int $targetWidth, ?int $targetHeight): array
    {
        if (! is_file($absolutePath)) {
            return [
                'applied' => false,
                'absolute_path' => $absolutePath,
                'width' => 0,
                'height' => 0,
            ];
        }

        $width = $targetWidth !== null && $targetWidth > 0 ? $targetWidth : null;
        $height = $targetHeight !== null && $targetHeight > 0 ? $targetHeight : null;

        if ($width === null && $height === null) {
            return [
                'applied' => false,
                'absolute_path' => $absolutePath,
                'width' => 0,
                'height' => 0,
            ];
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $outWidth = 0;
        $outHeight = 0;

        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($absolutePath);
                $origWidth = $imagick->getImageWidth();
                $origHeight = $imagick->getImageHeight();

                if ($width !== null && $height !== null) {
                    $outWidth = $width;
                    $outHeight = $height;
                } elseif ($width !== null) {
                    $outWidth = $width;
                    $ratio = $width / max(1, $origWidth);
                    $outHeight = (int) round($origHeight * $ratio);
                } else {
                    $outHeight = $height;
                    $ratio = $height / max(1, $origHeight);
                    $outWidth = (int) round($origWidth * $ratio);
                }

                $imagick->resizeImage(
                    $outWidth,
                    $outHeight,
                    \Imagick::FILTER_LANCZOS,
                    0.9,
                );

                if ($extension !== 'png') {
                    $imagick->setImageCompressionQuality(92);
                }

                $imagick->writeImage($absolutePath);
                $imagick->clear();
                $imagick->destroy();

                return [
                    'applied' => true,
                    'absolute_path' => $absolutePath,
                    'width' => $outWidth,
                    'height' => $outHeight,
                ];
            } catch (\Throwable $exception) {
                logger()->error(
                    'Imagick resize failed, falling back to Intervention Image.',
                    [
                        'path' => $absolutePath,
                        'message' => $exception->getMessage(),
                    ],
                );
            }
        }

        $image = Image::decodePath($absolutePath);

        if ($width !== null && $height !== null) {
            $image->resize(width: $width, height: $height);
        } elseif ($width !== null) {
            $image->scale(width: $width);
        } else {
            $image->scale(height: $height);
        }

        $outWidth = $image->width();
        $outHeight = $image->height();

        $format = match ($extension) {
            'webp' => Format::WEBP,
            'png' => Format::PNG,
            'gif' => Format::GIF,
            default => Format::JPEG,
        };

        $quality = $extension === 'png' ? null : 92;
        $encoded = $quality !== null
            ? (string) $image->encodeUsingFormat($format, quality: $quality)
            : (string) $image->encodeUsingFormat($format);

        file_put_contents($absolutePath, $encoded);

        return [
            'applied' => true,
            'absolute_path' => $absolutePath,
            'width' => $outWidth,
            'height' => $outHeight,
        ];
    }

    private function createTempImagePath(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $extension = 'jpg';
        }

        $dir = storage_path('app/temp/wp-media-resize');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'resize-' . uniqid('', true) . '.' . $extension;
    }

    private function cacheBustUrl(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 't=' . time();
    }
}
