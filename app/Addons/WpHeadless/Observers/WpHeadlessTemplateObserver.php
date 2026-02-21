<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Observers;

use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Khi wp_headless_templates có bản ghi được create/update:
 * 1) Gửi toàn bộ HTML template của site tới Next.js /api/wp-templates/receive để lưu file.
 * 2) Gửi webhook /api/wp-templates/updated để Next xóa cache / revalidate.
 */
class WpHeadlessTemplateObserver
{
    public function saved(WpHeadlessTemplate $template): void
    {
        $site = $template->wpHeadlessSite;
        if (!$site) {
            Log::debug('WpHeadlessTemplateObserver: skip (no wpHeadlessSite for site_id ' . $template->site_id . ')');
            return;
        }

        $baseUrl = $site->getNextjsBaseUrl();
        if ($baseUrl === '') {
            Log::debug('WpHeadlessTemplateObserver: skip (getNextjsBaseUrl empty for site_id ' . $template->site_id . ')');
            return;
        }

        $siteId = $template->site_id;
        $conn = $template->getConnectionName();

        $rows = WpHeadlessTemplate::on($conn)->where('site_id', $siteId)->get();
        $templates = [];
        foreach ($rows as $row) {
            $html = $row->template ?? '';
            $fileKey = ($row->template_path !== null && trim((string) $row->template_path) !== '')
                ? $row->type . '-' . trim((string) $row->template_path)
                : $row->type;
            // Giữ nguyên HTML (kể cả thuộc tính style inline) khi đẩy sang Next.js.
            $templates[$fileKey] = is_string($html) ? $html : '';
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

        if ($templates !== []) {
            try {
                $receiveResponse = Http::timeout(10)
                    ->post($baseUrl . '/api/wp-templates/receive', [
                        'site_id'   => $siteId,
                        'templates' => $templates,
                    ]);
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
