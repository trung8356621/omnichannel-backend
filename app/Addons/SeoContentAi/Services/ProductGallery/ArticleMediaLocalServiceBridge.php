<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ProductGallery;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Services\ArticleMediaLocalService;

/**
 * Thin album adapter for Mode 2 coordinator (keeps selection persist path centralized).
 */
final class ArticleMediaLocalServiceBridge
{
    public function __construct(
        private readonly ArticleMediaLocalService $album,
    ) {}

    /**
     * @param  list<int>  $mediaIds
     */
    public function replaceAlbum(SeoArticle $article, array $mediaIds): void
    {
        $items = [];
        foreach ($mediaIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $media = SeoMedia::query()->find($id);
            if (! $media instanceof SeoMedia) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'url' => $media->publicUrl(),
            ];
        }

        if ($items === []) {
            return;
        }

        $this->album->replaceProductAlbumLocal($article, $items);
    }
}
