<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Services;

use App\Addons\WpHeadless\Models\WpHeadlessSite;
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
     *
     * Với Post/Page, nếu theme (Flatsome, ...) có header/footer theo từng trang thì node sẽ có:
     * - _header (int|null): ID block header
     * - _footer (int|null): ID block footer
     * - _headerTemplate (string): HTML đã render của header
     * - _footerTemplate (string): HTML đã render của footer
     * Dùng hasCustomHeaderFooter($node) để kiểm tra trường hợp đặc biệt → thêm template, optimize CSS, đẩy Next.js.
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
      templatePath
      featuredImage { node { sourceUrl altText } }
    }
    ... on Page {
      databaseId
      uri
      title
      content
      excerpt
      templatePath
      featuredImage { node { sourceUrl altText } }
    }
    ... on Category {
      databaseId
      uri
      name
      description
      templatePath
    }
    ... on Tag {
      databaseId
      uri
      name
      description
      templatePath
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
     * Kiểm tra node có header/footer tùy chỉnh theo theme (Flatsome _header_block, _footer_block, ...).
     *
     * @param array<string, mixed> $node Mảng node trả về từ fetchNodeByUri (Post/Page).
     */
    public function hasCustomHeaderFooter(array $node): bool
    {
        $header = $node['_header'] ?? null;
        $footer = $node['_footer'] ?? null;
        return ($header !== null && (int) $header > 0) || ($footer !== null && (int) $footer > 0);
    }

    /**
     * Lưu header/footer tùy chỉnh (từ _headerTemplate, _footerTemplate) vào wp_headless_templates.
     * Type = header_{databaseId}, footer_{databaseId} để Next.js lấy theo post.
     *
     * @param array<string, mixed> $node Node từ fetchNodeByUri (có databaseId, _headerTemplate, _footerTemplate).
     */
    public function saveCustomHeaderFooterToDatabase(Site $site, array $node): void
    {
        $databaseId = (int) ($node['databaseId'] ?? 0);
        if ($databaseId <= 0) {
            return;
        }

        $headerHtml = trim((string) ($node['_headerTemplate'] ?? ''));
        $footerHtml = trim((string) ($node['_footerTemplate'] ?? ''));

        $siteId = $site->id;

        if ($headerHtml !== '') {
            $parsed = $this->parseTemplateHtmlForSave($headerHtml);
            WpHeadlessTemplate::updateOrCreate(
                ['site_id' => $siteId, 'type' => 'header_' . $databaseId],
                [
                    'parent_id'  => null,
                    'global'     => false,
                    'template'   => $headerHtml,
                    'classes'    => $parsed['classes'],
                    'body_class' => [],
                ]
            );
        }

        if ($footerHtml !== '') {
            $parsed = $this->parseTemplateHtmlForSave($footerHtml);
            WpHeadlessTemplate::updateOrCreate(
                ['site_id' => $siteId, 'type' => 'footer_' . $databaseId],
                [
                    'parent_id'  => null,
                    'global'     => false,
                    'template'   => $footerHtml,
                    'classes'    => $parsed['classes'],
                    'body_class' => [],
                ]
            );
        }
    }

    /**
     * Gửi payload (templates + data) tới Next.js API để lưu template.
     * Gọi sau khi đã save DB và chạy optimize.
     *
     * @param array<string, mixed> $payload site_id, templates (key = header_{id}/footer_{id}, value = HTML), data, post_type, optimizedCssUrls, bodyClass.
     */
    public function pushTemplatesToNextjs(Site $site, array $payload): bool
    {
        $wpSite = WpHeadlessSite::find($site->id);
        $nextjsUrl = $wpSite ? $wpSite->getNextjsBaseUrl() : '';
        if ($nextjsUrl === '') {
            Log::debug('WpGraphQLResolver: pushTemplatesToNextjs skipped, no nextjs URL for site');
            return false;
        }

        $endpoint = rtrim($nextjsUrl, '/') . '/api/wp-templates/receive';
        try {
            $response = Http::timeout(10)->post($endpoint, $payload);
            if (!$response->successful()) {
                Log::warning('WpGraphQLResolver: pushTemplatesToNextjs failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('WpGraphQLResolver: pushTemplatesToNextjs error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Bóc class từ HTML (dùng khi lưu template). */
    private function parseTemplateHtmlForSave(string $html): array
    {
        $classes = [];
        if (preg_match_all('/\bclass\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $classAttr) {
                foreach (preg_split('/\s+/', trim($classAttr), -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    $c = trim($c);
                    if ($c !== '') {
                        $classes[$c] = true;
                    }
                }
            }
        }
        $classes = array_keys($classes);
        sort($classes);
        return ['classes' => $classes];
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
