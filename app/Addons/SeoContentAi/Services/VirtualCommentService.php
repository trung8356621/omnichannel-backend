<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\CommentReviewRatingAssigner;
use App\Addons\SeoContentAi\Support\WordPressRestResponseParser;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lưu bình luận/review AI dưới dạng JSON (Laravel meta + WP post meta _omi_seo_virtual_comments).
 */
final class VirtualCommentService
{
    public const ARTICLE_META_KEY = 'virtual_comments';

    public const WP_META_KEY = '_omi_seo_virtual_comments';

    public function __construct(
        private readonly CommentReviewRatingAssigner $ratingAssigner,
        private readonly WordPressArticleContentService $contentService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{author: string, content: string, rating?: int, date: string}>
     */
    public function normalizeItems(array $items, bool $isProduct = false, ?SeoArticle $article = null): array
    {
        $validItems = [];
        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $content = trim((string) ($item['content'] ?? $item['comment'] ?? ''));
            if ($content === '') {
                continue;
            }

            $validItems[] = $item;
        }

        $publishedAt = $this->resolvePostPublishedAt($article);
        $staggeredDates = $this->buildStaggeredCommentDates(count($validItems), $publishedAt);

        $normalized = [];

        foreach ($validItems as $index => $item) {
            $author = trim((string) ($item['author'] ?? $item['author_name'] ?? 'Khách mua hàng'));
            if ($author === '') {
                $author = 'Khách mua hàng';
            }

            $row = [
                'author' => $author,
                'content' => trim((string) ($item['content'] ?? $item['comment'] ?? '')),
                'date' => $this->resolveDate(
                    $item,
                    $staggeredDates[$index] ?? $staggeredDates[0] ?? $publishedAt->format('Y-m-d H:i:s'),
                ),
            ];

            if ($isProduct) {
                $row['rating'] = $this->ratingAssigner->resolve(
                    $this->resolveExplicitRating($item),
                    $index,
                );
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function storeOnArticle(SeoArticle $article, array $items, bool $isProduct = false): void
    {
        $payload = $this->normalizeItems($items, $isProduct, $article);

        if ($payload === []) {
            $article->articleMetas()->where('meta_key', self::ARTICLE_META_KEY)->delete();

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::ARTICLE_META_KEY],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );
    }

    /**
     * @return list<array{author: string, content: string, rating?: int, date: string}>
     */
    public function getFromArticle(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $meta = $article->articleMetas->firstWhere('meta_key', self::ARTICLE_META_KEY);
        if ($meta === null || ! filled($meta->meta_value)) {
            return [];
        }

        try {
            $decoded = json_decode((string) $meta->meta_value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';

        $result = [];
        foreach (array_values($decoded) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) ($row['content'] ?? $row['comment'] ?? ''));
            if ($content === '') {
                continue;
            }

            $date = trim((string) ($row['date'] ?? ''));
            if ($date === '') {
                $date = now()->format('Y-m-d H:i:s');
            }

            $entry = [
                'author' => trim((string) ($row['author'] ?? 'Khách mua hàng')) ?: 'Khách mua hàng',
                'content' => $content,
                'date' => $date,
            ];

            if ($isProduct) {
                $entry['rating'] = $this->ratingAssigner->resolve(
                    $this->resolveExplicitRating($row),
                    $index,
                );
            } elseif (isset($row['rating']) && is_numeric($row['rating'])) {
                $entry['rating'] = max(1, min(5, (int) $row['rating']));
            }

            $result[] = $entry;
        }

        return array_values($result);
    }

    /**
     * @return list<array{author: string, content: string, rating?: int, date: string}>
     */
    public function getFromWordPress(SeoArticle $article): array
    {
        $wpPostId = (int) ($article->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [];
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [];
        }

        $site->loadMissing('metas');
        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [];
        }

        $base = $this->contentService->getPermalinkBase($site);
        if ($base === '') {
            return [];
        }

        $url = $base . '/wp-json/omi-seo-ai/v1/posts/' . $wpPostId . '/comment-reviews';

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($readToken)
                ->get($url);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();
        if (! is_array($body)) {
            return [];
        }

        $items = $body['items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';
        $result = [];
        foreach (array_values($items) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) ($row['content'] ?? $row['comment'] ?? ''));
            if ($content === '') {
                continue;
            }

            $date = trim((string) ($row['date'] ?? ''));
            if ($date === '') {
                $date = now()->format('Y-m-d H:i:s');
            }

            $entry = [
                'author' => trim((string) ($row['author'] ?? 'Khách mua hàng')) ?: 'Khách mua hàng',
                'content' => $content,
                'date' => $date,
            ];

            if ($isProduct) {
                $entry['rating'] = $this->ratingAssigner->resolve(
                    $this->resolveExplicitRating($row),
                    $index,
                );
            } elseif (isset($row['rating']) && is_numeric($row['rating'])) {
                $entry['rating'] = max(1, min(5, (int) $row['rating']));
            }

            $result[] = $entry;
        }

        return array_values($result);
    }

