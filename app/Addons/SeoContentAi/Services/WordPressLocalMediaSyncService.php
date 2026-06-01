<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class WordPressLocalMediaSyncService
{
    /** @var array<int, array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}> */
    private array $cache = [];

    /**
     * @return array{html: string, synced_media_ids: list<int>, errors: list<string>}
     */
    public function syncHtml(SeoArticle $article, string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [
                'html' => '',
                'synced_media_ids' => [],
                'errors' => [],
            ];
        }

        $syncedMediaIds = [];
        $errors = [];
        $updatedHtml = $html;

        foreach ($this->extractLocalSeoMediaUrls($html) as $originalUrl) {
            try {
                $path = $this->urlToSeoMediaPath($originalUrl);
                if ($path === '') {
                    continue;
                }

                $media = SeoMedia::query()->where('path', $path)->orderByDesc('id')->first();
                if (! $media instanceof SeoMedia) {
                    $errors[] = "Không tìm thấy seo_media cho URL {$originalUrl}.";
                    continue;
                }

                $result = $this->syncMedia($article, $media);
                if (! $result['success']) {
                    $errors[] = $result['message'];
                    continue;
                }

                if ($result['seo_media_id'] !== null) {
                    $syncedMediaIds[] = $result['seo_media_id'];
                }

                $wpUrl = trim($result['wp_url']);
                if ($wpUrl === '') {
                    $errors[] = "Ảnh #{$media->id}: không lấy được URL WordPress để thay src.";
                    continue;
                }

                $updatedHtml = str_replace($originalUrl, $wpUrl, $updatedHtml);
            } catch (Throwable $exception) {
                Log::warning('WordPress syncHtml URL replace failed', [
                    'article_id' => $article->id,
                    'url' => $originalUrl,
                    'error' => $exception->getMessage(),
                ]);
                $errors[] = "Lỗi sync URL {$originalUrl}: {$exception->getMessage()}";
            }
        }

        $byId = $this->applyWpUrlsToSeoMediaImages($article, $updatedHtml);
        $updatedHtml = $byId['html'];
        $syncedMediaIds = array_merge($syncedMediaIds, $byId['synced_media_ids']);
        $errors = array_merge($errors, $byId['errors']);

        return [
            'html' => $updatedHtml,
            'synced_media_ids' => array_values(array_unique($syncedMediaIds)),
            'errors' => array_values(array_unique(array_filter($errors))),
        ];
    }

    /**
     * @return array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}
     */
    public function syncAttachmentRef(SeoArticle $article, int $refId): array
    {
        if ($refId <= 0) {
            return [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => null,
                'message' => 'ID ảnh không hợp lệ.',
            ];
        }

        $media = SeoMedia::query()->whereKey($refId)->first();
        if (! $media instanceof SeoMedia) {
            return [
                'success' => true,
                'attachment_id' => $refId,
                'wp_url' => '',
                'seo_media_id' => null,
                'message' => '',
            ];
        }

        return $this->syncMedia($article, $media);
    }

    /**
     * @param  list<int>  $mediaIds
     */
    public function cleanupSyncedLocalMedia(array $mediaIds): int
    {
        $deleted = 0;

        foreach (array_values(array_unique(array_map(static fn ($id): int => (int) $id, $mediaIds))) as $mediaId) {
            if ($mediaId <= 0) {
                continue;
            }

            $media = SeoMedia::query()->whereKey($mediaId)->first();
            if (! $media instanceof SeoMedia) {
                continue;
            }

            $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
            $isUploadedFile = str_starts_with($path, 'uploads/seo_media/');
            if ($isUploadedFile && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            $media->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param list<int> $mediaIds
     */
    public function markSyncedLocalMediaAsTrash(array $mediaIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $mediaIds,
        ), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return 0;
        }

        return SeoMedia::query()
            ->whereIn('id', $ids)
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhere('status', 'completed')
                    ->orWhere('status', 'processing')
                    ->orWhere('status', 'failed');
            })
            ->update([
                'status' => 'trash',
                'error_message' => null,
                'wp_synced_at' => now(),
            ]);
    }

    /**
     * Đồng bộ lại các ảnh local đã chỉnh sửa (có wp_attachment_id) lên WordPress.
     *
     * @return array{synced: int, errors: list<string>}
     */
    public function syncDirtyLocalMediaForArticle(SeoArticle $article): array
    {
        $articleId = (int) ($article->id ?? 0);
        if ($articleId <= 0) {
            return ['synced' => 0, 'errors' => []];
        }

        $errors = [];
        $synced = 0;

        $rows = SeoMedia::query()
            ->where('article_id', $articleId)
            ->whereNotNull('wp_attachment_id')
            ->where('wp_attachment_id', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'completed');
            })
            ->where(function ($query): void {
                $query->whereNull('wp_synced_at')
                    ->orWhereColumnAfterMeta('updated_at', '>', 'wp_synced_at');
            })
            ->orderBy('id')
            ->get();

        foreach ($rows as $media) {
            try {
                $result = $this->syncMedia($article, $media);
                if (! ($result['success'] ?? false)) {
                    $errors[] = (string) ($result['message'] ?? ("Ảnh #{$media->id}: đồng bộ thất bại."));
                    continue;
                }

                $synced++;
            } catch (Throwable $exception) {
                $errors[] = "Ảnh #{$media->id}: {$exception->getMessage()}";
            }
        }

        return [
            'synced' => $synced,
            'errors' => array_values(array_unique(array_filter($errors))),
        ];
    }

    /**
     * @return array{success: bool, attachment_id: int, wp_url: string, seo_media_id: int|null, message: string}
     */
    private function syncMedia(SeoArticle $article, SeoMedia $media): array
    {
        $mediaId = (int) $media->id;
        if (isset($this->cache[$mediaId])) {
            return $this->cache[$mediaId];
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: thiếu thông tin site.",
            ];
        }

        $media = $this->hydrateMediaUsageForArticle($media, $article);
        $mediaId = (int) $media->id;

        $existingAttachmentId = (int) ($media->wp_attachment_id ?? 0);
        $existingWpUrl = '';
        if ($existingAttachmentId > 0) {
            $existingWpUrl = $this->fetchWordPressAttachmentUrl($site, $existingAttachmentId);
            if ($existingWpUrl === '') {
                Log::warning('WordPress attachment URL missing, fallback to replace/import', [
                    'article_id' => $article->id,
                    'seo_media_id' => $mediaId,
                    'wp_attachment_id' => $existingAttachmentId,
                ]);
            }
        }

        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: thiếu migration token.",
            ];
        }

        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: không xác định được URL WordPress.",
            ];
        }

        $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            $fallback = $this->cloneRemoteMediaToArticleSite($media, $article);
            if ($fallback instanceof SeoMedia) {
                $media = $fallback;
                $mediaId = (int) $media->id;
                $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
            }
        }

        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: không tìm thấy file local.",
            ];
        }

        $absolutePath = Storage::disk('public')->path($path);
        $optimization = app(SeoImageOptimizationService::class);
        if (! $optimization->isValidImageFile($absolutePath)) {
            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: file ảnh local hỏng hoặc rỗng (kiểm tra lại file trên disk).",
            ];
        }

        $uploadFile = $optimization->prepareWordPressUploadFile(
            $absolutePath,
            $optimization->resolveForSite((int) $site->id),
        );
        if ($uploadFile === null) {
            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: không chuẩn bị được file upload.",
            ];
        }

        $uploadPath = (string) ($uploadFile['path'] ?? $absolutePath);
        $uploadMime = (string) ($uploadFile['mime'] ?? $optimization->mimeFromPath($uploadPath));
        $cleanupTemp = (bool) ($uploadFile['temporary'] ?? false);

        $binary = @file_get_contents($uploadPath);
        if (! is_string($binary) || strlen($binary) < 256) {
            if ($cleanupTemp && is_file($uploadPath)) {
                @unlink($uploadPath);
            }

            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: đọc file upload thất bại.",
            ];
        }

        $response = null;

        if ($existingAttachmentId > 0) {
            try {
                $replaceResponse = Http::timeout(120)
                    ->acceptJson()
                    ->withToken($writeToken)
                    ->attach('file', $binary, basename($uploadPath), [
                        'Content-Type' => $uploadMime,
                    ])
                    ->post($base . '/wp-json/omi-seo-ai/v1/attachments/' . $existingAttachmentId . '/replace-binary');

                if ($replaceResponse->successful()) {
                    $replaceBody = $replaceResponse->json();
                    $replaceUrl = is_array($replaceBody) ? trim((string) ($replaceBody['url'] ?? '')) : '';
                    if ($replaceUrl === '') {
                        $replaceUrl = $this->fetchWordPressAttachmentUrl($site, $existingAttachmentId);
                    }

                    if (
                        is_array($replaceBody)
                        && ($replaceBody['success'] ?? false)
                        && $replaceUrl !== ''
                    ) {
                        $media->update([
                            'wp_attachment_id' => $existingAttachmentId,
                            'wp_synced_at' => now(),
                        ]);

                        return $this->cache[$mediaId] = [
                            'success' => true,
                            'attachment_id' => $existingAttachmentId,
                            'wp_url' => $replaceUrl,
                            'seo_media_id' => (int) $media->id,
                            'message' => '',
                        ];
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('WordPress replace attachment binary failed, fallback to import', [
                    'article_id' => $article->id,
                    'seo_media_id' => $mediaId,
                    'wp_attachment_id' => $existingAttachmentId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($writeToken)
                ->attach('file', $binary, basename($uploadPath), [
                    'Content-Type' => $uploadMime,
                ])
                ->post($base . '/wp-json/omi-seo-ai/v1/attachments/import', [
                    'slug' => (string) ($media->slug ?? ''),
                    'title' => (string) ($media->slug ?? ''),
                    'alt_text' => (string) ($media->alt_text ?? $media->slug ?? ''),
                ]);
        } catch (Throwable $exception) {
            Log::warning('WordPress local media import failed', [
                'article_id' => $article->id,
                'seo_media_id' => $mediaId,
                'error' => $exception->getMessage(),
            ]);

            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: không kết nối được WordPress ({$exception->getMessage()}).",
            ];
        } finally {
            if ($cleanupTemp && is_file($uploadPath)) {
                @unlink($uploadPath);
            }
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('message') ?? $response->body());

            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: WordPress lỗi HTTP {$response->status()} ({$message}).",
            ];
        }

        $body = $response->json();
        $attachmentId = (int) ($body['attachment_id'] ?? 0);
        $wpUrl = trim((string) ($body['url'] ?? ''));
        if ($wpUrl === '' && $attachmentId > 0) {
            $wpUrl = $this->fetchWordPressAttachmentUrl($site, $attachmentId);
        }
        if (! is_array($body) || ! ($body['success'] ?? false) || $attachmentId <= 0 || $wpUrl === '') {
            $message = is_array($body) ? (string) ($body['message'] ?? 'Phản hồi không hợp lệ.') : 'Phản hồi không hợp lệ.';
            if ($existingAttachmentId > 0 && $existingWpUrl !== '') {
                return $this->cache[$mediaId] = [
                    'success' => true,
                    'attachment_id' => $existingAttachmentId,
                    'wp_url' => $existingWpUrl,
                    'seo_media_id' => $mediaId,
                    'message' => '',
                ];
            }

            return $this->cache[$mediaId] = [
                'success' => false,
                'attachment_id' => 0,
                'wp_url' => '',
                'seo_media_id' => $mediaId,
                'message' => "Ảnh #{$mediaId}: {$message}",
            ];
        }

        $media->update([
            'wp_attachment_id' => $attachmentId,
            'wp_synced_at' => now(),
        ]);

        return $this->cache[$mediaId] = [
            'success' => true,
            'attachment_id' => $attachmentId,
            'wp_url' => $wpUrl,
            'seo_media_id' => (int) $media->id,
            'message' => '',
        ];
    }

    private function hydrateMediaUsageForArticle(SeoMedia $media, SeoArticle $article): SeoMedia
    {
        $payload = [];
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId > 0 && (int) ($media->site_id ?? 0) <= 0) {
            $payload['site_id'] = $siteId;
        }

        $articleId = (int) ($article->id ?? 0);
        if ($articleId > 0) {
            $ids = SeoMedia::normalizeArticleIds($media->article_id);
            if (! in_array($articleId, $ids, true)) {
                $ids[] = $articleId;
                $payload['article_id'] = $ids;
            }
        }

        if ($payload === []) {
            return $media;
        }

        $media->update($payload);

        return $media->fresh() ?? $media;
    }

    private function cloneRemoteMediaToArticleSite(SeoMedia $media, SeoArticle $article): ?SeoMedia
    {
        $remoteUrl = trim((string) ($media->url ?? ''));
        if ($remoteUrl === '' || ! filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            return app(SeoMediaStorageService::class)->storeFromRemoteUrl(
                $remoteUrl,
                (int) ($article->site_id ?? 0) > 0 ? (int) $article->site_id : null,
                (int) ($article->id ?? 0) > 0 ? (int) $article->id : null,
            );
        } catch (Throwable $exception) {
            Log::warning('WordPress sync fallback remote clone failed', [
                'article_id' => (int) ($article->id ?? 0),
                'seo_media_id' => (int) ($media->id ?? 0),
                'url' => $remoteUrl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Gán src WordPress cho thẻ img có data-seo-media-id (kể cả src rỗng / localhost bị WP gỡ).
     *
     * @return array{html: string, synced_media_ids: list<int>, errors: list<string>}
     */
    private function applyWpUrlsToSeoMediaImages(SeoArticle $article, string $html): array
    {
        $syncedMediaIds = [];
        $errors = [];

        if (! preg_match_all('/<img\b[^>]*\bdata-seo-media-id\s*=\s*["\']?(\d+)["\']?[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [
                'html' => $html,
                'synced_media_ids' => [],
                'errors' => [],
            ];
        }

        $replacements = [];
        foreach ($matches[0] as $index => $match) {
            $tag = (string) ($match[0] ?? '');
            $offset = (int) ($match[1] ?? 0);
            $seoMediaId = (int) ($matches[1][$index][0] ?? 0);
            if ($tag === '' || $seoMediaId <= 0) {
                continue;
            }

            if (! $this->imageTagNeedsWpSrc($tag)) {
                continue;
            }

            try {
                $media = SeoMedia::query()->whereKey($seoMediaId)->first();
                if (! $media instanceof SeoMedia) {
                    $errors[] = "Không tìm thấy seo_media #{$seoMediaId} trong data-seo-media-id.";
                    continue;
                }

                $result = $this->syncMedia($article, $media);
                if (! $result['success']) {
                    $errors[] = $result['message'];
                    continue;
                }

                if ($result['seo_media_id'] !== null) {
                    $syncedMediaIds[] = $result['seo_media_id'];
                }

                $wpUrl = trim($result['wp_url']);
                $attachmentId = (int) ($result['attachment_id'] ?? 0);
                if ($wpUrl === '' || $attachmentId <= 0) {
                    $errors[] = "Ảnh #{$seoMediaId}: thiếu URL WordPress sau sync.";
                    continue;
                }

                $newTag = $this->patchImageTagWithWpSrc($tag, $wpUrl, $attachmentId);
                if ($newTag !== $tag) {
                    $replacements[$offset] = ['length' => strlen($tag), 'tag' => $newTag];
                }
            } catch (Throwable $exception) {
                Log::warning('WordPress syncHtml data-seo-media-id failed', [
                    'article_id' => $article->id,
                    'seo_media_id' => $seoMediaId,
                    'error' => $exception->getMessage(),
                ]);
                $errors[] = "Ảnh #{$seoMediaId}: {$exception->getMessage()}";
            }
        }

        if ($replacements === []) {
            return [
                'html' => $html,
                'synced_media_ids' => $syncedMediaIds,
                'errors' => $errors,
            ];
        }

        krsort($replacements);
        foreach ($replacements as $offset => $item) {
            $html = substr_replace($html, $item['tag'], $offset, $item['length']);
        }

        return [
            'html' => $html,
            'synced_media_ids' => $syncedMediaIds,
            'errors' => $errors,
        ];
    }

    private function imageTagNeedsWpSrc(string $tag): bool
    {
        if (! preg_match('/\bsrc\s*=\s*(["\']?)([^"\'>\s]*)\1/i', $tag, $srcMatch)) {
            return true;
        }

        $src = trim(html_entity_decode((string) ($srcMatch[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '' || $src === '#') {
            return true;
        }

        if (str_contains($src, 'placeholder-loading')) {
            return true;
        }

        return $this->isLocalSeoMediaSrc($src);
    }

    private function isLocalSeoMediaSrc(string $src): bool
    {
        return preg_match('#/storage/uploads/seo_media/|uploads/seo_media/#i', $src) === 1;
    }

    private function patchImageTagWithWpSrc(string $tag, string $wpUrl, int $attachmentId): string
    {
        $wpUrl = trim($wpUrl);
        $escapedUrl = htmlspecialchars($wpUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $wpClass = 'wp-image-' . $attachmentId;

        if (preg_match('/\bsrc\s*=/i', $tag) === 1) {
            if (preg_match('/\bsrc\s*=\s*("|\')/i', $tag) === 1) {
                $tag = (string) preg_replace('/\bsrc\s*=\s*("|\')[^"\']*\1/i', 'src="' . $escapedUrl . '"', $tag, 1);
            } else {
                $tag = (string) preg_replace('/\bsrc\s*=\s*[^\s>]+/i', 'src="' . $escapedUrl . '"', $tag, 1);
            }
        } else {
            $tag = preg_replace('/<img\b/i', '<img src="' . $escapedUrl . '"', $tag, 1) ?? $tag;
        }

        if (preg_match('/\bclass\s*=\s*("|\')([^"\']*)\1/i', $tag, $classMatch)) {
            $classes = trim((string) ($classMatch[2] ?? ''));
            if (! preg_match('/\b' . preg_quote($wpClass, '/') . '\b/', $classes)) {
                $classes = trim($classes . ' ' . $wpClass);
            }
            $tag = (string) preg_replace(
                '/\bclass\s*=\s*("|\')[^"\']*\1/i',
                'class="' . htmlspecialchars($classes, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"',
                $tag,
                1,
            );
        } else {
            $tag = preg_replace('/<img\b/i', '<img class="' . $wpClass . '"', $tag, 1) ?? $tag;
        }

        if (preg_match('/\bdata-id\s*=/i', $tag) === 1) {
            $tag = (string) preg_replace('/\bdata-id\s*=\s*("|\')[^"\']*\1/i', 'data-id="' . $attachmentId . '"', $tag, 1);
        } else {
            $tag = preg_replace('/<img\b/i', '<img data-id="' . $attachmentId . '"', $tag, 1) ?? $tag;
        }

        return $tag;
    }

    private function fetchWordPressAttachmentUrl(Site $site, int $attachmentId): string
    {
        if ($attachmentId <= 0) {
            return '';
        }

        $site->loadMissing('metas');
        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        $tokens = array_values(array_unique(array_filter([
            trim((string) ($site->getMeta('seo_read_token') ?? '')),
            trim((string) ($site->getMeta('seo_migration_token') ?? '')),
        ])));
        if ($tokens === []) {
            $tokens = [''];
        }

        foreach ($tokens as $token) {
            try {
                $request = Http::timeout(30)->acceptJson();
                if ($token !== '') {
                    $request = $request->withToken($token);
                }

                $response = $request->get($base . '/wp-json/wp/v2/media/' . $attachmentId);
            } catch (Throwable $exception) {
                Log::warning('WordPress attachment URL fetch failed', [
                    'attachment_id' => $attachmentId,
                    'error' => $exception->getMessage(),
                ]);
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                continue;
            }

            $source = trim((string) ($payload['source_url'] ?? ''));
            if ($source !== '') {
                return $source;
            }

            $guid = $payload['guid']['rendered'] ?? '';
            $guid = is_string($guid) ? trim($guid) : '';
            if ($guid !== '') {
                return $guid;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function extractLocalSeoMediaUrls(string $html): array
    {
        if (! preg_match_all(
            '#https?://[^\s"\'<>]*?/storage/uploads/seo_media/[^\s"\'<>]+|/storage/uploads/seo_media/[^\s"\'<>]+#i',
            $html,
            $matches,
        )) {
            return [];
        }

        $urls = array_map(static fn ($url): string => trim((string) $url), $matches[0] ?? []);
        $urls = array_values(array_unique(array_filter($urls, static fn (string $url): bool => $url !== '')));

        return $urls;
    }

    private function urlToSeoMediaPath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $path = $url;
        if (preg_match('#^https?://#i', $url) === 1) {
            $parsed = parse_url($url, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        } else {
            $path = explode('?', $path, 2)[0];
        }

        if (! str_starts_with($path, '/storage/uploads/seo_media/')) {
            return '';
        }

        return ltrim(substr($path, strlen('/storage/')), '/');
    }
}

