<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\CommentReviewPayloadParser;
use App\Addons\SeoContentAi\Support\CommentReviewRatingAssigner;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WordPressCommentReviewService
{
    public function __construct(
        private readonly CommentReviewPayloadParser $parser,
        private readonly CommentReviewRatingAssigner $ratingAssigner,
        private readonly WordPressArticleContentService $contentService,
    ) {}

    /**
     * @return array{success: bool, message: string, created_count?: int, error_count?: int, created?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function publishFromAiOutput(SeoArticle $article, string $aiOutput): array
    {
        $items = $this->parser->parse($aiOutput);
        if ($items === []) {
            return [
                'success' => false,
                'message' => 'Không parse được JSON bình luận/review từ kết quả AI.',
            ];
        }

        return $this->publishItems($article, $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{success: bool, message: string, created_count?: int, error_count?: int, created?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function publishItems(SeoArticle $article, array $items): array
    {
        $wpPostId = (int) ($article->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa có WordPress Post ID.',
            ];
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa gắn domain.',
            ];
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu Migration/Write token trên domain. Cấu hình token giống API WRITE trên plugin WordPress.',
            ];
        }

        $isProduct = (string) ($article->type ?? '') === 'product';
        $payloadItems = [];

        foreach (array_values($items) as $index => $item) {
            $row = [
                'content' => (string) ($item['content'] ?? $item['comment'] ?? ''),
                'author' => (string) ($item['author'] ?? 'Khách'),
                'email' => (string) ($item['email'] ?? ''),
            ];

            if ($isProduct) {
                $explicit = isset($item['rating']) && is_numeric($item['rating'])
                    ? (int) $item['rating']
                    : null;
                $row['rating'] = $this->ratingAssigner->resolve($explicit, $index);
            }

            $payloadItems[] = $row;
        }

        $url = $this->buildPublishUrl($site, $wpPostId);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($url, ['items' => $payloadItems]);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP ' . $response->status() . ': ' . mb_substr($message, 0, 400),
                ];
            }

            $body = $response->json();
            if (! is_array($body)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi WordPress không hợp lệ.',
                ];
            }

            $createdCount = (int) ($body['created_count'] ?? count($body['created'] ?? []));
            $errorCount = (int) ($body['error_count'] ?? count($body['errors'] ?? []));

            if ($createdCount <= 0) {
                return [
                    'success' => false,
                    'message' => 'Không đăng được mục nào lên WordPress.',
                    'created_count' => 0,
                    'error_count' => $errorCount,
                    'errors' => is_array($body['errors'] ?? null) ? $body['errors'] : [],
                ];
            }

            $kind = $isProduct ? 'review' : 'bình luận';

            return [
                'success' => true,
                'message' => sprintf(
                    'Đã đăng %d %s lên WordPress (WP #%d)%s.',
                    $createdCount,
                    $kind,
                    $wpPostId,
                    $errorCount > 0 ? ", {$errorCount} lỗi" : ''
                ),
                'created_count' => $createdCount,
                'error_count' => $errorCount,
                'created' => is_array($body['created'] ?? null) ? $body['created'] : [],
                'errors' => is_array($body['errors'] ?? null) ? $body['errors'] : [],
            ];
        } catch (Throwable $e) {
            Log::error('WordPress comment/review publish failed', [
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

    private function buildPublishUrl(Site $site, int $wpPostId): string
    {
        $base = $this->contentService->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/posts/' . $wpPostId . '/comment-reviews';
    }
}
