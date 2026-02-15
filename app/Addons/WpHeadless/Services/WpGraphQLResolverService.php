<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Services;

use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Models\Site;
use App\Models\SiteService;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gọi WordPress GraphQL: resolve URI → post_type và lấy toàn bộ dữ liệu node (page/post/category/...) để gửi cho Next.js.
 */
final class WpGraphQLResolverService
{
    /** Map __typename từ WPGraphQL sang post_type (wp_headless_templates.type / styles). */
    private const TYPENAME_TO_POST_TYPE = [
        'Post'            => 'post',
        'Page'            => 'page',
        'Category'        => 'category',
        'Tag'             => 'post_tag',
        'PostTag'         => 'post_tag',
        'Product'         => 'product',
        'ProductCategory' => 'product_cat',
        'ProductTag'      => 'product_tag',
    ];

    /**
     * Resolve URL (hoặc path) tới post_type bằng cách gọi nodeByUri trên WordPress GraphQL.
     * Trả về post_type string hoặc null nếu không resolve được.
     */
    public function resolveUriToPostType(Site $site, string $urlOrPath): ?string
    {
        $path = $this->normalizeUriToPath($urlOrPath, $site);
        $graphqlUrl = $this->graphqlUrl($site);
        $headers = $this->graphqlHeaders($site);
        if ($headers === null) {
            return null;
        }

        $query = <<<'GQL'
query GetNodeByUri($uri: String!) {
  nodeByUri(uri: $uri) {
    __typename
  }
}
GQL;

        $response = Http::timeout(15)
            ->withHeaders($headers)
            ->post($graphqlUrl, [
                'query'     => $query,
                'variables' => ['uri' => $path],
            ]);

        if (!$response->successful()) {
            Log::debug('WpGraphQLResolver: nodeByUri failed', ['uri' => $path, 'status' => $response->status()]);
            return null;
        }

        $typename = $response->json('data.nodeByUri.__typename');
        if ($typename === null || $typename === '') {
            return null;
        }

        return self::TYPENAME_TO_POST_TYPE[$typename] ?? $this->typenameToSlug($typename);
    }

    /**
     * Lấy toàn bộ dữ liệu node từ WordPress theo URI (post, page, category, tag, ...) để gửi cho Next.js.
     * Trả về mảng dữ liệu node (title, content, excerpt, uri, featuredImage, ...) hoặc null.
     */
    public function fetchNodeByUri(Site $site, string $urlOrPath): ?array
    {
        $path = $this->normalizeUriToPath($urlOrPath, $site);
        $graphqlUrl = $this->graphqlUrl($site);
        $headers = $this->graphqlHeaders($site);
        if ($headers === null) {
            return null;
        }

        $query = <<<'GQL'
query GetNodeByUri($uri: String!) {
  nodeByUri(uri: $uri) {
    __typename
    ... on Post {
      databaseId
      uri
      title
      content
      excerpt
      date
      featuredImage { node { sourceUrl altText } }
    }
    ... on Page {
      databaseId
      uri
      title
      content
      excerpt
      featuredImage { node { sourceUrl altText } }
    }
    ... on Category {
      databaseId
      uri
      name
      description
    }
    ... on Tag {
      databaseId
      uri
      name
      description
    }
  }
}
GQL;

        $response = Http::timeout(15)
            ->withHeaders($headers)
            ->post($graphqlUrl, [
                'query'     => $query,
                'variables' => ['uri' => $path],
            ]);

        if (!$response->successful()) {
            Log::debug('WpGraphQLResolver: fetchNodeByUri failed', ['uri' => $path, 'status' => $response->status()]);
            return null;
        }

        $node = $response->json('data.nodeByUri');
        return \is_array($node) ? $node : null;
    }

    /**
     * Lấy bodyClass (get_body_class từ WordPress) cho post_type từ wp_headless_templates.
     * Next.js dùng để gán class cho <body>.
     */
    public function getBodyClassForPostType(Site $site, string $postType): array
    {
        $t = WpHeadlessTemplate::where('site_id', $site->id)->where('type', $postType)->first();
        if ($t === null || !is_array($t->body_class)) {
            return [];
        }
        return array_values($t->body_class);
    }

    /** Chuẩn hóa URL đầy đủ hoặc path thành path (bắt đầu bằng /). */
    private function normalizeUriToPath(string $urlOrPath, Site $site): string
    {
        $s = trim($urlOrPath);
        if ($s === '') {
            return '/';
        }
        if (preg_match('#^https?://#i', $s)) {
            $parsed = parse_url($s);
            $path = $parsed['path'] ?? '/';
            $host = $parsed['host'] ?? '';
            $siteHost = preg_replace('/^www\./', '', $site->domain ?? '');
            $requestHost = preg_replace('/^www\./', '', strtolower($host));
            if ($requestHost !== '' && $requestHost !== strtolower($siteHost)) {
                return $path;
            }
            return $path === '' ? '/' : $path;
        }
        return str_starts_with($s, '/') ? $s : '/' . $s;
    }

    private function graphqlUrl(Site $site): string
    {
        $scheme = ($site->ssl ?? true) ? 'https' : 'http';
        return $scheme . '://' . $site->domain . '/graphql';
    }

    private function graphqlHeaders(Site $site): ?array
    {
        $siteService = $this->getWpHeadlessSiteService($site);
        if ($siteService === null) {
            return null;
        }
        $readToken = $siteService->settings['READ_TOKEN'] ?? '';
        if ($readToken === '') {
            return null;
        }
        return [
            'Content-Type'      => 'application/json',
            'X-GraphQL-Secret'  => $readToken,
        ];
    }

    private function getWpHeadlessSiteService(Site $site): ?SiteService
    {
        $service = Service::where('slug', 'wp-headless')->first();
        if ($service === null) {
            return null;
        }
        return SiteService::where('site_id', $site->id)
            ->where('service_id', $service->id)
            ->first();
    }

    private function typenameToSlug(string $typename): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($typename)));
        return trim($slug, '_') ?: 'post';
    }
}