    /**
     * Ưu tiên dữ liệu local; fallback qua WordPress nếu local chưa có.
     *
     * @return list<array{author: string, content: string, rating?: int, date: string}>
     */
    public function getForEditor(SeoArticle $article): array
    {
        $local = $this->getFromArticle($article);
        if ($local !== []) {
            return $local;
        }

        return $this->getFromWordPress($article);
    }

    /**
     * @param  list<array<string, mixed>>|null  $items  null = đọc từ meta bài viết
     * @return array{success: bool, message: string, count?: int}
     */
    public function syncToWordPress(SeoArticle $article, ?array $items = null): array
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

        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';

        if ($items !== null) {
            $this->storeOnArticle($article, $items, $isProduct);
        }

        $virtualComments = $this->getFromArticle($article);

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $url = $this->buildSyncUrl($site, $wpPostId);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        $payloadComments = array_values(array_map(
            function (array $row) use ($isProduct): array {
                $normalized = [
                    'author' => (string) ($row['author'] ?? 'Khách mua hàng'),
                    'content' => (string) ($row['content'] ?? ''),
                    'date' => (string) ($row['date'] ?? ''),
                ];

                if ($isProduct) {
                    $normalized['rating'] = max(1, min(5, (int) ($row['rating'] ?? 5)));
                } elseif (isset($row['rating']) && is_numeric($row['rating'])) {
                    $normalized['rating'] = max(1, min(5, (int) $row['rating']));
                }

                return $normalized;
            },
            $virtualComments,
        ));

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($url, [
                    'virtual_comments' => $payloadComments,
                    'meta_input' => [
                        self::WP_META_KEY => json_encode(
                            $payloadComments,
                            JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0),
                        ),
                    ],
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => WordPressRestResponseParser::formatHttpErrorMessage(
                        $response->status(),
                        $response,
                    ),
                ];
            }

            $body = $response->json();
            if (! is_array($body) || ! ($body['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($body['message'] ?? 'WordPress từ chối lưu bình luận ảo.'),
                ];
            }

            $count = (int) ($body['virtual_count'] ?? $body['count'] ?? count($virtualComments));
            $kind = $isProduct ? 'review ảo' : 'bình luận ảo';

            return [
                'success' => true,
                'message' => sprintf('Đã lưu %d %s trên WordPress (meta %s).', $count, $kind, self::WP_META_KEY),
                'count' => $count,
            ];
        } catch (Throwable $e) {
            Log::error('Virtual comments sync failed', [
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

    private function buildSyncUrl(Site $site, int $wpPostId): string
    {
        $base = $this->contentService->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/posts/' . $wpPostId . '/virtual-comments';
    }

    private function resolvePostPublishedAt(?SeoArticle $article): Carbon
    {
        if ($article !== null) {
            if ($article->published_at instanceof Carbon) {
                return $article->published_at->copy();
            }

            if ($article->created_at instanceof Carbon) {
                return $article->created_at->copy();
            }
        }

        return Carbon::now();
    }

    /**
     * Mỗi comment: +2..+6 ngày sau ngày đăng bài, offset khác nhau khi có thể.
     *
     * @return list<string>
     */
    private function buildStaggeredCommentDates(int $count, Carbon $publishedAt): array
    {
        if ($count <= 0) {
            return [];
        }

        $pool = [2, 3, 4, 5, 6];
        shuffle($pool);

        $dates = [];

        for ($i = 0; $i < $count; $i++) {
            $days = $i < count($pool)
                ? $pool[$i]
                : random_int(2, 6);

            $dates[] = $publishedAt->copy()
                ->addDays($days)
                ->setTime(random_int(8, 21), random_int(0, 59), 0)
                ->format('Y-m-d H:i:s');
        }

        sort($dates);

        return $dates;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveExplicitRating(array $item): ?int
    {
        foreach (['rating', 'star_ranking', 'stars', 'star'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return max(1, min(5, (int) $item[$key]));
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveDate(array $item, string $fallbackDate): string
    {
        $raw = trim((string) ($item['date'] ?? $item['comment_date'] ?? ''));
        if ($raw !== '') {
            try {
                return Carbon::parse($raw)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                // fall through
            }
        }

        return $fallbackDate;
    }
}
