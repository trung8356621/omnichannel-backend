<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\CommentReviewPayloadParser;
use App\Models\Site;

final class WordPressCommentReviewService
{
    public function __construct(
        private readonly CommentReviewPayloadParser $parser,
        private readonly VirtualCommentService $virtualComments,
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
                'message' => 'Không parse được bình luận/review từ kết quả AI.',
            ];
        }

        return $this->publishItems($article, $items);
    }

    /**
     * Lưu bình luận/review dưới dạng JSON meta (không tạo comment thật trong DB WordPress).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{success: bool, message: string, created_count?: int, error_count?: int, created?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function publishItems(SeoArticle $article, array $items): array
    {
        if ($items === []) {
            return [
                'success' => false,
                'message' => 'Không có mục bình luận/review hợp lệ để lưu.',
                'created_count' => 0,
                'error_count' => 0,
            ];
        }

        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';
        $kind = $isProduct ? 'review ảo' : 'bình luận ảo';

        $this->virtualComments->storeOnArticle($article, $items, $isProduct);
        $localCount = count($this->virtualComments->getFromArticle($article));

        if ($localCount <= 0) {
            return [
                'success' => false,
                'message' => 'Không lưu được review vào meta bài viết.',
                'created_count' => 0,
                'error_count' => count($items),
            ];
        }

        $wpPostId = (int) ($article->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'success' => true,
                'message' => sprintf(
                    'Đã lưu %d %s (chưa đồng bộ WordPress — thiếu WP Post ID).',
                    $localCount,
                    $kind,
                ),
                'created_count' => $localCount,
                'error_count' => 0,
            ];
        }

        $article->loadMissing('site');
        if (! $article->site instanceof Site) {
            return [
                'success' => true,
                'message' => sprintf(
                    'Đã lưu %d %s (chưa đồng bộ — bài chưa gắn domain).',
                    $localCount,
                    $kind,
                ),
                'created_count' => $localCount,
                'error_count' => 0,
            ];
        }

        $result = $this->virtualComments->syncToWordPress($article);
        $count = (int) ($result['count'] ?? $localCount);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => true,
                'message' => sprintf(
                    'Đã lưu %d %s. Đồng bộ WordPress thất bại: %s',
                    $localCount,
                    $kind,
                    (string) ($result['message'] ?? ''),
                ),
                'created_count' => $localCount,
                'error_count' => 0,
            ];
        }

        return [
            'success' => true,
            'message' => (string) ($result['message'] ?? sprintf('Đã lưu %d %s.', $count, $kind)),
            'created_count' => max($count, $localCount),
            'error_count' => 0,
            'created' => array_map(
                static fn (int $i): array => ['index' => $i, 'virtual' => true],
                range(0, max($count, $localCount) - 1),
            ),
            'errors' => [],
        ];
    }
}
