<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Inbound;

use App\Addons\SeoContentAi\Services\SiteSync\Contracts\CapabilityManifestData;
use App\Addons\SeoContentAi\Services\SiteSync\Contracts\SiteSyncBatchData;
use App\Addons\SeoContentAi\Services\SiteSync\Contracts\SiteSyncSchema;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pulls site_sync.v1 payloads from WordPress bridge.
 */
final class WordPressSiteSyncClient
{
    /**
     * @return array{success: bool, message: string, manifest?: CapabilityManifestData}
     */
    public function fetchCapabilities(Site $site): array
    {
        $auth = $this->authContext($site);
        if ($auth['error'] !== null) {
            return ['success' => false, 'message' => $auth['error']];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/omi-seo-ai/v1/capabilities');

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => 'capabilities HTTP '.$response->status(),
                ];
            }

            $json = $response->json();
            $payload = is_array($json['manifest'] ?? null)
                ? $json['manifest']
                : (is_array($json) ? $json : []);

            if (! ($json['success'] ?? true) && isset($json['manifest']) === false && ! isset($payload['schema'])) {
                return ['success' => false, 'message' => 'capabilities payload invalid'];
            }

            if (! isset($payload['schema'])) {
                $payload['schema'] = SiteSyncSchema::VERSION;
            }

            return [
                'success' => true,
                'message' => 'ok',
                'manifest' => CapabilityManifestData::fromArray($payload),
            ];
        } catch (Throwable $e) {
            RuntimeLogger::warning('site_sync.capabilities_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{success: bool, message: string, batch?: SiteSyncBatchData}
     */
    public function fetchDelta(Site $site, array $query = []): array
    {
        $auth = $this->authContext($site);
        if ($auth['error'] !== null) {
            return ['success' => false, 'message' => $auth['error']];
        }

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/omi-seo-ai/v1/sync/v2/delta', $query);

            return $this->decodeBatchResponse($response->successful(), $response->status(), $response->json());
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{success: bool, message: string, batch?: SiteSyncBatchData}
     */
    public function fetchBatches(Site $site, array $body): array
    {
        $auth = $this->authContext($site);
        if ($auth['error'] !== null) {
            return ['success' => false, 'message' => $auth['error']];
        }

        try {
            $response = Http::timeout(90)
                ->acceptJson()
                ->withToken($auth['token'])
                ->post($auth['base'].'/wp-json/omi-seo-ai/v1/sync/v2/batches', $body);

            return $this->decodeBatchResponse($response->successful(), $response->status(), $response->json());
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string, profile?: array<string, mixed>}
     */
    public function fetchProfile(Site $site): array
    {
        $auth = $this->authContext($site);
        if ($auth['error'] !== null) {
            return ['success' => false, 'message' => $auth['error']];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/omi-seo-ai/v1/sync/v2/profile');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'profile HTTP '.$response->status()];
            }

            $json = $response->json();
            $profile = is_array($json['profile'] ?? null) ? $json['profile'] : [];

            return ['success' => true, 'message' => 'ok', 'profile' => $profile];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string, entries?: list<array<string, mixed>>, totals?: array<string, int>, by_type?: array<string, int>, summary?: bool}
     */
    public function fetchLightweightManifest(Site $site, bool $summaryOnly = false): array
    {
        $auth = $this->authContext($site);
        if ($auth['error'] !== null) {
            return ['success' => false, 'message' => $auth['error']];
        }

        try {
            $url = $auth['base'].'/wp-json/omi-seo-ai/v1/sync/v2/manifest';
            if ($summaryOnly) {
                $url .= '?summary=1';
            }
            $response = Http::timeout($summaryOnly ? 20 : 90)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($url);
            if (! $response->successful()) {
                return ['success' => false, 'message' => 'manifest HTTP '.$response->status()];
            }
            $json = $response->json();
            $manifest = is_array($json['manifest'] ?? null) ? $json['manifest'] : [];
            $entries = is_array($manifest['entries'] ?? null)
                ? $manifest['entries']
                : (is_array($json['entries'] ?? null) ? $json['entries'] : []);
            $totals = is_array($manifest['totals'] ?? null) ? $manifest['totals'] : [];
            $byType = is_array($manifest['by_type'] ?? null) ? $manifest['by_type'] : [];

            return [
                'success' => true,
                'message' => 'ok',
                'entries' => $entries,
                'totals' => $totals,
                'by_type' => $byType,
                'summary' => (bool) ($manifest['summary'] ?? $summaryOnly),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{success: bool, message: string, batch?: SiteSyncBatchData}
     */
    private function decodeBatchResponse(bool $ok, int $status, mixed $json): array
    {
        if (! $ok) {
            $detail = '';
            if (is_array($json)) {
                $detail = trim((string) ($json['message'] ?? $json['error'] ?? ''));
            }

            return [
                'success' => false,
                'message' => $detail !== ''
                    ? 'batch HTTP '.$status.': '.$detail
                    : 'batch HTTP '.$status,
            ];
        }

        if (! is_array($json)) {
            return ['success' => false, 'message' => 'batch json invalid'];
        }

        $payload = is_array($json['batch'] ?? null) ? $json['batch'] : $json;
        if (! isset($payload['schema'])) {
            $payload['schema'] = SiteSyncSchema::VERSION;
        }

        try {
            return [
                'success' => true,
                'message' => 'ok',
                'batch' => SiteSyncBatchData::fromArray($payload),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{token: string, base: string, error: ?string}
     */
    private function authContext(Site $site): array
    {
        $site->loadMissing('metas');
        $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($token === '') {
            return ['token' => '', 'base' => '', 'error' => 'Thiếu SEO Read Token.'];
        }

        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return ['token' => '', 'base' => '', 'error' => 'Domain site không hợp lệ.'];
        }

        $base = preg_match('#^https?://#i', $domain) === 1
            ? rtrim($domain, '/')
            : 'https://'.ltrim($domain, '/');

        return ['token' => $token, 'base' => rtrim($base, '/'), 'error' => null];
    }
}
