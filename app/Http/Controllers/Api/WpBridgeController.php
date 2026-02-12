<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Addons\WpHeadless\Services\WpHeadlessSyncService;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class WpBridgeController extends Controller
{
    /**
     * Xử lý yêu cầu cấp lại Key mới cho WordPress (Refresh Key).
     * Xác thực bằng MIGRATION_TOKEN cũ và trả về MIGRATION_TOKEN mới.
     * Endpoint: POST /api/wp-bridge/refresh-key
     */
    public function refreshKey(Request $request)
    {
        // 1. Validate dữ liệu đầu vào trước để xác định Site nào đang gửi request
        $request->validate([
            'site_url' => 'required|url',
        ]);

        $siteUrl = $request->input('site_url');
        $domain = parse_url($siteUrl, PHP_URL_HOST);

        try {
            return DB::transaction(function () use ($request, $domain) {
                // 2. Tìm Site dựa trên domain
                $site = Site::where('domain', $domain)->first();

                if (!$site) {
                    return response()->json(['message' => 'Site not found in system'], 404);
                }

                // 3. Tìm Service WP Headless
                $service = Service::where('slug', 'wp-headless')->first();
                if (!$service) {
                    return response()->json(['message' => 'WP Headless service not configured'], 500);
                }

                // 4. Tìm liên kết site_services
                $siteService = SiteService::where('site_id', $site->id)
                    ->where('service_id', $service->id)
                    ->first();

                if (!$siteService) {
                    return response()->json(['message' => 'Service not activated for this site'], 403);
                }

                // 5. XÁC THỰC: So sánh Bearer Token với MIGRATION_TOKEN cũ trong settings
                $bridgeToken = $request->bearerToken();
                $settings = $siteService->settings ?? [];
                $oldMigrationToken = $settings['MIGRATION_TOKEN'] ?? null;

                // Nếu không có token cũ hoặc không khớp, từ chối request
                if (!$bridgeToken || $bridgeToken !== $oldMigrationToken) {
                    return response()->json(['message' => 'Unauthorized: Invalid Migration Token'], 401);
                }

                // 6. TẠO TOKEN MỚI
                $newMigrationToken = 'mig_' . Str::random(40);
                $newReadToken = 'mig_' . Str::random(32);


                // 7. CẬP NHẬT JSON SETTINGS
                $settings['MIGRATION_TOKEN'] = $newMigrationToken;
                $settings['READ_TOKEN'] = $newReadToken;
                $settings['last_refresh_at'] = now()->toDateTimeString();

                $siteService->update([
                    'settings' => $settings,
                    'status' => 'active'
                ]);

                // 8. Trả về Token mới cho WordPress
                return response()->json([
                    'success' => true,
                    'tokens' => [
                        'read' => $newReadToken,
                        "write" => $newMigrationToken
                    ],
                    'domain' => $domain,
                    'refreshed_at' => now()->toDateTimeString()
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error refreshing key: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Đồng bộ plugin/theme và CSS theo post_type từ WordPress (WPGraphQL + teamviahe-graphql) vào site_meta.
     * Endpoint: POST /api/wp-bridge/sync-site-data
     */
    public function syncSiteData(Request $request): JsonResponse
    {
        $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
        ]);

        $site = Site::findOrFail($request->input('site_id'));
        $result = app(WpHeadlessSyncService::class)->sync($site);

        if (!$result['success']) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }
}