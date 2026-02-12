<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Services;

use App\Addons\WpHeadless\Models\WpHeadlessSite;
use App\Addons\WpHeadless\Models\WpHeadlessStyle;
use App\Models\Site;
use App\Models\SiteMeta;
use App\Models\SiteService;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class WpHeadlessSyncService
{
    private const META_PLUGINS_THEMES = 'wp_plugins_themes';
    private const META_POST_TYPE_STYLES = 'wp_post_type_styles';
    private const TEMPLATE_DIR = 'wp-headless/sites';

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
            'Content-Type'     => 'application/json',
            'X-GraphQL-Secret' => $readToken,
        ];

        $result = ['success' => true, 'synced' => []];

        $pluginsThemes = $this->queryPluginsAndThemes($graphqlUrl, $headers);
        if ($pluginsThemes !== null) {
            $this->setMeta($site, self::META_PLUGINS_THEMES, $pluginsThemes);
            $result['synced'][] = self::META_PLUGINS_THEMES;
        } else {
            Log::warning('WpHeadlessSync: headlessPluginsAndThemes failed', ['site_id' => $site->id]);
        }

        $templates = $this->queryTemplates($graphqlUrl, $headers);
        if ($templates !== null) {
            $this->saveTemplateFiles($site, $templates);
            $this->upsertWpHeadlessSite($site, $templates);
            $result['synced'][] = 'templates';
        } else {
            Log::warning('WpHeadlessSync: headlessTemplates failed', ['site_id' => $site->id]);
        }

        $postTypeStyles = $this->queryPostTypeStyles($graphqlUrl, $headers);
        if ($postTypeStyles !== null) {
            $this->setMeta($site, self::META_POST_TYPE_STYLES, $postTypeStyles);
            $this->syncStylesToAddonDb($site->id, $postTypeStyles);
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
        return $scheme . '://' . $site->domain . '/graphql';
    }

    private function queryPluginsAndThemes(string $url, array $headers): ?array
    {
        $query = <<<'GQL'
query {
  headlessPluginsAndThemes
}
GQL;

        $response = Http::timeout(30)->withHeaders($headers)->post($url, ['query' => $query]);
        if (!$response->successful()) {
            return null;
        }
        $data = $response->json('data.headlessPluginsAndThemes');
        if ($data === null) {
            return null;
        }
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function queryTemplates(string $url, array $headers): ?array
    {
        $query = <<<'GQL'
query {
  headlessTemplates
}
GQL;

        $response = Http::timeout(60)->withHeaders($headers)->post($url, ['query' => $query]);
        if (!$response->successful()) {
            return null;
        }
        $data = $response->json('data.headlessTemplates');
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

        $response = Http::timeout(120)->withHeaders($headers)->post($url, ['query' => $query]);
        if (!$response->successful()) {
            return null;
        }
        $data = $response->json('data.headlessPostTypeStyles');
        if ($data === null) {
            return null;
        }
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Lưu header.html, footer.html cho postType global. */
    private function saveTemplateFiles(Site $site, array $templates): void
    {
        $dir = self::TEMPLATE_DIR . '/' . $site->id;
        $header = $templates['header'] ?? '';
        $footer = $templates['footer'] ?? '';
        if ($header !== '') {
            Storage::disk('local')->put($dir . '/header.html', $header);
        }
        if ($footer !== '') {
            Storage::disk('local')->put($dir . '/footer.html', $footer);
        }
    }

    /** Upsert bảng wp_headless_sites (addon DB): id = site_id, type từ theme. */
    private function upsertWpHeadlessSite(Site $site, array $templates): void
    {
        $theme = $templates['theme'] ?? [];
        $type = $theme['type'] ?? 'unknown';
        $type = in_array($type, ['flatsome', 'elementor_based', 'wp_blocks', 'unknown'], true)
            ? $type
            : 'unknown';

        WpHeadlessSite::updateOrCreate(
            ['id' => $site->id],
            ['type' => $type]
        );
    }

    /** Đồng bộ styles từ headlessPostTypeStyles vào bảng wp_headless_styles (addon DB). */
    private function syncStylesToAddonDb(int $siteId, array $postTypeStyles): void
    {
        try {
            DB::connection('wp_headless')->transaction(function () use ($siteId, $postTypeStyles) {
                foreach ($postTypeStyles as $item) {
                    $postType = $item['postType'] ?? 'global';
                    $styles = $item['styles'] ?? [];

                    WpHeadlessStyle::where('site_id', $siteId)->where('post_type', $postType)->delete();

                    $sortOrder = 0;
                    foreach ($styles as $s) {
                        $url = $s['url'] ?? '';
                        $name = $s['name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: '') ?: ('style-' . $sortOrder);
                        $styleType = ($url !== '' && !str_starts_with($url, 'data:')) ? 'file' : 'inline';

                        WpHeadlessStyle::create([
                            'site_id'    => $siteId,
                            'post_type'  => $postType,
                            'style_type' => $styleType,
                            'name'       => $name,
                            'url'        => $styleType === 'file' ? $url : null,
                            'content'    => $styleType === 'inline' ? $url : null,
                            'sort_order' => $sortOrder++,
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessSync: syncStylesToAddonDb failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);
        }
    }

    private function setMeta(Site $site, string $metaKey, array $value): void
    {
        SiteMeta::updateOrCreate(
            ['site_id' => $site->id, 'meta_key' => $metaKey],
            ['meta_value' => json_encode($value, JSON_UNESCAPED_UNICODE)]
        );
    }
}
