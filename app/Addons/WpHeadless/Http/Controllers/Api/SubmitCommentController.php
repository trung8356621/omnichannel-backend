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
 * Next.js gửi form đăng comment → Laravel gọi custom WordPress API (tvh/v1/comment).
 * POST /api/wp-headless/submit-comment
 * Body: site_id, post_id, author_name, author_email, content
 *
 * WordPress cần có custom endpoint POST /wp-json/tvh/v1/comment (teamviahe-graphql),
 * xác thực bằng header X-GraphQL-Secret = READ_TOKEN. Comment mặc định chờ duyệt;
 * nếu author_email trùng user WordPress thì tự động duyệt.
 */
class SubmitCommentController extends Controller
{
    private const TVH_COMMENT_ENDPOINT = '/wp-json/tvh/v1/comment';

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'site_id'      => 'required|integer|exists:sites,id',
            'post_id'      => 'required|integer|min:1',
            'author_name'  => 'required|string|max:255',
            'author_email' => 'required|email',
            'content'      => 'required|string|max:65535',
        ]);

        $site = Site::find($request->input('site_id'));
        if (!$site) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $siteService = $this->getWpHeadlessSiteService($site);
        if (!$siteService) {
            return response()->json(['success' => false, 'message' => 'WP Headless not configured for this site.'], 403);
        }

        $readToken = (string) ($siteService->settings['READ_TOKEN'] ?? '');
        if ($readToken === '') {
            return response()->json(['success' => false, 'message' => 'READ_TOKEN not configured for this site.'], 503);
        }

        $scheme      = ($site->ssl ?? true) ? 'https' : 'http';
        $baseUrl     = $scheme . '://' . $site->domain;
        $commentUrl  = rtrim($baseUrl, '/') . self::TVH_COMMENT_ENDPOINT;

        $payload = [
            'post_id'      => (int) $request->input('post_id'),
            'author_name'  => $request->input('author_name'),
            'author_email' => $request->input('author_email'),
            'content'      => $request->input('content'),
        ];

        $response = Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-GraphQL-Secret' => $readToken])
            ->post($commentUrl, $payload);

        if (!$response->successful()) {
            Log::debug('WpHeadless submit-comment failed', [
                'site_id' => $site->id,
                'post_id' => $request->input('post_id'),
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
            $message = $response->json('message') ?? $response->body() ?: 'WordPress rejected the comment.';
            return response()->json([
                'success' => false,
                'message' => \is_string($message) ? $message : 'Comment submission failed.',
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 422);
        }

        $data = $response->json();
        return response()->json([
            'success' => true,
            'message' => $data['message'] ?? 'Comment submitted.',
            'data'    => $data['comment'] ?? $data,
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
