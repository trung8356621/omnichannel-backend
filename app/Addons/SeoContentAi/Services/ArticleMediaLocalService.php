<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;

final class ArticleMediaLocalService
{
    public const META_FEATURED_URL = 'wp_featured_image_url';

    public const META_FEATURED_ATTACHMENT_ID = 'wp_featured_attachment_id';

    public const META_PRODUCT_GALLERY = 'wp_product_gallery';

    public const META_PRODUCT_GALLERY_IDS = 'wp_product_gallery_attachment_ids';

    public const META_MEDIA_PENDING_SYNC = 'wp_media_pending_sync';

    public function applyFeaturedLocal(SeoArticle $article, int $attachmentId, string $url): void
    {
        $url = trim($url);
        if ($attachmentId <= 0 || $url === '') {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_FEATURED_URL],
            ['meta_value' => $url],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_FEATURED_ATTACHMENT_ID],
            ['meta_value' => (string) $attachmentId],
        );
        $this->markMediaPendingSync($article);
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    public function appendGalleryLocal(SeoArticle $article, int $attachmentId, string $url): array
    {
        $url = trim($url);
        if ($attachmentId <= 0 || $url === '') {
            return $this->resolveGallery($article);
        }

        $gallery = $this->resolveGallery($article);
        foreach ($gallery as $item) {
            if ((int) ($item['id'] ?? 0) === $attachmentId) {
                return $gallery;
            }
        }

        $gallery[] = [
            'id' => $attachmentId,
            'url' => $url,
        ];

        $ids = array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $gallery);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_PRODUCT_GALLERY],
            ['meta_value' => json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_PRODUCT_GALLERY_IDS],
            ['meta_value' => json_encode($ids, JSON_UNESCAPED_UNICODE)],
        );
        $this->markMediaPendingSync($article);

        return $gallery;
    }

    /**
     * @return array{attempted: bool, success: bool, message: string}
     */
    public function pushPendingMediaToWordPress(SeoArticle $article): array
    {
        if (! $this->hasPendingMediaSync($article)) {
            return [
                'attempted' => false,
                'success' => true,
                'message' => '',
            ];
        }

        $article->loadMissing('articleMetas');
        $mediaService = app(WordPressArticleMediaService::class);
        $messages = [];
        $ok = true;

        $featuredId = (int) ($article->articleMetas->firstWhere('meta_key', self::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        if ($featuredId > 0) {
            $result = $mediaService->setFeaturedImage($article, $featuredId);
            $ok = $ok && ($result['success'] ?? false);
            if (filled($result['message'] ?? null)) {
                $messages[] = (string) $result['message'];
            }
        }

        $galleryIds = $this->resolveGalleryAttachmentIds($article);
        if ($galleryIds !== []) {
            $result = $mediaService->setProductGallery($article, $galleryIds);
            $ok = $ok && ($result['success'] ?? false);
            if (filled($result['message'] ?? null)) {
                $messages[] = (string) $result['message'];
            }
        }

        if ($ok) {
            $this->clearMediaPendingSync($article);
        }

        return [
            'attempted' => true,
            'success' => $ok,
            'message' => implode(' ', array_filter($messages)),
        ];
    }

    public function hasPendingMediaSync(SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');

        return trim((string) ($article->articleMetas->firstWhere('meta_key', self::META_MEDIA_PENDING_SYNC)?->meta_value ?? '')) === '1';
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    public function resolveGallery(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', self::META_PRODUCT_GALLERY)?->meta_value ?? '';
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? app(WordPressArticleContentService::class)->normalizeProductGallery($decoded)
            : [];
    }

    /**
     * @return list<int>
     */
    public function resolveGalleryAttachmentIds(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', self::META_PRODUCT_GALLERY_IDS)?->meta_value ?? '';
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map(static fn ($id): int => (int) $id, $decoded), static fn (int $id): bool => $id > 0));
            }
        }

        return array_values(array_filter(array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $this->resolveGallery($article),
        ), static fn (int $id): bool => $id > 0));
    }

    private function markMediaPendingSync(SeoArticle $article): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_MEDIA_PENDING_SYNC],
            ['meta_value' => '1'],
        );
    }

    private function clearMediaPendingSync(SeoArticle $article): void
    {
        $article->articleMetas()->where('meta_key', self::META_MEDIA_PENDING_SYNC)->delete();
    }
}
