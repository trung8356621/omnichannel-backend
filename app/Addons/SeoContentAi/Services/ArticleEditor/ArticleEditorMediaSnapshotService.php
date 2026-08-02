<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ArticleEditor;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ArticleMediaLocalService;
use App\Addons\SeoContentAi\Services\ArticlePostImagesService;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;

/**
 * Canonical Article Editor media snapshot (Featured + Gallery + content image counts).
 * Laravel owns persistence; React owns presentation only.
 */
final class ArticleEditorMediaSnapshotService
{
    public const META_SNAPSHOT_VERSION = 'editor_media_snapshot_version';

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly ArticleMediaLocalService $mediaLocal,
        private readonly ArticlePostImagesService $postImages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(SeoArticle $article, ?User $viewer = null): array
    {
        $article->loadMissing(['articleMetas', 'site']);
        $article->refresh();
        $article->unsetRelation('articleMetas');
        $article->load('articleMetas');

        $supportsGallery = $this->supportsProductGallery($article);
        $featured = $this->buildFeatured($article, $supportsGallery);
        $gallery = $this->buildGallery($article, $supportsGallery, $featured);
        $contentImages = $this->buildContentImagesSummary($article);
        $version = $this->currentVersion($article);

        return [
            'version' => self::SCHEMA_VERSION,
            'snapshot_version' => $version,
            'article_id' => (int) $article->getKey(),
            'document_version' => max(1, (int) ($article->document_version ?? 1)),
            'generated_at' => now()->utc()->toIso8601String(),
            'featured' => $featured,
            'content_images' => $contentImages,
            'gallery' => $gallery,
            'capabilities' => $this->capabilities($article, $viewer, $supportsGallery),
        ];
    }

    public function currentVersion(SeoArticle $article): int
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas
            ->firstWhere('meta_key', self::META_SNAPSHOT_VERSION)?->meta_value;

