<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Middleware;

use App\Models\Site;
use App\Models\SiteService;
use App\Models\Service;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bảo mật API wp-headless: chỉ chấp nhận POST và Authorization: Bearer = site_services.settings.READ_TOKEN.
 * site_id lấy từ query (POST body) hoặc query string.
 */
class WpHeadlessReadTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return response()->json([
                'success' => false,
                'message' => 'Method not allowed. Use POST with header: Authorization: Bearer <read_token>.',
            ], 405);
        }

        $siteId = $request->input('site_id');
        if ($siteId === null || $siteId === '') {
            return response()->json(['success' => false, 'message' => 'Missing site_id'], 400);
        }

        $site = Site::find((int) $siteId);
        if ($site === null) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $service = Service::where('slug', 'wp-headless')->first();
        if ($service === null) {
            return response()->json(['success' => false, 'message' => 'Service not configured'], 502);
        }

        $siteService = SiteService::where('site_id', $site->id)
            ->where('service_id', $service->id)
            ->first();
        if ($siteService === null) {
            return response()->json(['success' => false, 'message' => 'Site not configured for WP Headless'], 404);
        }

        $settings = $siteService->settings ?? [];
        $readToken = $settings['READ_TOKEN'] ?? '';
        if ($readToken === '') {
            return response()->json(['success' => false, 'message' => 'READ_TOKEN not configured for site'], 401);
        }

        $auth = $request->header('Authorization');
        if ($auth === null || $auth === '') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        if (! preg_match('#^Bearer\s+(.+)$#i', $auth, $m)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        $token = trim($m[1]);
        if ($token !== $readToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
