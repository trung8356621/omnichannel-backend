<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Services;

use App\Models\Site;
use App\Models\SiteMeta;
use App\Models\SiteService;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class WpHeadlessSyncService
{
    private const META_PLUGINS_THEMES = 'wp_plugins_themes';
    private const META_POST_TYPE_STYLES = 'wp_post_type_styles';

    public function sync(Site $site): array
    {
        $siteService = $this->getWpHeadlessSiteService($site);
        if ($siteService === null) {
            return ['success' => false, 'message' => 'Site chưa kích hoạt WP Headless.'];
        }

        $settings = $siteService->settings ?? [];
        $readToken = $settings['READ_TOKEN'] ?? '';
        if ($readToken === '') {
            return ['success' => false, 'message' => 'Thiếu READ_TOKEN trong cấu hình site.'];
        }

        $graphqlUrl = $this->graphqlUrl($site);
        $headers = [
            'Content-Type'       => 'application/json',
            'X-GraphQL-Secret'   => $readToken,
        ];

        $result = ['success' => true, 'synced' => []];

        $pluginsThemes = $this->queryPluginsAndThemes($graphqlUrl, $headers);
        if ($pluginsThemes !== null) {
            $this->setMeta($site, self::META_PLUGINS_THEMES, $pluginsThemes);
            $result['synced'][] = self::META_PLUGINS_THEMES;
        } else {
            Log::warning('WpHeadlessSync: headlessPluginsAndThemes failed', ['site_id' => $site->id]);
        }

        $postTypeStyles = $this->queryPostTypeStyles($graphqlUrl, $headers);
        if ($postTypeStyles !== null) {
            $this->setMeta($site, self::META_POST_TYPE_STYLES, $postTypeStyles);
            $result['synced'][] = self::META_POST_TYPE_STYLES;
        } else {
            Log::warning('WpHeadlessSync: headlessPostTypeStyles failed', ['site_id' => $site->id]);
        }

        return $result;
    }

    private function getWpHeadlessSiteService(Site $site): ?SiteService
    {
        $service = Service::where('slug', 'wp-headless')->first();
        if ($service === null) {
            return null;
        }

        return SiteService::where('site_id', $site->id)
            ->where('service_id', $service->id)
            ->first();
    }

    private function graphqlUrl(Site $site): string
    {
        $scheme = ($site->ssl ?? true) ? 'https' : 'http';
        $domain = $site->domain;

        return $scheme . '://' . $domain . '/graphql';
    }

    private function queryPluginsAndThemes(string $url, array $headers): ?array
    {
        $query = <<<'GQL'
query {
  headlessPluginsAndThemes
}
GQL;

        $response = Http::timeout(30)
            ->withHeaders($headers)
            ->post($url, ['query' => $query]);

        if (!$response->successful()) {
            return null;
        }

        $json = $response->json();
        $data = $json['data']['headlessPluginsAndThemes'] ?? null;
        if ($data === null) {
            return null;
        }

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function queryPostTypeStyles(string $url, array $headers): ?array
    {
        $query = <<<'GQL'
query {
  headlessPostTypeStyles
}
GQL;

        $response = Http::timeout(60)
            ->withHeaders($headers)
            ->post($url, ['query' => $query]);

        if (!$response->successful()) {
            return null;
        }

        $json = $response->json();
        $data = $json['data']['headlessPostTypeStyles'] ?? null;
        if ($data === null) {
            return null;
        }

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function setMeta(Site $site, string $metaKey, array $value): void
    {
        SiteMeta::updateOrCreate(
            [
                'site_id'  => $site->id,
                'meta_key' => $metaKey,
            ],
            [
                'meta_value' => json_encode($value, JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}
