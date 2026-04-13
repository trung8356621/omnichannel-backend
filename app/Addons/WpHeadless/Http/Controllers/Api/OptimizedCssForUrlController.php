<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Models\WpHeadlessStyle;
use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
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
     * Response: { success, data, post_type, optimizedCssUrls: [global, post_type], fontUrls: [...] }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
            'url'     => 'required|string|max:2048',
        ]);

        $siteId = (int) $request->input('site_id');
        $site = Site::find($siteId);
        if (!$site) {
            return response()->json(['message' => 'Site not found'], 404,[
                'Content-Type'  => 'application/json',
                'Cache-Control' => 'public, max-age=60',
            ], \JSON_UNESCAPED_UNICODE);
        }

        $urlOrPath = $request->input('url');

        $data = $this->resolver->fetchNodeByUri($site, $urlOrPath);
        if ($data === null) {
            $this->resolver->clearCachedNode($site, $urlOrPath);
        }
        $postType = $data !== null
            ? (self::TYPENAME_TO_POST_TYPE[$data['__typename'] ?? ''] ?? strtolower((string) ($data['__typename'] ?? 'post')))
            : $this->resolver->resolveUriToPostType($site, $urlOrPath);

        if ($postType === null) {
            $postType = 'global';
        }

        // URI "/" (trang chủ): nếu WordPress trả về Page (trang tĩnh làm front page) → trả post_type = home để Next.js dùng template "home".
        $pathForHome = parse_url($urlOrPath, PHP_URL_PATH);
        $pathForHome = ($pathForHome === null || $pathForHome === false) ? '' : trim($pathForHome, '/');
        if ($pathForHome === '' && $postType === 'page') {
            $postType = 'home';
        }

        $templatePath = $data !== null ? ($data['templatePath'] ?? null) : null;
        $templatePath = $templatePath !== null && $templatePath !== '' ? (string) $templatePath : null;

        $templateRow = WpHeadlessTemplate::where('site_id', $site->id)->where('type', $postType)->first();
        $storedTemplatePath = $templateRow?->template_path;

        if (!in_array($postType, ['global'], true)) {
            $this->optimizer->ensureGlobalOptimized($site);
        }

        // Next.js: path /wp-headless/... ; css_origin=laravel → URL đầy đủ tới Laravel public.
        $cssOrigin = strtolower((string) ($request->input('css_origin') ?? 'next'));
        $cssOriginNext = $cssOrigin !== 'laravel';

        if ($this->optimizer->siteNeedsCssOptimize($site, $postType)) {
            $this->optimizer->optimize($site, $postType);
        }

        $pathList = $this->optimizer->getOptimizedCssUrlPathsForPostType($site, $postType);
        $optimizedCssUrls = $cssOriginNext
            ? $pathList
            : array_map(
                static fn (string $path): string => rtrim((string) config('app.url', ''), '/') . $path,
                $pathList
            );
        $optimizedCssUrls = array_values(array_filter($optimizedCssUrls));

        // Font URLs (Google Fonts, etc.) từ wp_headless_styles style_type = font — Next.js dùng <link> preload/stylesheet.
        $fontUrls = WpHeadlessStyle::where('site_id', $site->id)
            ->where('style_type', 'font')
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->orderBy('sort_order')
            ->get()
            ->pluck('url')
            ->unique()
            ->values()
            ->all();

        $bodyClass = $this->resolver->getBodyClassForPostType($site, $postType);

        $payload = [
            'success'           => true,
            'data'              => $data,
            'post_type'         => $postType,
            'templatePath'      => $templatePath,
            'template_path'     => $storedTemplatePath,
            'bodyClass'         => $bodyClass,
            'optimizedCssUrls'  => $optimizedCssUrls,
            'fontUrls'          => $fontUrls,
        ];

        return response()->json($payload, 200, [
            'Content-Type'  => 'application/json',
            'Cache-Control' => 'public, max-age=60',
        ], \JSON_UNESCAPED_UNICODE);
    }

    private const TYPENAME_TO_POST_TYPE = [
        'Post' => 'post',
        'Page' => 'page',
        'Category' => 'category',
        'Tag' => 'post_tag',
        'PostTag' => 'post_tag',
        'Product' => 'product',
        'ProductCategory' => 'product_cat',
        'ProductTag' => 'product_tag',
    ];
}
