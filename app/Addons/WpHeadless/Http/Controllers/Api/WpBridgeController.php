<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Services\WpHeadlessSyncService;
use App\Addons\WpHeadless\Models\WpHeadlessSite;
use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            if ($step >= 1 && $step <= 6) {
                $result = in_array($step, [2, 3, 4], true)
                    ? $this->syncTemplateStepWithoutObserverStorm($site, $step, $step === 4)
                    : app(WpHeadlessSyncService::class)->syncStep($site, $step);
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
     * Trạng thái đồng bộ (giữ endpoint để tương thích ngược với client cũ).
     * Hiện tại sync-site-data chạy trực tiếp, không dùng queue cho các step install.
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
        if ($siteId <= 0 || $step < 1 || $step > 6) {
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

        return response()->json([
            'success' => true,
            'status' => 'completed',
            'result' => [
                'success' => true,
                'site_id' => $siteId,
                'step' => $step,
                'message' => 'Đồng bộ chạy trực tiếp, không dùng queue.',
            ],
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

    private static function fileKeyForTemplateRow(WpHeadlessTemplate $row): string
    {
        $path = $row->template_path !== null && trim((string) $row->template_path) !== ''
            ? trim((string) $row->template_path)
            : '';
        return $path !== '' ? $row->type . '-' . $path : $row->type;
    }

    /**
     * Step 2 (templates) có thể ghi rất nhiều row loop item.
     * Tạm tắt model events để tránh observer bắn sync nhiều lần.
     * Sau khi sync DB xong, đẩy thẳng templates sang Next.js (không callback ngược về WordPress)
     * để tránh treo request admin-ajax khi WordPress chờ kết quả cài đặt.
     */
    private function syncTemplateStepWithoutObserverStorm(Site $site, int $step = 2, bool $pushToNextAfterSuccess = false): array
    {
        $dispatcher = WpHeadlessTemplate::getEventDispatcher();
        WpHeadlessTemplate::unsetEventDispatcher();
        try {
            $result = app(WpHeadlessSyncService::class)->syncStep($site, $step);
        } finally {
            WpHeadlessTemplate::setEventDispatcher($dispatcher);
        }

        if ($pushToNextAfterSuccess && !empty($result['success'])) {
            $this->pushTemplatesToNextDirect($site);
        }

        return $result;
    }

    private function pushTemplatesToNextDirect(Site $site): void
    {
        $wpSite = WpHeadlessSite::find($site->id);
        if ($wpSite === null) {
            return;
        }
        $nextBaseUrl = trim((string) $wpSite->getNextjsWebhookUrl());
        if ($nextBaseUrl === '') {
            return;
        }

        $rows = WpHeadlessTemplate::where('site_id', $site->id)->get();
        $templates = [];
        $templateRelations = [];
        $idToFileKey = [];
        foreach ($rows as $row) {
            $fileKey = self::fileKeyForTemplateRow($row);
            $idToFileKey[$row->id] = $fileKey;
            $html = $row->template ?? '';
            $templateStr = is_string($html)
                ? $html
                : (is_array($html) ? (json_encode($html, JSON_UNESCAPED_UNICODE) ?: '') : (string) $html);
            $bodyClass = is_array($row->body_class) ? array_values($row->body_class) : [];
            // Cùng shape với GET /api/wp-headless/templates để Next normalizeTemplatePayload gộp bodyClass → template-bodyclass.json.
            $templates[$fileKey] = [
                'template'      => $templateStr,
                'bodyClass'     => $bodyClass,
                'template_path' => $row->template_path !== null ? trim((string) $row->template_path) : '',
            ];
        }
        foreach ($rows as $row) {
            if ($row->parent_id !== null && isset($idToFileKey[$row->parent_id])) {
                $templateRelations[$idToFileKey[$row->id]] = $idToFileKey[$row->parent_id];
            }
        }

        $siteService = $this->getWpHeadlessSiteService($site);
        $readToken = $siteService && is_array($siteService->settings)
            ? trim((string) ($siteService->settings['READ_TOKEN'] ?? ''))
            : '';

        $settings = is_array($wpSite->settings) ? $wpSite->settings : [];
        $info = array_merge([
            'site_id'           => $site->id,
            'domain'            => trim((string) ($site->domain ?? '')),
            'wp_uploads_origin' => $wpSite->getWpUploadsOrigin(),
            'next_url'          => rtrim($nextBaseUrl, '/'),
            'laravel_api_url'   => rtrim(config('app.url', ''), '/'),
            'read_token'        => $readToken,
        ], $settings);

        $headers = ['Content-Type' => 'application/json'];
        if ($readToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $readToken;
        }
        $types = array_keys($templates);
        try {
            Http::timeout(8)->post(rtrim($nextBaseUrl, '/') . '/api/wp-templates/updated', [
                'site_id' => $site->id,
                'types'   => $types,
            ]);
            Http::timeout(60)
                ->withHeaders($headers)
                ->post(rtrim($nextBaseUrl, '/') . '/api/wp-templates/receive', [
                    'site_id'            => $site->id,
                    'templates'          => $templates,
                    'template_relations' => $templateRelations,
                    'info'               => $info,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WpBridgeController: pushTemplatesToNextDirect error', [
                'site_id' => $site->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
