<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoImageOptimizationSetting;
use App\Addons\SeoContentAi\Support\SeoImagePipeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeoImageOptimizationService
{
    public const WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES = 102400;

    public function __construct(
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SeoMediaPathAllocator $mediaPaths,
        private readonly SeoImagePipeline $imagePipeline,
    ) {}

    public function resolveForSite(?int $siteId): SeoImageOptimizationSetting
    {
        if ($siteId !== null) {
            $siteSetting = SeoImageOptimizationSetting::query()
                ->where('site_id', $siteId)
                ->first();

            if ($siteSetting instanceof SeoImageOptimizationSetting) {
                return $siteSetting;
            }
        }

        $global = SeoImageOptimizationSetting::query()
            ->whereNull('site_id')
            ->first();

        return $global ?? new SeoImageOptimizationSetting;
    }

    /**
     * @return array{slug: string, filename: string, relative_path: string, alt_text: string, binary: string}
     */
    public function processUpload(
        UploadedFile $file,
        SeoImageOptimizationSetting $config,
        ?SeoArticle $article = null,
    ): array {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $originalExtension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');

        if ($config->clean_filename) {
            $slug = Str::slug($originalName);
        } else {
            $slug = Str::slug($originalName) !== ''
                ? Str::slug($originalName)
                : 'img-'.time();
        }

        if ($slug === '') {
            $slug = 'img-'.time();
        }

        $extension = $this->normalizeExtension($originalExtension);

        $allocated = $this->mediaPaths->allocate($slug, $extension);
        $slug = $allocated['slug'];
        $filename = $allocated['filename'];
        $relativePath = $allocated['relative_path'];

        $tempPath = $this->createTempImagePath('upload', $extension);
        try {
            $sourcePath = $file->getRealPath();
            if ($sourcePath === false || ! is_file($sourcePath)) {
                throw new \RuntimeException('Không đọc được file upload.');
            }

            if (! copy($sourcePath, $tempPath)) {
                throw new \RuntimeException('Không sao chép được file upload.');
            }

            $this->applyConfiguredDimensionLimitsToPath($tempPath, $config);
            $quality = max(10, min(100, (int) $config->quality));
            if (! $this->imagePipeline->encodeFile($tempPath, $extension, $quality)) {
                throw new \RuntimeException('Không encode được ảnh upload.');
            }

            $binary = file_get_contents($tempPath);
            if (! is_string($binary) || $binary === '') {
                throw new \RuntimeException('Ảnh upload rỗng sau xử lý.');
            }
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        $altText = $this->buildAltText($config, $article, $slug);

        return [
            'slug' => $slug,
            'filename' => $filename,
            'relative_path' => $relativePath,
            'alt_text' => $altText,
            'binary' => $binary,
        ];
    }

    public function buildAltText(
        SeoImageOptimizationSetting $config,
        ?SeoArticle $article,
        string $fallbackSlug,
    ): string {
        if (! $config->auto_alt_tag || $article === null) {
            return $fallbackSlug;
        }

        $pattern = (string) ($config->alt_tag_pattern ?? '{post_title}');
        $focusKeyword = $this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '';
        $title = trim((string) ($article->title ?? ''));

        $altText = str_replace(
            ['{post_title}', '{focus_keyword}'],
            [$title, $focusKeyword],
            $pattern,
        );

        $altText = trim(preg_replace('/\s*-\s*$/', '', trim($altText, " \t\n\r\0\x0B-")));

        return $altText !== '' ? $altText : $fallbackSlug;
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

    public function isWebpPath(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'webp';
    }

    public function isWebpUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $this->isWebpPath($path);
    }

    public function canEncodeWebp(): bool
    {
        if (extension_loaded('imagick')) {
            try {
                return in_array('WEBP', \Imagick::queryFormats(), true);
            } catch (\Throwable) {
                // fall through
            }
        }

        return function_exists('imagewebp');
    }

    /**
     * Ảnh đã có trên WordPress nhưng URL chưa .webp — chỉ khi bản WebP local thật sự dùng được.
     * Không ép backfill khi đã fallback JPEG tối ưu (tránh import trùng attachment).
     */
    public function needsWordPressWebpBackfill(
        SeoImageOptimizationSetting $config,
        string $absolutePath,
        ?string $existingWpUrl = null,
    ): bool {
        if (! (bool) $config->auto_convert_webp) {
            return false;
        }

        if (! is_file($absolutePath) || ! $this->isValidImageFile($absolutePath)) {
            return false;
        }

        if ($existingWpUrl !== null && $existingWpUrl !== '' && $this->isWebpUrl($existingWpUrl)) {
            return false;
        }

        if ($this->hasPersistentOptimizedUploadFallback($absolutePath)) {
            return false;
        }

        if ($this->isWebpPath($absolutePath)) {
            return false;
        }

        return $this->hasUsableLocalWebpCopy($absolutePath);
    }

    public function hasPersistentOptimizedUploadFallback(string $absolutePath): bool
    {
        $optimizedPath = $this->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, 'jpg');
        $sourceMtime = @filemtime($absolutePath) ?: 0;

        return is_file($optimizedPath)
            && (@filemtime($optimizedPath) ?: 0) >= $sourceMtime
            && $this->isValidImageFile($optimizedPath);
    }

    private function hasUsableLocalWebpCopy(string $absolutePath): bool
    {
        if (! $this->canEncodeWebp()) {
            return false;
        }

        $webpPath = $this->resolveSiblingWebpAbsolutePath($absolutePath);
        $sourceMtime = @filemtime($absolutePath) ?: 0;
        if (! is_file($webpPath) || (@filemtime($webpPath) ?: 0) < $sourceMtime) {
            return false;
        }

        $binary = @file_get_contents($webpPath);

        return is_string($binary) && $this->isValidWebpBinary($binary);
    }

    /**
     * Tối ưu file trên đĩa Laravel (resize, nén) — giữ nguyên định dạng, không chuyển WebP.
     *
     * @return array{applied: bool, absolute_path: string}
     */
    public function optimizeAbsolutePath(string $absolutePath, SeoImageOptimizationSetting $config): array
    {
        if (! is_file($absolutePath) || $this->isWebpPath($absolutePath)) {
            return ['applied' => false, 'absolute_path' => $absolutePath];
        }

        try {
            $encoded = $this->encodeOptimizedImage($absolutePath, $config, false);
        } catch (\Throwable) {
            return ['applied' => false, 'absolute_path' => $absolutePath];
        }

        if ($encoded === null) {
            return ['applied' => false, 'absolute_path' => $absolutePath];
        }

        $currentExtension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($currentExtension === 'jpeg') {
            $currentExtension = 'jpg';
        }

        $targetExtension = $this->normalizeExtension($currentExtension);

        $directory = dirname($absolutePath);
        $basename = pathinfo($absolutePath, PATHINFO_FILENAME);
        $newAbsolutePath = $directory.DIRECTORY_SEPARATOR.$basename.'.'.$targetExtension;

        file_put_contents($newAbsolutePath, $encoded);

        if ($newAbsolutePath !== $absolutePath && is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        return ['applied' => true, 'absolute_path' => $newAbsolutePath];
    }

    /**
     * Tạo (hoặc tái sử dụng) bản WebP cạnh file gốc trên disk Laravel — không đổi file PNG/JPG gốc.
     */
    public function ensureLocalWebpCopy(string $absolutePath, SeoImageOptimizationSetting $config): ?string
    {
        if (! (bool) $config->auto_convert_webp) {
            return null;
        }

        if ($this->isWebpPath($absolutePath)) {
            return $absolutePath;
        }

        if (! $this->canEncodeWebp() || ! $this->isValidImageFile($absolutePath)) {
            return null;
        }

        $webpPath = $this->resolveSiblingWebpAbsolutePath($absolutePath);
        $sourceMtime = @filemtime($absolutePath) ?: 0;
        if (
            is_file($webpPath)
            && (@filemtime($webpPath) ?: 0) >= $sourceMtime
            && $this->isValidImageFile($webpPath)
            && $this->isWebpPath($webpPath)
        ) {
            return $webpPath;
        }

        $sourceTemp = $this->copyToTempWorkspace($absolutePath);
        if ($sourceTemp === null) {
            return null;
        }

        $outputTemp = $this->createTempImagePath('wp-webp', 'webp');
        $quality = max(10, min(100, (int) $config->quality));

        try {
            $this->applyConfiguredDimensionLimitsToPath($sourceTemp, $config);

            if (! $this->imagePipeline->encodeSourceToPath($sourceTemp, $outputTemp, 'webp', $quality)) {
                logger()->warning('Local WebP encode failed.', [
                    'path' => $absolutePath,
                    'driver' => $this->imagePipeline->lastDriver(),
                    'can_encode_webp' => $this->canEncodeWebp(),
                ]);

                return null;
            }

            $encoded = file_get_contents($outputTemp);
        } catch (\Throwable $exception) {
            logger()->warning('Local WebP encode failed.', [
                'path' => $absolutePath,
                'message' => $exception->getMessage(),
                'driver' => $this->imagePipeline->lastDriver(),
            ]);

            return null;
        } finally {
            if (is_file($sourceTemp)) {
                @unlink($sourceTemp);
            }
            if (is_file($outputTemp)) {
                @unlink($outputTemp);
            }
        }

        if (! is_string($encoded) || ! $this->isValidWebpBinary($encoded)) {
            logger()->warning('Local WebP output invalid.', [
                'path' => $absolutePath,
                'driver' => $this->imagePipeline->lastDriver(),
            ]);

            return null;
        }

        $directory = dirname($webpPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return null;
        }

        if (@file_put_contents($webpPath, $encoded) === false) {
            return null;
        }

        return $webpPath;
    }

    public function resolveSiblingWebpAbsolutePath(string $absolutePath): string
    {
        $directory = dirname($absolutePath);
        $basename = pathinfo($absolutePath, PATHINFO_FILENAME);

        return $directory.DIRECTORY_SEPARATOR.$basename.'.webp';
    }

    public function resolveSiblingWebpRelativePath(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $directory = pathinfo($relativePath, PATHINFO_DIRNAME);
        $basename = pathinfo($relativePath, PATHINFO_FILENAME);

        if ($directory === '' || $directory === '.') {
            return $basename.'.webp';
        }

        return $directory.'/'.$basename.'.webp';
    }

    /**
     * Bản JPEG/PNG tối ưu cạnh file gốc — dùng khi WebP thất bại, mục tiêu ≤ 100KB cho upload WordPress.
     */
    public function ensureLocalOptimizedUploadCopy(
        string $absolutePath,
        SeoImageOptimizationSetting $config,
        int $maxBytes = self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
    ): ?string {
        if (! is_file($absolutePath) || ! $this->isValidImageFile($absolutePath)) {
            return null;
        }

        $targetExtension = $this->resolveOptimizedUploadTargetExtension($absolutePath);
        $optimizedPath = $this->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, $targetExtension);
        $sourceMtime = @filemtime($absolutePath) ?: 0;

        if (
            is_file($optimizedPath)
            && (@filemtime($optimizedPath) ?: 0) >= $sourceMtime
            && $this->isValidImageFile($optimizedPath)
            && (int) filesize($optimizedPath) <= $maxBytes
        ) {
            return $optimizedPath;
        }

        $quality = max(15, min(100, (int) $config->quality));
        $scaleFactor = 1.0;

        for ($attempt = 0; $attempt < 14; $attempt++) {
            $sourceTemp = $this->copyToTempWorkspace($absolutePath);
            if ($sourceTemp === null) {
                break;
            }

            $outputTemp = $this->createTempImagePath('wp-opt', $targetExtension);

            try {
                if ($scaleFactor < 1.0) {
                    $this->applyScaleToPath($sourceTemp, $scaleFactor);
                } else {
                    $this->applyConfiguredDimensionLimitsToPath($sourceTemp, $config);
                }

                if (
                    $this->imagePipeline->encodeSourceToPath($sourceTemp, $outputTemp, $targetExtension, $quality)
                    && $this->isValidImageFile($outputTemp)
                    && (int) filesize($outputTemp) <= $maxBytes
                ) {
                    $directory = dirname($optimizedPath);
                    if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                        return null;
                    }

                    if (@copy($outputTemp, $optimizedPath) || @file_put_contents($optimizedPath, (string) file_get_contents($outputTemp)) !== false) {
                        logger()->info('WordPress upload fallback: ảnh đã nén dưới ngưỡng.', [
                            'path' => $absolutePath,
                            'optimized_path' => $optimizedPath,
                            'bytes' => (int) filesize($optimizedPath),
                            'max_bytes' => $maxBytes,
                            'quality' => $quality,
                            'scale' => round($scaleFactor, 2),
                        ]);

                        return $optimizedPath;
                    }
                }
            } finally {
                if (is_file($sourceTemp)) {
                    @unlink($sourceTemp);
                }
                if (is_file($outputTemp)) {
                    @unlink($outputTemp);
                }
            }

            if ($quality > 20) {
                $quality = max(15, $quality - 10);
            } elseif ($scaleFactor > 0.35) {
                $scaleFactor *= 0.88;
                $quality = max(15, min(100, (int) $config->quality));
            } else {
                break;
            }
        }

        return null;
    }

    public function resolveSiblingOptimizedUploadAbsolutePath(string $absolutePath, string $targetExtension): string
    {
        $directory = dirname($absolutePath);
        $basename = pathinfo($absolutePath, PATHINFO_FILENAME);
        $targetExtension = $this->normalizeExtension($targetExtension);

        return $directory.DIRECTORY_SEPARATOR.$basename.'-wp-upload.'.$targetExtension;
    }

    private function resolveOptimizedUploadTargetExtension(string $absolutePath): string
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        return in_array($extension, ['jpg', 'jpeg'], true) ? 'jpg' : 'jpg';
    }

    private function applyScaleToPath(string $absolutePath, float $scaleFactor): void
    {
        if ($scaleFactor >= 1.0 || $scaleFactor <= 0.0 || ! is_file($absolutePath)) {
            return;
        }

        $size = @getimagesize($absolutePath);
        if (! is_array($size)) {
            return;
        }

        $width = (int) ($size[0] ?? 0);
        $height = (int) ($size[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $newWidth = max(1, (int) round($width * $scaleFactor));
        $newHeight = max(1, (int) round($height * $scaleFactor));
        $this->imagePipeline->resizeFile($absolutePath, $newWidth, $newHeight);
    }

    /**
     * File dùng khi upload WordPress: bản WebP local persistent nếu bật convert, ngược lại file gốc.
     *
     * @return array{path: string, temporary: bool, mime: string}|null
     */
    public function prepareWordPressUploadFile(string $absolutePath, SeoImageOptimizationSetting $config): ?array
    {
        if (! is_file($absolutePath) || ! $this->isValidImageFile($absolutePath)) {
            return null;
        }

        if ((bool) $config->auto_convert_webp) {
            $webpPath = $this->ensureLocalWebpCopy($absolutePath, $config);
            if ($webpPath !== null) {
                return [
                    'path' => $webpPath,
                    'temporary' => false,
                    'mime' => 'image/webp',
                ];
            }

            logger()->warning('Không tạo được bản WebP local — thử fallback nén <100KB.', [
                'path' => $absolutePath,
                'can_encode_webp' => $this->canEncodeWebp(),
            ]);

            $optimizedPath = $this->ensureLocalOptimizedUploadCopy($absolutePath, $config);
            if ($optimizedPath !== null) {
                return [
                    'path' => $optimizedPath,
                    'temporary' => false,
                    'mime' => $this->mimeFromPath($optimizedPath),
                ];
            }

            if ((int) filesize($absolutePath) <= self::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES) {
                return $this->fallbackWordPressUploadFile(
                    $absolutePath,
                    'WebP thất bại; dùng file gốc vì đã dưới 100KB.',
                );
            }

            logger()->error('Không tạo được WebP và không nén được ảnh dưới 100KB.', [
                'path' => $absolutePath,
                'bytes' => (int) filesize($absolutePath),
            ]);

            return null;
        }

        return $this->fallbackWordPressUploadFile($absolutePath);
    }

    /**
     * @return array{path: string, temporary: bool, mime: string}
     */
    private function fallbackWordPressUploadFile(string $absolutePath, ?string $reason = null): array
    {
        if ($reason !== null) {
            logger()->warning($reason, ['path' => $absolutePath]);
        }

        return [
            'path' => $absolutePath,
            'temporary' => false,
            'mime' => $this->mimeFromPath($absolutePath),
        ];
    }

    private function isValidWebpBinary(string $binary): bool
    {
        if (strlen($binary) < 16) {
            return false;
        }

        return strncmp($binary, 'RIFF', 4) === 0 && substr($binary, 8, 4) === 'WEBP';
    }

    private function copyToTempWorkspace(string $absolutePath): ?string
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'png');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $tempPath = $this->createTempImagePath('wp-source', $this->normalizeExtension($extension));
        if (! copy($absolutePath, $tempPath)) {
            return null;
        }

        return $tempPath;
    }

    private function createSystemTempPath(string $prefix, string $extension): ?string
    {
        $extension = $this->normalizeExtension($extension);
        $tempBase = tempnam(sys_get_temp_dir(), $prefix);
        if ($tempBase === false) {
            return null;
        }

        @unlink($tempBase);

        return $tempBase.'.'.$extension;
    }

    public function isValidImageFile(string $absolutePath): bool
    {
        if (! is_file($absolutePath)) {
            return false;
        }

        $size = (int) filesize($absolutePath);
        if ($size < 256) {
            return false;
        }

        $info = @getimagesize($absolutePath);

        return is_array($info) && (int) ($info[0] ?? 0) > 0 && (int) ($info[1] ?? 0) > 0;
    }

    /**
     * @return string|null Binary ảnh đã encode, null nếu thất bại.
     */
    private function encodeOptimizedImage(
        string $absolutePath,
        SeoImageOptimizationSetting $config,
        bool $convertToWebp,
    ): ?string {
        if (! is_file($absolutePath)) {
            return null;
        }

        $this->applyConfiguredDimensionLimitsToPath($absolutePath, $config);

        $currentExtension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'png');
        if ($currentExtension === 'jpeg') {
            $currentExtension = 'jpg';
        }

        $targetExtension = $convertToWebp
            ? 'webp'
            : $this->normalizeExtension($currentExtension === 'webp' ? 'png' : $currentExtension);

        $quality = max(10, min(100, (int) $config->quality));
        if (! $this->imagePipeline->encodeFile($absolutePath, $targetExtension, $quality)) {
            return null;
        }

        $encoded = file_get_contents($absolutePath);

        return is_string($encoded) && strlen($encoded) >= 256 ? $encoded : null;
    }

    public function absoluteToPublicRelative(string $absolutePath): string
    {
        $publicRoot = Storage::disk('public')->path('');
        $normalized = str_replace('\\', '/', $absolutePath);
        $root = str_replace('\\', '/', $publicRoot);

        if (str_starts_with($normalized, $root)) {
            return ltrim(substr($normalized, strlen($root)), '/');
        }

        return ltrim(str_replace('\\', '/', $absolutePath), '/');
    }

    public function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * Giới hạn một chiều qua pipeline Imagick/GD (file trên đĩa).
     */
    private function applyConfiguredDimensionLimitsToPath(string $absolutePath, SeoImageOptimizationSetting $config): void
    {
        if (! $config->limit_dimensions || ! is_file($absolutePath)) {
            return;
        }

        $maxWidth = max(0, (int) $config->max_width);
        $maxHeight = max(0, (int) $config->max_height);

        $limitByWidth = $maxWidth > 0 && $maxHeight <= 0;
        $limitByHeight = $maxHeight > 0 && $maxWidth <= 0;

        if ($maxWidth > 0 && $maxHeight > 0) {
            $limitByWidth = true;
            $limitByHeight = false;
        }

        if (! $limitByWidth && ! $limitByHeight) {
            return;
        }

        $this->imagePipeline->applyMaxDimensions(
            $absolutePath,
            $maxWidth,
            $maxHeight,
            $limitByWidth,
            $limitByHeight,
        );
    }

    private function createTempImagePath(string $prefix, string $extension): string
    {
        $extension = $this->normalizeExtension($extension);
        $dir = storage_path('app/temp/seo-image-optimize');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.DIRECTORY_SEPARATOR.$prefix.'-'.uniqid('', true).'.'.$extension;
    }

    /**
     * @return array{slug: string, filename: string, relative_path: string, alt_text: string, binary: string}
     */
    public function processBinary(
        string $binary,
        string $originalExtension,
        SeoImageOptimizationSetting $config,
        ?SeoArticle $article = null,
        ?string $slugSeed = null,
    ): array {
        $originalExtension = strtolower($originalExtension);
        if ($originalExtension === 'jpeg') {
            $originalExtension = 'jpg';
        }

        $seed = $slugSeed !== null && trim($slugSeed) !== ''
            ? Str::slug($slugSeed)
            : 'img-'.time();

        if ($config->clean_filename) {
            $slug = Str::slug($seed);
        } else {
            $slug = Str::slug($seed) !== '' ? Str::slug($seed) : 'img-'.time();
        }

        if ($slug === '') {
            $slug = 'img-'.time();
        }

        $extension = $this->normalizeExtension($originalExtension);

        $allocated = $this->mediaPaths->allocate($slug, $extension);
        $slug = $allocated['slug'];
        $filename = $allocated['filename'];
        $relativePath = $allocated['relative_path'];

        $tempPath = $this->createTempImagePath('binary', $extension);
        try {
            file_put_contents($tempPath, $binary);
            $this->applyConfiguredDimensionLimitsToPath($tempPath, $config);
            $quality = max(10, min(100, (int) $config->quality));
            if (! $this->imagePipeline->encodeFile($tempPath, $extension, $quality)) {
                throw new \RuntimeException('Không encode được ảnh binary.');
            }

            $encoded = file_get_contents($tempPath);
            if (! is_string($encoded) || $encoded === '') {
                throw new \RuntimeException('Ảnh binary rỗng sau xử lý.');
            }
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        $altText = $this->buildAltText($config, $article, $slug);

        return [
            'slug' => $slug,
            'filename' => $filename,
            'relative_path' => $relativePath,
            'alt_text' => $altText,
            'binary' => $encoded,
        ];
    }
}
