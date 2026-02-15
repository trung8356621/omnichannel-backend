<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Models\WpHeadlessStyleOptimized;
use App\Addons\WpHeadless\Services\WpGraphQLResolverService;
use App\Addons\WpHeadless\Services\WpHeadlessStylesOptimizerService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API cho Next.js: nhận URL → Laravel lấy toàn bộ data từ WordPress (GraphQL) + CSS tối ưu, gửi về Next.js.
 */
class OptimizedCssForUrlController extends Controller
{
    public function __construct(
        private WpGraphQLResolverService $resolver,
        private WpHeadlessStylesOptimizerService $optimizer
    ) {}

    /**
     * POST /api/wp-headless/page-by-url
     * Body (từ Next.js): { "site_id": 2, "url": "/blog/my-post" }
     * Laravel: nodeByUri(uri) lấy full data WordPress, resolve post_type, lấy/generate optimized CSS.
     * Response: { success, data: <node WP>, post_type, optimizedCssUrls: [...] }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
            'url'     => 'required|string|max:2048',
        ]);

        $site = Site::find($request->input('site_id'));
        if (!$site) {
            return response()->json(['message' => 'Site not found'], 404);
        }

        $urlOrPath = $request->input('url');

        $data = $this->resolver->fetchNodeByUri($site, $urlOrPath);
        $postType = $data !== null
            ? (self::TYPENAME_TO_POST_TYPE[$data['__typename'] ?? ''] ?? strtolower((string) ($data['__typename'] ?? 'post')))
            : $this->resolver->resolveUriToPostType($site, $urlOrPath);

        if ($postType === null) {
            $postType = 'global';
        }

        $existing = WpHeadlessStyleOptimized::where('site_id', $site->id)
            ->where('post_type', $postType)
            ->orderBy('chunk_index')
            ->get();

        if ($existing->isEmpty()) {
            $result = $this->optimizer->optimize($site, $postType);
            $urls = ($result['success'] ?? false) ? ($result['urls'] ?? []) : [];
        } else {
            $urls = $existing->map(fn ($row) => $row->public_url)->filter()->values()->all();
        }

        $bodyClass = $this->resolver->getBodyClassForPostType($site, $postType);

        return response()->json([
            'success'          => true,
            'data'             => $data,
            'post_type'        => $postType,
            'bodyClass'        => $bodyClass,
            'optimizedCssUrls' => $urls,
        ]);
    }

    private const TYPENAME_TO_POST_TYPE = [
        'Post' => 'post', 'Page' => 'page', 'Category' => 'category',
        'Tag' => 'post_tag', 'PostTag' => 'post_tag', 'Product' => 'product',
        'ProductCategory' => 'product_cat', 'ProductTag' => 'product_tag',
    ];
}
