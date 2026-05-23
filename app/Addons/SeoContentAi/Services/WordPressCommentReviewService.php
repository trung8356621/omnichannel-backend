<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
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
                'message' => 'Không parse được JSON bình luận/review từ kết quả AI.',
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
        $wpPostId = (int) ($article->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa có WordPress Post ID.',
            ];
        }

        $article->loadMissing('site');
        if (! $article->site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa gắn domain.',
            ];
        }

        $result = $this->virtualComments->syncToWordPress($article, $items);
        $count = (int) ($result['count'] ?? 0);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Đồng bộ bình luận ảo thất bại.'),
                'created_count' => 0,
                'error_count' => count($items),
            ];
        }

        if ($count <= 0) {
            return [
                'success' => false,
                'message' => 'Không có mục bình luận/review hợp lệ để lưu.',
                'created_count' => 0,
                'error_count' => count($items),
            ];
        }

        $isProduct = (string) ($article->type ?? '') === 'product';
        $kind = $isProduct ? 'review ảo' : 'bình luận ảo';

        return [
            'success' => true,
            'message' => (string) ($result['message'] ?? sprintf('Đã lưu %d %s.', $count, $kind)),
            'created_count' => $count,
            'error_count' => 0,
            'created' => array_map(
                static fn (int $i): array => ['index' => $i, 'virtual' => true],
                range(0, $count - 1),
            ),
            'errors' => [],
        ];
    }
}
