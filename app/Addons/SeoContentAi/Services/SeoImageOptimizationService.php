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

        $slug .= '-' . random_int(100, 999);

        $extension = $config->auto_convert_webp
            ? 'webp'
            : $this->normalizeExtension($originalExtension);

        $filename = $slug . '.' . $extension;
        $randomFolder = Str::random(10);
        $relativePath = "uploads/seo_media/{$randomFolder}/{$filename}";

        $image = Image::decodePath($file->getRealPath());

        if ($config->limit_dimensions) {
            $image->scaleDown(
                width: max(1, (int) $config->max_width),
                height: max(1, (int) $config->max_height),
            );
        }

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

        $image = Image::decodePath($absolutePath);

        if ($config->limit_dimensions) {
            $image->scaleDown(
                width: max(1, (int) $config->max_width),
                height: max(1, (int) $config->max_height),
            );
        }

        $currentExtension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($currentExtension === 'jpeg') {
            $currentExtension = 'jpg';
        }

        $targetExtension = $config->auto_convert_webp
            ? 'webp'
            : $this->normalizeExtension($currentExtension);

        $quality = max(10, min(100, (int) $config->quality));
        $format = $this->formatForExtension($targetExtension);
        $encoded = (string) $image->encodeUsingFormat($format, quality: $quality);

        $directory = dirname($absolutePath);
        $basename = pathinfo($absolutePath, PATHINFO_FILENAME);
        $newAbsolutePath = $directory . DIRECTORY_SEPARATOR . $basename . '.' . $targetExtension;

        file_put_contents($newAbsolutePath, $encoded);

        if ($newAbsolutePath !== $absolutePath && is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        return ['applied' => true, 'absolute_path' => $newAbsolutePath];
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
}
