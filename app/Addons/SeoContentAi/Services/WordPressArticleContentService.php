<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\Site;
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
        if ($cached !== '') {
            return $cached;
        }

        $remote = $this->fetchPostFromWordPress($article);

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

        $remote = $this->fetchPostFromWordPress($article);

        return trim((string) ($remote['slug'] ?? ''));
    }

    public function resolveFeaturedImageUrl(SeoArticle $article): ?string
    {
        $cached = trim((string) $this->getMeta($article, 'wp_featured_image_url', ''));
        if ($cached !== '') {
            return $cached;
        }

        $remote = $this->fetchPostFromWordPress($article);

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
        $cached = $this->getMetaJson($article, 'wp_product_gallery');
        if ($cached !== []) {
            return $this->normalizeProductGallery($cached);
        }

        $remote = $this->fetchPostFromWordPress($article);
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
    public function fetchPostFromWordPress(SeoArticle $article): array
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

        $url = $this->buildPostUrl($site, $wpPostId);
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

            if (filled($post['post_content'] ?? null)) {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_content'],
                    ['meta_value' => (string) $post['post_content']],
                );
            }

            if (filled($post['slug'] ?? null)) {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_slug'],
                    ['meta_value' => (string) $post['slug']],
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

            return $post;
        } catch (Throwable $e) {
            Log::warning('WordPress post fetch failed', [
                'article_id' => $article->id,
                'wp_post_id' => $wpPostId,
                'error' => $e->getMessage(),
            ]);

            return [];
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
    private function normalizeProductGallery(array $items): array
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
}
