<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Observers;

use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Addons\WpHeadless\Services\WpHeadlessStylesOptimizerService;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Khi wp_headless_templates có bản ghi được create/update:
 * - Chỉ gửi payload sang WordPress dispatcher.
 * - WordPress tách thành các tiến trình con và gọi Next.js theo từng request riêng
 *   (updated / receive / receive-css-chunks) để giảm nguy cơ timeout trong luồng save.
 */
class WpHeadlessTemplateObserver
{
    private static function fileKeyForRow(WpHeadlessTemplate $row): string
    {
        $path = $row->template_path !== null && trim((string) $row->template_path) !== ''
            ? trim((string) $row->template_path)
            : '';
        return $path !== '' ? $row->type . '-' . $path : $row->type;
    }

    public function saved(WpHeadlessTemplate $template): void
    {
        $site = $template->wpHeadlessSite;
        if (!$site) {
            Log::debug('WpHeadlessTemplateObserver: skip (no wpHeadlessSite for site_id ' . $template->site_id . ')');
            return;
        }

        $baseUrl = $site->getNextjsWebhookUrl();
        if ($baseUrl === '') {
            Log::debug('WpHeadlessTemplateObserver: skip (getNextjsWebhookUrl empty for site_id ' . $template->site_id . ')');
            return;
        }

        $siteId = $template->site_id;
        $conn = $template->getConnectionName();

        $rows = WpHeadlessTemplate::on($conn)->where('site_id', $siteId)->get();
        $templates = [];
        $templateRelations = [];
        $idToFileKey = [];
        foreach ($rows as $row) {
            $fileKey = self::fileKeyForRow($row);
            $idToFileKey[$row->id] = $fileKey;
            $html = $row->template ?? '';
            $templates[$fileKey] = is_string($html) ? $html : '';
        }
        foreach ($rows as $row) {
            if ($row->parent_id !== null && isset($idToFileKey[$row->parent_id])) {
                $templateRelations[$idToFileKey[$row->id]] = $idToFileKey[$row->parent_id];
            }
        }

        $types = array_keys($templates);

        $settings = $site->settings ?? [];
        $settings = is_array($settings) ? $settings : [];

        // READ_TOKEN lấy từ site_services.settings (bảng site_services), không phải wp_headless_sites.settings.
        $readToken = '';
        $wpHeadlessService = Service::where('slug', 'wp-headless')->first();
        if ($wpHeadlessService) {
            $siteService = SiteService::where('site_id', $siteId)->where('service_id', $wpHeadlessService->id)->first();
            if ($siteService && is_array($siteService->settings)) {
                $readToken = trim((string) ($siteService->settings['READ_TOKEN'] ?? ''));
            }
        }

        // Payload info.json: site_id, domain, wp_uploads_origin, next_url, laravel_api_url, read_token, + settings (seo, locale, favicon...)
        $mainSite = $site->getMainSite();
        $domain = $mainSite ? trim((string) ($mainSite->domain ?? '')) : '';
        $info = [
            'site_id'            => $siteId,
            'domain'             => $domain,
            'wp_uploads_origin'  => $site->getWpUploadsOrigin(),
            'next_url'           => rtrim($baseUrl, '/'),
            'laravel_api_url'    => rtrim(config('app.url', ''), '/'),
            'read_token'         => $readToken,
        ];
        $info = array_merge($info, $settings);

        $mainSite = $site->getMainSite() ?? Site::find($siteId);
        if (!($mainSite instanceof Site)) {
            Log::warning('WpHeadlessTemplateObserver: skip dispatch (main site not found)', [
                'site_id' => $siteId,
            ]);
            return;
        }

        $cssFiles = app(WpHeadlessStylesOptimizerService::class)->buildCssFilesPayloadForNextReceive($mainSite);
        $dispatchTimeout = count($cssFiles) > 0 ? 45 : 8;

        $scheme = !empty($mainSite->ssl) ? 'https' : 'http';
        $host = trim((string) ($mainSite->domain ?? ''));
        if ($host === '') {
            Log::warning('WpHeadlessTemplateObserver: skip dispatch (empty wordpress domain)', [
                'site_id' => $siteId,
            ]);
            return;
        }
        $wpBaseUrl = $scheme . '://' . preg_replace('#^https?://#i', '', $host);
        $dispatchUrl = rtrim($wpBaseUrl, '/') . '/wp-json/tvh/v1/next-sync-dispatch';

        try {
            $headers = ['Content-Type' => 'application/json'];
            if ($readToken !== '') {
                $headers['Authorization'] = 'Bearer ' . $readToken;
                $headers['X-GraphQL-Secret'] = $readToken;
            }
            $dispatchResponse = Http::timeout($dispatchTimeout)
                ->withHeaders($headers)
                ->post($dispatchUrl, [
                    'site_id'            => $siteId,
                    'types'              => $types,
                    'next_url'           => rtrim($baseUrl, '/'),
                    'read_token'         => $readToken,
                    'templates'          => $templates,
                    'template_relations' => $templateRelations,
                    'info'               => $info,
                    'cssFiles'           => $cssFiles,
                ]);

            if (!$dispatchResponse->successful()) {
                Log::warning('WpHeadlessTemplateObserver: wordpress dispatch failed', [
                    'site_id' => $siteId,
                    'url'     => $dispatchUrl,
                    'status'  => $dispatchResponse->status(),
                    'body'    => $dispatchResponse->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessTemplateObserver: wordpress dispatch error', [
                'site_id' => $siteId,
                'url'     => $dispatchUrl,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
