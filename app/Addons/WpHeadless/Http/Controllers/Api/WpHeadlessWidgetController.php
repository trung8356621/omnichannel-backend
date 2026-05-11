<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteService;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * API widget cho Next.js: products, product-categories, layered-nav, price-filter, posts.
 * Laravel proxy sang WordPress REST (tvh/v1/widget-*). WordPress cần đăng ký route tương ứng.
 */
class WpHeadlessWidgetController extends Controller
{
    /**
     * GET /api/wp-headless/widget/products?site_id=&limit=4&orderby=&order=&category=&ids=
     */
    public function products(Request $request): JsonResponse
    {
        $request->validate(['site_id' => 'required|integer|exists:sites,id']);
        $site = Site::find((int) $request->input('site_id'));
        if ($site === null) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $normalizedCategory = $this->normalizeWidgetProductCategoryFilter($site, $request->input('category'));
        $params = [
            'limit'   => $request->input('limit', 4),
            'orderby' => $request->input('orderby'),
            'order'   => $request->input('order'),
            'category'=> $normalizedCategory,
            'ids'     => $request->input('ids'),
        ];
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');

        return $this->proxyToWordPress($site, 'widget-products', $params);
    }

    /**
     * GET /api/wp-headless/widget/product-categories?site_id=&parent=
     */
    public function productCategories(Request $request): JsonResponse
    {
        $request->validate(['site_id' => 'required|integer|exists:sites,id']);
        $site = Site::find((int) $request->input('site_id'));
        if ($site === null) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $params = array_filter([
            'parent' => $request->input('parent'),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->proxyToWordPress($site, 'widget-product-categories', $params);
    }

    /**
     * GET /api/wp-headless/widget/layered-nav?site_id=&attribute=
     */
    public function layeredNav(Request $request): JsonResponse
    {
        $request->validate(['site_id' => 'required|integer|exists:sites,id']);
        $site = Site::find((int) $request->input('site_id'));
        if ($site === null) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $params = array_filter([
            'attribute' => $request->input('attribute'),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->proxyToWordPress($site, 'widget-layered-nav', $params);
    }

    /**
     * GET /api/wp-headless/widget/price-filter?site_id=
     */
    public function priceFilter(Request $request): JsonResponse
    {
        $request->validate(['site_id' => 'required|integer|exists:sites,id']);
        $site = Site::find((int) $request->input('site_id'));
        if ($site === null) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        return $this->proxyToWordPress($site, 'widget-price-filter', []);
    }

    /**
     * POST /api/wp-headless/widget/posts
     * Body or query: site_id (required), per_page=5, ids=
     * Header: Authorization: Bearer <read_token>
     */
    public function posts(Request $request): JsonResponse
    {
        $request->validate(['site_id' => 'required|integer|exists:sites,id']);
        $site = Site::find((int) $request->input('site_id'));
        if ($site === null) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $params = array_filter([
            'per_page' => $request->input('per_page', 5),
            'ids'      => $request->input('ids'),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->proxyToWordPress($site, 'widget-posts', $params);
    }

    private function proxyToWordPress(Site $site, string $resource, array $queryParams): JsonResponse
    {
        $baseUrl = $this->wpBaseUrl($site);
        $headers = $this->wpRestHeaders($site);
        if ($headers === null) {
            return response()->json([
                'success' => false,
                'message' => 'WordPress REST token not configured',
            ], 502);
        }

        $url = $baseUrl . '/wp-json/tvh/v1/' . $resource;
        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessWidget: proxy request failed', [
                'resource' => $resource,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'WordPress request failed',
            ], 502);
        }

        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'WordPress returned ' . $response->status(),
            ], 502);
        }
        $body = $response->json();
        return response()->json(is_array($body) ? $body : ['success' => true, 'data' => $body]);
    }

    private function wpBaseUrl(Site $site): string
    {
        $scheme = ($site->ssl ?? true) ? 'https' : 'http';
        return $scheme . '://' . $site->domain;
    }

    private function wpRestHeaders(Site $site): ?array
    {
        $service = Service::where('slug', 'wp-headless')->first();
        if ($service === null) {
            return null;
        }
        $siteService = SiteService::where('site_id', $site->id)
            ->where('service_id', $service->id)
            ->first();
        if ($siteService === null) {
            return null;
        }
        $token = $siteService->settings['READ_TOKEN'] ?? '';
        if ($token === '') {
            return null;
        }
        return [
            'Content-Type'     => 'application/json',
            'X-GraphQL-Secret' => $token,
        ];
    }

    /**
     * Chuẩn hóa filter category cho widget-products:
     * - Flatsome thường gửi cat là term_id (số)
     * - wc_get_products(category=...) ưu tiên slug
     * => map term_id -> slug để query đúng dữ liệu.
     */
    private function normalizeWidgetProductCategoryFilter(Site $site, mixed $rawCategory): ?string
    {
        if ($rawCategory === null) {
            return null;
        }

        $raw = trim((string) $rawCategory);
        if ($raw === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            explode(',', $raw)
        ), static fn (string $value): bool => $value !== ''));
        if ($parts === []) {
            return null;
        }

        $headers = $this->wpRestHeaders($site) ?? ['Content-Type' => 'application/json'];
        $baseUrl = $this->wpBaseUrl($site);
        $resolved = [];

        foreach ($parts as $part) {
            if (!ctype_digit($part)) {
                $resolved[] = $part;
                continue;
            }

            $termId = (int) $part;
            if ($termId <= 0) {
                continue;
            }

            try {
                $termResponse = Http::timeout(8)
                    ->withHeaders($headers)
                    ->get($baseUrl . '/wp-json/wp/v2/product_cat/' . $termId);
                if ($termResponse->successful()) {
                    $slug = trim((string) ($termResponse->json('slug') ?? ''));
                    if ($slug !== '') {
                        $resolved[] = $slug;
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('WpHeadlessWidget: resolve category slug failed', [
                    'site_id' => $site->id,
                    'term_id' => $termId,
                    'error'   => $e->getMessage(),
                ]);
            }

            // Fallback giữ nguyên giá trị gốc nếu không resolve được slug.
            $resolved[] = $part;
        }

        $resolved = array_values(array_unique(array_filter($resolved, static fn (string $value): bool => $value !== '')));
        return $resolved === [] ? null : implode(',', $resolved);
    }
}
