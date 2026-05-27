<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WordPressArticleContentService
{
    public function resolveEditorHtml(SeoArticle $article): string
    {
        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return (string) $article->body;
        }

        $cached = trim((string) $this->getMeta($article, 'wp_post_content', ''));
        $entity = trim((string) $this->getMeta($article, 'wp_entity', ''));

        // Danh mục (product_cat / category): chỉ tin cache sau khi đồng bộ đúng entity=term.
        // Tránh hiển thị nhầm nội dung post WP trùng term_id (vd. JSON font family).
        if ($this->isTaxonomyRecord($article)) {
            if ($entity === 'term' && $cached !== '') {
                return $cached;
            }

            $remote = $this->fetchFromWordPress($article);
            $fresh = trim((string) ($remote['post_content'] ?? ''));

            return $fresh !== '' ? $fresh : $cached;
        }

        if ($cached !== '') {
            return $cached;
        }

        $remote = $this->fetchFromWordPress($article);

        return trim((string) ($remote['post_content'] ?? ''));
    }

    public function resolveSlug(SeoArticle $article): string
    {
        if (filled($article->slug)) {
            return (string) $article->slug;
        }

        $metaSlug = trim((string) $this->getMeta($article, 'wp_slug', ''));
        if ($metaSlug !== '') {
            return $metaSlug;
        }

        $remote = $this->fetchFromWordPress($article);

        return trim((string) ($remote['slug'] ?? ''));
    }

    /**
     * URL công khai theo cấu trúc permalink WordPress (không ghép domain + slug).
     */
    public function resolvePermalink(SeoArticle $article): string
    {
        $cached = trim((string) $this->getMeta($article, 'wp_permalink', ''));
        if ($cached !== '') {
            return $cached;
        }

        $remote = $this->fetchFromWordPress($article);

        return trim((string) ($remote['permalink'] ?? ''));
    }

    public function resolveFeaturedImageUrl(SeoArticle $article): ?string
    {
        $cached = trim((string) $this->getMeta($article, 'wp_featured_image_url', ''));
        if ($cached !== '') {
            return $cached;
        }

        $remote = $this->fetchFromWordPress($article);

        $url = trim((string) ($remote['featured_image_url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * Album ảnh sản phẩm WooCommerce (đồng bộ từ WordPress).
     *
     * @return array<int, array{id: int, url: string}>
     */
    public function resolveProductGallery(SeoArticle $article): array
    {
        if ($this->isTaxonomyRecord($article)) {
            return [];
        }

        $cached = $this->getMetaJson($article, 'wp_product_gallery');
        if ($cached !== []) {
            return $this->normalizeProductGallery($cached);
        }

        $remote = $this->fetchFromWordPress($article);
        $gallery = $remote['product_gallery'] ?? null;

        return is_array($gallery) ? $this->normalizeProductGallery($gallery) : [];
    }

    public function getPermalinkBase(Site $site): string
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

    /**
     * @return array<string, mixed>
     */
    public function fetchFromWordPress(SeoArticle $article, bool $importFaqs = true): array
    {
        $wpId = (int) ($article->wp_post_id ?? 0);
        if ($wpId <= 0) {
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

        $taxonomy = $this->resolveWpTaxonomy($article);
        $url = $taxonomy !== null
            ? $this->buildTermUrl($site, $taxonomy, $wpId)
            : $this->buildPostUrl($site, $wpId);

        if ($url === '') {
            return [];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($readToken)
                ->get($url);

            if (! $response->successful()) {
                return [];
            }

            $payload = $response->json();
            if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                return [];
            }

            $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];

            $this->persistFetchedMeta($article, $post, $taxonomy !== null, $importFaqs);

            return $post;
        } catch (Throwable $e) {
            Log::warning('WordPress content fetch failed', [
                'article_id' => $article->id,
                'wp_post_id' => $wpId,
                'taxonomy' => $taxonomy,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @deprecated Use fetchFromWordPress()
     *
     * @return array<string, mixed>
     */
    public function fetchPostFromWordPress(SeoArticle $article): array
    {
        return $this->fetchFromWordPress($article);
    }

    public function isTaxonomyRecord(SeoArticle $article): bool
    {
        return $this->resolveWpTaxonomy($article) !== null;
    }

    public function resolveWpTaxonomy(SeoArticle $article): ?string
    {
        $entity = trim((string) $this->getMeta($article, 'wp_entity', ''));
        if ($entity === 'term') {
            $taxonomy = trim((string) $this->getMeta($article, 'wp_post_type', ''));

            return $this->normalizeTaxonomySlug($taxonomy);
        }

        $type = strtolower(trim((string) ($article->type ?? '')));
        if ($type === 'product_category') {
            return 'product_cat';
        }
        if ($type === 'category') {
            return 'category';
        }

        $wpPostType = trim((string) $this->getMeta($article, 'wp_post_type', ''));

        return $this->normalizeTaxonomySlug($wpPostType);
    }

    private function normalizeTaxonomySlug(string $taxonomy): ?string
    {
        return match ($taxonomy) {
            'product_cat', 'product_category' => 'product_cat',
            'category' => 'category',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function persistFetchedMeta(SeoArticle $article, array $post, bool $isTaxonomy, bool $importFaqs = true): void
    {
        $syncFlags = app(ArticleWordPressSyncFlagService::class);

        if (! $syncFlags->shouldBlockWordPressImport($article)) {
            $title = $syncFlags->decodeWordPressText((string) ($post['post_title'] ?? ''));
            if ($title !== '') {
                $article->update(['title' => $title]);
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_title'],
                    ['meta_value' => $title],
                );
            }

            $updates = [];
            $remoteStatus = strtolower(trim((string) ($post['status'] ?? '')));
            if ($remoteStatus !== '') {
                $updates['status'] = match ($remoteStatus) {
                    'publish', 'published' => 'published',
                    'future', 'scheduled' => 'scheduled',
                    'private' => 'private',
                    default => 'draft',
                };
            }

            $publishedAt = $this->parseRemotePublishedAt($post['published_at'] ?? null);
            if ($publishedAt !== null) {
                $updates['published_at'] = $publishedAt;
            }

            if ($updates !== []) {
                $article->update($updates);
            }
        }

        if (array_key_exists('post_content', $post)) {
            $rawContent = (string) $post['post_content'];
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_content'],
                ['meta_value' => $rawContent],
            );

            app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $rawContent);
        }

        if (filled($post['slug'] ?? null)) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_slug'],
                ['meta_value' => (string) $post['slug']],
            );
        }

        if (filled($post['permalink'] ?? null)) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => (string) $post['permalink']],
            );
        }

        if (filled($post['featured_image_url'] ?? null)) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_featured_image_url'],
                ['meta_value' => (string) $post['featured_image_url']],
            );
        }

        if (is_array($post['product_gallery'] ?? null) && $post['product_gallery'] !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_product_gallery'],
                ['meta_value' => json_encode($post['product_gallery'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        }

        if (is_array($post['post_images'] ?? null) && $post['post_images'] !== []) {
            app(ArticlePostImagesService::class)->importFromSyncItem($article, $post);
        }

        if (is_array($post['faqs'] ?? null) && $post['faqs'] !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_faqs'],
                ['meta_value' => json_encode($post['faqs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        }

        if ($importFaqs && is_array($post['faqs'] ?? null)) {
            app(ArticleFaqWordPressImportService::class)->importFromWordPressSyncItem($article, $post);
        }
    }

    private function getMeta(SeoArticle $article, string $key, ?string $default = null): ?string
    {
        $article->loadMissing('articleMetas');
        $value = $article->articleMetas->firstWhere('meta_key', $key)?->meta_value;

        return $value !== null && $value !== '' ? (string) $value : $default;
    }

    /**
     * @return array<int, mixed>
     */
    private function getMetaJson(SeoArticle $article, string $key): array
    {
        $raw = $this->getMeta($article, $key, '');
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{id: int, url: string}>
     */
    public function normalizeProductGallery(array $items): array
    {
        $gallery = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $gallery[] = [
                'id' => (int) ($item['id'] ?? 0),
                'url' => $url,
            ];
        }

        return $gallery;
    }

    private function buildPostUrl(Site $site, int $wpPostId): string
    {
        $base = $this->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/posts/' . $wpPostId;
    }

    private function buildTermUrl(Site $site, string $taxonomy, int $termId): string
    {
        $base = $this->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/terms/' . rawurlencode($taxonomy) . '/' . $termId;
    }

    private function parseRemotePublishedAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * URL đẩy nội dung từ SEO editor lên WordPress (post hoặc taxonomy term).
     */
    public function buildEditorSyncUrl(Site $site, SeoArticle $article): string
    {
        $wpId = (int) ($article->wp_post_id ?? 0);
        if ($wpId <= 0) {
            return '';
        }

        $base = $this->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        $taxonomy = $this->resolveWpTaxonomy($article);
        if ($taxonomy !== null) {
            return $base . '/wp-json/omi-seo-ai/v1/terms/' . rawurlencode($taxonomy) . '/' . $wpId . '/editor-sync';
        }

        return $base . '/wp-json/omi-seo-ai/v1/posts/' . $wpId . '/editor-sync';
    }
}
