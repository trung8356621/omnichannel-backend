<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\PromptResult;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoPromptResultLink;
use App\Addons\SeoContentAi\Support\WordPressRestResponseParser;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WordPressArticleSyncService
{
    public function __construct(
        private readonly WordPressArticleTimestampService $timestampService,
    ) {}

    /**
     * Tạo post/product mới trên WordPress và liên kết lại với bản ghi Laravel.
     *
     * @return array{success: bool, message: string, wp_post_id?: int, permalink?: string}
     */
    public function createForArticle(SeoArticle $article): array
    {
        if ((int) ($article->wp_post_id ?? 0) > 0) {
            return [
                'success' => true,
                'message' => 'Bài viết đã liên kết WordPress.',
                'wp_post_id' => (int) $article->wp_post_id,
            ];
        }

        $article->loadMissing('site', 'articleMetas');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy tên miền của bài viết.',
            ];
        }

        $type = strtolower(trim((string) ($article->type ?? 'article')));
        if (in_array($type, ['category', 'product_category'], true)) {
            return [
                'success' => false,
                'message' => 'Danh mục phải được tạo bằng luồng taxonomy WordPress, không thể đăng như bài viết.',
            ];
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        $postType = $type === 'product' ? 'product' : 'post';

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($base.'/wp-json/omi-seo-ai/v1/posts', [
                    'title' => trim((string) ($article->title ?? '')),
                    // Luôn gửi slug từ focus keyword — không để WordPress tự sinh slug từ tiêu đề.
                    'slug' => $this->resolveSlugForNewPost($article),
                    'status' => $this->mapStatusForWordPress((string) ($article->status ?? 'draft')),
                    'post_date' => $this->formatPostDateForWordPress($article),
                    'post_type' => $postType,
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => WordPressRestResponseParser::formatHttpErrorMessage($response->status(), $response),
                ];
            }

            $decoded = $response->json();
            $wpPostId = is_array($decoded) ? (int) ($decoded['wp_post_id'] ?? 0) : 0;
            if (! is_array($decoded) || ! ($decoded['success'] ?? false) || $wpPostId <= 0) {
                return [
                    'success' => false,
                    'message' => (string) ($decoded['message'] ?? 'WordPress không trả về ID bài viết mới.'),
                ];
            }

            $permalink = trim((string) ($decoded['permalink'] ?? ''));
            $remoteSlug = trim((string) ($decoded['slug'] ?? ''));
            $article->update(array_filter([
                'wp_post_id' => $wpPostId,
                'slug' => $remoteSlug !== '' ? $remoteSlug : null,
            ], static fn (mixed $value): bool => $value !== null));
            $this->timestampService->sync($article, $decoded);
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_type'],
                ['meta_value' => $postType],
            );
            if ($remoteSlug !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_slug'],
                    ['meta_value' => $remoteSlug],
                );
            }
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_entity'],
                ['meta_value' => 'post'],
            );
            if ($permalink !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_permalink'],
                    ['meta_value' => $permalink],
                );
            }

            return [
                'success' => true,
                'message' => (string) ($decoded['message'] ?? 'Đã tạo bài viết mới trên WordPress.'),
                'wp_post_id' => $wpPostId,
                'permalink' => $permalink,
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress article create exception', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Cập nhật slug lên WordPress ngay khi sửa permalink trên editor (không cần đồng bộ full).
     *
     * @return array{success: bool, message: string}
     */
    public function syncSlugForArticle(SeoArticle $article, string $slug): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return [
                'success' => false,
                'message' => 'Slug không hợp lệ.',
            ];
        }

        $context = $this->resolveEditorSyncContext($article);
        if (! ($context['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($context['message'] ?? 'Không thể đồng bộ slug lên WordPress.'),
            ];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken((string) $context['write_token'])
                ->post((string) $context['url'], [
                    'slug' => $slug,
                ]);

            if (! $response->successful()) {
                $message = WordPressRestResponseParser::formatHttpErrorMessage(
                    $response->status(),
                    $response,
                );

                Log::warning('WordPress slug sync failed', [
                    'article_id' => $article->id,
                    'wp_post_id' => $context['wp_post_id'] ?? null,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => $message,
                ];
            }

            $decoded = $response->json();
            if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($decoded['message'] ?? 'WordPress từ chối cập nhật slug.'),
                ];
            }

            return [
                'success' => true,
                'message' => (string) ($decoded['message'] ?? 'Đã cập nhật slug trên WordPress.'),
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress slug sync exception', [
                'article_id' => $article->id,
                'wp_post_id' => $context['wp_post_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Đẩy tiêu đề, slug, trạng thái, nội dung và FAQ lên WordPress (nút «Đồng bộ»).
     *
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array{
     *     success: bool,
     *     message: string,
     *     faq_count?: int,
     *     faq_extract_debug?: array<string, mixed>|null,
     *     post_type?: string,
     *     post_type_changed?: bool
     * }
     */
    public function syncForArticle(SeoArticle $article, ?array $seoOverride = null): array
    {
        $context = $this->resolveEditorSyncContext($article);
        if (! ($context['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($context['message'] ?? 'Không thể đồng bộ lên WordPress.'),
            ];
        }

        $writeToken = (string) $context['write_token'];
        $url = (string) $context['url'];
        $wpPostId = (int) ($context['wp_post_id'] ?? 0);

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy tên miền của bài viết.',
            ];
        }

        $postContent = trim((string) ($article->body ?? ''));
        $localMediaSyncErrors = [];
        $syncedLocalMediaIds = [];
        if ($postContent !== '') {
            $postContent = app(ArticleEditorHtmlSanitizeService::class)->prepareHtmlForWordPressSync($postContent);
            $postContent = app(ArticleCtaPlaceholderService::class)->replaceInHtml($postContent, $site);
            $postContent = app(WorkflowParserService::class)->removeFaqAndAppendShortcodeFromContent($postContent);
            $postContent = app(ArticlePostContentFaqPlaceholder::class)->normalizeForWordPress($postContent);
            try {
                $localMediaSync = app(WordPressLocalMediaSyncService::class)->syncHtml($article, $postContent);
            } catch (Throwable $mediaException) {
                Log::warning('WordPress local media sync exception', [
                    'article_id' => $article->id,
                    'error' => $mediaException->getMessage(),
                ]);
                $localMediaSync = [
                    'html' => $postContent,
                    'errors' => ['Lỗi đồng bộ ảnh local: '.$mediaException->getMessage()],
                ];
            }
            $postContent = (string) ($localMediaSync['html'] ?? $postContent);
            $localMediaSyncErrors = is_array($localMediaSync['errors'] ?? null)
                ? $localMediaSync['errors']
                : [];
            $syncedLocalMediaIds = is_array($localMediaSync['synced_media_ids'] ?? null)
                ? array_values(array_filter(array_map(
                    static fn ($id): int => (int) $id,
                    $localMediaSync['synced_media_ids'],
                )))
                : [];
        }

        $faqs = $article->resolveFaqs();
        $faqs = $this->sanitizeFaqsForWordPress($faqs);
        $faqs = app(ArticleCtaPlaceholderService::class)->replaceInFaqs($faqs, $site);
        $faqExtractDebug = null;

        if ($faqs === []) {
            $bodyForDiagnosis = trim((string) ($article->body ?? ''));
            if ($bodyForDiagnosis !== '') {
                $diagnosis = app(WorkflowParserService::class)->diagnoseManualFaqExtract($bodyForDiagnosis);
                $faqExtractDebug = app(ArticleFaqExtractDebugService::class)->recordFromContentDiagnosis(
                    $article,
                    $diagnosis,
                    'wp_sync_empty_faqs',
                    'sync',
                );
            }
        } else {
            app(ArticleFaqExtractDebugService::class)->clear($article);
        }

        $virtualComments = app(VirtualCommentService::class)->getFromArticle($article);
        $requestedPostType = strtolower(trim((string) ($article->type ?? 'article'))) === 'product'
            ? 'product'
            : 'post';

        $payload = [
            'title' => (string) ($article->title ?? ''),
            'slug' => (string) ($article->slug ?? ''),
            'status' => $this->mapStatusForWordPress((string) ($article->status ?? 'draft')),
            'post_date' => $this->formatPostDateForWordPress($article),
            'post_type' => $requestedPostType,
            'post_content' => $postContent !== '' ? $postContent : null,
            'faqs' => $faqs,
            'virtual_comments' => $virtualComments,
            'seo' => $this->resolveSeoPayloadForWordPress($article, $seoOverride),
            'meta_input' => [
                VirtualCommentService::WP_META_KEY => json_encode(
                    $virtualComments,
                    JSON_UNESCAPED_UNICODE,
                ),
            ],
        ];

        try {
            $response = Http::timeout(45)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($url, $payload);

            if (! $response->successful()) {
                $message = WordPressRestResponseParser::formatHttpErrorMessage(
                    $response->status(),
                    $response,
                );

                Log::warning('WordPress article sync failed', [
                    'article_id' => $article->id,
                    'wp_post_id' => $wpPostId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => $message,
                ];
            }

            $decoded = $response->json();
            if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($decoded['message'] ?? 'WordPress từ chối đồng bộ.'),
                ];
            }

            $remoteSlug = trim((string) ($decoded['slug'] ?? ''));
            $remotePermalink = trim((string) ($decoded['permalink'] ?? ''));
            $remotePostType = strtolower(trim((string) ($decoded['post_type'] ?? $requestedPostType)));
            if (! in_array($remotePostType, ['post', 'product'], true)) {
                $remotePostType = $requestedPostType;
            }

            $article->update([
                'type' => $remotePostType === 'product' ? 'product' : 'article',
            ]);
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_type'],
                ['meta_value' => $remotePostType],
            );
            if ($remoteSlug !== '') {
                $article->update(['slug' => $remoteSlug]);
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_slug'],
                    ['meta_value' => $remoteSlug],
                );
            }
            if ($remotePermalink !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_permalink'],
                    ['meta_value' => $remotePermalink],
                );
            }

            $this->storeWpPostContentMeta($article, $postContent);
            if ($postContent !== '' && trim((string) ($article->body ?? '')) !== $postContent) {
                // Sau khi sync, body Laravel dùng URL WordPress để tránh quay lại URL local.
                $article->update(['body' => $postContent]);
            }

            $message = (string) ($decoded['message'] ?? 'Đã đồng bộ lên WordPress.');
            $virtualCount = (int) ($decoded['virtual_count'] ?? 0);
            if ($virtualCount > 0) {
                $message .= ' Đã đồng bộ '.$virtualCount.' review ảo.';
            }
            $virtualError = trim((string) ($decoded['virtual_comments_error'] ?? ''));
            if ($virtualError !== '') {
                $message .= ' Review chưa lưu: '.mb_substr($virtualError, 0, 200);
            }
            $mediaPush = app(ArticleMediaLocalService::class)->pushPendingMediaToWordPress($article->fresh());
            $dirtySync = app(WordPressLocalMediaSyncService::class)->syncDirtyLocalMediaForArticle($article->fresh());
            $syncedFromPending = is_array($mediaPush['synced_local_media_ids'] ?? null)
                ? array_values(array_filter(array_map(
                    static fn ($id): int => (int) $id,
                    $mediaPush['synced_local_media_ids'],
                )))
                : [];
            $syncedLocalMediaIds = array_values(array_unique(array_merge(
                $syncedLocalMediaIds,
                $syncedFromPending,
            )));

            if ($mediaPush['attempted']) {
                if ($mediaPush['success']) {
                    $message .= ' Đã đẩy ảnh đại diện/album lên WordPress.';
                } else {
                    $message .= ' Ảnh chưa đẩy được: '.mb_substr((string) $mediaPush['message'], 0, 200);
                }
            }
            if (($dirtySync['synced'] ?? 0) > 0) {
                $message .= ' Đã ghi đè '.(int) $dirtySync['synced'].' ảnh local đã chỉnh sửa lên WordPress.';
            }
            if (($dirtySync['errors'] ?? []) !== []) {
                $message .= ' Một số ảnh local chỉnh sửa chưa ghi đè được: '.mb_substr(implode(' | ', $dirtySync['errors']), 0, 300);
            }
            if ($localMediaSyncErrors !== []) {
                $message .= ' Một số ảnh trong nội dung chưa sync được: '.mb_substr(implode(' | ', $localMediaSyncErrors), 0, 300);
            }

            if ($syncedLocalMediaIds !== []) {
                $updatedPromptMediaLinks = $this->syncPromptMediaLinksToWordPressUrls($article, $syncedLocalMediaIds);
                if ($updatedPromptMediaLinks > 0) {
                    $message .= " Đã cập nhật {$updatedPromptMediaLinks} kết quả prompt sang URL ảnh WordPress.";
                }

                $trashed = app(WordPressLocalMediaSyncService::class)->markSyncedLocalMediaAsTrash($syncedLocalMediaIds);
                if ($trashed > 0) {
                    $message .= " Đã gắn cờ trash {$trashed} ảnh local đã đồng bộ.";
                }
            }

            $article->update(['body' => null]);
            app(ArticleWordPressSyncFlagService::class)->clearAll($article);
            $this->timestampService->sync($article, $decoded);

            return [
                'success' => true,
                'message' => $message,
                'faq_count' => count($faqs),
                'faq_extract_debug' => $faqExtractDebug,
                'post_type' => $remotePostType,
                'post_type_changed' => (bool) ($decoded['post_type_changed'] ?? false),
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress article sync exception', [
                'article_id' => $article->id,
                'wp_post_id' => $wpPostId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, message?: string, url?: string, write_token?: string, wp_post_id?: int}
     */
    private function resolveEditorSyncContext(SeoArticle $article): array
    {
        $wpPostId = (int) ($article->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài chưa liên kết WordPress (thiếu wp_post_id). Chạy đồng bộ domain trước.',
            ];
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy tên miền của bài viết.',
            ];
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $url = app(WordPressArticleContentService::class)->buildEditorSyncUrl($site, $article);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        return [
            'success' => true,
            'url' => $url,
            'write_token' => $writeToken,
            'wp_post_id' => $wpPostId,
        ];
    }

    /**
     * Slug khi đăng bài mới: ưu tiên focus keyword, sau đó slug đã lưu, cuối cùng mới đến tiêu đề.
     * WordPress sẽ tự thêm hậu tố (-2, -3...) nếu trùng — slug trả về được ghi lại vào article.
     */
    private function resolveSlugForNewPost(SeoArticle $article): string
    {
        $keyword = trim((string) (app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? ''));
        $slug = \Illuminate\Support\Str::slug($keyword);
        if ($slug !== '') {
            return $slug;
        }

        $slug = \Illuminate\Support\Str::slug((string) ($article->slug ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        return \Illuminate\Support\Str::slug((string) ($article->title ?? ''));
    }

    private function mapStatusForWordPress(string $status): string
    {
        return match ($status) {
            'published' => 'publish',
            'private' => 'private',
            'scheduled' => 'future',
            default => 'draft',
        };
    }

    private function formatPostDateForWordPress(SeoArticle $article): ?string
    {
        if (! $article->published_at instanceof Carbon) {
            return null;
        }

        return $article->published_at
            ->copy()
            ->timezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    private function storeWpPostContentMeta(SeoArticle $article, string $html): void
    {
        if ($html === '') {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_post_content'],
            ['meta_value' => $html],
        );
    }

    /**
     * @param  list<int>  $mediaIds
     */
    private function syncPromptMediaLinksToWordPressUrls(SeoArticle $article, array $mediaIds): int
    {
        $articleId = (int) ($article->id ?? 0);
        if ($articleId <= 0) {
            return 0;
        }

        $mediaIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $mediaIds,
        ), static fn (int $id): bool => $id > 0)));
        if ($mediaIds === []) {
            return 0;
        }

        $mediaById = SeoMedia::query()
            ->whereIn('id', $mediaIds)
            ->get()
            ->keyBy(static fn (SeoMedia $media): int => (int) $media->id);

        if ($mediaById->isEmpty()) {
            return 0;
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return 0;
        }

        $links = SeoPromptResultLink::query()
            ->where('article_id', $articleId)
            ->where('source', 'editor_media_generation')
            ->orderBy('id')
            ->get();

        if ($links->isEmpty()) {
            return 0;
        }

        $updated = 0;
        $resultCache = [];
        $wpUrlCache = [];
        foreach ($links as $link) {
            $meta = is_array($link->meta) ? $link->meta : [];
            $seoMediaId = (int) ($meta['seo_media_id'] ?? 0);
            if ($seoMediaId <= 0) {
                continue;
            }

            $media = $mediaById->get($seoMediaId);
            if (! $media instanceof SeoMedia) {
                continue;
            }

            if (! array_key_exists($seoMediaId, $wpUrlCache)) {
                $wpUrlCache[$seoMediaId] = $this->resolveWordPressMediaUrl($site, $media);
            }

            $wpUrl = trim((string) $wpUrlCache[$seoMediaId]);
            if ($wpUrl === '') {
                continue;
            }

            $resultId = (int) ($link->prompt_result_id ?? 0);
            if ($resultId <= 0) {
                continue;
            }

            if (! array_key_exists($resultId, $resultCache)) {
                $resultCache[$resultId] = PromptResult::query()->find($resultId);
            }

            $result = $resultCache[$resultId];
            if (! $result instanceof PromptResult) {
                continue;
            }

            $existingOutput = (string) ($result->output_text ?? '');
            $newOutput = $this->replaceFirstLocalMediaUrl($existingOutput, $wpUrl);
            if ($newOutput === null || $newOutput === $existingOutput) {
                continue;
            }

            $result->update(['output_text' => $newOutput]);
            $result->output_text = $newOutput;
            $resultCache[$resultId] = $result;

            $meta['wp_url'] = $wpUrl;
            $link->update(['meta' => $meta]);
            $updated++;
        }

        return $updated;
    }

    private function resolveWordPressMediaUrl(Site $site, SeoMedia $media): string
    {
        $candidate = trim((string) ($media->getAttribute('wp_url') ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $attachmentId = (int) ($media->wp_attachment_id ?? 0);
        if ($attachmentId <= 0) {
            return '';
        }

        $attachment = app(WordPressMediaLibraryService::class)->fetchAttachmentById($site, $attachmentId);
        if (! is_array($attachment)) {
            return '';
        }

        return trim((string) ($attachment['url'] ?? ''));
    }

    private function replaceFirstLocalMediaUrl(string $output, string $wpUrl): ?string
    {
        $wpUrl = trim($wpUrl);
        if ($wpUrl === '') {
            return null;
        }

        $normalized = trim($output);
        if ($normalized === '') {
            return null;
        }

        $lines = preg_split('/\R/', $normalized) ?: [];
        $firstLine = trim((string) ($lines[0] ?? ''));
        if ($firstLine === '' || ! $this->isLocalSeoMediaUrl($firstLine)) {
            return null;
        }

        if ($firstLine === $wpUrl) {
            return $normalized;
        }

        $lines[0] = $wpUrl;

        return implode("\n", $lines);
    }

    private function isLocalSeoMediaUrl(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $path = parse_url($value, PHP_URL_PATH);
        $path = is_string($path) ? $path : $value;

        return preg_match('#/storage/uploads/seo_media/#i', $path) === 1;
    }

    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $override
     * @return array{seo_title: string, meta_description: string, focus_keyword: string}
     */
    private function resolveSeoPayloadForWordPress(SeoArticle $article, ?array $override = null): array
    {
        $article->loadMissing('articleMetas');

        $seoTitle = trim((string) ($override['seo_title'] ?? ''));
        if ($seoTitle === '') {
            $seoTitle = trim((string) ($article->articleMetas->firstWhere('meta_key', 'seo_title')?->meta_value ?? ''));
        }
        if ($seoTitle === '') {
            $seoTitle = trim((string) ($article->title ?? ''));
        }

        $metaDescription = trim((string) ($override['meta_description'] ?? ''));
        if ($metaDescription === '') {
            $metaDescription = trim((string) (
                $article->articleMetas->first(
                    static fn ($meta): bool => in_array((string) $meta->meta_key, [
                        'seo_meta_description',
                        'meta_description',
                    ], true),
                )?->meta_value ?? ''
            ));
        }

        $focusKeyword = trim((string) ($override['focus_keyword'] ?? ''));
        if ($focusKeyword === '') {
            $focusKeyword = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? '';
        }

        return [
            'seo_title' => $seoTitle,
            'meta_description' => $metaDescription,
            'focus_keyword' => $focusKeyword,
        ];
    }

    /**
     * @param  list<array{question: string, answer: string, more?: string|null}>  $faqs
     * @return list<array{question: string, answer: string, more?: string|null}>
     */
    private function sanitizeFaqsForWordPress(array $faqs): array
    {
        $sanitizer = app(ArticleEditorHtmlSanitizeService::class);

        return array_map(static function (array $faq) use ($sanitizer): array {
            if (isset($faq['answer']) && is_string($faq['answer'])) {
                $faq['answer'] = $sanitizer->prepareHtmlForWordPressSync($faq['answer']);
            }

            return $faq;
        }, $faqs);
    }
}
