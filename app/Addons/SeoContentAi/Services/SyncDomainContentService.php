<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;
use App\Addons\SeoContentAi\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDomainContentService
{
    public function __construct(
        private readonly WordPressArticleTimestampService $timestampService,
    ) {}

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
            $siteInfoResult = app(WordPressSiteInfoService::class)->fetchAndStore($site);
            if (! ($siteInfoResult['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Không lấy được thông tin plugin SEO từ WordPress: '
                        .(string) ($siteInfoResult['message'] ?? 'Lỗi không xác định.'),
                ];
            }

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
                    'message' => 'WordPress trả lỗi HTTP '.$response->status().': '.mb_substr($message, 0, 300),
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
            $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];

            $response = [
                'success' => true,
                'message' => sprintf(
                    'Đồng bộ thành công %d mục từ WordPress%s. Plugin SEO: %s.',
                    array_sum($synced),
                    $isTest ? ' (chế độ test)' : '',
                    (string) ($siteInfoResult['site_info']['active'] ?? 'none'),
                ),
                'synced' => $synced,
            ];

            if ($counts !== []) {
                $response['counts'] = $counts;
            }

            return $response;
        } catch (Throwable $e) {
            Log::error('SeoContentAi sync failed', [
                'site_id' => $site->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
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

        $syncFlags = app(ArticleWordPressSyncFlagService::class);

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

            $existing = SeoArticle::query()
                ->where('site_id', $site->id)
                ->where('wp_post_id', $wpId)
                ->where('type', $type)
                ->first();

            if ($existing instanceof SeoArticle && $syncFlags->shouldBlockWordPressImport($existing)) {
                $syncFlags->markDataOutOfSync($existing);

                if (array_key_exists('conflict', $synced)) {
                    $synced['conflict']++;
                } else {
                    $synced['conflict'] = 1;
                }

                continue;
            }

            $title = $this->resolveSyncItemTitle($item, $syncFlags);
            $hasLocalBody = $existing instanceof SeoArticle && $syncFlags->hasLocalEditorContent($existing);

            // Bài chưa có nội dung editor trên SEO: ghi đè scoring (xóa slug/body). Bài đã có body: chỉ cập nhật tiêu đề/trạng thái.
            $articleAttributes = [
                'type' => $type,
                'title' => $title !== '' ? $title : 'Untitled',
                'status' => $this->normalizeStatus((string) ($item['status'] ?? 'draft')),
                'published_at' => $publishedAt,
            ];

            if (! $hasLocalBody) {
                $articleAttributes['slug'] = null;
                $articleAttributes['excerpt'] = null;
                $articleAttributes['body'] = null;
                $articleAttributes['blocks'] = null;
            }

            $article = SeoArticle::query()->updateOrCreate(
                [
                    'site_id' => $site->id,
                    'wp_post_id' => $wpId,
                    'type' => $type,
                ],
                $articleAttributes,
            );

            if ($title !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_title'],
                    ['meta_value' => $title],
                );
            }

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
            $this->syncSchemaAndWooCommerceMeta($article, $item);
            app(ArticlePostImagesService::class)->importFromSyncItem($article, $item);

            if (! $hasLocalBody) {
                app(ArticleFaqWordPressImportService::class)->importFromWordPressSyncItem($article, $item);
            }

            $this->syncSeoMetaFromWordPress($article, $item);
            $this->syncFocusKeyword($site, $userId, $article, $item);

            $syncFlags->clearAll($article);

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

            $this->timestampService->sync($article, $item);
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

            app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $content);
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
            $article->loadMissing('site');
            if ($article->site instanceof Site) {
                $permalink = app(WordPressPermalinkBuilder::class)->resolveFromSyncItem($article->site, $item);
            }
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
    private function resolveSyncItemTitle(array $item, ArticleWordPressSyncFlagService $syncFlags): string
    {
        $raw = (string) ($item['title'] ?? $item['post_title'] ?? '');

        return $syncFlags->decodeWordPressText($raw);
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
    /**
     * @param  array<string, mixed>  $item
     */
    private function syncSchemaAndWooCommerceMeta(SeoArticle $article, array $item): void
    {
        $schema = trim((string) ($item['schema_json_ld'] ?? ''));
        if ($schema !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'rank_math_schema_json'],
                ['meta_value' => $schema],
            );
        }

        $woocommerce = is_array($item['woocommerce'] ?? null) ? $item['woocommerce'] : [];
        if ($woocommerce === []) {
            return;
        }

        $currency = strtoupper(trim((string) ($woocommerce['currency'] ?? 'VND')));

        $map = [
            '_price' => (string) ($woocommerce['price'] ?? ''),
            'regular_price' => (string) ($woocommerce['regular_price'] ?? ''),
            '_regular_price' => (string) ($woocommerce['regular_price'] ?? ''),
            'sale_price' => (string) ($woocommerce['sale_price'] ?? ''),
            '_sale_price' => (string) ($woocommerce['sale_price'] ?? ''),
            'min_price' => (string) ($woocommerce['min_price'] ?? ''),
            'max_price' => (string) ($woocommerce['max_price'] ?? ''),
            'price_currency' => $currency !== '' ? $currency : 'VND',
        ];

        foreach ($map as $metaKey => $metaValue) {
            $metaValue = trim($metaValue);
            if ($metaValue === '') {
                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $metaKey],
                ['meta_value' => $metaValue],
            );
        }
    }

    private function syncSeoMetaFromWordPress(SeoArticle $article, array $item): void
    {
        $article->articleMetas()->where('meta_key', 'seo_plugin')->delete();

        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];

        $metaMap = [
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

        KeywordFocusAttach::syncMainKeyword($article, $site->id, $userId, $phrase);
    }

    private function buildSyncUrl(Site $site): string
    {
        return $this->buildSiteBaseUrl($site).'/wp-json/omi-seo-ai/v1/sync';
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

        return $scheme.'://'.rtrim($domain, '/');
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
