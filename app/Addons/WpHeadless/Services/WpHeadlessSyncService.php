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
    private const META_SITE_SETTINGS = 'wp_site_settings';

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
        $templateItems = $this->queryTemplateItems($graphqlUrl, $headers);
        if ($templates !== null) {
            $this->upsertWpHeadlessSite($site, $templates);
            $this->saveTemplateFiles($site, $templates, $graphqlUrl, $headers, $templateItems);
            $this->syncSidebarWidgets($site, $headers);
            $result['synced'][] = 'templates';
        } else {
            Log::warning('WpHeadlessSync: headlessTemplates failed', ['site_id' => $site->id]);
        }

        $postTypeStyles = $this->queryPostTypeStyles($graphqlUrl, $headers);
        $taxonomyStyles = $this->queryTaxonomyStyles($graphqlUrl, $headers);
        $mergedStyles = $this->mergePostTypeAndTaxonomyStyles($postTypeStyles, $taxonomyStyles);
        if ($mergedStyles !== null) {
            $this->setMeta($site, self::META_POST_TYPE_STYLES, $mergedStyles);
            $this->syncStylesToAddonDb($site, $mergedStyles);
            $result['synced'][] = self::META_POST_TYPE_STYLES;
        } else {
            Log::warning('WpHeadlessSync: headlessPostTypeStyles failed', ['site_id' => $site->id]);
        }

        $siteSettings = $this->querySiteSettings($graphqlUrl, $headers);
        if ($siteSettings !== null) {
            $this->setMeta($site, self::META_SITE_SETTINGS, $siteSettings);
            $this->saveSiteSettingsToWpHeadlessSite($site, $siteSettings);
            $this->pushSeoSettingsToNextjs($site);
            $result['synced'][] = self::META_SITE_SETTINGS;
        } else {
            Log::warning('WpHeadlessSync: headlessSiteSettings failed', ['site_id' => $site->id]);
        }

        return $result;
    }

    /**
     * Chạy một bước đồng bộ (dùng khi WordPress gọi sync-site-data theo từng bước).
     * step 1 = plugin & theme, 2 = templates, 3 = post type styles, 4 = site settings (SEO + locale).
     */
    public function syncStep(Site $site, int $step): array
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

        $result = ['success' => true, 'step' => $step, 'synced' => []];

        if ($step === 1) {
            $pluginsThemes = $this->queryPluginsAndThemes($graphqlUrl, $headers);
            if ($pluginsThemes !== null) {
                $this->setMeta($site, self::META_PLUGINS_THEMES, $pluginsThemes);
                $result['synced'][] = self::META_PLUGINS_THEMES;
            } else {
                Log::warning('WpHeadlessSync: headlessPluginsAndThemes failed', ['site_id' => $site->id]);
            }
        } elseif ($step === 2) {
            $templates = $this->queryTemplates($graphqlUrl, $headers);
            $templateItems = $this->queryTemplateItems($graphqlUrl, $headers);
            if ($templates !== null) {
                $this->upsertWpHeadlessSite($site, $templates);
                $this->saveTemplateFiles($site, $templates, $graphqlUrl, $headers, $templateItems ?? []);
                $this->syncSidebarWidgets($site, $headers);
                $result['synced'][] = 'templates';
            } else {
                Log::warning('WpHeadlessSync: headlessTemplates failed', ['site_id' => $site->id]);
            }
        } elseif ($step === 3) {
            $postTypeStyles = $this->queryPostTypeStyles($graphqlUrl, $headers);
            $taxonomyStyles = $this->queryTaxonomyStyles($graphqlUrl, $headers);
            $mergedStyles = $this->mergePostTypeAndTaxonomyStyles($postTypeStyles, $taxonomyStyles);
            if ($mergedStyles !== null) {
                $this->setMeta($site, self::META_POST_TYPE_STYLES, $mergedStyles);
                $this->syncStylesToAddonDb($site, $mergedStyles);
                $result['synced'][] = self::META_POST_TYPE_STYLES;
            } else {
                Log::warning('WpHeadlessSync: headlessPostTypeStyles/headlessTaxonomyStyles failed', ['site_id' => $site->id]);
            }
        } elseif ($step === 4) {
            $siteSettings = $this->querySiteSettings($graphqlUrl, $headers);
            if ($siteSettings !== null) {
                $this->setMeta($site, self::META_SITE_SETTINGS, $siteSettings);
                $this->saveSiteSettingsToWpHeadlessSite($site, $siteSettings);
                $this->pushSeoSettingsToNextjs($site);
                $result['synced'][] = self::META_SITE_SETTINGS;
            } else {
                Log::warning('WpHeadlessSync: headlessSiteSettings failed', ['site_id' => $site->id]);
            }
        } else {
            return ['success' => false, 'message' => 'Invalid step.', 'step' => $step];
        }

        $result['site_id'] = $site->id;
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

    /** Base URL WordPress (không có path /graphql) dùng cho REST wp-json. */
    private function wpBaseUrl(Site $site): string
    {
        $scheme = ($site->ssl ?? true) ? 'https' : 'http';
        return rtrim($scheme . '://' . $site->domain, '/');
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
            Log::warning('WpHeadlessSync: headlessPluginsAndThemes failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }
        $data = $response->json('data.headlessPluginsAndThemes');
        if ($data === null) {
            return null;
        }
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Lấy templates từ WordPress qua GraphQL.
     * Ưu tiên headlessTemplates (JSON đầy đủ); nếu lỗi/rỗng thì fallback query các field nhỏ (headlessTemplatesTheme, ...) và ghép.
     * Trả về: theme, header, footer, headerTemplateJson, footerTemplateJson, sidebars, postTypes, taxonomies (đồng bộ với WP).
     */
    private function queryTemplates(string $url, array $headers): ?array
    {
        $response = Http::timeout(60)->withHeaders($headers)->post($url, [
            'query' => <<<'GQL'
query {
  headlessTemplates
}
GQL,
        ]);
        if (!$response->successful()) {
            Log::warning('WpHeadlessSync: headlessTemplates request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }
        $raw = $response->json('data.headlessTemplates');
        $decoded = null;
        if ($raw !== null) {
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        }
        if (is_array($decoded) && isset($decoded['postTypes'])) {
            return $this->normalizeTemplatesResponse($decoded);
        }
        return $this->queryTemplatesFromSmallFields($url, $headers);
    }

    /**
     * Fallback: lấy templates từ các field GraphQL nhỏ và ghép.
     * Đồng bộ với WordPress: mỗi field tương ứng TVH_Headless_Templates::get_headless_templates_*() (register-templates.php).
     */
    private function queryTemplatesFromSmallFields(string $url, array $headers): ?array
    {
        $query = <<<'GQL'
query {
  headlessTemplatesTheme
  headlessTemplatesHeaderId
  headlessTemplatesFooterId
  headlessTemplatesHeaderJson
  headlessTemplatesFooterJson
  headlessTemplatesSidebars
  headlessTemplatesPostTypes
  headlessTemplatesTaxonomies
}
GQL;

        $response = Http::timeout(60)->withHeaders($headers)->post($url, ['query' => $query]);
        if (!$response->successful()) {
            return null;
        }
        $data = $response->json('data');
        if (!is_array($data)) {
            return null;
        }
        $themeRaw = $data['headlessTemplatesTheme'] ?? '';
        $theme = is_string($themeRaw) && $themeRaw !== '' ? json_decode($themeRaw, true) : [];
        $decoded = [
            'theme'              => is_array($theme) ? $theme : [],
            'header'             => $data['headlessTemplatesHeaderId'] ?? '',
            'footer'             => $data['headlessTemplatesFooterId'] ?? '',
            'headerTemplateJson' => (string) ($data['headlessTemplatesHeaderJson'] ?? ''),
            'footerTemplateJson' => (string) ($data['headlessTemplatesFooterJson'] ?? ''),
            'sidebars'           => $this->decodeJsonField($data['headlessTemplatesSidebars'] ?? '{}'),
            'postTypes'          => $this->decodeJsonField($data['headlessTemplatesPostTypes'] ?? '{}'),
            'taxonomies'         => $this->decodeJsonField($data['headlessTemplatesTaxonomies'] ?? '{}'),
        ];
        return $this->normalizeTemplatesResponse($decoded);
    }

    /** @param array<string, mixed> $decoded */
    private function normalizeTemplatesResponse(array $decoded): array
    {
        return [
            'theme'              => $decoded['theme'] ?? [],
            'header'              => $decoded['header'] ?? '',
            'footer'              => $decoded['footer'] ?? '',
            'headerTemplateJson'  => $decoded['headerTemplateJson'] ?? '',
            'footerTemplateJson'  => $decoded['footerTemplateJson'] ?? '',
            'sidebars'            => $decoded['sidebars'] ?? [],
            'settings'            => $decoded['settings'] ?? null,
            'postTypes'           => $decoded['postTypes'] ?? [],
            'taxonomies'          => $decoded['taxonomies'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJsonField(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Lấy loop template items từ WordPress GraphQL headlessTemplateItems (product/post card, portfolio, content-none).
     *
     * @return array<int, array{slug: string, type: string, template: string, classes: array}>
     */
    private function queryTemplateItems(string $url, array $headers): array
    {
        $query = <<<'GQL'
query {
  headlessTemplateItems
}
GQL;

        $response = Http::timeout(60)->withHeaders($headers)->post($url, ['query' => $query]);
        if (! $response->successful()) {
            Log::warning('WpHeadlessSync: headlessTemplateItems request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return [];
        }
        $raw = $response->json('data.headlessTemplateItems');
        if ($raw === null) {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return [];
        }
        return $decoded;
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

    private function queryTaxonomyStyles(string $url, array $headers): ?array
    {
        $query = <<<'GQL'
query {
  headlessTaxonomyStyles
}
GQL;

        $response = Http::timeout(120)->withHeaders($headers)->post($url, ['query' => $query]);
        if (!$response->successful()) {
            return null;
        }
        $data = $response->json('data.headlessTaxonomyStyles');
        if ($data === null) {
            return null;
        }
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function querySiteSettings(string $url, array $headers): ?array
    {
        $query = <<<'GQL'
query {
  headlessSiteSettings
}
GQL;

        $response = Http::timeout(30)->withHeaders($headers)->post($url, ['query' => $query]);
        if (!$response->successful()) {
            Log::warning('WpHeadlessSync: headlessSiteSettings request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }
        $data = $response->json('data.headlessSiteSettings');
        if ($data === null) {
            return null;
        }
        $decoded = is_string($data) ? json_decode($data, true) : $data;
        return is_array($decoded) ? $decoded : null;
    }

    /** Gộp [post, page, ...] + [category, ...] và tính global từ post types, đưa global lên đầu. */
    private function mergePostTypeAndTaxonomyStyles(?array $postTypeStyles, ?array $taxonomyStyles): ?array
    {
        if ($postTypeStyles === null) {
            return null;
        }
        $merged = $postTypeStyles;
        if ($taxonomyStyles !== null && $taxonomyStyles !== []) {
            $merged = array_merge($postTypeStyles, $taxonomyStyles);
        }
        return $this->ensureGlobalStylesComputed($merged);
    }

    /**
     * Toàn bộ phần global style: tính từ post types (styles xuất hiện ở mọi post type), loại khỏi từng post type, thêm entry global lên đầu.
     * Nếu đã có entry postType=global (WP cũ) thì giữ nguyên.
     */
    private function ensureGlobalStylesComputed(array $items): array
    {
        if (isset($items[0]) && ($items[0]['postType'] ?? '') === 'global') {
            return $items;
        }
        $postTypeItems = array_values(array_filter($items, fn($i) => ($i['kind'] ?? '') === 'post_type'));
        $taxonomyItems = array_values(array_filter($items, fn($i) => ($i['kind'] ?? '') === 'taxonomy'));
        if ($postTypeItems === []) {
            return $items;
        }
        $allSets = [];
        foreach ($postTypeItems as $item) {
            $pt = $item['postType'] ?? '';
            $styles = $item['styles'] ?? [];
            if ($pt !== '') {
                $allSets[$pt] = $styles;
            }
        }
        $globalStyles = $this->computeGlobalStyles($allSets);
        if ($globalStyles === [] && $allSets !== []) {
            $maxPt = null;
            $maxCount = 0;
            foreach ($allSets as $pt => $styles) {
                $c = count($styles);
                if ($c > $maxCount) {
                    $maxCount = $c;
                    $maxPt = $pt;
                }
            }
            if ($maxPt !== null) {
                $globalStyles = $allSets[$maxPt];
            }
        }
        $globalKeys = [];
        $globalNames = [];
        foreach ($globalStyles as $s) {
            $k = $this->styleKeyForDedup($s);
            if ($k !== '') {
                $globalKeys[$k] = true;
            }
            $name = trim((string) ($s['name'] ?? ''));
            if ($name !== '') {
                $globalNames[$name] = true;
            }
        }
        $filteredPostTypeItems = [];
        foreach ($postTypeItems as $item) {
            $item['styles'] = array_values(array_filter($item['styles'] ?? [], function ($s) use ($globalKeys, $globalNames) {
                $k = $this->styleKeyForDedup($s);
                if ($k !== '' && isset($globalKeys[$k])) {
                    return false;
                }
                $name = trim((string) ($s['name'] ?? ''));
                if ($name !== '' && isset($globalNames[$name])) {
                    return false;
                }
                return true;
            }));
            $filteredPostTypeItems[] = $item;
        }
        // Lọc bỏ style đã nằm trong global khỏi từng taxonomy → tránh trùng khi build CSS taxonomy.
        $filteredTaxonomyItems = [];
        foreach ($taxonomyItems as $item) {
            $item['styles'] = array_values(array_filter($item['styles'] ?? [], function ($s) use ($globalKeys, $globalNames) {
                $k = $this->styleKeyForDedup($s);
                if ($k !== '' && isset($globalKeys[$k])) {
                    return false;
                }
                $name = trim((string) ($s['name'] ?? ''));
                if ($name !== '' && isset($globalNames[$name])) {
                    return false;
                }
                return true;
            }));
            $filteredTaxonomyItems[] = $item;
        }
        $globalEntry = [
            'postType' => 'global',
            'kind'     => 'global',
            'styles'   => $globalStyles,
        ];
        return array_merge([$globalEntry], $filteredPostTypeItems, $filteredTaxonomyItems);
    }

    private function computeGlobalStyles(array $allSets): array
    {
        if ($allSets === []) {
            return [];
        }
        $num = count($allSets);
        $keyInfo = [];
        foreach ($allSets as $postType => $styles) {
            foreach ($styles as $s) {
                $key = $this->styleKeyForDedup($s);
                if ($key === '') {
                    continue;
                }
                if (!isset($keyInfo[$key])) {
                    $keyInfo[$key] = ['style' => $s, 'post_types' => []];
                }
                if (!in_array($postType, $keyInfo[$key]['post_types'], true)) {
                    $keyInfo[$key]['post_types'][] = $postType;
                }
            }
        }
        $global = [];
        foreach ($keyInfo as $info) {
            if (count($info['post_types']) >= $num) {
                $global[] = $info['style'];
            }
        }
        return $global;
    }

    /** Key dedup: URL chuẩn hóa (bỏ query, fragment) hoặc inline:md5. Cùng format với WpHeadlessStylesOptimizerService::rowStyleKey. */
    private function styleKeyForDedup(array $s): string
    {
        $url = $s['url'] ?? '';
        $content = $s['content'] ?? '';
        if ($url !== '') {
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? '';
            $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
            $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) . '://' : '//';
            return $scheme . $host . $path;
        }
        if ($content !== '') {
            return 'inline:' . md5($content);
        }
        return '';
    }

    /**
     * Gọi GraphQL headlessHeaderFooter(header: Int, footer: Int) lấy HTML và template JSON của header/footer block.
     *
     * @return array{headerHtml: string, footerHtml: string, headerTemplateJson: string, footerTemplateJson: string}
     */
    private function queryHeaderFooterHtml(string $url, array $headers, ?int $headerId, ?int $footerId): array
    {
        $headerId = $headerId > 0 ? $headerId : null;
        $footerId = $footerId > 0 ? $footerId : null;
        $vars = [];
        if ($headerId !== null) {
            $vars['header'] = $headerId;
        }
        if ($footerId !== null) {
            $vars['footer'] = $footerId;
        }
        $query = <<<'GQL'
query HeadlessHeaderFooter($header: Int, $footer: Int) {
  headlessHeaderFooter(header: $header, footer: $footer) {
    headerTemplateJson
    footerTemplateJson
  }
}
GQL;
        $response = Http::timeout(30)->withHeaders($headers)->post($url, [
            'query'     => $query,
            'variables' => $vars,
        ]);
        if (!$response->successful()) {
            Log::warning('WpHeadlessSync: headlessHeaderFooter request failed', [
                'status' => $response->status(),
                'header' => $headerId,
                'footer' => $footerId,
            ]);
            return ['headerHtml' => '', 'footerHtml' => '', 'headerTemplateJson' => '', 'footerTemplateJson' => ''];
        }
        $node = $response->json('data.headlessHeaderFooter');
        if (!is_array($node)) {
            return ['headerHtml' => '', 'footerHtml' => '', 'headerTemplateJson' => '', 'footerTemplateJson' => ''];
        }
        return [
            'headerTemplateJson' => (string) ($node['headerTemplateJson'] ?? ''),
            'footerTemplateJson' => (string) ($node['footerTemplateJson'] ?? ''),
        ];
    }

    /**
     * Thu thập mọi header_id / footer_id từ postTypes và taxonomies; dùng headerTemplateJson/footerTemplateJson từ response nếu có, không thì gọi HeadlessHeaderFooter.
     */
    private function ensureHeaderFooterBlocksSaved(int $siteId, array $templates, string $graphqlUrl, array $headers): void
    {
        $headerIds = [];
        $footerIds = [];
        $headerJsonById = [];
        $footerJsonById = [];

        $collect = static function (array $part) use (&$headerIds, &$footerIds, &$headerJsonById, &$footerJsonById): void {
            $h = $part['header'] ?? null;
            $f = $part['footer'] ?? null;
            if (is_numeric($h) && (int) $h > 0) {
                $headerIds[(int) $h] = true;
                $json = trim((string) ($part['headerTemplateJson'] ?? ''));
                if ($json !== '' && ! isset($headerJsonById[(int) $h])) {
                    $headerJsonById[(int) $h] = $json;
                }
            }
            if (is_numeric($f) && (int) $f > 0) {
                $footerIds[(int) $f] = true;
                $json = trim((string) ($part['footerTemplateJson'] ?? ''));
                if ($json !== '' && ! isset($footerJsonById[(int) $f])) {
                    $footerJsonById[(int) $f] = $json;
                }
            }
        };

        if (is_numeric($templates['header'] ?? null) && (int) $templates['header'] > 0) {
            $hid = (int) $templates['header'];
            $headerIds[$hid] = true;
            $rootHeaderJson = trim((string) ($templates['headerTemplateJson'] ?? ''));
            if ($rootHeaderJson !== '') {
                $headerJsonById[$hid] = $rootHeaderJson;
            }
        }
        if (is_numeric($templates['footer'] ?? null) && (int) $templates['footer'] > 0) {
            $fid = (int) $templates['footer'];
            $footerIds[$fid] = true;
            $rootFooterJson = trim((string) ($templates['footerTemplateJson'] ?? ''));
            if ($rootFooterJson !== '') {
                $footerJsonById[$fid] = $rootFooterJson;
            }
        }
        foreach ($templates['postTypes'] ?? [] as $part) {
            if (is_array($part)) {
                $collect($part);
            }
        }
        foreach ($templates['taxonomies'] ?? [] as $part) {
            if (is_array($part)) {
                $collect($part);
            }
        }
        $headerIds = array_keys($headerIds);
        $footerIds = array_keys($footerIds);

        // Luôn đảm bảo có header/footer mặc định (type 'header', 'footer') khi root không có ID dạng số > 0.
        $rootHeader = $templates['header'] ?? null;
        $rootFooter = $templates['footer'] ?? null;
        $needDefaultHeader = ! (is_numeric($rootHeader) && (int) $rootHeader > 0);
        $needDefaultFooter = ! (is_numeric($rootFooter) && (int) $rootFooter > 0);
        if ($needDefaultHeader || $needDefaultFooter) {
            $defaultHeaderJson = $templates['headerTemplateJson'] ?? '';
            $defaultFooterJson = $templates['footerTemplateJson'] ?? '';
            $defaultHeaderJson = is_array($defaultHeaderJson)
                ? json_encode($defaultHeaderJson, \JSON_UNESCAPED_UNICODE)
                : trim((string) $defaultHeaderJson);
            $defaultFooterJson = is_array($defaultFooterJson)
                ? json_encode($defaultFooterJson, \JSON_UNESCAPED_UNICODE)
                : trim((string) $defaultFooterJson);
            if (($needDefaultHeader && $defaultHeaderJson === '') || ($needDefaultFooter && $defaultFooterJson === '')) {
                $out = $this->queryHeaderFooterHtml($graphqlUrl, $headers, null, null);
                if ($needDefaultHeader && $defaultHeaderJson === '') {
                    $defaultHeaderJson = trim((string) ($out['headerTemplateJson'] ?? ''));
                }
                if ($needDefaultFooter && $defaultFooterJson === '') {
                    $defaultFooterJson = trim((string) ($out['footerTemplateJson'] ?? ''));
                }
            }
            if ($needDefaultHeader && $defaultHeaderJson !== '') {
                $decoded = json_decode($defaultHeaderJson, true);
                $classes = is_array($decoded) && isset($decoded['classes']) && is_array($decoded['classes'])
                    ? $decoded['classes']
                    : [];
                sort($classes);
                WpHeadlessTemplate::updateOrCreate(
                    ['site_id' => $siteId, 'type' => 'header'],
                    [
                        'parent_id'     => null,
                        'template_path' => null,
                        'global'        => true,
                        'template'      => WpHeadlessTemplate::normalizeTemplateValue($defaultHeaderJson),
                        'classes'       => $classes,
                        'body_class'    => [],
                    ]
                );
            }
            if ($needDefaultFooter && $defaultFooterJson !== '') {
                $decoded = json_decode($defaultFooterJson, true);
                $classes = is_array($decoded) && isset($decoded['classes']) && is_array($decoded['classes'])
                    ? $decoded['classes']
                    : [];
                sort($classes);
                WpHeadlessTemplate::updateOrCreate(
                    ['site_id' => $siteId, 'type' => 'footer'],
                    [
                        'parent_id'     => null,
                        'template_path' => null,
                        'global'        => true,
                        'template'      => WpHeadlessTemplate::normalizeTemplateValue($defaultFooterJson),
                        'classes'       => $classes,
                        'body_class'    => [],
                    ]
                );
            }
        }

        foreach ($headerIds as $id) {
            $headerJson = $headerJsonById[$id] ?? null;
            if ($headerJson === null || $headerJson === '') {
                $out = $this->queryHeaderFooterHtml($graphqlUrl, $headers, $id, null);
                $headerJson = trim((string) ($out['headerTemplateJson'] ?? ''));
            }
            if ($headerJson !== '') {
                $decoded = json_decode($headerJson, true);
                $classes = is_array($decoded) && isset($decoded['classes']) && is_array($decoded['classes'])
                    ? $decoded['classes']
                    : [];
                sort($classes);
                WpHeadlessTemplate::updateOrCreate(
                    ['site_id' => $siteId, 'type' => 'header_' . $id],
                    [
                        'parent_id'     => null,
                        'template_path' => null,
                        'global'        => true,
                        'template'      => WpHeadlessTemplate::normalizeTemplateValue($headerJson),
                        'classes'       => $classes,
                        'body_class'    => [],
                    ]
                );
            }
        }
        foreach ($footerIds as $id) {
            $footerJson = $footerJsonById[$id] ?? null;
            if ($footerJson === null || $footerJson === '') {
                $out = $this->queryHeaderFooterHtml($graphqlUrl, $headers, null, $id);
                $footerJson = trim((string) ($out['footerTemplateJson'] ?? ''));
            }
            if ($footerJson !== '') {
                $decoded = json_decode($footerJson, true);
                $classes = is_array($decoded) && isset($decoded['classes']) && is_array($decoded['classes'])
                    ? $decoded['classes']
                    : [];
                sort($classes);
                WpHeadlessTemplate::updateOrCreate(
                    ['site_id' => $siteId, 'type' => 'footer_' . $id],
                    [
                        'parent_id'     => null,
                        'template_path' => null,
                        'global'        => true,
                        'template'      => WpHeadlessTemplate::normalizeTemplateValue($footerJson),
                        'classes'       => $classes,
                        'body_class'    => [],
                    ]
                );
            }
        }
    }

    /**
     * Lưu header / footer / sidebar / postTypes / taxonomies + loop template items vào bảng wp_headless_templates.
     * Payload từ WordPress (headlessTemplates): toàn bộ là JSON. headlessTemplateItems: loop card (product/post) với placeholder, JSON.
     * Header/footer block: lấy từ root headerTemplateJson/footerTemplateJson hoặc gọi HeadlessHeaderFooter.
     *
     * @param array<int, array{slug: string, type: string, template: string, classes: array}> $templateItems từ headlessTemplateItems
     */
    private function saveTemplateFiles(Site $site, array $templates, string $graphqlUrl, array $headers, array $templateItems = []): void
    {
        $siteId = $site->id;

        // Xóa hết bản ghi wp_headless_templates cũ cùng site_id trước khi đồng bộ.
        WpHeadlessTemplate::where('site_id', $siteId)->delete();

        // Thu thập header_id, footer_id từ postTypes và taxonomies; gọi HeadlessHeaderFooter cho từng ID chưa có, lưu type header_{id} / footer_{id}.
        $this->ensureHeaderFooterBlocksSaved($siteId, $templates, $graphqlUrl, $headers);

        $sidebars = $templates['sidebars'] ?? [];
        $parts = [
            'header'  => $templates['header'] ?? '',
            'footer'  => $templates['footer'] ?? '',
            ...$sidebars,
            ...($templates['postTypes'] ?? []),
            ...($templates['taxonomies'] ?? [])
        ];

        foreach ($templateItems as $item) {
            $type = $item['type'] ?? '';
            if ($type !== '' && isset($item['template'])) {
                $parts[$type] = [
                    'template'      => $item['template'],
                    'classes'       => $item['classes'] ?? [],
                    'bodyClass'     => [],
                    'template_path' => null,
                ];
            }
        }

        /** Các type là global (không phải post_type / taxonomy): header, footer, sidebars */
        $globalTypes = array_merge(['header', 'footer'], array_keys($sidebars));

        /** key (classes json) => id bản ghi gốc (parent_id = null) */
        $canonicalIdByClassesKey = [];

        foreach ($parts as $type => $part) {
            // Header/footer: khi giá trị chỉ là ID (số) thì không lưu row type=header/footer — nội dung thật nằm ở header_{id}, footer_{id} (đã lưu JSON trong ensureHeaderFooterBlocksSaved).
            if (($type === 'header' || $type === 'footer') && (is_numeric($part) || (is_string($part) && trim($part) !== '' && preg_match('/^\d+$/', trim($part))))) {
                continue;
            }
            // Toàn bộ từ WordPress là JSON: mỗi item chỉ có trường template (chuỗi JSON), không còn full_html.
            if (is_array($part)) {
                $rawTemplate = $part['template'] ?? '';
                $html = is_array($rawTemplate) ? json_encode($rawTemplate) : $this->ensureJsonOrError(trim((string) $rawTemplate));
            } else {
                $html = is_array($part) ? json_encode($part) : $this->ensureJsonOrError(trim((string) $part));
            }
            $html = is_string($html) ? trim($html) : '';
            // Chỉ strip comment khi là HTML; nếu đã là JSON thì giữ nguyên.
            if ($html !== '' && ! str_starts_with($html, '{') && ! str_starts_with($html, '[')) {
                $html = $this->stripHtmlComments($html);
            }
            $bodyClass = is_array($part) ? ($part['bodyClass'] ?? []) : [];
            $bodyClass = is_array($bodyClass) ? array_values($bodyClass) : [];
            $templatePath = is_array($part) ? (trim((string) ($part['template_path'] ?? ''))) : '';
            $templatePath = $templatePath !== '' ? $templatePath : null;

            $isSidebarType = str_starts_with((string) $type, 'sidebar_') && isset($sidebars[$type]);
            if ($html === '' && ! $isSidebarType) {
                continue;
            }
            // Sidebar: WordPress chỉ gửi list ID (template rỗng); nội dung widget lấy qua API tvh/v1/sidebar-widgets?sidebar_id=...
            // Không lưu nếu giống post_content (chỉ nội dung bài) — tránh ghi đè template đúng. Bỏ qua nếu là template JSON (mọi data từ WP giờ là JSON).
            $isPostTypeOrTaxonomy = ! \in_array($type, $globalTypes, true);
            if ($isPostTypeOrTaxonomy && ! $this->looksLikeFullTemplate($html) && ! $this->isTemplateJsonString($html)) {
                continue;
            }
            // WordPress đã gửi classes (từ parse_full_html_to_template_json) → dùng luôn, bỏ tách ở Laravel.
            $classes = null;
            if (is_array($part) && isset($part['classes']) && is_array($part['classes'])) {
                $classes = array_values($part['classes']);
                sort($classes);
            }
            if ($classes === null && $this->isTemplateJsonString($html)) {
                $decoded = json_decode($html, true);
                if (is_array($decoded) && isset($decoded['classes']) && is_array($decoded['classes'])) {
                    $classes = array_values($decoded['classes']);
                    sort($classes);
                }
            }
            if ($classes === null) {
                $forStaticWithScript = in_array($type, ['header', 'footer'], true)
                    || (isset($sidebars[$type]) && $type !== '');
                $parsed = $this->parseTemplateHtml($html, $forStaticWithScript);
                $classes = $parsed['classes'];
            }

            $classesKey = json_encode($classes);
            // type = sidebar_* không tính parent_id (mỗi sidebar độc lập, không dedupe theo classes).
            $parentId = $isSidebarType ? null : ($canonicalIdByClassesKey[$classesKey] ?? null);

            $isGlobal = in_array($type, $globalTypes, true);

            $row = WpHeadlessTemplate::updateOrCreate(
                ['site_id' => $siteId, 'type' => $type],
                [
                    'parent_id'     => $parentId,
                    'template_path' => $templatePath,
                    'global'        => $isGlobal,
                    'template'      => WpHeadlessTemplate::normalizeTemplateValue($html),
                    'classes'       => $classes,
                    'body_class'    => $bodyClass,
                ]
            );

            if ($parentId === null && ! $isSidebarType) {
                $canonicalIdByClassesKey[$classesKey] = $row->id;
            }
        }

        // Merge toàn bộ class trong site → 1 file để fetchNodeByUri so sánh và xóa class dư thừa trong post_content JSON.
        $this->saveMergedClassesForSite($siteId);
    }

    /**
     * Sau khi sync template xong: merge toàn bộ class từ wp_headless_templates của site vào 1 file JSON.
     * File dùng để so sánh với post_content JSON và xóa class không tồn tại trong template.
     */
    public function saveMergedClassesForSite(int $siteId): void
    {
        $rows = WpHeadlessTemplate::where('site_id', $siteId)->get();
        $merged = [];
        foreach ($rows as $row) {
            $classes = $row->classes;
            if (is_array($classes)) {
                foreach ($classes as $c) {
                    $c = trim((string) $c);
                    if ($c !== '') {
                        $merged[$c] = true;
                    }
                }
            }
        }
        $classes = array_keys($merged);
        sort($classes);

        $dir = storage_path('app/wp_headless/sites/' . $siteId);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/merged_classes.json';
        file_put_contents($path, json_encode($classes, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Kéo toàn bộ template dạng sidebar_ từ WordPress REST:
     * GET {wp_url}/wp-json/tvh/v1/sidebar-widgets?sidebar_id=sidebar-main
     * Lưu JSON trả về vào cột template của từng bản ghi sidebar.
     */
    private function syncSidebarWidgets(Site $site, array $headers): void
    {
        $siteId = $site->id;
        $rows   = WpHeadlessTemplate::where('site_id', $siteId)
            ->where('type', 'like', 'sidebar_%')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $baseUrl = $this->wpBaseUrl($site);
        $endpoint = $baseUrl . '/wp-json/tvh/v1/sidebar-widgets';

        foreach ($rows as $row) {
            $sidebarId = Str::startsWith($row->type, 'sidebar_')
                ? substr($row->type, 8)
                : $row->type;
            if ($sidebarId === '') {
                continue;
            }

            $url = $endpoint . '?sidebar_id=' . rawurlencode($sidebarId);

            try {
                $response = Http::timeout(15)
                    ->withHeaders($headers)
                    ->acceptJson()
                    ->get($url);
            } catch (\Throwable $e) {
                Log::warning('WpHeadlessSync: sidebar-widgets request failed', [
                    'site_id'    => $siteId,
                    'sidebar_id' => $sidebarId,
                    'error'      => $e->getMessage(),
                ]);
                continue;
            }

            if (! $response->successful()) {
                Log::debug('WpHeadlessSync: sidebar-widgets non-2xx', [
                    'site_id'    => $siteId,
                    'sidebar_id' => $sidebarId,
                    'status'     => $response->status(),
                ]);
                continue;
            }

            $body = $response->body();
            $json = $response->json();
            if (is_array($json) && ($json['success'] ?? false) === true) {
                $row->template = WpHeadlessTemplate::normalizeTemplateValue($body);
                // Đồng bộ classes từ class_bao_ngoai và html_class tách từ custom_html (để CSS optimize dùng).
                $mergedClasses = $this->mergeSidebarClassesFromPayload($json);
                if ($mergedClasses !== []) {
                    $row->classes = $mergedClasses;
                }
                $row->save();
            }
        }
    }

    /**
     * Gom class_bao_ngoai và html_class từ từng widget (custom_html) trong payload sidebar API.
     *
     * @param array<string, mixed> $payload Decoded response từ tvh/v1/sidebar-widgets
     * @return list<string>
     */
    private function mergeSidebarClassesFromPayload(array $payload): array
    {
        $classes = [];
        foreach ((array) ($payload['class_bao_ngoai'] ?? []) as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $classes[$c] = true;
            }
        }
        foreach ((array) ($payload['danh_sach_widget'] ?? []) as $widget) {
            if (! is_array($widget)) {
                continue;
            }
            foreach ((array) ($widget['html_class'] ?? []) as $c) {
                $c = trim((string) $c);
                if ($c !== '') {
                    $classes[$c] = true;
                }
            }
        }
        $list = array_keys($classes);
        sort($list);

        return array_values($list);
    }

    /** Xóa mọi HTML comment <!-- ... --> trước khi lưu template. */
    private function stripHtmlComments(string $html): string
    {
        return preg_replace('/<!--.*?-->/s', '', $html);
    }

    /**
     * Kiểm tra HTML có phải full template (layout đầy đủ) hay chỉ giống post_content.
     * Tránh lưu nhầm post_content vào wp_headless_templates.
     */
    private function looksLikeFullTemplate(string $html): bool
    {
        $html = trim($html);
        if ($html === '') {
            return false;
        }
        $markers = [
            '{{get_header}}',
            '{{get_footer}}',
            '{{content}}',
            'content-area',
            'section',
            'class="content',
            'id="content"',
            'id=\'content\'',
            'entry-content',
            'col-inner',
            'main',
            'role="main"',
            'class="post',
            'single-post',
            'page-content',
        ];
        foreach ($markers as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Nếu chuỗi là JSON hợp lệ thì trả về nguyên; nếu không (vd. HTML) thì trả về chuỗi JSON lỗi để lưu DB. */
    private function ensureJsonOrError(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        json_decode($s);
        if (json_last_error() === \JSON_ERROR_NONE) {
            return $s;
        }
        return '{"error":"not JSON"}';
    }

    /** Chuỗi có phải template JSON (children array) từ WordPress — vẫn lưu vào wp_headless_templates. */
    private function isTemplateJsonString(string $value): bool
    {
        $v = trim($value);
        return $v !== '' && str_contains($v, '"children"') && str_contains($v, '[');
    }

    /**
     * Bóc tách toàn bộ class từ HTML template.
     * Khi $includeDescendantClasses = true (template tĩnh có script: header, footer, sidebar): lấy thêm mọi class
     * từ phần tử con của phần tử có class chính. VD phần tử .sticky-jump chứa .stuck → thêm .stuck.
     */
    private function parseTemplateHtml(string $html, bool $includeDescendantClasses = false): array
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

        if ($includeDescendantClasses) {
            $this->collectDescendantClasses($html, $classes);
        }

        $classes = array_keys($classes);
        sort($classes);

        return ['classes' => $classes];
    }

    /**
     * Với mỗi phần tử có class nằm trong $classes (class chính), lấy thêm mọi class từ phần tử con.
     * VD .sticky-jump .stuck → thêm "stuck" vào $classes.
     */
    private function collectDescendantClasses(string $html, array &$classes): void
    {
        $mainClasses = array_keys($classes);
        if ($mainClasses === []) {
            return;
        }

        $wrap = '<div id="__wp_headless_parse_root">' . $html . '</div>';
        $useErrors = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $wrap, $flags);
            libxml_clear_errors();
            $xpath = new \DOMXPath($dom);
            foreach ($mainClasses as $mainClass) {
                $safeClass = str_replace("'", "''", $mainClass);
                $nodes = @$xpath->query(
                    "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $safeClass . " ')]"
                );
                if ($nodes === false || $nodes->length === 0) {
                    continue;
                }
                foreach ($nodes as $node) {
                    $this->collectClassesFromNodeAndDescendants($node, $classes);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('WpHeadlessSync: collectDescendantClasses failed', ['message' => $e->getMessage()]);
        } finally {
            libxml_use_internal_errors($useErrors);
        }
    }

    private function collectClassesFromNodeAndDescendants(\DOMNode $node, array &$classes): void
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }
        if ($node instanceof \DOMElement && $node->hasAttribute('class')) {
            $classAttr = $node->getAttribute('class');
            foreach (preg_split('/\s+/', trim($classAttr), -1, PREG_SPLIT_NO_EMPTY) as $c) {
                $c = trim($c);
                if ($c !== '') {
                    $classes[$c] = true;
                }
            }
        }
        if ($node->childNodes !== null) {
            foreach ($node->childNodes as $child) {
                $this->collectClassesFromNodeAndDescendants($child, $classes);
            }
        }
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
     * Style đã có bản gốc global thì không lưu bản con (parent_id != null) vì global đã kiểm tra toàn trang.
     * Nếu cùng một style (cùng styleKey) xuất hiện ở nhiều post_type (post + page, category, ...) thì đưa bản gốc
     * vào global để tối ưu CSS một lần thay vì tạo nhiều bản con.
     */
    private function syncStylesToAddonDb(Site $site, array $postTypeStyles): void
    {
        $siteId = $site->id;
        $siteHost = $this->normalizeHost($site->domain ?? '');
        try {
            DB::connection('wp_headless')->transaction(function () use ($siteId, $siteHost, $postTypeStyles) {
                WpHeadlessStyle::where('site_id', $siteId)->delete();

                /** key => ['id' => id bản gốc, 'post_type' => post_type bản gốc] */
                $canonicalByKey = [];

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
                                $styleKey = $this->styleKeyForDedup($s);
                                if ($styleKey === '') {
                                    $styleKey = 'url:' . $url;
                                }
                            }
                        }

                        $canonical = $canonicalByKey[$styleKey] ?? null;
                        if ($canonical === null) {
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
                            $canonicalByKey[$styleKey] = ['id' => $row->id, 'post_type' => $postType];
                        } else {
                            $parentPostType = $canonical['post_type'] ?? '';
                            if ($parentPostType === 'global') {
                                // Style global đã kiểm tra toàn trang, không lưu bản con (post_tag, taxonomy, ...).
                                continue;
                            }
                            // Cùng style xuất hiện ở post_type khác (post + page, category, ...) → đưa bản gốc vào global
                            // để tối ưu CSS một lần, không tạo bản con.
                            WpHeadlessStyle::where('id', $canonical['id'])->update(['post_type' => 'global']);
                            $canonicalByKey[$styleKey]['post_type'] = 'global';
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

    /** Ghi $siteSettings vào wp_headless_sites.settings (merge với settings hiện có). */
    private function saveSiteSettingsToWpHeadlessSite(Site $site, array $siteSettings): void
    {
        $wpSite = WpHeadlessSite::find($site->id);
        if ($wpSite === null) {
            return;
        }
        $current = is_array($wpSite->settings) ? $wpSite->settings : [];
        $wpSite->settings = array_merge($current, $siteSettings);
        $wpSite->save();
    }

    /** Đẩy wp_headless_sites.settings sang Next.js /api/wp-templates/receive để ghi seo.json. */
    private function pushSeoSettingsToNextjs(Site $site): void
    {
        $wpSite = WpHeadlessSite::find($site->id);
        if ($wpSite === null) {
            return;
        }
        $settings = $wpSite->settings;
        if (! is_array($settings) || $settings === []) {
            return;
        }
        $baseUrl = $wpSite->getNextjsBaseUrl();
        if ($baseUrl === '') {
            return;
        }
        try {
            $response = Http::timeout(10)->post(rtrim($baseUrl, '/') . '/api/wp-templates/receive', [
                'site_id'  => $site->id,
                'settings' => $settings,
            ]);
            if (! $response->successful()) {
                Log::warning('WpHeadlessSync: pushSeoSettingsToNextjs failed', [
                    'site_id' => $site->id,
                    'status'  => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessSync: pushSeoSettingsToNextjs error', [
                'site_id' => $site->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
