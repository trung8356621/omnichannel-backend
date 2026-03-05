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

        $baseUrl = $site->getNextjsBaseUrl();
        if ($baseUrl === '') {
            Log::debug('WpHeadlessTemplateObserver: skip (getNextjsBaseUrl empty for site_id ' . $template->site_id . ')');
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

        if ($templates !== [] || $settings !== []) {
            try {
                $receivePayload = [
                    'site_id'            => $siteId,
                    'templates'          => $templates,
                    'template_relations' => $templateRelations,
                ];
                if ($settings !== []) {
                    $receivePayload['settings'] = $settings;
                }
                $receiveResponse = Http::timeout(10)
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
