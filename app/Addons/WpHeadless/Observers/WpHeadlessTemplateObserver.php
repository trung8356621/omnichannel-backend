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
 * 1) Gửi toàn bộ HTML template của site tới Next.js /api/wp-templates/receive để lưu file.
 * 2) CSS: Laravel tối ưu và đẩy thẳng nội dung file (cssFiles) cho Next lưu, không lưu file trên Laravel.
 * 3) Gửi webhook /api/wp-templates/updated để Next xóa cache / revalidate.
 */
class WpHeadlessTemplateObserver
{
    public function __construct(
        private WpHeadlessStylesOptimizerService $optimizer
    ) {}
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

        // 1) Gọi updated trước (xóa cache cũ) rồi mới gửi receive (lưu file mới).
        // Nếu gọi receive trước rồi updated thì updated sẽ xóa luôn thư mục vừa lưu.
        try {
            $response = Http::timeout(5)
                ->post($baseUrl . '/api/wp-templates/updated', [
                    'site_id' => $siteId,
                    'types'   => $types,
                ]);
            if (!$response->successful()) {
                Log::warning('WpHeadlessTemplateObserver: templates-updated failed', [
                    'site_id' => $siteId,
                    'url'     => $baseUrl . '/api/wp-templates/updated',
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessTemplateObserver: templates-updated error', [
                'site_id' => $siteId,
                'url'     => $baseUrl . '/api/wp-templates/updated',
                'message' => $e->getMessage(),
            ]);
        }

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

        // Laravel tối ưu CSS và đẩy thẳng nội dung file cho Next lưu (không lưu file trên Laravel).
        $cssFiles = [];
        $mainSite = $site->getMainSite() ?? Site::find($siteId);
        if ($mainSite instanceof Site) {
            try {
                $cssFiles = $this->optimizer->buildAllCssChunksForNext($mainSite);
            } catch (\Throwable $e) {
                Log::warning('WpHeadlessTemplateObserver: buildAllCssChunksForNext error', [
                    'site_id' => $siteId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($templates !== [] || $info !== [] || $cssFiles !== []) {
            try {
                $receivePayload = [
                    'site_id'            => $siteId,
                    'templates'          => $templates,
                    'template_relations' => $templateRelations,
                    'info'               => $info,
                ];
                if ($cssFiles !== []) {
                    $receivePayload['cssFiles'] = $cssFiles;
                }
                $headers = ['Content-Type' => 'application/json'];
                if ($readToken !== '') {
                    $headers['Authorization'] = 'Bearer ' . $readToken;
                }
                $receiveResponse = Http::timeout(30)
                    ->withHeaders($headers)
                    ->post($baseUrl . '/api/wp-templates/receive', $receivePayload);
                if (!$receiveResponse->successful()) {
                    Log::warning('WpHeadlessTemplateObserver: receive failed', [
                        'site_id' => $siteId,
                        'url'     => $baseUrl . '/api/wp-templates/receive',
                        'status'  => $receiveResponse->status(),
                        'body'    => $receiveResponse->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('WpHeadlessTemplateObserver: receive error', [
                    'site_id' => $siteId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
