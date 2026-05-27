<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoImageOptimizationSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Format;
use Intervention\Image\Laravel\Facades\Image;

class SeoImageOptimizationService
{
    public function __construct(
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SeoMediaPathAllocator $mediaPaths,
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

        return $global ?? new SeoImageOptimizationSetting();
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
                : 'img-' . time();
        }

        if ($slug === '') {
            $slug = 'img-' . time();
        }

        $extension = $config->auto_convert_webp
            ? 'webp'
            : $this->normalizeExtension($originalExtension);

        $allocated = $this->mediaPaths->allocate($slug, $extension);
        $slug = $allocated['slug'];
        $filename = $allocated['filename'];
        $relativePath = $allocated['relative_path'];

        $image = Image::decodePath($file->getRealPath());
        $this->applyConfiguredDimensionLimits($image, $config);

        $quality = max(10, min(100, (int) $config->quality));
        $format = $this->formatForExtension($extension);
        $encoded = $image->encodeUsingFormat($format, quality: $quality);

        $altText = $this->buildAltText($config, $article, $slug);

        return [
            'slug' => $slug,
            'filename' => $filename,
            'relative_path' => $relativePath,
            'alt_text' => $altText,
            'binary' => (string) $encoded,
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

    private function formatForExtension(string $extension): Format
    {
        return match ($extension) {
            'webp' => Format::WEBP,
            'jpg', 'jpeg' => Format::JPEG,
            'gif' => Format::GIF,
            default => Format::PNG,
        };
    }

    public function isWebpPath(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'webp';
    }

    /**
     * Tối ưu file trên đĩa (resize, nén, chuyển WebP). Bỏ qua nếu đã là .webp.
     *
     * @return array{applied: bool, absolute_path: string}
     */
    public function optimizeAbsolutePath(string $absolutePath, SeoImageOptimizationSetting $config): array
    {
        if (! is_file($absolutePath) || $this->isWebpPath($absolutePath)) {
            return ['applied' => false, 'absolute_path' => $absolutePath];
        }

        try {
            $encoded = $this->encodeOptimizedImage($absolutePath, $config, (bool) $config->auto_convert_webp);
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

        $targetExtension = $config->auto_convert_webp
            ? 'webp'
            : $this->normalizeExtension($currentExtension);

        $directory = dirname($absolutePath);
        $basename = pathinfo($absolutePath, PATHINFO_FILENAME);
        $newAbsolutePath = $directory . DIRECTORY_SEPARATOR . $basename . '.' . $targetExtension;

        file_put_contents($newAbsolutePath, $encoded);

        if ($newAbsolutePath !== $absolutePath && is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        return ['applied' => true, 'absolute_path' => $newAbsolutePath];
    }

    /**
     * Tối ưu tạm để upload WordPress: giữ JPEG/PNG (không WebP), không đổi file gốc trên disk.
     *
     * @return array{path: string, temporary: bool, mime: string}|null
     */
    public function prepareWordPressUploadFile(string $absolutePath, SeoImageOptimizationSetting $config): ?array
    {
        if (! is_file($absolutePath) || ! $this->isValidImageFile($absolutePath)) {
            return null;
        }

        try {
            $encoded = $this->encodeOptimizedImage($absolutePath, $config, false);
        } catch (\Throwable) {
            return [
                'path' => $absolutePath,
                'temporary' => false,
                'mime' => $this->mimeFromPath($absolutePath),
            ];
        }

        if ($encoded === null) {
            return [
                'path' => $absolutePath,
                'temporary' => false,
                'mime' => $this->mimeFromPath($absolutePath),
            ];
        }

        $currentExtension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($currentExtension === 'jpeg') {
            $currentExtension = 'jpg';
        }
        if ($currentExtension === 'webp') {
            $currentExtension = 'jpg';
        }

        $targetExtension = $this->normalizeExtension($currentExtension);
        $tempBase = tempnam(sys_get_temp_dir(), 'seo_wp_upload_');
        if ($tempBase === false) {
            return [
                'path' => $absolutePath,
                'temporary' => false,
                'mime' => $this->mimeFromPath($absolutePath),
            ];
        }

        @unlink($tempBase);
        $tempPath = $tempBase . '.' . $targetExtension;
        file_put_contents($tempPath, $encoded);

        if (! $this->isValidImageFile($tempPath)) {
            @unlink($tempPath);

            return [
                'path' => $absolutePath,
                'temporary' => false,
                'mime' => $this->mimeFromPath($absolutePath),
            ];
        }

        return [
            'path' => $tempPath,
            'temporary' => true,
            'mime' => $this->mimeFromPath($tempPath),
        ];
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
        $image = Image::decodePath($absolutePath);
        $this->applyConfiguredDimensionLimits($image, $config);

        $currentExtension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($currentExtension === 'jpeg') {
            $currentExtension = 'jpg';
        }

        $targetExtension = $convertToWebp
            ? 'webp'
            : $this->normalizeExtension($currentExtension === 'webp' ? 'jpg' : $currentExtension);

        $quality = max(10, min(100, (int) $config->quality));
        $format = $this->formatForExtension($targetExtension);
        $encoded = (string) $image->encodeUsingFormat($format, quality: $quality);

        return strlen($encoded) >= 256 ? $encoded : null;
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
     * Chỉ giới hạn một chiều: width hoặc height (không scaleDown cả hai — tránh méo/cắt).
     */
    public function applyConfiguredDimensionLimits(mixed $image, SeoImageOptimizationSetting $config): void
    {
        if (! $config->limit_dimensions) {
            return;
        }

        $maxWidth = max(0, (int) $config->max_width);
        $maxHeight = max(0, (int) $config->max_height);

        if ($maxWidth > 0 && $maxHeight <= 0) {
            $image->scaleDown(width: max(1, $maxWidth));

            return;
        }

        if ($maxHeight > 0 && $maxWidth <= 0) {
            $image->scaleDown(height: max(1, $maxHeight));

            return;
        }

        if ($maxWidth > 0) {
            $image->scaleDown(width: max(1, $maxWidth));
        }
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
            : 'img-' . time();

        if ($config->clean_filename) {
            $slug = Str::slug($seed);
        } else {
            $slug = Str::slug($seed) !== '' ? Str::slug($seed) : 'img-' . time();
        }

        if ($slug === '') {
            $slug = 'img-' . time();
        }

        $extension = $config->auto_convert_webp
            ? 'webp'
            : $this->normalizeExtension($originalExtension);

        $allocated = $this->mediaPaths->allocate($slug, $extension);
        $slug = $allocated['slug'];
        $filename = $allocated['filename'];
        $relativePath = $allocated['relative_path'];

        $image = Image::decodeBinary($binary);
        $this->applyConfiguredDimensionLimits($image, $config);

        $quality = max(10, min(100, (int) $config->quality));
        $format = $this->formatForExtension($extension);
        $encoded = $image->encodeUsingFormat($format, quality: $quality);

        $altText = $this->buildAltText($config, $article, $slug);

        return [
            'slug' => $slug,
            'filename' => $filename,
            'relative_path' => $relativePath,
            'alt_text' => $altText,
            'binary' => (string) $encoded,
        ];
    }
}
