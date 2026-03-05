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
     * - tmHeader (int|null): ID block header
     * - tmFooter (int|null): ID block footer
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

        // Nếu có cache còn hạn thì trả về cache cho Next.js.
        $cached = $this->getCachedNode($site, $path);
        if ($cached !== null) {
            return $cached;
        }

        $postFields = [
            'databaseId',
            'uri',
            'title',
            'contentTemplateJson',
            'excerpt',
            'date',
            'templatePath',
            'featuredImage { node { sourceUrl altText } }',
            'headlessSeo',
        ];

        $taxonomyFields = [
            'databaseId',
            'uri',
            'name',
            'description',
            'templatePath',
        ];

    $extendedFields = [
        'tmHeader',
        'tmFooter',
    ];

    $postFields = array_merge($postFields, $extendedFields);
    $taxonomyFields = array_merge($taxonomyFields, $extendedFields);
        // 2. Dùng Nowdoc làm template với các điểm giữ chỗ (%1$s, %2$s)
$queryTemplate = <<<'GQL'
query GetNodeByUri($uri: String!) {
  nodeByUri(uri: $uri) {
    __typename
    ... on Post {
      %1$s
    }
    ... on Page {
      %1$s
    }
    ... on Category {
      %2$s
    }
    ... on Tag {
      %2$s
    }
  }
}
GQL;

// 3. Nối chuỗi bằng hàm sprintf
$query = sprintf($queryTemplate, implode("\n", $postFields), implode("\n", $taxonomyFields));


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
        if (!\is_array($node) || empty($node)) {
            return null;
        }

        // So sánh class trong contentTemplateJson với merged_classes; xóa class không tồn tại.
        if (!empty($node['contentTemplateJson']) && is_string($node['contentTemplateJson'])) {
            $merged = $this->getMergedClassesForSite($site);
            $decoded = json_decode($node['contentTemplateJson'], true);
            if (is_array($decoded)) {
                $node['contentTemplateJson'] = json_encode($this->stripClassesInContentTemplateJson($decoded, $merged));
            }
            // Có contentTemplateJson thì bỏ content (Next.js dùng JSON để render, không cần HTML).
            unset($node['content']);
        }

        // Chỉ cache khi có node hợp lệ (có __typename); không bao giờ cache null/empty.
        if (!empty($node['__typename'])) {
            $this->setCachedNode($site, $path, $node);
        }

        return $node;
    }

    /**
     * Thời gian cache node (giây). Lấy từ wp_headless_sites.settings['node_cache_ttl_seconds'].
     */
    public function getNodeCacheTtlSeconds(Site $site): int
    {
        $wpSite = WpHeadlessSite::find($site->id);
        if ($wpSite === null || !is_array($wpSite->settings)) {
            return 300;
        }
        $ttl = $wpSite->settings['node_cache_ttl_seconds'] ?? null;
        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 300;
    }

    /**
     * Đọc danh sách class đã merge của site (từ file lưu sau sync template).
     *
     * @return array<int, string>
     */
    public function getMergedClassesForSite(Site $site): array
    {
        $path = storage_path('app/wp_headless/sites/' . $site->id . '/merged_classes.json');
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Xóa class trong cây template JSON không nằm trong danh sách allowed (merged_classes).
     *
     * @param array<string, mixed> $json Cấu trúc { children: [...], classes?: [...] }
     * @param array<int, string> $allowedClasses
     * @return array<string, mixed>
     */
    public function stripClassesInContentTemplateJson(array $json, array $allowedClasses): array
    {
        $set = array_flip($allowedClasses);

        $filterInTree = function (array $nodes) use (&$filterInTree, $set): array {
            $out = [];
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    $out[] = $node;
                    continue;
                }
                if (isset($node['type']) && $node['type'] === 'element' && isset($node['attrs']['class'])) {
                    $classStr = $node['attrs']['class'];
                    $filtered = [];
                    foreach (preg_split('/\s+/', trim($classStr), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
                        $c = trim($c);
                        if ($c !== '' && isset($set[$c])) {
                            $filtered[] = $c;
                        }
                    }
                    $node['attrs']['class'] = implode(' ', $filtered);
                    if ($node['attrs']['class'] === '') {
                        unset($node['attrs']['class']);
                    }
                    if (empty($node['attrs'])) {
                        unset($node['attrs']);
                    }
                }
                if (isset($node['children']) && is_array($node['children'])) {
                    $node['children'] = $filterInTree($node['children']);
                }
                $out[] = $node;
            }
            return $out;
        };

        if (isset($json['children']) && is_array($json['children'])) {
            $json['children'] = $filterInTree($json['children']);
        }
        if (isset($json['classes']) && is_array($json['classes'])) {
            $json['classes'] = array_values(array_intersect($json['classes'], $allowedClasses));
            sort($json['classes']);
        }

        return $json;
    }

    private function getCachedNode(Site $site, string $uri): ?array
    {
        $ttl = $this->getNodeCacheTtlSeconds($site);
        $dir = storage_path('app/wp_headless/cache/nodes');
        if (!is_dir($dir)) {
            return null;
        }
        $key = md5($site->id . '_' . $uri);
        $path = $dir . '/' . $key . '.json';
        if (!is_file($path)) {
            return null;
        }
        $mtime = filemtime($path);
        if ($mtime === false || time() - $mtime > $ttl) {
            return null;
        }
        $raw = file_get_contents($path);
        $decoded = json_decode($raw, true);
        // Chỉ trả về cache khi decode ra array hợp lệ (có __typename); nếu file chứa null hoặc không hợp lệ thì xóa file và coi như cache miss.
        if (is_array($decoded) && !empty($decoded['__typename'])) {
            return $decoded;
        }
        @unlink($path);
        return null;
    }

    private function setCachedNode(Site $site, string $uri, array $node): void
    {
        $dir = storage_path('app/wp_headless/cache/nodes');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $key = md5($site->id . '_' . $uri);
        $path = $dir . '/' . $key . '.json';
        file_put_contents($path, json_encode($node, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Xóa cache node cho path (để lần sau fetchNodeByUri gọi lại GraphQL).
     * Gọi khi data = null để tránh dùng lại cache cũ không hợp lệ.
     */
    public function clearCachedNode(Site $site, string $urlOrPath): void
    {
        $path = $this->normalizeUriToPath($urlOrPath, $site);
        $dir = storage_path('app/wp_headless/cache/nodes');
        $key = md5($site->id . '_' . $path);
        $filePath = $dir . '/' . $key . '.json';
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Kiểm tra node có header/footer tùy chỉnh theo theme (Flatsome _header_block, _footer_block, ...).
     *
     * @param array<string, mixed> $node Mảng node trả về từ fetchNodeByUri (Post/Page).
     */
    public function hasCustomHeaderFooter(array $node): bool
    {
        $header = $node['tmHeader'] ?? null;
        $footer = $node['tmFooter'] ?? null;
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
                    'template'   => WpHeadlessTemplate::normalizeTemplateValue($headerHtml),
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
                    'template'   => WpHeadlessTemplate::normalizeTemplateValue($footerHtml),
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
