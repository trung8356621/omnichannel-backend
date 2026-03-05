<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Models\WpHeadlessStyle;
use App\Addons\WpHeadless\Models\WpHeadlessStyleOptimized;
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

        $site = Site::find($request->input('site_id'));
        if (!$site) {
            return response()->json(['message' => 'Site not found'], 404);
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

        // Đảm bảo global đã có file CSS trước khi lấy URLs (header/footer gộp trong post_type).
        if (!in_array($postType, ['global'], true)) {
            $this->optimizer->ensureGlobalOptimized($site);
        }

        $existing = WpHeadlessStyleOptimized::where('site_id', $site->id)
            ->where('post_type', $postType)
            ->orderBy('chunk_index')
            ->get();

        // Next.js dùng file CSS local (Laravel đẩy file qua Next.js public): trả path thay vì full URL.
        // Mặc định trả path; gửi css_origin=laravel nếu cần full URL.
        $cssOrigin = strtolower((string) ($request->input('css_origin') ?? 'next'));
        $cssOriginNext = $cssOrigin !== 'laravel';

        if ($existing->isEmpty()) {
            $result = $this->optimizer->optimize($site, $postType);
            $urls = ($result['success'] ?? false) ? ($result['urls'] ?? []) : [];
            if ($cssOriginNext && !empty($urls)) {
                $postTypeRows = WpHeadlessStyleOptimized::where('site_id', $site->id)
                    ->where('post_type', $postType)
                    ->orderBy('chunk_index')
                    ->get();
                $urls = $postTypeRows->map(fn($row) => $row->path ? '/' . ltrim($row->path, '/') : null)->filter()->values()->all();
            }
        } else {
            $urls = $cssOriginNext
                ? $existing->map(fn($row) => $row->path ? '/' . ltrim($row->path, '/') : null)->filter()->values()->all()
                : $existing->map(fn($row) => $row->public_url)->filter()->values()->all();
        }

        // Global CSS: luôn gửi kèm để Next.js load base (special blocks).
        $globalRows = WpHeadlessStyleOptimized::where('site_id', $site->id)
            ->where('post_type', 'global')
            ->orderBy('chunk_index')
            ->get();
        $globalUrls = $cssOriginNext
            ? $globalRows->map(fn($row) => $row->path ? '/' . ltrim($row->path, '/') : null)->filter()->values()->all()
            : $globalRows->pluck('public_url')->filter()->values()->all();
        $optimizedCssUrls = array_values(array_filter(array_merge($globalUrls, $urls)));

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

        return response()->json([
            'success'           => true,
            'data'              => $data,
            'post_type'         => $postType,
            'templatePath'      => $templatePath,
            'template_path'     => $storedTemplatePath,
            'bodyClass'         => $bodyClass,
            'optimizedCssUrls'  => $optimizedCssUrls,
            'fontUrls'          => $fontUrls,
        ]);
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
