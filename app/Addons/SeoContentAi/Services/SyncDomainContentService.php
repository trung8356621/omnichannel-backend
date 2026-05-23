<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDomainContentService
{
    /**
     * @param  array{is_test?:bool,limit_per_type?:int}  $options
     * @return array{success:bool,message:string,synced?:array<string,int>,counts?:array<string,int>}
     */
    public function sync(Site $site, array $options = []): array
    {
        $site->loadMissing('metas');

        $platform = (string) ($site->getMeta('seo_platform') ?? '');
        if ($platform !== 'wordpress') {
            return [
                'success' => false,
                'message' => 'Site chưa cấu hình nền tảng WordPress.',
            ];
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu SEO Read Token. Hãy lưu token trong trang chỉnh sửa domain.',
            ];
        }

        $isTest = (bool) ($options['is_test'] ?? false);
        $limitPerType = (int) ($options['limit_per_type'] ?? 0);
        if ($isTest && $limitPerType <= 0) {
            $limitPerType = 2;
        }

        $url = $this->buildSyncUrl($site);

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($readToken)
                ->get($url, [
                    'is_test' => $isTest ? 1 : 0,
                    'limit_per_type' => $limitPerType,
                ]);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP ' . $response->status() . ': ' . mb_substr($message, 0, 300),
                ];
            }

            $payload = $response->json();
            if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi WordPress không hợp lệ.',
                ];
            }

            $items = $payload['items'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            $synced = $this->importItems($site, $items);

            return $this->buildImportSuccessResponse($synced, $isTest, is_array($payload['counts'] ?? null) ? $payload['counts'] : []);
        } catch (Throwable $e) {
            Log::error('SeoContentAi sync failed', [
                'site_id' => $site->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Nhận payload đẩy từ plugin WordPress (hook save_post / term).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success:bool,message:string,synced?:array<string,int>}
     */
    public function importPushedItems(Site $site, array $items): array
    {
        $platform = (string) ($site->getMeta('seo_platform') ?? '');
        if ($platform !== 'wordpress') {
            return [
                'success' => false,
                'message' => 'Site chưa cấu hình nền tảng WordPress.',
            ];
        }

        $synced = $this->importItems($site, $items);

        return $this->buildImportSuccessResponse($synced, false, []);
    }

    /**
     * @param  array<string, int>  $synced
     * @param  array<string, int>  $counts
     * @return array{success:bool,message:string,synced:array<string,int>,counts?:array<string,int>}
     */
    private function buildImportSuccessResponse(array $synced, bool $isTest, array $counts): array
    {
        $response = [
            'success' => true,
            'message' => sprintf(
                'Đồng bộ thành công %d mục từ WordPress%s.',
                array_sum($synced),
                $isTest ? ' (chế độ test)' : ''
            ),
            'synced' => $synced,
        ];

        if ($counts !== []) {
            $response['counts'] = $counts;
        }

        return $response;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, int>
     */
    public function importItems(Site $site, array $items): array
    {
        $synced = [
            'article' => 0,
            'product' => 0,
            'category' => 0,
            'product_category' => 0,
            'other' => 0,
        ];

        $analyzer = app(SeoAnalyzerService::class);
        $userId = (int) $site->user_id;
        $siteDomain = (string) $site->domain;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $wpId = (int) ($item['wp_id'] ?? 0);
            if ($wpId <= 0) {
                continue;
            }

            $type = $this->normalizeType((string) ($item['type'] ?? 'article'));
            $publishedAt = $this->parsePublishedAt($item['published_at'] ?? null);

            // Bảng articles = bản ghi trung gian chấm điểm. Mỗi lần đồng bộ WP phải ghi đè và xóa
            // slug/excerpt/body/blocks (chỉ bài viết mới qua AI mới được điền các cột đó).
            $article = SeoArticle::query()->updateOrCreate(
                [
                    'site_id' => $site->id,
                    'wp_post_id' => $wpId,
                    'type' => $type,
                ],
                [
                    'type' => $type,
                    'title' => (string) ($item['title'] ?? 'Untitled'),
                    'slug' => null,
                    'excerpt' => null,
                    'body' => null,
                    'blocks' => null,
                    'status' => $this->normalizeStatus((string) ($item['status'] ?? 'draft')),
                    'published_at' => $publishedAt,
                ]
            );

            $wpPostType = (string) ($item['wp_post_type'] ?? '');
            if ($wpPostType !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_type'],
                    ['meta_value' => $wpPostType]
                );
            }

            $wpEntity = trim((string) ($item['wp_entity'] ?? ''));
            if ($wpEntity !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_entity'],
                    ['meta_value' => $wpEntity],
                );
            }

            $this->syncWordPressPostMeta($article, $item);
            app(ArticlePostImagesService::class)->importFromSyncItem($article, $item);
            app(ArticleFaqWordPressImportService::class)->importFromWordPressSyncItem($article, $item);
            $this->syncSeoMetaFromWordPress($article, $item);
            $this->syncFocusKeyword($site, $userId, $article, $item);

            if (array_key_exists($type, $synced)) {
                $synced[$type]++;
            } else {
                $synced['other']++;
            }

            try {
                $analyzer->analyzeFromSyncItem($article, $item, $siteDomain);
            } catch (Throwable $e) {
                Log::warning('SeoAnalyzer failed after sync', [
                    'article_id' => $article->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $synced;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncWordPressPostMeta(SeoArticle $article, array $item): void
    {
        $type = $this->normalizeType((string) ($item['type'] ?? 'article'));
        $isTaxonomy = in_array($type, ['category', 'product_category'], true);
        $content = $this->resolveSyncItemContent($item);

        if ($isTaxonomy || $content !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_content'],
                ['meta_value' => $content],
            );
        }

        $slug = trim((string) ($item['slug'] ?? ''));
        if ($slug !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_slug'],
                ['meta_value' => $slug],
            );
        }

        $permalink = trim((string) ($item['permalink'] ?? ''));
        if ($permalink !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $permalink],
            );
        }

        if ($isTaxonomy) {
            $article->articleMetas()->whereIn('meta_key', [
                'wp_featured_image_url',
                'wp_product_gallery',
            ])->delete();

            return;
        }

        $featuredImageUrl = trim((string) ($item['featured_image_url'] ?? ''));
        if ($featuredImageUrl !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_featured_image_url'],
                ['meta_value' => $featuredImageUrl],
            );
        }

        $gallery = $item['product_gallery'] ?? null;
        if (is_array($gallery) && $gallery !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_product_gallery'],
                ['meta_value' => json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        }

        $virtualComments = $item['virtual_comments'] ?? null;
        if (is_array($virtualComments) && $virtualComments !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => VirtualCommentService::ARTICLE_META_KEY],
                ['meta_value' => json_encode($virtualComments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveSyncItemContent(array $item): string
    {
        $content = trim((string) ($item['post_content'] ?? ''));
        if ($content !== '') {
            return $content;
        }

        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];

        return trim((string) ($scoring['body'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncSeoMetaFromWordPress(SeoArticle $article, array $item): void
    {
        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];

        $metaMap = [
            'seo_plugin' => (string) ($seo['plugin'] ?? ''),
            'seo_title' => (string) ($seo['seo_title'] ?? ''),
            'seo_meta_description' => (string) ($seo['meta_description'] ?? ''),
            'seo_focus_keyword' => (string) ($seo['focus_keyword'] ?? ''),
        ];

        foreach ($metaMap as $metaKey => $metaValue) {
            $metaValue = trim($metaValue);
            if ($metaValue === '') {
                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $metaKey],
                ['meta_value' => $metaValue]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncFocusKeyword(Site $site, int $userId, SeoArticle $article, array $item): void
    {
        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];
        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];

        $phrase = trim((string) ($seo['focus_keyword'] ?? $scoring['focus_keyword'] ?? ''));
        if ($phrase === '') {
            return;
        }

        $keyword = Keyword::query()->firstOrCreate(
            [
                'site_id' => $site->id,
                'phrase' => $phrase,
            ],
            [
                'user_id' => $userId,
            ]
        );

        $article->keywords()->syncWithoutDetaching([
            $keyword->id => ['weight' => 1.0],
        ]);
    }

    private function buildSyncUrl(Site $site): string
    {
        return $this->buildSiteBaseUrl($site) . '/wp-json/omi-seo-ai/v1/sync';
    }

    private function buildSiteBaseUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            return rtrim($domain, '/');
        }

        $scheme = ! empty($site->ssl) ? 'https' : 'http';

        return $scheme . '://' . rtrim($domain, '/');
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'article', 'post', 'page' => 'article',
            'product' => 'product',
            'category' => 'category',
            'product_category', 'product_cat' => 'product_category',
            default => $type !== '' ? $type : 'article',
        };
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'publish', 'published' => 'published',
            'future' => 'scheduled',
            'private' => 'private',
            default => 'draft',
        };
    }

    private function parsePublishedAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
