<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tải ảnh/video từ URL (kết quả AI) và lưu vào thư viện seo_media nội bộ.
 */
final class PromptMediaStorageService
{
    public function __construct(
        private readonly SeoMediaStorageService $mediaStorage,
    ) {}

    /**
     * Nếu $rawOutput chứa URL media hợp lệ — tải, lưu disk public + seo_media, trả URL nội bộ (/storage/...).
     */
    public function persistRemoteMediaIfPresent(string $rawOutput, string $toolType, ?string $aiGenerator = null): string
    {
        if (! in_array($toolType, ['image', 'video'], true)) {
            return $rawOutput;
        }

        $firstLine = trim(strtok($rawOutput, "\n") ?: $rawOutput);
        if (str_starts_with($firstLine, '/storage/')) {
            return $firstLine;
        }

        $remoteUrl = $this->extractUrl($rawOutput);
        if ($remoteUrl === '' || ! filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            return $rawOutput;
        }

        $storedUrl = $this->downloadAndStore($remoteUrl, $toolType, $aiGenerator);

        return $storedUrl ?? $rawOutput;
    }

    /**
     * Lưu bytes ảnh/video từ API (inlineData) vào thư viện nội bộ.
     */
    public function storeBinaryMedia(
        string $binary,
        string $mimeType,
        string $toolType = 'image',
        ?string $aiGenerator = null,
    ): string
    {
        $ext = $this->extensionFromMime($mimeType, $toolType);
        $randomFolder = Str::random(10);
        $slug = 'gen-' . time() . '-' . random_int(100, 999);
        $filename = "{$slug}.{$ext}";
        $relativePath = "uploads/seo_media/{$randomFolder}/{$filename}";

        Storage::disk('public')->put($relativePath, $binary);

        $publicUrl = $this->mediaStorage->urlForPath($relativePath);

        SeoMedia::query()->create([
            'filename' => $filename,
            'slug' => $slug,
            'path' => $relativePath,
            'url' => $publicUrl,
            'source' => 'ai_prompt',
            'ai_generator' => $aiGenerator !== null ? trim($aiGenerator) : null,
        ]);

        return $publicUrl;
    }

    private function extensionFromMime(string $mimeType, string $toolType): string
    {
        return $this->resolveExtension('', $toolType, $mimeType);
    }

    private function downloadAndStore(string $remoteUrl, string $toolType, ?string $aiGenerator = null): ?string
    {
        try {
            $response = Http::timeout(120)->get($remoteUrl);
            if (! $response->successful()) {
                return null;
            }

            $fileData = $response->body();
            if ($fileData === '') {
                return null;
            }

            $ext = $this->resolveExtension($remoteUrl, $toolType, (string) $response->header('Content-Type'));
            $randomFolder = Str::random(10);
            $slug = 'gen-' . time() . '-' . random_int(100, 999);
            $filename = "{$slug}.{$ext}";
            $relativePath = "uploads/seo_media/{$randomFolder}/{$filename}";

            Storage::disk('public')->put($relativePath, $fileData);

            $publicUrl = $this->mediaStorage->urlForPath($relativePath);

            SeoMedia::query()->create([
                'filename' => $filename,
                'slug' => $slug,
                'path' => $relativePath,
                'url' => $publicUrl,
                'source' => 'ai_prompt',
                'ai_generator' => $aiGenerator !== null ? trim($aiGenerator) : null,
            ]);

            return $publicUrl;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveExtension(string $remoteUrl, string $toolType, string $contentType): string
    {
        $pathExt = strtolower((string) pathinfo(parse_url($remoteUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if (in_array($pathExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov'], true)) {
            return $pathExt === 'jpeg' ? 'jpg' : $pathExt;
        }

        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        return match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default => $toolType === 'video' ? 'mp4' : 'png',
        };
    }

    private function extractUrl(string $value): string
    {
        $value = trim($value);

        if (preg_match('/\((https?:\/\/[^)]+)\)/i', $value, $matches) === 1) {
            return (string) $matches[1];
        }

        if (preg_match('#https?://[^\s<>"\'\)]+#i', $value, $matches) === 1) {
            return rtrim((string) $matches[0], '.,;');
        }

        return $value;
    }
}
