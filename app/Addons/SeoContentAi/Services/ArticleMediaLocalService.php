<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;

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
     * @return array{attempted: bool, success: bool, message: string, synced_local_media_ids: list<int>}
     */
    public function pushPendingMediaToWordPress(SeoArticle $article): array
    {
        if (! $this->hasPendingMediaSync($article)) {
            return [
                'attempted' => false,
                'success' => true,
                'message' => '',
                'synced_local_media_ids' => [],
            ];
        }

        $article->loadMissing('articleMetas');
        $mediaService = app(WordPressArticleMediaService::class);
        $localMediaSync = app(WordPressLocalMediaSyncService::class);
        $messages = [];
        $syncErrors = [];
        $syncedLocalMediaIds = [];
        $ok = true;

        $featuredRefId = (int) ($article->articleMetas->firstWhere('meta_key', self::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        if ($featuredRefId > 0) {
            $resolved = $this->resolveWordPressAttachmentId($article, $featuredRefId, $localMediaSync);
            if ($resolved['seo_media_id'] !== null) {
                $syncedLocalMediaIds[] = (int) $resolved['seo_media_id'];
            }
            if (! ($resolved['success'] ?? false) || (int) ($resolved['attachment_id'] ?? 0) <= 0) {
                $ok = false;
                if (filled($resolved['message'] ?? null)) {
                    $syncErrors[] = (string) $resolved['message'];
                }
            } else {
                $featuredWpId = (int) $resolved['attachment_id'];
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => self::META_FEATURED_ATTACHMENT_ID],
                    ['meta_value' => (string) $featuredWpId],
                );
                $result = $mediaService->setFeaturedImage($article, $featuredWpId);
                $ok = $ok && ($result['success'] ?? false);
                if (filled($result['message'] ?? null)) {
                    $messages[] = (string) $result['message'];
                }
            }
        }

        $galleryRefs = $this->resolveGalleryAttachmentIds($article);
        if ($galleryRefs !== []) {
            $galleryWpIds = [];
            foreach ($galleryRefs as $refId) {
                $resolved = $this->resolveWordPressAttachmentId($article, (int) $refId, $localMediaSync);
                if ($resolved['seo_media_id'] !== null) {
                    $syncedLocalMediaIds[] = (int) $resolved['seo_media_id'];
                }
                if (! ($resolved['success'] ?? false) || (int) ($resolved['attachment_id'] ?? 0) <= 0) {
                    $ok = false;
                    if (filled($resolved['message'] ?? null)) {
                        $syncErrors[] = (string) $resolved['message'];
                    }
                    continue;
                }

                $galleryWpIds[] = (int) $resolved['attachment_id'];
            }

            $galleryWpIds = array_values(array_unique(array_filter($galleryWpIds, static fn (int $id): bool => $id > 0)));
            if ($galleryWpIds !== []) {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => self::META_PRODUCT_GALLERY_IDS],
                    ['meta_value' => json_encode($galleryWpIds, JSON_UNESCAPED_UNICODE)],
                );
                $result = $mediaService->setProductGallery($article, $galleryWpIds);
                $ok = $ok && ($result['success'] ?? false);
                if (filled($result['message'] ?? null)) {
                    $messages[] = (string) $result['message'];
                }
            }
        }

        if ($syncErrors !== []) {
            $messages = array_merge($messages, $syncErrors);
        }

        if ($ok) {
            $this->clearMediaPendingSync($article);
        }

        return [
            'attempted' => true,
            'success' => $ok,
            'message' => implode(' ', array_filter($messages)),
            'synced_local_media_ids' => array_values(array_unique(array_filter(array_map(
                static fn ($id): int => (int) $id,
                $syncedLocalMediaIds,
            )))),
        ];
    }

    /**
     * @return array{success: bool, attachment_id: int, seo_media_id: int|null, message: string}
     */
    private function resolveWordPressAttachmentId(
        SeoArticle $article,
        int $refId,
        WordPressLocalMediaSyncService $localMediaSync,
    ): array {
        if ($refId <= 0) {
            return [
                'success' => false,
                'attachment_id' => 0,
                'seo_media_id' => null,
                'message' => 'ID ảnh không hợp lệ.',
            ];
        }

        $media = SeoMedia::query()->whereKey($refId)->first();
        if (! $media instanceof SeoMedia) {
            return [
                'success' => true,
                'attachment_id' => $refId,
                'seo_media_id' => null,
                'message' => '',
            ];
        }

        $result = $localMediaSync->syncAttachmentRef($article, $refId);

        return [
            'success' => (bool) ($result['success'] ?? false),
            'attachment_id' => (int) ($result['attachment_id'] ?? 0),
            'seo_media_id' => isset($result['seo_media_id']) ? (int) $result['seo_media_id'] : null,
            'message' => (string) ($result['message'] ?? ''),
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