        return max(1, (int) $raw);
    }

    public function bumpVersion(SeoArticle $article): int
    {
        $next = $this->currentVersion($article) + 1;
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_SNAPSHOT_VERSION],
            ['meta_value' => (string) $next],
        );
        $article->unsetRelation('articleMetas');

        return $next;
    }

    public function assertExpectedVersion(SeoArticle $article, int|string|null $expected): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        $expectedInt = (int) $expected;
        if ($expectedInt <= 0) {
            return;
        }

        $current = $this->currentVersion($article);
        if ($expectedInt !== $current) {
            throw ArticleEditorSessionException::make(
                'media_snapshot_version_conflict',
                'Media snapshot version conflict.',
                [
                    'expected_snapshot_version' => $expectedInt,
                    'snapshot_version' => $current,
                ],
                409,
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildFeatured(SeoArticle $article, bool $supportsGallery): ?array
    {
        $url = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_URL)?->meta_value ?? ''));
        $attachmentId = (int) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);

        if ($url === '' && $supportsGallery) {
            $album = $this->mediaLocal->resolveProductAlbum($article);
            if ($album !== []) {
                $url = trim((string) ($album[0]['url'] ?? ''));
                $attachmentId = (int) ($album[0]['id'] ?? 0);
            }
        }

        if ($url === '') {
            return null;
        }

        return $this->enrichMediaItem([
            'media_id' => null,
            'wp_attachment_id' => $attachmentId > 0 ? $attachmentId : null,
            'url' => $url,
            'alt' => '',
            'title' => '',
            'position' => 0,
        ], (int) ($article->site_id ?? 0));
    }

    /**
     * @param  array<string, mixed>|null  $featured
     * @return array{required: bool, items: list<array<string, mixed>>}
     */
    private function buildGallery(SeoArticle $article, bool $supportsGallery, ?array $featured): array
    {
        if (! $supportsGallery) {
            return [
                'required' => false,
                'items' => [],
            ];
        }

        $album = $this->mediaLocal->resolveProductAlbum($article);
        $items = [];
        foreach ($album as $index => $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $attachmentId = (int) ($row['id'] ?? 0);
            $items[] = $this->enrichMediaItem([
                'id' => $this->stableGalleryItemId($attachmentId, $url, $index),
                'media_id' => null,
                'wp_attachment_id' => $attachmentId > 0 ? $attachmentId : null,
                'url' => $url,
                'alt' => '',
                'title' => '',
                'position' => $index,
            ], (int) ($article->site_id ?? 0));
        }

        return [
            'required' => true,
            'items' => $items,
        ];
    }

    /**
     * @return array{occurrence_count: int, valid_count: int, invalid_count: int, items: list<array<string, mixed>>}
     */
    private function buildContentImagesSummary(SeoArticle $article): array
    {
        $rows = $this->postImages->resolveForArticle($article);
        $items = [];
        $valid = 0;
        $invalid = 0;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? $row['src'] ?? $row['full_url'] ?? ''));
            $exists = $url !== '' && ! str_starts_with($url, 'blob:');
            if ($exists) {
                $valid++;
            } else {
                $invalid++;
            }
            $items[] = [
                'position' => $index,
                'url' => $url !== '' ? $url : null,
                'exists' => $exists,
                'integrity' => [
                    'status' => $exists ? 'healthy' : 'error',
                    'reasons' => $exists ? [] : ['content_image_missing'],
                ],
            ];
        }

        return [
            'occurrence_count' => count($items),
            'valid_count' => $valid,
            'invalid_count' => $invalid,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function enrichMediaItem(array $base, int $siteId): array
    {
        $url = trim((string) ($base['url'] ?? ''));
        $wpId = (int) ($base['wp_attachment_id'] ?? 0);
        $seoMedia = $this->findSeoMedia($siteId, $wpId, $url);

        $mediaId = $seoMedia instanceof SeoMedia ? (int) $seoMedia->getKey() : null;
        $alt = trim((string) ($seoMedia?->alt_text ?? $seoMedia?->alt ?? $base['alt'] ?? ''));
        $title = trim((string) ($seoMedia?->title ?? $base['title'] ?? ''));
        $filename = $this->filenameFromUrl($url);
        $source = $this->classifySource($seoMedia, $wpId, $url);
        $exists = $url !== '' && ! str_starts_with($url, 'blob:') && ! str_starts_with($url, 'data:');
        $uploadIncomplete = str_starts_with($url, 'blob:') || str_contains($url, 'placeholder-loading');

        $reasons = [];
        $status = 'healthy';
        if (! $exists || $uploadIncomplete) {
            $status = 'error';
            $reasons[] = $uploadIncomplete ? 'featured_upload_incomplete' : 'media_reference_broken';
        } elseif ($alt === '') {
            $status = 'warning';
            $reasons[] = 'featured_alt_missing';
        }

        // WP filename ≠ keyword is NOT a hard error (Phase 2A).

        return [
            'id' => $base['id'] ?? ($mediaId !== null ? 'media:'.$mediaId : ($wpId > 0 ? 'wp:'.$wpId : 'url:'.substr(hash('sha256', $url), 0, 12))),
            'media_id' => $mediaId,
            'wp_attachment_id' => $wpId > 0 ? $wpId : null,
            'source' => $source,
            'url' => $url,
            'thumbnail_url' => $url,
            'filename' => $filename,
            'alt' => $alt,
            'title' => $title,
            'position' => (int) ($base['position'] ?? 0),
            'exists' => $exists && ! $uploadIncomplete,
            'upload_status' => $uploadIncomplete ? 'pending' : ($exists ? 'ready' : 'missing'),
            'integrity' => [
                'status' => $status,
                'reasons' => $reasons,
            ],
        ];
    }

    private function findSeoMedia(int $siteId, int $wpAttachmentId, string $url): ?SeoMedia
    {
        if ($siteId <= 0) {
            return null;
        }

        if ($wpAttachmentId > 0) {
            $byWp = SeoMedia::query()
                ->where('site_id', $siteId)
                ->where('wp_attachment_id', $wpAttachmentId)
                ->orderByDesc('id')
                ->first();
            if ($byWp instanceof SeoMedia) {
                return $byWp;
            }
        }

        if ($url === '') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $relativePath = str_starts_with($path, '/storage/')
            ? ltrim(substr($path, strlen('/storage/')), '/')
            : '';

        $byUrl = SeoMedia::query()
            ->where('site_id', $siteId)
            ->where(function ($q) use ($url, $path, $relativePath): void {
                $q->where('url', $url);

                if ($path !== '') {
                    $q->orWhere('url', $path);
                }

                if ($relativePath !== '') {
                    $q->orWhere('path', $relativePath);
                }
            })
            ->orderByDesc('id')
            ->first();

        return $byUrl instanceof SeoMedia ? $byUrl : null;
    }

    private function classifySource(?SeoMedia $media, int $wpAttachmentId, string $url): string
    {
        if ($media instanceof SeoMedia) {
            $kind = strtolower(trim((string) ($media->source_type ?? $media->kind ?? '')));
            if (in_array($kind, ['wordpress', 'wp', 'local', 'generated', 'uploaded'], true)) {
                return $kind === 'wp' ? 'wordpress' : $kind;
            }
            if ((int) ($media->wp_attachment_id ?? 0) > 0) {
                return 'wordpress';
            }
            if (trim((string) ($media->ai_job_id ?? '')) !== '' || str_contains(strtolower((string) ($media->status ?? '')), 'generat')) {
                return 'generated';
            }

            return 'local';
        }

        if ($wpAttachmentId > 0 || str_contains($url, '/wp-content/uploads/')) {
            return 'wordpress';
        }
        if (str_contains($url, '/storage/seo/') || str_contains($url, '/seo-media/')) {
            return 'local';
        }

        return $url !== '' ? 'uploaded' : 'unknown';
    }

    private function filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return '';
        }

        return basename($path);
    }

    private function stableGalleryItemId(int $attachmentId, string $url, int $index): string
    {
        if ($attachmentId > 0) {
            return 'wp:'.$attachmentId;
        }

        return 'url:'.substr(hash('sha256', $url.'|'.$index), 0, 16);
    }

    private function supportsProductGallery(SeoArticle $article): bool
    {
        $postType = ArticlePostTypeResolver::resolve($article);
        if ($postType === SeoProjectTask::POST_TYPE_PRODUCT) {
            return true;
        }

        return strtolower(trim((string) ($article->articleMetas->firstWhere('meta_key', 'canary_type')?->meta_value ?? ''))) === 'product_gallery';
    }

    /**
     * @return array<string, bool>
     */
    private function capabilities(SeoArticle $article, ?User $viewer, bool $supportsGallery): array
    {
        $canEdit = SeoAccessControl::canAccessArticle($article);
        $archived = false;
        try {
            app(ArticleEditorSessionService::class)->assertArticleEditable($article);
        } catch (ArticleEditorSessionException) {
            $archived = true;
            $canEdit = false;
        }

        $canRenameWp = $viewer instanceof User
            && SeoAccessControl::canAccessManagerFeatures();

        return [
            'can_edit_featured' => $canEdit && ! $archived,
            'can_edit_gallery' => $canEdit && ! $archived && $supportsGallery,
            'can_browse_wordpress_media' => $canEdit,
            'can_upload_local_media' => $canEdit && ! $archived,
            'can_rename_wordpress_media' => $canRenameWp && ! $archived,
            'gallery_required' => $supportsGallery,
            'content_project_archived' => $archived,
        ];
    }
}
