<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WordPressArticleSyncService
{
    /**
     * Đẩy tiêu đề, slug, trạng thái, nội dung và FAQ lên WordPress (nút «Đồng bộ»).
     *
     * @return array{success: bool, message: string, faq_count?: int, faq_extract_debug?: array<string, mixed>|null}
     */
    public function syncForArticle(SeoArticle $article): array
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

        $postContent = trim((string) ($article->body ?? ''));
        if ($postContent !== '') {
            $postContent = app(WorkflowParserService::class)->removeFaqAndAppendShortcodeFromContent($postContent);
            $postContent = app(ArticlePostContentFaqPlaceholder::class)->normalizeForWordPress($postContent);
        }

        $faqs = $article->resolveFaqs();
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

        $payload = [
            'title' => (string) ($article->title ?? ''),
            'slug' => (string) ($article->slug ?? ''),
            'status' => $this->mapStatusForWordPress((string) ($article->status ?? 'draft')),
            'post_content' => $postContent !== '' ? $postContent : null,
            'faqs' => $faqs,
            'virtual_comments' => $virtualComments,
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
                $message = 'WordPress trả lỗi HTTP ' . $response->status();
                $body = $response->json();
                if (is_array($body) && isset($body['message'])) {
                    $message .= ': ' . (string) $body['message'];
                }

                Log::warning('WordPress article sync failed', [
                    'article_id' => $article->id,
                    'wp_post_id' => $wpPostId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => mb_substr($message, 0, 500),
                ];
            }

            $decoded = $response->json();
            if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($decoded['message'] ?? 'WordPress từ chối đồng bộ.'),
                ];
            }

            $this->storeWpPostContentMeta($article, $postContent);

            $message = (string) ($decoded['message'] ?? 'Đã đồng bộ lên WordPress.');
            $mediaPush = app(ArticleMediaLocalService::class)->pushPendingMediaToWordPress($article->fresh());
            if ($mediaPush['attempted']) {
                if ($mediaPush['success']) {
                    $message .= ' Đã đẩy ảnh đại diện/album lên WordPress.';
                } else {
                    $message .= ' Ảnh chưa đẩy được: ' . mb_substr((string) $mediaPush['message'], 0, 200);
                }
            }

            return [
                'success' => true,
                'message' => $message,
                'faq_count' => count($faqs),
                'faq_extract_debug' => $faqExtractDebug,
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress article sync exception', [
                'article_id' => $article->id,
                'wp_post_id' => $wpPostId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: ' . $e->getMessage(),
            ];
        }
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

}
