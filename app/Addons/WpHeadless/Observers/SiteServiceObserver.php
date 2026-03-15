<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Observers;

use App\Addons\WpHeadless\Models\WpHeadlessSite;
use App\Models\Site;
use App\Models\SiteService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * - Khi Site Service wp-headless được active: tạo/cập nhật row wp_headless_sites.
 * - Khi site_services.status đổi: đồng bộ sang WordPress "Đã kết nối" (active) / "Chưa kết nối" (inactive/maintenance).
 */
class SiteServiceObserver
{
    public const SITE_TYPE_WORDPRESS = 'wordpress';

    private const WP_HEADLESS_SLUG = 'wp-headless';

    public function saved(SiteService $siteService): void
    {
        $siteService->loadMissing('service');
        $service = $siteService->service;
        if (!$service || $service->slug !== self::WP_HEADLESS_SLUG) {
            return;
        }

        $siteId = (int) $siteService->site_id;
        if ($siteId <= 0) {
            return;
        }

        $status = (string) ($siteService->status ?? 'inactive');

        if ($status === 'active') {
            $this->syncWpHeadlessSitesRow($siteService, $siteId);
        }

        $this->syncConnectionStatusToWordPress($siteService, $siteId, $status);
    }

    private function syncWpHeadlessSitesRow(SiteService $siteService, int $siteId): void
    {
        try {
            if (!Schema::connection('wp_headless')->hasTable('wp_headless_sites')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $settings = $siteService->settings ?? [];
        $type = is_array($settings) && isset($settings['site_type']) && $settings['site_type'] !== ''
            ? (string) $settings['site_type']
            : 'unknown';

        WpHeadlessSite::on('wp_headless')->updateOrCreate(
            ['id' => $siteId],
            ['type' => $type]
        );
    }

    /**
     * Gọi WordPress REST POST /wp-json/tvh/v1/connection-status để đồng bộ "Đã kết nối" / "Chưa kết nối".
     */
    private function syncConnectionStatusToWordPress(SiteService $siteService, int $siteId, string $status): void
    {
        $site = Site::find($siteId);
        if (!$site) {
            return;
        }

        $settings = $siteService->settings ?? [];
        $readToken = isset($settings['READ_TOKEN']) && trim((string) $settings['READ_TOKEN']) !== ''
            ? trim((string) $settings['READ_TOKEN'])
            : null;
        if ($readToken === null) {
            return;
        }

        $scheme = !empty($site->ssl) ? 'https' : 'http';
        $host = trim((string) $site->domain);
        if ($host === '') {
            return;
        }
        $baseUrl = $scheme . '://' . preg_replace('#^https?://#i', '', $host);
        $url = rtrim($baseUrl, '/') . '/wp-json/tvh/v1/connection-status';

        try {
            Http::timeout(10)
                ->withHeaders(['X-GraphQL-Secret' => $readToken])
                ->post($url, ['status' => $status]);
        } catch (\Throwable $e) {
            // Log nếu cần; không làm fail save SiteService
        }
    }
}
