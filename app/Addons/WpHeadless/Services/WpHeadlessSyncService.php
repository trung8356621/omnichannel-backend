<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Services;

use App\Addons\WpHeadless\Models\WpHeadlessSite;
use App\Addons\WpHeadless\Models\WpHeadlessStyle;
use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Models\Site;
use App\Models\SiteMeta;
use App\Models\SiteService;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            $this->syncStylesToAddonDb($site, $postTypeStyles);
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
            dd($response->body());
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

    /**
     * Lưu header / footer / sidebar vào bảng wp_headless_templates (bóc tách classes + styles từ HTML).
     * Lọc trùng theo classes: nếu danh sách class giống bản ghi đã lưu → gán parent_id trỏ tới bản gốc.
     */
    private function saveTemplateFiles(Site $site, array $templates): void
    {
        $siteId = $site->id;
        $parts = [
            'header'  => $templates['header'] ?? '',
            'footer'  => $templates['footer'] ?? '',
            ...($templates['sidebars'] ?? []),
            ...($templates['postTypes'] ?? []),
            ...($templates['taxonomies'] ?? [])
        ];

        /** key (classes json) => id bản ghi gốc (parent_id = null) */
        $canonicalIdByClassesKey = [];

        foreach ($parts as $type => $part) {
            $html = is_array($part) ? ($part['template'] ?? '') : $part;
            $bodyClass = is_array($part) ? ($part['bodyClass'] ?? []) : [];
            $bodyClass = is_array($bodyClass) ? array_values($bodyClass) : [];

            if ($html === '') {
                continue;
            }
            $parsed = $this->parseTemplateHtml($html);
            $classes = $parsed['classes'];

            $classesKey = json_encode($classes);
            $parentId = $canonicalIdByClassesKey[$classesKey] ?? null;

            $row = WpHeadlessTemplate::updateOrCreate(
                ['site_id' => $siteId, 'type' => $type],
                [
                    'parent_id'  => $parentId,
                    'template'   => $html,
                    'classes'    => $classes,
                    'styles'     => $parsed['styles'],
                    'body_class' => $bodyClass,
                ]
            );

            if ($parentId === null) {
                $canonicalIdByClassesKey[$classesKey] = $row->id;
            }
        }
    }

    /** Bóc tách toàn bộ class và inline style từ HTML template. */
    private function parseTemplateHtml(string $html): array
    {
        $classes = [];

        if (preg_match_all('/\bclass\s*=\s*["\']([^"\']+)["\']/i', $html, $classMatches)) {
            foreach ($classMatches[1] as $classAttr) {
                foreach (preg_split('/\s+/', trim($classAttr), -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    $c = trim($c);
                    if ($c !== '') {
                        $classes[$c] = true;
                    }
                }
            }
        }
        $classes = array_keys($classes);
        sort($classes);

        return [
            'classes' => $classes,
            'styles'  => [],
        ];
    }

    /** Upsert bảng wp_headless_sites (addon DB): id = site_id, type. Domain/slug lấy từ bảng sites (DB chính). */
    private function upsertWpHeadlessSite(Site $site, array $templates): void
    {
        $theme = $templates['theme'] ?? [];
        $type = $theme['type'] ?? 'unknown';
        $type = in_array($type, ['flatsome', 'elementor_based', 'wp_blocks', 'unknown'], true)
            ? $type
            : 'unknown';

        $existing = WpHeadlessSite::where('id', $site->id)->first();
        $publicUrl = $existing->public_url ?? (Str::kebab($site->domain) . '-' . Str::random(16));
        $settings = isset($templates['settings']) && is_array($templates['settings'])
            ? $templates['settings']
            : null;

        WpHeadlessSite::updateOrCreate(
            ['id' => $site->id],
            [
                'type' => $type,
                'public_url' => $publicUrl,
                'settings' => $settings,
            ]
        );
    }

    /** Tên miền CDN font phổ biến → dùng style_type = font thay vì css. */
    private const FONT_CDN_HOSTS = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'cdnjs.cloudflare.com',
        'use.typekit.net',
        'fast.fonts.net',
        'fonts.adobe.com',
        'fonts.bunny.net',
        'cdn.jsdelivr.net',
        'unpkg.com',
        'fonts.cdnfonts.com',
        'fontawesome.com',
        'kit.fontawesome.com',
    ];

    /**
     * Đồng bộ styles từ headlessPostTypeStyles vào bảng wp_headless_styles (addon DB).
     * Lọc trùng: cùng url (file) hoặc cùng content (inline) → gán parent_id trỏ tới bản ghi gốc đầu tiên.
     */
    private function syncStylesToAddonDb(Site $site, array $postTypeStyles): void
    {
        $siteId = $site->id;
        $siteHost = $this->normalizeHost($site->domain ?? '');
        try {
            DB::connection('wp_headless')->transaction(function () use ($siteId, $siteHost, $postTypeStyles) {
                WpHeadlessStyle::where('site_id', $siteId)->delete();

                /** key (url hoặc inline:md5) => id bản ghi gốc (parent_id = null) */
                $canonicalIdByKey = [];

                foreach ($postTypeStyles as $item) {
                    $postType = $item['postType'] ?? 'global';
                    $styles = $item['styles'] ?? [];
                    $sortOrder = 0;

                    foreach ($styles as $s) {
                        $url = $s['url'] ?? '';
                        $content = $s['content'] ?? null;
                        $isInline = $content !== null && $content !== '';

                        if ($isInline) {
                            $name = $s['name'] ?? ('inline-' . $sortOrder);
                            $styleType = 'inline';
                            $styleUrl = null;
                            $styleContent = $content;
                            $external = false;
                            $styleKey = 'inline:' . md5((string) $styleContent);
                        } else {
                            $name = $s['name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: '') ?: ('style-' . $sortOrder);
                            if ($url === '' || str_starts_with($url, 'data:')) {
                                $styleType = 'inline';
                                $styleUrl = null;
                                $styleContent = $url;
                                $external = false;
                                $styleKey = 'inline:' . md5((string) $url);
                            } else {
                                $host = parse_url($url, PHP_URL_HOST);
                                $hostNorm = $this->normalizeHost($host ?? '');
                                $external = $hostNorm !== '' && $hostNorm !== $siteHost;
                                if ($this->isFontCdnHost($hostNorm)) {
                                    $styleType = 'font';
                                } else {
                                    $styleType = 'file';
                                }
                                $styleUrl = $url;
                                $styleContent = null;
                                $styleKey = 'url:' . $url;
                            }
                        }

                        $parentId = $canonicalIdByKey[$styleKey] ?? null;
                        if ($parentId === null) {
                            // Bản ghi gốc (lần đầu gặp CSS này)
                            $row = WpHeadlessStyle::create([
                                'site_id'    => $siteId,
                                'parent_id'  => null,
                                'post_type'  => $postType,
                                'style_type' => $styleType,
                                'name'       => $name,
                                'url'        => $styleUrl,
                                'content'    => $styleContent,
                                'sort_order' => $sortOrder++,
                                'external'   => $external,
                            ]);
                            $canonicalIdByKey[$styleKey] = $row->id;
                        } else {
                            // Trùng CSS → trỏ về bản gốc
                            WpHeadlessStyle::create([
                                'site_id'    => $siteId,
                                'parent_id'  => $parentId,
                                'post_type'  => $postType,
                                'style_type' => $styleType,
                                'name'       => $name,
                                'url'        => $styleUrl,
                                'content'    => '',//Empty content
                                'sort_order' => $sortOrder++,
                                'external'   => $external,
                            ]);
                        }
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessSync: syncStylesToAddonDb failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);
        }
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    private function isFontCdnHost(string $normalizedHost): bool
    {
        foreach (self::FONT_CDN_HOSTS as $cdn) {
            if ($normalizedHost === $cdn || str_ends_with($normalizedHost, '.' . $cdn)) {
                return true;
            }
        }
        return false;
    }

    private function setMeta(Site $site, string $metaKey, array $value): void
    {
        SiteMeta::updateOrCreate(
            ['site_id' => $site->id, 'meta_key' => $metaKey],
            ['meta_value' => json_encode($value, JSON_UNESCAPED_UNICODE)]
        );
    }
}
