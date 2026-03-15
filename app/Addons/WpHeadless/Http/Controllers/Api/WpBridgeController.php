<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Jobs\SyncSiteDataStepJob;
use App\Addons\WpHeadless\Services\WpHeadlessSyncService;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WpBridgeController extends Controller
{
    /**
     * Xử lý yêu cầu cấp lại Key mới cho WordPress (Refresh Key).
     * Xác thực bằng MIGRATION_TOKEN cũ và trả về MIGRATION_TOKEN mới.
     * Endpoint: POST /api/wp-bridge/refresh-key
     */
    public function refreshKey(Request $request): JsonResponse
    {
        $request->validate([
            'site_url' => 'required|url',
        ]);

        $siteUrl = $request->input('site_url');
        $domain = parse_url($siteUrl, PHP_URL_HOST);

        try {
            return DB::transaction(function () use ($request, $domain) {
                $site = Site::where('domain', $domain)->first();
                if (!$site) {
                    return response()->json(['message' => 'Site not found in system'], 404);
                }

                $service = Service::where('slug', 'wp-headless')->first();
                if (!$service) {
                    return response()->json(['message' => 'WP Headless service not configured'], 500);
                }

                $siteService = SiteService::where('site_id', $site->id)
                    ->where('service_id', $service->id)
                    ->first();
                if (!$siteService) {
                    return response()->json(['message' => 'Service not activated for this site'], 403);
                }

                $bridgeToken = $request->bearerToken();
                $settings = $siteService->settings ?? [];
                $oldMigrationToken = $settings['MIGRATION_TOKEN'] ?? null;
                if (!$bridgeToken || $bridgeToken !== $oldMigrationToken) {
                    return response()->json(['message' => 'Unauthorized: Invalid Migration Token'], 401);
                }

                $newMigrationToken = 'mig_' . Str::random(40);
                $newReadToken = 'mig_' . Str::random(32);
                $settings['MIGRATION_TOKEN'] = $newMigrationToken;
                $settings['READ_TOKEN'] = $newReadToken;
                $settings['last_refresh_at'] = now()->toDateTimeString();

                $siteService->update([
                    'settings' => $settings,
                    'status' => 'active',
                ]);

                return response()->json([
                    'success' => true,
                    'tokens' => [
                        'read' => $newReadToken,
                        'write' => $newMigrationToken,
                    ],
                    'domain' => $domain,
                    'refreshed_at' => now()->toDateTimeString(),
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error refreshing key: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Đồng bộ plugin/theme và CSS theo post_type từ WordPress (WPGraphQL + teamviahe-graphql) vào site_meta.
     * Endpoint: POST /api/wp-bridge/sync-site-data
     * Body: site_id (int) HOẶC site_url (string). Xác thực: header X-GraphQL-Secret hoặc Authorization: Bearer = READ_TOKEN của site.
     */
    public function syncSiteData(Request $request): JsonResponse
    {
        $siteId = $request->input('site_id');
        $siteUrl = $request->input('site_url');

        if ($siteId !== null) {
            $request->validate(['site_id' => 'required|integer|exists:sites,id']);
            $site = Site::findOrFail((int) $siteId);
        } elseif ($siteUrl !== null && $siteUrl !== '') {
            $request->validate(['site_url' => 'required|string']);
            $domain = parse_url($siteUrl, PHP_URL_HOST);
            if (!$domain) {
                return response()->json(['success' => false, 'message' => 'Invalid site_url.'], 422);
            }
            $site = Site::where('domain', $domain)->first();
            if (!$site) {
                return response()->json(['success' => false, 'message' => 'Site not found for domain.'], 404);
            }
        } else {
            return response()->json(['success' => false, 'message' => 'Provide site_id or site_url.'], 422);
        }

        $token = $request->header('X-GraphQL-Secret') ?: $request->bearerToken();
        $siteService = $this->getWpHeadlessSiteService($site);
        if (!$siteService) {
            return response()->json(['success' => false, 'message' => 'WP Headless not activated for this site.'], 403);
        }
        $readToken = $siteService->settings['READ_TOKEN'] ?? '';
        if ($token === '' || $token !== $readToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized: invalid or missing token.'], 401);
        }

        $step = $request->input('step');
        if ($step !== null && $step !== '') {
            $step = (int) $step;
            if ($step >= 1 && $step <= 4) {
                $useQueue = config('queue.default') !== 'sync';
                if ($useQueue) {
                    $cacheKey = SyncSiteDataStepJob::CACHE_PREFIX . $site->id . '_' . $step;
                    Cache::put($cacheKey, ['status' => 'pending', 'result' => null], SyncSiteDataStepJob::CACHE_TTL_SECONDS);
                    SyncSiteDataStepJob::dispatch($site->id, $step);
                    return response()->json([
                        'success' => true,
                        'queued' => true,
                        'site_id' => $site->id,
                        'step' => $step,
                        'message' => 'Đang đồng bộ ở nền. Vui lòng gọi GET /api/wp-bridge/sync-site-data/status để kiểm tra kết quả.',
                    ]);
                }
                $result = app(WpHeadlessSyncService::class)->syncStep($site, $step);
                if (!$result['success']) {
                    return response()->json($result, 422);
                }
                return response()->json($result);
            }
        }

        $result = app(WpHeadlessSyncService::class)->sync($site);
        if (!$result['success']) {
            return response()->json($result, 422);
        }
        $result['site_id'] = $site->id;
        return response()->json($result);
    }

    /**
     * Trạng thái bước đồng bộ đã đưa vào queue (WordPress poll khi nhận queued: true).
     * GET /api/wp-bridge/sync-site-data/status?site_id=1&step=1
     * Header: X-GraphQL-Secret = READ_TOKEN
     */
    public function syncSiteDataStatus(Request $request): JsonResponse
    {
        $siteId = $request->input('site_id');
        $step = $request->input('step');
        if ($siteId === null || $step === null) {
            return response()->json(['success' => false, 'message' => 'Thiếu site_id hoặc step.'], 422);
        }
        $siteId = (int) $siteId;
        $step = (int) $step;
        if ($siteId <= 0 || $step < 1 || $step > 4) {
            return response()->json(['success' => false, 'message' => 'site_id hoặc step không hợp lệ.'], 422);
        }

        $site = Site::find($siteId);
        if (!$site) {
            return response()->json(['success' => false, 'message' => 'Site not found.'], 404);
        }

        $token = $request->header('X-GraphQL-Secret') ?: $request->bearerToken();
        $siteService = $this->getWpHeadlessSiteService($site);
        if (!$siteService) {
            return response()->json(['success' => false, 'message' => 'WP Headless not activated.'], 403);
        }
        $readToken = $siteService->settings['READ_TOKEN'] ?? '';
        if ($token === '' || $token !== $readToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $cacheKey = SyncSiteDataStepJob::CACHE_PREFIX . $siteId . '_' . $step;
        $data = Cache::get($cacheKey);
        if ($data === null) {
            return response()->json([
                'success' => true,
                'status' => 'pending',
                'message' => 'Job đang chờ hoặc đang chạy. Đảm bảo php artisan queue:work đang chạy.',
            ]);
        }

        $status = $data['status'] ?? 'pending';
        $result = $data['result'] ?? null;
        return response()->json([
            'success' => true,
            'status' => $status,
            'result' => $result,
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
