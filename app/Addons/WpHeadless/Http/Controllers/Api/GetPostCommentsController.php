<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Next.js gọi để lấy danh sách comment của post.
 * GET /api/wp-headless/post-comments?site_id=2&post_id=123
 *
 * Laravel gọi custom WordPress API GET /wp-json/tvh/v1/comments?post_id=123 (header X-GraphQL-Secret = READ_TOKEN).
 */
class GetPostCommentsController extends Controller
{
    private const TVH_COMMENTS_ENDPOINT = '/wp-json/tvh/v1/comments';

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
            'post_id' => 'required|integer|min:1',
        ]);

        $site = Site::find($request->input('site_id'));
        if (!$site) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $siteService = $this->getWpHeadlessSiteService($site);
        if (!$siteService) {
            return response()->json(['success' => false, 'message' => 'WP Headless not configured', 'comments' => []], 403);
        }

        $readToken = (string) ($siteService->settings['READ_TOKEN'] ?? '');
        if ($readToken === '') {
            return response()->json(['success' => false, 'message' => 'READ_TOKEN not configured', 'comments' => []], 503);
        }

        $scheme     = ($site->ssl ?? true) ? 'https' : 'http';
        $baseUrl    = $scheme . '://' . $site->domain;
        $commentsUrl = rtrim($baseUrl, '/') . self::TVH_COMMENTS_ENDPOINT . '?post_id=' . (int) $request->input('post_id');

        $response = Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-GraphQL-Secret' => $readToken])
            ->get($commentsUrl);

        if (!$response->successful()) {
            Log::debug('WpHeadless get post-comments failed', [
                'site_id' => $site->id,
                'post_id' => $request->input('post_id'),
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
            return response()->json([
                'success'  => false,
                'message'  => 'Failed to load comments',
                'comments' => [],
            ], 422);
        }

        $data = $response->json();
        $comments = \is_array($data['comments'] ?? null) ? $data['comments'] : [];

        return response()->json([
            'success'  => true,
            'comments' => $comments,
        ]);
    }

    private function getWpHeadlessSiteService(Site $site): ?SiteService
    {
        $service = Service::where('slug', 'wp-headless')->first();
        if (!$service) {
            return null;
        }
        return SiteService::where('site_id', $site->id)
            ->where('service_id', $service->id)
            ->first();
    }
}
