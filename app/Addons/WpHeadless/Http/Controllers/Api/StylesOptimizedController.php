<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Models\WpHeadlessSite;
use App\Addons\WpHeadless\Services\WpHeadlessStylesOptimizerService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StylesOptimizedController extends Controller
{
    public function __construct(
        private WpHeadlessStylesOptimizerService $optimizer
    ) {}

    /**
     * POST /api/wp-headless/styles-optimized
     * Body: { "site_id": 1, "post_type": "page" }
     * Lấy template classes, lọc CSS, ghi file public (public/wp-headless/{site_id}/{post_type}-{chunk}.css), lưu path vào DB.
     * WordPress lấy trực tiếp qua URL trả về trong response.urls. Nếu > 100 KB thì tách nhiều file.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'site_id'   => 'required|integer|exists:sites,id',
            'post_type' => 'required|string|max:64',
        ]);

        $site = Site::find($request->input('site_id'));
        if (!$site) {
            return response()->json(['message' => 'Site not found'], 404);
        }

        $result = $this->optimizer->optimize($site, $request->input('post_type'));

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Optimization failed',
            ], 422);
        }

        $this->pushCssFilesToNextjs($request, $site, $result);

        $urls = $result['urls'] ?? [];
        $message = !empty($urls) ? implode(', ', $urls) : '';

        return response()->json(array_merge($result, ['message' => $message]));
    }

    /**
     * Sau khi optimize qua endpoint styles-optimized (WP step #5),
     * đẩy cssFiles sang Next.js /api/wp-templates/receive để lưu ngay vào public/wp-headless/{siteId}.
     *
     * Không fail toàn request nếu webhook lỗi; chỉ ghi log để dễ debug.
     *
     * @param array<string, mixed> $result
     */
    private function pushCssFilesToNextjs(Request $request, Site $site, array $result): void
    {
        $cssFiles = isset($result['css_chunks']) && is_array($result['css_chunks']) ? $result['css_chunks'] : [];
        if ($cssFiles === []) {
            return;
        }

        $wpSite = WpHeadlessSite::find($site->id);
        if (!$wpSite) {
            return;
        }

        $baseUrl = rtrim($wpSite->getNextjsWebhookUrl(), '/');
        if ($baseUrl === '') {
            return;
        }

        $token = (string) ($request->header('X-GraphQL-Secret') ?: $request->bearerToken() ?: '');
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders($headers)
                ->post($baseUrl . '/api/wp-templates/receive', [
                    'site_id'   => $site->id,
                    'cssFiles'  => $cssFiles,
                ]);

            if (!$response->successful()) {
                Log::warning('StylesOptimizedController: push cssFiles to Next.js failed', [
                    'site_id' => $site->id,
                    'post_type' => (string) ($result['post_type'] ?? ''),
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('StylesOptimizedController: push cssFiles to Next.js error', [
                'site_id' => $site->id,
                'post_type' => (string) ($result['post_type'] ?? ''),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
