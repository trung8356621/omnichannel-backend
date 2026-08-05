<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\ArticleFeaturedImageResolver;
use App\Addons\SeoContentAi\Support\ArticleFeaturedImageSource;
use App\Addons\SeoContentAi\Support\ArticleFeaturedImageStatus;
use Illuminate\Support\Facades\Schema;

/**
 * Write-through + persist canonical featured projection on articles.*.
 * List GET only reads columns — never rebuilds.
 */
final class ArticleFeaturedImageProjection
{
    public function __construct(
        private readonly ArticleFeaturedImageResolver $resolver,
    ) {}

    /**
     * @return array{status: string, url: ?string, media_id: ?int, source: ?string}
     */
    public function forList(SeoArticle $article): array
    {
        return $this->resolver->forList($article);
    }

    public function syncAvailable(
        SeoArticle $article,
        string $url,
        ?int $mediaId,
        string $source,
    ): void {
        $url = trim($url);
        if ($url === '' || ! $this->hasProjectionColumns()) {
            return;
        }

        $this->write($article, [
            'featured_thumb_url' => $url,
            'featured_media_id' => $mediaId !== null && $mediaId > 0 ? $mediaId : null,
            'featured_image_status' => ArticleFeaturedImageStatus::AVAILABLE,
            'featured_image_source' => $source,
        ]);
    }

    public function clear(SeoArticle $article): void
    {
        if (! $this->hasProjectionColumns()) {
            return;
        }

        $this->write($article, [
            'featured_thumb_url' => null,
            'featured_media_id' => null,
            'featured_image_status' => ArticleFeaturedImageStatus::ABSENT,
            'featured_image_source' => ArticleFeaturedImageSource::CLEARED,
        ]);
    }

    /**
     * Rebuild from stored metas / SeoMedia and persist (backfill / write-through repair).
     *
     * @return array{status: string, url: ?string, media_id: ?int, source: string, conflict: bool, changed: bool}
     */
    public function rebuildAndPersist(SeoArticle $article, bool $persist = true): array
    {
        $built = $this->resolver->rebuildFromStoredSources($article);
        $payload = [
            'featured_thumb_url' => $built['url'],
            'featured_media_id' => $built['media_id'],
            'featured_image_status' => $built['status'],
            'featured_image_source' => $built['source'],
        ];

        $changed = $this->projectionDiffers($article, $payload);
        if ($persist && $changed && $this->hasProjectionColumns()) {
            $this->write($article, $payload);
        }

        return [
            'status' => $built['status'],
            'url' => $built['url'],
            'media_id' => $built['media_id'],
            'source' => $built['source'],
            'conflict' => $built['conflict'],
            'changed' => $changed,
        ];
    }

    /**
     * @param  array{featured_thumb_url: ?string, featured_media_id: ?int, featured_image_status: string, featured_image_source: string}  $payload
     */
    private function write(SeoArticle $article, array $payload): void
    {
        $article->forceFill($payload);
        $article->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function projectionDiffers(SeoArticle $article, array $payload): bool
    {
        $attrs = $article->getAttributes();
        foreach ($payload as $key => $value) {
            $current = $attrs[$key] ?? null;
            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function hasProjectionColumns(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        try {
            $ok = Schema::connection('omi_seo_ai')->hasColumn('articles', 'featured_image_status');
        } catch (\Throwable) {
            $ok = false;
        }

        return $ok;
    }
}
