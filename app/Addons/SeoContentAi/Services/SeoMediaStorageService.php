<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeoMediaStorageService
{
    public function __construct(
        private readonly SeoImageOptimizationService $optimization,
    ) {}

    public function storeUpload(
        UploadedFile $file,
        ?int $siteId = null,
        ?int $articleId = null,
        string $source = 'clipboard',
    ): SeoMedia {
        $article = $articleId !== null
            ? SeoArticle::query()->find($articleId)
            : null;

        $config = $this->optimization->resolveForSite($siteId);
        $processed = $this->optimization->processUpload($file, $config, $article);

        Storage::disk('public')->put($processed['relative_path'], $processed['binary']);

        $media = SeoMedia::query()->create([
            'site_id' => $siteId,
            'article_id' => $articleId,
            'filename' => $processed['filename'],
            'slug' => $processed['slug'],
            'path' => $processed['relative_path'],
            'url' => $this->urlForPath($processed['relative_path']),
            'source' => $source,
            'alt_text' => $processed['alt_text'],
        ]);

        if ($siteId !== null) {
            app(SeoWatermarkService::class)->applyToMediaIfEnabled($media);
        }

        return $media->fresh();
    }

    public function renameBySlug(SeoMedia $media, string $newSlug): SeoMedia
    {
        $newSlug = Str::slug($newSlug);
        if ($newSlug === '') {
            throw new \InvalidArgumentException('Slug không hợp lệ.');
        }

        $oldPath = (string) $media->path;
        $extension = pathinfo($oldPath, PATHINFO_EXTENSION) ?: pathinfo((string) $media->filename, PATHINFO_EXTENSION);
        $directory = dirname(str_replace('\\', '/', $oldPath));
        $newFilename = $newSlug . ($extension !== '' ? '.' . $extension : '');
        $newPath = $directory . '/' . $newFilename;

        $disk = Storage::disk('public');
        if ($disk->exists($oldPath)) {
            if ($disk->exists($newPath) && $newPath !== $oldPath) {
                throw new \RuntimeException('Đã tồn tại file cùng tên trong thư mục.');
            }

            $disk->move($oldPath, $newPath);
        }

        $media->update([
            'filename' => $newFilename,
            'slug' => $newSlug,
            'path' => $newPath,
            'url' => $this->urlForPath($newPath),
        ]);

        return $media->fresh();
    }

    public function urlForPath(string $relativePath): string
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');

        return '/storage/' . $normalized;
    }
}
