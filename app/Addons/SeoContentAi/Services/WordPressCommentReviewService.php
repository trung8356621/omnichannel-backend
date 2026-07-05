<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\CommentReviewPayloadParser;
use App\Addons\SeoContentAi\Support\SeoAccessControl;

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
     * Lưu bình luận/review ảo trực tiếp lên WordPress (meta _omi_seo_virtual_comments).
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

        $wpPostId = (int) ($article->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa có WordPress Post ID — không thể lưu ' . $kind . '.',
                'created_count' => 0,
                'error_count' => count($items),
            ];
        }

        if (! SeoAccessControl::canSyncArticlesToWordPress()) {
            return [
                'success' => false,
                'message' => 'Quản lý nội dung không được đăng ' . $kind . ' lên WordPress.',
                'created_count' => 0,
                'error_count' => count($items),
            ];
        }

        $result = $this->virtualComments->pushToWordPress($article, $items);
        $count = (int) ($result['count'] ?? 0);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Không lưu được ' . $kind . ' lên WordPress.'),
                'created_count' => 0,
                'error_count' => count($items),
            ];
        }

        return [
            'success' => true,
            'message' => (string) ($result['message'] ?? sprintf('Đã lưu %d %s trên WordPress.', $count, $kind)),
            'created_count' => $count,
            'error_count' => 0,
            'created' => array_map(
                static fn (int $i): array => ['index' => $i, 'virtual' => true],
                range(0, max($count, 1) - 1),
            ),
            'errors' => [],
        ];
    }
}
