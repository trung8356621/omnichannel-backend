<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Services;

use App\Addons\WpHeadless\Models\WpHeadlessStyle;
use App\Addons\WpHeadless\Models\WpHeadlessStyleOptimized;
use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Models\Site;
use App\Models\SiteMeta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class WpHeadlessStylesOptimizerService
{
    /* global.css: reset / :root / thẻ hệ thống. main.css: gộp post types + taxonomy (dedup block md5). home.css + layouts/header-*.css: bundle riêng. */
    private const MAX_CHUNK_BYTES = 100 * 1024; // 100 KB

    /** CSS reset mặc định: luôn đặt đầu output (*, ::before, ::after, html). */
    private const DEFAULT_CSS_RESET = '';

    /** Thư mục public chứa file CSS tối ưu (relative từ public_path). */
    public const PUBLIC_CSS_DIR = 'wp-headless';

    /** site_meta key: JSON { global: timestamp, ... } — dùng cho ensureGlobalOptimized (throttle). */
    private const META_KEY_STYLES_OPTIMIZED_AT = 'wp_headless_styles_optimized_at';

    /** site_meta key: site settings từ WP (headlessSiteSettings), có has_darkmode. */
    private const META_KEY_SITE_SETTINGS = 'wp_site_settings';

    /** site_meta key: manifest JSON (global, main, home, layouts) cho Next.js. */
    private const META_KEY_CSS_MANIFEST = 'wp_headless_css_manifest';

    /** Thư mục con (trong public/wp-headless/{siteId}/) chứa CSS header/footer. */
    private const LAYOUTS_DIR = 'layouts';

    /** post_type tổng hợp trong DB cho bundle main.css. */
    private const BUNDLE_POST_TYPE_MAIN = 'main';

    /** post_type tổng hợp trong DB cho bundle home.css. */
    private const BUNDLE_POST_TYPE_HOME = 'home';

    public function optimize(Site $site, string $postType, array &$existingSignatures = []): array
    {
        $siteId = $site->id;

        $isGlobal = $postType === 'global';
        if (!$isGlobal) {
            $this->ensureGlobalOptimized($site);
        }

        if ($isGlobal) {
            return $this->optimizeGlobal($site, $siteId, $existingSignatures);
        }

        if ($postType === 'loop_content' || str_starts_with($postType, 'loop_content')) {
            return [
                'success'   => false,
                'message'   => 'loop_content types are not optimized; loop uses taxonomy template + page global CSS.',
                'chunks'    => 0,
                'urls'      => [],
                'css_chunks'=> [],
            ];
        }

        if ($this->isLayoutPostType($postType)) {
            return $this->optimizeBundledLayout($site, $postType);
        }

        if ($postType === 'home' || $postType === self::BUNDLE_POST_TYPE_HOME) {
            return $this->optimizeBundledHome($site);
        }

        if ($this->belongsToMainCssBundle($postType, $siteId)) {
            return $this->optimizeBundledMain($site, $postType);
        }

        return [
            'success'    => false,
            'message'    => 'Unknown or unsupported post_type for CSS bundle: ' . $postType,
            'chunks'     => 0,
            'urls'       => [],
            'css_chunks' => [],
        ];
    }

    /**
     * Đường dẫn public (bắt đầu /) để Next.js nạp CSS theo post_type / layout.
     *
     * @return list<string>
     */
    public function getOptimizedCssUrlPathsForPostType(Site $site, string $postType): array
    {
        $manifest = $this->getCssManifest($site);
        $global = $this->normalizeManifestPathList($manifest['global'] ?? []);
        if ($global === []) {
            $global = $this->publicUrlsFromOptimizedRows($site, 'global');
        }

        if ($postType === 'global') {
            return $global;
        }

        if ($postType === 'home' || $postType === self::BUNDLE_POST_TYPE_HOME) {
            $home = $this->normalizeManifestPathList($manifest['home'] ?? []);
            if ($home === []) {
                $home = $this->publicUrlsFromOptimizedRows($site, self::BUNDLE_POST_TYPE_HOME);
            }

            return array_values(array_unique(array_merge($global, $home)));
        }

        if ($this->isLayoutPostType($postType)) {
            $layouts = is_array($manifest['layouts'] ?? null) ? $manifest['layouts'] : (array) ($manifest['layouts'] ?? []);
            $layoutPaths = isset($layouts[$postType]) ? $this->normalizeManifestPathList(
                is_array($layouts[$postType]) ? $layouts[$postType] : [$layouts[$postType]]
            ) : [];
            if ($layoutPaths === []) {
                $layoutPaths = $this->publicUrlsFromOptimizedRows($site, $postType);
            }

            return array_values(array_unique(array_merge($global, $layoutPaths)));
        }

        if ($this->belongsToMainCssBundle($postType, $site->id)) {
            $main = $this->normalizeManifestPathList($manifest['main'] ?? []);
            if ($main === []) {
                $main = $this->publicUrlsFromOptimizedRows($site, self::BUNDLE_POST_TYPE_MAIN);
            }

            return array_values(array_unique(array_merge($global, $main)));
        }

        return $global;
    }

    /**
     * @return list<string>
     */
    private function publicUrlsFromOptimizedRows(Site $site, string $postTypeKey): array
    {
        $paths = [];
        foreach (
            WpHeadlessStyleOptimized::where('site_id', $site->id)
                ->where('post_type', $postTypeKey)
                ->orderBy('chunk_index')
                ->get() as $row
        ) {
            $p = $this->manifestPathFromOptimizedRow($row);
            if ($p !== '') {
                $paths[] = $p;
            }
        }

        return $this->normalizeManifestPathList($paths);
    }

    /**
     * @return array<string, mixed>
     */
    public function siteNeedsCssOptimize(Site $site, string $postType): bool
    {
        $postType = trim($postType);
        if ($postType === 'global') {
            return !WpHeadlessStyleOptimized::where('site_id', $site->id)->where('post_type', 'global')->exists();
        }
        if ($postType === 'home' || $postType === self::BUNDLE_POST_TYPE_HOME) {
            return !WpHeadlessStyleOptimized::where('site_id', $site->id)->where('post_type', self::BUNDLE_POST_TYPE_HOME)->exists();
        }
        if ($this->isLayoutPostType($postType)) {
            return !WpHeadlessStyleOptimized::where('site_id', $site->id)->where('post_type', $postType)->exists();
        }
        if ($this->belongsToMainCssBundle($postType, $site->id)) {
            return !WpHeadlessStyleOptimized::where('site_id', $site->id)->where('post_type', self::BUNDLE_POST_TYPE_MAIN)->exists();
        }

        return !WpHeadlessStyleOptimized::where('site_id', $site->id)->where('post_type', $postType)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCssManifest(Site $site): array
    {
        $row = SiteMeta::where('site_id', $site->id)->where('meta_key', self::META_KEY_CSS_MANIFEST)->first();
        $value = $row->meta_value ?? null;
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string|mixed> $paths
     * @return list<string>
     */
    private function normalizeManifestPathList(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $out[] = str_starts_with($p, '/') ? $p : '/' . ltrim($p, '/');
        }

        return array_values(array_unique($out));
    }

    /** Ưu tiên public_url (accessor), nếu trống thì build từ path. Trả '' nếu không dùng được. */
    private function manifestPathFromOptimizedRow(WpHeadlessStyleOptimized $row): string
    {
        $pub = $row->public_url;
        if ($pub === null || $pub === '') {
            $pub = '/' . ltrim((string) ($row->path ?? ''), '/');
        }
        $pub = trim((string) $pub);

        return ($pub === '' || $pub === '/') ? '' : $pub;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function saveCssManifest(Site $site, array $manifest): void
    {
        $manifest['version'] = (int) ($manifest['version'] ?? 1);
        $manifest['updated_at'] = time();
        SiteMeta::updateOrCreate(
            ['site_id' => $site->id, 'meta_key' => self::META_KEY_CSS_MANIFEST],
            ['meta_value' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    /** Đồng bộ site_meta wp_headless_css_manifest từ toàn bộ bản ghi wp_headless_styles_optimized của site. */
    private function syncCssManifestFromOptimizedTable(Site $site): void
    {
        $siteId = $site->id;
        $rows = WpHeadlessStyleOptimized::where('site_id', $siteId)
            ->orderBy('post_type')
            ->orderBy('chunk_index')
            ->get();

        $manifest = [
            'version'   => 1,
            'global'    => [],
            'main'      => [],
            'home'      => [],
            'layouts'   => [],
        ];

        foreach ($rows as $row) {
            $pub = $this->manifestPathFromOptimizedRow($row);
            if ($pub === '') {
                continue;
            }
            $pt = trim((string) ($row->post_type ?? ''));
            if ($pt === 'global') {
                $manifest['global'][] = $pub;
            } elseif ($pt === self::BUNDLE_POST_TYPE_MAIN) {
                $manifest['main'][] = $pub;
            } elseif ($pt === self::BUNDLE_POST_TYPE_HOME) {
                $manifest['home'][] = $pub;
            } elseif ($this->isLayoutPostType($pt)) {
                if (!isset($manifest['layouts'][$pt]) || !is_array($manifest['layouts'][$pt])) {
                    $manifest['layouts'][$pt] = [];
                }
                $manifest['layouts'][$pt][] = $pub;
            }
        }

        if (!is_array($manifest['layouts']) || $manifest['layouts'] === []) {
            $manifest['layouts'] = new \stdClass();
        } else {
            $manifest['layouts'] = (object) $manifest['layouts'];
        }

        $this->saveCssManifest($site, $manifest);
    }

    private function isLayoutPostType(string $postType): bool
    {
        return $postType === 'header' || $postType === 'footer'
            || str_starts_with($postType, 'header_')
            || str_starts_with($postType, 'footer_');
    }

    private function belongsToMainCssBundle(string $postType, int $siteId): bool
    {
        if ($postType === self::BUNDLE_POST_TYPE_MAIN) {
            return true;
        }
        if ($postType === 'global' || $postType === 'home' || $postType === self::BUNDLE_POST_TYPE_HOME) {
            return false;
        }
        if ($this->isLayoutPostType($postType)) {
            return false;
        }
        if ($postType === 'loop_content' || str_starts_with($postType, 'loop_content')) {
            return false;
        }

        return $this->applyExcludeLoopContentTemplates(
            WpHeadlessTemplate::where('site_id', $siteId)->where('type', $postType)
        )->exists()
            || in_array($postType, ['post', 'page', 'product', 'slider'], true)
            || $this->isTaxonomyStylePostType($postType);
    }

    /**
     * Tên file CSS trong layouts/ (không có thư mục), ví dụ header-42.css
     */
    private function layoutCssFileNameForType(string $layoutType, ?int $templateRowId = null): string
    {
        if (preg_match('/^header_(\d+)$/', $layoutType, $m)) {
            return 'header-' . $m[1] . '.css';
        }
        if (preg_match('/^footer_(\d+)$/', $layoutType, $m)) {
            return 'footer-' . $m[1] . '.css';
        }
        if ($layoutType === 'header') {
            return 'header.css';
        }
        if ($layoutType === 'footer') {
            return 'footer.css';
        }

        $safe = preg_replace('/[^a-z0-9\-_]+/i', '-', $layoutType) ?? 'layout';

        return strtolower(trim($safe, '-')) . '.css';
    }

    /**
     * Đường dẫn tương đối từ public/: wp-headless/{siteId}/layouts/file.css
     */
    private function relativeLayoutCssPath(int $siteId, string $layoutType, ?int $templateRowId = null): string
    {
        return self::PUBLIC_CSS_DIR . '/' . $siteId . '/' . self::LAYOUTS_DIR . '/' . $this->layoutCssFileNameForType($layoutType, $templateRowId);
    }

    /**
     * @return list<string>
     */
    private function collectDistinctLayoutTypes(int $siteId): array
    {
        $types = $this->applyExcludeLoopContentTemplates(
            WpHeadlessTemplate::where('site_id', $siteId)
                ->whereNotNull('type')
                ->where(function ($q) {
                    $q->where('type', 'header')->orWhere('type', 'like', 'header_%')
                        ->orWhere('type', 'footer')->orWhere('type', 'like', 'footer_%');
                })
        )
            ->distinct()
            ->pluck('type')
            ->all();
        $set = [];
        foreach ($types as $t) {
            $t = trim((string) $t);
            if ($t !== '') {
                $set[$t] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * @return list<string>
     */
    private function collectMainBundlePostTypes(int $siteId): array
    {
        $rows = $this->applyExcludeLoopContentTemplates(
            WpHeadlessTemplate::where('site_id', $siteId)
                ->whereNotNull('type')
                ->where('type', 'not like', 'sidebar_%')
        )->get();

        $set = [];
        foreach ($rows as $t) {
            $type = trim((string) ($t->type ?? ''));
            if ($type === '' || str_starts_with($type, 'loop_content')) {
                continue;
            }
            if ($type === 'global') {
                continue;
            }
            if ($this->isLayoutPostType($type)) {
                continue;
            }
            if ($type === 'home') {
                continue;
            }
            if ($type === 'page' && $this->rowTemplatePathIsHome($t)) {
                continue;
            }
            $set[$type] = true;
        }
        $set['slider'] = true;

        return array_keys($set);
    }

    private function rowTemplatePathIsHome(WpHeadlessTemplate $t): bool
    {
        $path = $t->template_path ?? null;
        if ($path === null) {
            return false;
        }

        return strtolower(trim((string) $path)) === 'home';
    }

    /**
     * @return list<string>
     */
    private function computeOptimizedNonGlobalBlocks(Site $site, string $postType, array &$existingSignatures): array
    {
        $siteId = $site->id;
        if ($postType === 'loop_content' || str_starts_with($postType, 'loop_content')) {
            return [];
        }

        $templatesData = $this->collectTemplateClassesAndIds($siteId, $postType);
        $classes = $templatesData['classes'] ?? [];
        $ids = $templatesData['ids'] ?? [];

        $rawCss = $this->fetchStylesCss($siteId, $postType);
        if ($rawCss === '') {
            return [];
        }

        // Giữ nguyên rule .dark trong mọi trường hợp (không strip ở bước optimize).
        $stripDarkMode = false;
        $sigForFilter = $postType === 'slider' ? [] : $existingSignatures;
        $filtered = WpHeadlessStylesFilterHelper::filterCssByClassesAndIds(
            $rawCss,
            $classes,
            $ids,
            $stripDarkMode,
            $sigForFilter,
            false
        );
        if ($postType === 'slider') {
            foreach (array_keys($sigForFilter) as $k) {
                $existingSignatures[$k] = true;
            }
        }
        $classBlocks = $filtered['blocks'] ?? [];
        if ($postType === 'slider') {
            $classBlocks = WpHeadlessStylesFilterHelper::stripSliderTypographyAndDocumentNoiseFromBlocks(is_array($classBlocks) ? $classBlocks : []);
        }
        $allBlocks = array_merge(
            $postType === 'slider' ? [] : ($filtered['specialBlocks'] ?? []),
            $postType === 'slider' ? [] : [self::DEFAULT_CSS_RESET],
            is_array($classBlocks) ? $classBlocks : []
        );
        $allBlocks = array_map(fn(string $b) => WpHeadlessStylesFilterHelper::removeExcludedIdRulesFromCss($b), $allBlocks);
        $allBlocks = array_map(fn(string $b) => WpHeadlessStylesFilterHelper::minifyCss($b), $allBlocks);

        return array_values(array_filter($allBlocks, static fn(string $b) => trim($b) !== ''));
    }

    /**
     * Ghi một file CSS bundle + bản ghi DB + copy Next; đồng bộ manifest từ bảng optimized.
     *
     * @param array<string, mixed> $manifestMut dành cho tương thích gọi (không dùng)
     * @param list<array{filename: string, content: string}> $cssChunksForNext
     */
    private function persistBundleCssFile(
        Site $site,
        string $dbPostType,
        string $relativePathUnderPublic,
        string $cssContent,
        array &$manifestMut,
        array &$cssChunksForNext
    ): void {
        $siteId = $site->id;
        $fullPath = public_path($relativePathUnderPublic);
        $writtenPath = $this->writeCssFileSafe($fullPath, $cssContent);
        $relativeWritten = $this->toPublicRelativePath($writtenPath);
        $this->copyToNextjsPublic($writtenPath, $siteId, $relativeWritten);

        WpHeadlessStyleOptimized::where('site_id', $siteId)->where('post_type', $dbPostType)->delete();
        WpHeadlessStyleOptimized::create([
            'site_id'     => $siteId,
            'post_type'   => $dbPostType,
            'chunk_index' => 0,
            'path'        => $relativeWritten,
            'size'        => strlen($cssContent),
        ]);

        $nextFilename = ltrim(preg_replace('#^' . preg_quote(self::PUBLIC_CSS_DIR . '/' . $siteId . '/', '#') . '#', '', str_replace('\\', '/', $relativeWritten)) ?? '', '/');
        $cssChunksForNext[] = ['filename' => $nextFilename, 'content' => $cssContent];
    }

    private function toPublicRelativePath(string $absolutePath): string
    {
        $public = rtrim(str_replace('\\', '/', public_path()), '/');
        $abs = str_replace('\\', '/', $absolutePath);
        if (str_starts_with($abs, $public . '/')) {
            return ltrim(substr($abs, strlen($public) + 1), '/');
        }

        return ltrim(basename($abs), '/');
    }

    /**
     * @param array<string, mixed> $manifestMut
     * @param list<array{filename: string, content: string}> $cssChunksForNext
     */
    private function optimizeBundledLayout(Site $site, string $postType): array
    {
        $siteId = $site->id;
        $row = $this->applyExcludeLoopContentTemplates(
            WpHeadlessTemplate::where('site_id', $siteId)->where('type', $postType)
        )->first();
        $templateRowId = $row !== null ? (int) $row->id : null;

        $sig = [];
        $this->seedSignaturesFromOptimizedBundles($site, ['global', self::BUNDLE_POST_TYPE_MAIN, self::BUNDLE_POST_TYPE_HOME], $sig);
        $blocks = $this->computeOptimizedNonGlobalBlocks($site, $postType, $sig);
        $css = implode("\n", $blocks);
        $size = strlen($css);
        if ($size === 0) {
            return [
                'success'    => false,
                'message'    => 'No CSS content for layout post_type.',
                'chunks'     => 0,
                'urls'       => [],
                'css_chunks' => [],
            ];
        }

        $relativePath = $this->relativeLayoutCssPath($siteId, $postType, $templateRowId);

        try {
            $cssChunksForNext = [];
            $manifestSlice = [];
            DB::connection('wp_headless')->transaction(function () use ($site, $postType, $relativePath, $css, &$cssChunksForNext, &$manifestSlice) {
                $this->deleteOldOptimizedRowsAndFiles($site->id, [$postType]);
                $manifestSlice = [];
                $this->persistBundleCssFile($site, $postType, $relativePath, $css, $manifestSlice, $cssChunksForNext);
            });
            $this->syncCssManifestFromOptimizedTable($site);
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessStylesOptimizer: layout bundle failed', ['site_id' => $siteId, 'post_type' => $postType, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage(), 'chunks' => 0, 'urls' => [], 'css_chunks' => []];
        }

        $urls = $this->normalizeManifestPathList(
            WpHeadlessStyleOptimized::where('site_id', $siteId)
                ->where('post_type', $postType)
                ->orderBy('chunk_index')
                ->get()
                ->map(fn (WpHeadlessStyleOptimized $row) => $this->manifestPathFromOptimizedRow($row))
                ->filter(static fn (string $p) => $p !== '')
                ->values()
                ->all()
        );

        return [
            'success'    => true,
            'post_type'  => $postType,
            'size'       => $size,
            'chunks'     => 1,
            'urls'       => $urls,
            'css_chunks' => $cssChunksForNext,
        ];
    }

    /**
     * @param list<string> $postTypesFilter chỉ xóa các post_type này
     */
    private function deleteOldOptimizedRowsAndFiles(int $siteId, array $postTypesFilter): void
    {
        $oldRows = WpHeadlessStyleOptimized::where('site_id', $siteId)->whereIn('post_type', $postTypesFilter)->get();
        foreach ($oldRows as $old) {
            $oldPath = trim((string) ($old->path ?? ''));
            if ($oldPath === '') {
                continue;
            }
            $oldFullPath = public_path($oldPath);
            if (File::exists($oldFullPath)) {
                @File::delete($oldFullPath);
            }
            $this->deleteFromNextjsPublic($siteId, $oldPath);
        }
        WpHeadlessStyleOptimized::where('site_id', $siteId)->whereIn('post_type', $postTypesFilter)->delete();
    }

    /**
     * @param list<array{filename: string, content: string}> $cssChunksForNext
     */
    private function optimizeBundledHome(Site $site): array
    {
        $siteId = $site->id;
        $sig = [];
        $this->seedSignaturesFromOptimizedGlobalCss($site, $sig);
        $seenBlockMd5 = array_fill_keys(array_keys($sig), true);
        $outBlocks = [];

        foreach ($this->computeOptimizedNonGlobalBlocks($site, 'home', $sig) as $b) {
            $h = md5($b);
            if (isset($seenBlockMd5[$h])) {
                continue;
            }
            $seenBlockMd5[$h] = true;
            $outBlocks[] = $b;
        }
        foreach ($this->computeOptimizedNonGlobalBlocks($site, 'slider', $sig) as $b) {
            $h = md5($b);
            if (isset($seenBlockMd5[$h])) {
                continue;
            }
            $seenBlockMd5[$h] = true;
            $outBlocks[] = $b;
        }

        $css = implode("\n", $outBlocks);
        $size = strlen($css);
        if ($size === 0) {
            return [
                'success'    => false,
                'message'    => 'No CSS content for home bundle.',
                'chunks'     => 0,
                'urls'       => [],
                'css_chunks' => [],
            ];
        }

        $relativePath = self::PUBLIC_CSS_DIR . '/' . $siteId . '/home.css';

        try {
            $cssChunksForNext = [];
            DB::connection('wp_headless')->transaction(function () use ($site, $relativePath, $css, &$cssChunksForNext) {
                $this->deleteOldOptimizedRowsAndFiles($site->id, [self::BUNDLE_POST_TYPE_HOME, 'slider']);
                $manifestSlice = [];
                $this->persistBundleCssFile($site, self::BUNDLE_POST_TYPE_HOME, $relativePath, $css, $manifestSlice, $cssChunksForNext);
            });
            $this->syncCssManifestFromOptimizedTable($site);
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessStylesOptimizer: home bundle failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage(), 'chunks' => 0, 'urls' => [], 'css_chunks' => []];
        }

        $urls = $this->normalizeManifestPathList(
            WpHeadlessStyleOptimized::where('site_id', $siteId)
                ->where('post_type', self::BUNDLE_POST_TYPE_HOME)
                ->orderBy('chunk_index')
                ->get()
                ->map(fn (WpHeadlessStyleOptimized $row) => $this->manifestPathFromOptimizedRow($row))
                ->filter(static fn (string $p) => $p !== '')
                ->values()
                ->all()
        );

        return [
            'success'    => true,
            'post_type'  => 'home',
            'size'       => $size,
            'chunks'     => 1,
            'urls'       => $urls,
            'css_chunks' => $cssChunksForNext,
        ];
    }

    /**
     * @param list<array{filename: string, content: string}> $cssChunksForNext
     */
    private function optimizeBundledMain(Site $site, string $triggerPostType): array
    {
        $siteId = $site->id;
        $mainTypes = $this->collectMainBundlePostTypes($siteId);
        usort($mainTypes, static function (string $a, string $b): int {
            if ($a === 'slider') {
                return 1;
            }
            if ($b === 'slider') {
                return -1;
            }

            return strcmp($a, $b);
        });

        $filterSignatures = [];
        $this->seedSignaturesFromOptimizedGlobalCss($site, $filterSignatures);
        $seenBlockMd5 = array_fill_keys(array_keys($filterSignatures), true);
        $outBlocks = [];

        foreach ($mainTypes as $pt) {
            $blocks = $this->computeOptimizedNonGlobalBlocks($site, $pt, $filterSignatures);
            foreach ($blocks as $b) {
                $h = md5($b);
                if (isset($seenBlockMd5[$h])) {
                    continue;
                }
                $seenBlockMd5[$h] = true;
                $outBlocks[] = $b;
            }
        }

        if ($outBlocks === []) {
            return [
                'success'    => false,
                'message'    => 'No CSS content for main bundle.',
                'chunks'     => 0,
                'urls'       => [],
                'css_chunks' => [],
            ];
        }

        $css = implode("\n", $outBlocks);
        unset($outBlocks);
        $size = strlen($css);

        $relativePath = self::PUBLIC_CSS_DIR . '/' . $siteId . '/main.css';

        try {
            $cssChunksForNext = [];
            DB::connection('wp_headless')->transaction(function () use ($site, $relativePath, $css, &$cssChunksForNext) {
                $this->deleteOldOptimizedRowsAndFiles($site->id, [self::BUNDLE_POST_TYPE_MAIN, 'slider']);
                $manifestSlice = [];
                $this->persistBundleCssFile($site, self::BUNDLE_POST_TYPE_MAIN, $relativePath, $css, $manifestSlice, $cssChunksForNext);
            });
            $this->syncCssManifestFromOptimizedTable($site);
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessStylesOptimizer: main bundle failed', ['site_id' => $siteId, 'trigger' => $triggerPostType, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage(), 'chunks' => 0, 'urls' => [], 'css_chunks' => []];
        }

        $urls = $this->normalizeManifestPathList(
            WpHeadlessStyleOptimized::where('site_id', $siteId)
                ->where('post_type', self::BUNDLE_POST_TYPE_MAIN)
                ->orderBy('chunk_index')
                ->get()
                ->map(fn (WpHeadlessStyleOptimized $row) => $this->manifestPathFromOptimizedRow($row))
                ->filter(static fn (string $p) => $p !== '')
                ->values()
                ->all()
        );

        return [
            'success'    => true,
            'post_type'  => $triggerPostType,
            'bundle'     => self::BUNDLE_POST_TYPE_MAIN,
            'size'       => $size,
            'chunks'     => 1,
            'urls'       => $urls,
            'css_chunks' => $cssChunksForNext,
        ];
    }

    /** @param array<int, string> $classes */
    public function optimizeByClasses(Site $site, string $postType, array $classes, string $uri): array
    {
        $siteId = $site->id;
        $postType = trim($postType) !== '' ? trim($postType) : 'page';
        $allowedClasses = $this->normalizeClassList($classes);
        if ($allowedClasses === []) {
            return ['success' => false, 'message' => 'No classes provided.'];
        }

        $rawCss = $this->fetchStylesCss($siteId, $postType);
        if ($rawCss === '') {
            return ['success' => false, 'message' => 'No CSS content for post_type.'];
        }

        // Giữ nguyên rule .dark trong mọi trường hợp (không strip ở bước optimize).
        $stripDarkMode = false;
        $sigScratch = [];
        $filtered = WpHeadlessStylesFilterHelper::filterCssByClassesAndIds(
            $rawCss,
            $allowedClasses,
            [],
            $stripDarkMode,
            $sigScratch,
            false
        );
        $specialBlocks = is_array($filtered['specialBlocks'] ?? null) ? $filtered['specialBlocks'] : [];
        $classBlocks = is_array($filtered['blocks'] ?? null) ? $filtered['blocks'] : [];
        $specialBlocksNotInGlobal = $this->excludeGlobalSpecialBlocks($siteId, $specialBlocks, $stripDarkMode);
        $allBlocks = array_merge(
            $specialBlocksNotInGlobal,
            [self::DEFAULT_CSS_RESET],
            $classBlocks
        );
        $allBlocks = array_map(fn(string $b) => WpHeadlessStylesFilterHelper::removeExcludedIdRulesFromCss($b), $allBlocks);
        $allBlocks = array_map(fn(string $b) => WpHeadlessStylesFilterHelper::minifyCss($b), $allBlocks);
        $allBlocks = array_values(array_filter($allBlocks, static fn(string $b) => trim($b) !== ''));
        $optimizedCss = implode("\n", $allBlocks);
        if (trim($optimizedCss) === '') {
            return ['success' => false, 'message' => 'Empty optimized CSS result.'];
        }

        $baseName = $this->sanitizeUriToCssBaseName($uri);
        $filename = $baseName . '.css';
        $relativeDir = self::PUBLIC_CSS_DIR . '/' . $siteId;
        $fullPath = public_path($relativeDir . '/' . $filename);

        try {
            File::ensureDirectoryExists(dirname($fullPath));
            File::put($fullPath, $optimizedCss);
            $this->copyToNextjsPublic($fullPath, $siteId, $filename);
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessStylesOptimizer: optimizeByClasses save failed', [
                'site_id' => $siteId,
                'post_type' => $postType,
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => true,
            'post_type' => $postType,
            'uri' => $uri,
            'filename' => $filename,
            'path' => '/' . ltrim($relativeDir . '/' . $filename, '/'),
            'css' => $optimizedCss,
            'size' => strlen($optimizedCss),
        ];
    }

    /**
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    private function normalizeClassList(array $classes): array
    {
        $out = [];
        foreach ($classes as $item) {
            foreach (preg_split('/\s+/', trim((string) $item), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
                $c = trim((string) $c);
                if ($c === '') {
                    continue;
                }
                $out[$c] = true;
            }
        }
        return array_values(array_keys($out));
    }

    private function sanitizeUriToCssBaseName(string $uri): string
    {
        $raw = trim($uri);
        if ($raw === '' || $raw === '/') {
            return 'home';
        }
        $path = parse_url($raw, PHP_URL_PATH);
        $path = is_string($path) ? $path : $raw;
        $path = trim($path, '/');
        if ($path === '') {
            return 'home';
        }
        $safe = preg_replace('/[^a-z0-9\-_]+/i', '-', $path) ?? 'home';
        $safe = trim($safe, '-');
        return $safe !== '' ? strtolower($safe) : 'home';
    }

    public function ensureGlobalOptimized(Site $site): void
    {
        $at = $this->getStylesOptimizedAt($site);
        if (($at['global'] ?? 0) > 0) {
            return;
        }
        $this->optimize($site, 'global');
    }

    /**
     * Dọn wp_headless_styles_optimized: xóa record (và file CSS trỏ bởi path) có post_type không còn trong templates + reserved.
     * Lưu ý: bảng optimized dùng cột post_type (slug template), không có post_id; cột ids trên wp_headless_templates là id DOM cho lọc CSS, không dùng làm khóa ở đây.
     *
     * @return int số bản ghi đã xóa
     */
    public function cleanupOrphanedStyles(Site $site): int
    {
        $siteId = $site->id;
        $valid = $this->collectValidOptimizedPostTypeIdentifiers($siteId);

        $orphans = WpHeadlessStyleOptimized::where('site_id', $siteId)
            ->whereNotIn('post_type', $valid)
            ->get();

        $deleted = 0;
        foreach ($orphans as $old) {
            $oldPath = trim((string) ($old->path ?? ''));
            if ($oldPath !== '') {
                $full = public_path($oldPath);
                if (File::exists($full)) {
                    @File::delete($full);
                }
                $this->deleteFromNextjsPublic($siteId, $oldPath);
            }
            $old->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * post_type hợp lệ cho file optimized: mọi type template (trừ loop_content*) + global + slider.
     *
     * @return list<string>
     */
    private function collectValidOptimizedPostTypeIdentifiers(int $siteId): array
    {
        $types = WpHeadlessTemplate::where('site_id', $siteId)
            ->whereNotNull('type')
            ->where('type', 'not like', 'loop_content%')
            ->distinct()
            ->pluck('type')
            ->all();

        $set = [];
        foreach ($types as $t) {
            $t = trim((string) $t);
            if ($t !== '') {
                $set[$t] = true;
            }
        }
        $set['global'] = true;
        $set[self::BUNDLE_POST_TYPE_MAIN] = true;
        $set[self::BUNDLE_POST_TYPE_HOME] = true;

        return array_keys($set);
    }

    /** Mọi truy vấn template phục vụ CSS: loại trừ type bắt đầu loop_content (kể cả loop_content-…). */
    private function applyExcludeLoopContentTemplates(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('type', 'not like', 'loop_content%');
    }

    /**
     * Seed chữ ký block từ global.css đã optimize để loại trùng ở home/main/layout.
     *
     * @param array<string, bool> $signatures
     */
    private function seedSignaturesFromOptimizedGlobalCss(Site $site, array &$signatures): void
    {
        $this->seedSignaturesFromOptimizedBundles($site, ['global'], $signatures);
    }

    /**
     * Seed chữ ký block từ các bundle css đã optimize theo post_type.
     *
     * @param list<string> $postTypes
     * @param array<string, bool> $signatures
     */
    private function seedSignaturesFromOptimizedBundles(Site $site, array $postTypes, array &$signatures): void
    {
        if ($postTypes === []) {
            return;
        }

        $rows = WpHeadlessStyleOptimized::where('site_id', $site->id)
            ->whereIn('post_type', $postTypes)
            ->orderBy('post_type')
            ->orderBy('chunk_index')
            ->get();

        foreach ($rows as $row) {
            $relativePath = trim((string) ($row->path ?? ''));
            if ($relativePath === '') {
                continue;
            }
            $fullPath = public_path($relativePath);
            if (!File::exists($fullPath)) {
                continue;
            }
            $css = (string) File::get($fullPath);
            if (trim($css) === '') {
                continue;
            }

            $blocks = WpHeadlessStylesFilterHelper::extractCssBlocks($css);
            if ($blocks === []) {
                $signatures[md5(WpHeadlessStylesFilterHelper::minifyCss($css))] = true;
                continue;
            }

            foreach ($blocks as $block) {
                $full = (string) ($block['full'] ?? '');
                if ($full === '') {
                    continue;
                }
                $signatures[md5(WpHeadlessStylesFilterHelper::minifyCss($full))] = true;
            }
        }
    }

    /**
     * Gom CSS theo nhóm (global, home, main, headers[], footers[]) rồi ghi file — luồng rebuild đầy đủ.
     * Dữ liệu nguồn: wp_headless_templates (type / template_path) + tính chuỗi tối ưu (tương đương cột css_optimized trong pseudocode).
     *
     * @return array{
     *   global: string,
     *   home: string,
     *   main: string,
     *   headers: array<string, string>,
     *   footers: array<string, string>
     * }
     */
    private function buildCssGroups(Site $site): array
    {
        $siteId = $site->id;

        /** @var array<string, string> keyed by md5(block) */
        $globalAtRules = [];

        $cssGroups = [
            'global'  => '',
            'home'    => '',
            'main'    => '',
            'headers' => [],
            'footers' => [],
        ];

        $gSig = [];
        $globalStr = $this->computeGlobalCssString($site, $gSig);
        $this->accumulateGlobalAtRulesFromCss($globalAtRules, $globalStr);
        $cssGroups['global'] = $globalStr;

        $orderedTypes = $this->collectOrderedTemplateTypesForFullCssRebuild($siteId);
        $mainMergeSig = $gSig;
        $mainSeenMd5 = array_fill_keys(array_keys($gSig), true);
        $mainBlocks = [];
        $homeCssMerged = false;
        $sliderSig = [];
        $sliderBlocks = $this->computeOptimizedNonGlobalBlocks($site, 'slider', $sliderSig);

        foreach ($orderedTypes as $type) {
            $type = trim((string) $type);
            if ($type === '' || $type === 'global' || str_starts_with($type, 'loop_content')) {
                continue;
            }

            $slug = $this->templateSlugForType($siteId, $type);

            if ($type === 'home' || ($type === 'page' && $slug !== null && strtolower(trim($slug)) === 'home')) {
                if ($homeCssMerged) {
                    continue;
                }
                $sig = $gSig;
                $blocks = $this->mergeUniqueCssBlocksByMd5(
                    $this->computeOptimizedNonGlobalBlocks($site, $type, $sig),
                    $sliderBlocks,
                    $gSig
                );
                $homeStr = implode("\n", $blocks);
                $this->accumulateGlobalAtRulesFromCss($globalAtRules, $homeStr);
                $cssGroups['home'] = $homeStr;
                $homeCssMerged = true;

                continue;
            }

            if ($this->isLayoutPostType($type)) {
                $sig = $gSig;
                $blocks = $this->computeOptimizedNonGlobalBlocks($site, $type, $sig);
                $css = implode("\n", $blocks);
                $this->accumulateGlobalAtRulesFromCss($globalAtRules, $css);
                if (trim($css) === '') {
                    continue;
                }

                if (str_starts_with($type, 'header_')) {
                    $id = str_replace('header_', '', $type);
                    $cssGroups['headers'][$id] = ($cssGroups['headers'][$id] ?? '') . $css;
                } elseif (str_starts_with($type, 'footer_')) {
                    $id = str_replace('footer_', '', $type);
                    $cssGroups['footers'][$id] = ($cssGroups['footers'][$id] ?? '') . $css;
                } elseif ($type === 'header') {
                    $cssGroups['headers']['default'] = ($cssGroups['headers']['default'] ?? '') . $css;
                } elseif ($type === 'footer') {
                    $cssGroups['footers']['default'] = ($cssGroups['footers']['default'] ?? '') . $css;
                }

                continue;
            }

            if (!$this->belongsToMainCssBundle($type, $siteId)) {
                continue;
            }

            $sigForFilter = $type === 'slider' ? [] : $mainMergeSig;
            $blocks = $type === 'slider'
                ? $sliderBlocks
                : $this->computeOptimizedNonGlobalBlocks($site, $type, $sigForFilter);
            if ($type === 'slider') {
                foreach (array_keys($sigForFilter) as $k) {
                    $mainMergeSig[$k] = true;
                }
            }
            foreach ($blocks as $b) {
                $h = md5($b);
                if (isset($mainSeenMd5[$h])) {
                    continue;
                }
                $mainSeenMd5[$h] = true;
                $mainBlocks[] = $b;
            }
        }

        $mainStr = implode("\n", $mainBlocks);
        $this->accumulateGlobalAtRulesFromCss($globalAtRules, $mainStr);
        $cssGroups['main'] = $mainStr;

        if (!$homeCssMerged && $this->siteHasHomeTemplates($siteId)) {
            $sig = $gSig;
            $blocks = $this->mergeUniqueCssBlocksByMd5(
                $this->computeOptimizedNonGlobalBlocks($site, 'home', $sig),
                $sliderBlocks,
                $gSig
            );
            $homeStr = implode("\n", $blocks);
            $this->accumulateGlobalAtRulesFromCss($globalAtRules, $homeStr);
            $cssGroups['home'] = $homeStr;
        }

        if ($globalAtRules !== []) {
            $merged = implode("\n", array_values($globalAtRules));
            $cssGroups['global'] = trim($merged . "\n" . trim($cssGroups['global']));
        }

        // Rebuild theo block map + frequency map để đẩy rule dùng chung về global.
        $globalMap = $this->blockMapFromCssString((string) ($cssGroups['global'] ?? ''));
        $homeMap = $this->blockMapFromCssString((string) ($cssGroups['home'] ?? ''));
        $mainMap = $this->blockMapFromCssString((string) ($cssGroups['main'] ?? ''));
        $headerMaps = [];
        foreach ($cssGroups['headers'] as $key => $content) {
            $headerMaps[(string) $key] = $this->blockMapFromCssString((string) $content);
        }
        $footerMaps = [];
        foreach ($cssGroups['footers'] as $key => $content) {
            $footerMaps[(string) $key] = $this->blockMapFromCssString((string) $content);
        }

        $frequency = [];
        $registerGroup = static function (string $groupName, array $map, array &$frequency): void {
            foreach ($map as $hash => $block) {
                if (!isset($frequency[$hash])) {
                    $frequency[$hash] = ['count' => 0, 'block' => $block, 'groups' => []];
                }
                if (!isset($frequency[$hash]['groups'][$groupName])) {
                    $frequency[$hash]['groups'][$groupName] = true;
                    $frequency[$hash]['count']++;
                }
            }
        };
        $registerGroup('global', $globalMap, $frequency);
        $registerGroup('home', $homeMap, $frequency);
        $registerGroup('main', $mainMap, $frequency);
        foreach ($headerMaps as $key => $map) {
            $registerGroup('header:' . $key, $map, $frequency);
        }
        foreach ($footerMaps as $key => $map) {
            $registerGroup('footer:' . $key, $map, $frequency);
        }

        $sharedHashes = [];
        foreach ($frequency as $hash => $info) {
            if ((int) ($info['count'] ?? 0) > 1) {
                $sharedHashes[$hash] = true;
                if (!isset($globalMap[$hash])) {
                    $globalMap[$hash] = (string) ($info['block'] ?? '');
                }
            }
        }

        foreach (array_keys($sharedHashes) as $hash) {
            unset($homeMap[$hash], $mainMap[$hash]);
            foreach ($headerMaps as $k => $map) {
                unset($headerMaps[$k][$hash]);
            }
            foreach ($footerMaps as $k => $map) {
                unset($footerMaps[$k][$hash]);
            }
        }

        // Global order: @font-face/@keyframes trước, sau đó shared blocks, cuối cùng phần còn lại.
        $globalAtTop = [];
        $globalShared = [];
        $globalOthers = [];
        foreach ($globalMap as $hash => $block) {
            if ($this->isFontFaceOrKeyframesBlock((string) $block)) {
                $globalAtTop[] = (string) $block;
                continue;
            }
            if (isset($sharedHashes[$hash])) {
                $globalShared[] = (string) $block;
                continue;
            }
            $globalOthers[] = (string) $block;
        }
        $cssGroups['global'] = implode("\n", array_merge($globalAtTop, $globalShared, $globalOthers));
        $cssGroups['home'] = implode("\n", array_values($homeMap));
        $cssGroups['main'] = implode("\n", array_values($mainMap));
        $cssGroups['headers'] = [];
        foreach ($headerMaps as $key => $map) {
            $joined = implode("\n", array_values($map));
            if (trim($joined) !== '') {
                $cssGroups['headers'][$key] = $joined;
            }
        }
        $cssGroups['footers'] = [];
        foreach ($footerMaps as $key => $map) {
            $joined = implode("\n", array_values($map));
            if (trim($joined) !== '') {
                $cssGroups['footers'][$key] = $joined;
            }
        }

        return $cssGroups;
    }

    /**
     * @param array<string, string> $globalAtRules keyed by md5(block)
     */
    private function accumulateGlobalAtRulesFromCss(array &$globalAtRules, string &$css): void
    {
        $pulled = WpHeadlessStylesFilterHelper::extractGlobalAtRules($css);
        foreach ($pulled as $block) {
            $k = md5($block);
            if (!isset($globalAtRules[$k])) {
                $globalAtRules[$k] = $block;
            }
        }
    }

    /**
     * @param list<string> $blocksA
     * @param list<string> $blocksB
     * @param array<string, bool> $seedSignatures
     * @return list<string>
     */
    private function mergeUniqueCssBlocksByMd5(array $blocksA, array $blocksB, array $seedSignatures = []): array
    {
        $seen = array_fill_keys(array_keys($seedSignatures), true);
        $out = [];
        foreach (array_merge($blocksA, $blocksB) as $block) {
            $hash = md5((string) $block);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $out[] = (string) $block;
        }

        return $out;
    }

    /**
     * @return array<string, string> hash(minified_block) => original block
     */
    private function blockMapFromCssString(string $css): array
    {
        $css = trim($css);
        if ($css === '') {
            return [];
        }
        $out = [];
        $blocks = WpHeadlessStylesFilterHelper::extractCssBlocks($css);
        if ($blocks === []) {
            $hash = md5(WpHeadlessStylesFilterHelper::minifyCss($css));
            $out[$hash] = $css;
            return $out;
        }
        foreach ($blocks as $block) {
            $full = trim((string) ($block['full'] ?? ''));
            if ($full === '') {
                continue;
            }
            $hash = md5(WpHeadlessStylesFilterHelper::minifyCss($full));
            if (!isset($out[$hash])) {
                $out[$hash] = $full;
            }
        }

        return $out;
    }

    private function isFontFaceOrKeyframesBlock(string $block): bool
    {
        return (bool) preg_match('/^@(?:font-face|(?:-\w+-)?keyframes)\b/i', ltrim($block));
    }

    /**
     * @return list<string>
     */
    private function collectOrderedTemplateTypesForFullCssRebuild(int $siteId): array
    {
        $types = $this->applyExcludeLoopContentTemplates(
            WpHeadlessTemplate::where('site_id', $siteId)
                ->whereNotNull('type')
                ->where('type', 'not like', 'sidebar_%')
        )
            ->distinct()
            ->pluck('type')
            ->all();

        $set = [];
        foreach ($types as $t) {
            $t = trim((string) $t);
            if ($t !== '' && !str_starts_with($t, 'loop_content')) {
                $set[$t] = true;
            }
        }
        $set['slider'] = true;

        $list = array_keys($set);
        usort($list, static function (string $a, string $b): int {
            if ($a === 'slider') {
                return 1;
            }
            if ($b === 'slider') {
                return -1;
            }

            return strcmp($a, $b);
        });

        return $list;
    }

    private function templateSlugForType(int $siteId, string $type): ?string
    {
        $row = WpHeadlessTemplate::where('site_id', $siteId)->where('type', $type)->first();
        if ($row === null || $row->template_path === null) {
            return null;
        }
        $s = trim((string) $row->template_path);

        return $s === '' ? null : $s;
    }

    /**
     * Nội dung global.css (specialBlocks, isGlobal=true) — không ghi file.
     */
    private function computeGlobalCssString(Site $site, array &$existingSignatures): string
    {
        $siteId = $site->id;
        $postType = 'global';
        $rawCss = $this->fetchStylesCss($siteId, $postType);
        if ($rawCss === '') {
            return '';
        }
        // Giữ nguyên rule .dark trong mọi trường hợp (không strip ở bước optimize).
        $stripDarkMode = false;
        $templatesData = $this->collectTemplateClassesAndIds($siteId, $postType);
        $classes = $templatesData['classes'] ?? [];
        $ids = $templatesData['ids'] ?? [];
        $filtered = WpHeadlessStylesFilterHelper::filterCssByClassesAndIds($rawCss, $classes, $ids, $stripDarkMode, $existingSignatures, true);
        $specialBlocks = $filtered['specialBlocks'] ?? [];
        $specialBlocks = array_map(fn(string $b) => WpHeadlessStylesFilterHelper::removeExcludedIdRulesFromCss($b), $specialBlocks);
        $allBlocks = array_map(fn(string $b) => WpHeadlessStylesFilterHelper::minifyCss($b), $specialBlocks);
        $allBlocks = array_values(array_filter($allBlocks, static fn(string $b) => trim($b) !== ''));
        $optimizedCss = implode("\n", $allBlocks);
        if ($optimizedCss === '' && trim($rawCss) !== '') {
            return $rawCss;
        }

        return $optimizedCss;
    }

    /**
     * Ghi toàn bộ file từ $cssGroups, cập nhật wp_headless_styles_optimized + copy Next.
     *
     * @param array{global: string, home: string, main: string, headers: array<string, string>, footers: array<string, string>} $cssGroups
     * @return array{success: bool, css_chunks: list<array{filename: string, content: string}>}
     */
    private function generateOptimizedCssFilesFromCssGroups(Site $site, array $cssGroups): array
    {
        $siteId = $site->id;
        $relativeDir = self::PUBLIC_CSS_DIR . '/' . $siteId;
        $layoutsDir = $relativeDir . '/' . self::LAYOUTS_DIR;
        File::ensureDirectoryExists(public_path($layoutsDir));

        $cssChunksForNext = [];
        $this->deduplicateCssGroups($cssGroups);

        try {
            DB::connection('wp_headless')->transaction(function () use ($site, $siteId, $relativeDir, $layoutsDir, $cssGroups, &$cssChunksForNext) {
                $this->deleteAllOptimizedFilesForSite($siteId);

                $globalCss = (string) ($cssGroups['global'] ?? '');
                if (trim($globalCss) !== '') {
                    $manifestSlice = [];
                    $this->persistBundleCssFile($site, 'global', $relativeDir . '/global.css', $globalCss, $manifestSlice, $cssChunksForNext);
                }

                $homeCss = (string) ($cssGroups['home'] ?? '');
                if (trim($homeCss) !== '') {
                    $manifestSlice = [];
                    $this->persistBundleCssFile($site, self::BUNDLE_POST_TYPE_HOME, $relativeDir . '/home.css', $homeCss, $manifestSlice, $cssChunksForNext);
                }

                $mainCss = (string) ($cssGroups['main'] ?? '');
                if (trim($mainCss) !== '') {
                    $manifestSlice = [];
                    $this->persistBundleCssFile($site, self::BUNDLE_POST_TYPE_MAIN, $relativeDir . '/main.css', $mainCss, $manifestSlice, $cssChunksForNext);
                }

                foreach ($cssGroups['headers'] ?? [] as $id => $content) {
                    $content = (string) $content;
                    if (trim($content) === '') {
                        continue;
                    }
                    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $id) ?: '0';
                    $dbPostType = WpHeadlessTemplate::where('site_id', $siteId)->where('type', 'header_' . $safeId)->exists()
                        ? 'header_' . $safeId
                        : 'header';
                    $relativePath = $dbPostType === 'header'
                        ? $layoutsDir . '/header.css'
                        : $layoutsDir . '/header-' . $safeId . '.css';
                    $manifestSlice = [];
                    $this->persistBundleCssFile($site, $dbPostType, $relativePath, $content, $manifestSlice, $cssChunksForNext);
                }

                foreach ($cssGroups['footers'] ?? [] as $id => $content) {
                    $content = (string) $content;
                    if (trim($content) === '') {
                        continue;
                    }
                    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $id) ?: '0';
                    $dbPostType = WpHeadlessTemplate::where('site_id', $siteId)->where('type', 'footer_' . $safeId)->exists()
                        ? 'footer_' . $safeId
                        : 'footer';
                    $relativePath = $dbPostType === 'footer'
                        ? $layoutsDir . '/footer.css'
                        : $layoutsDir . '/footer-' . $safeId . '.css';
                    $manifestSlice = [];
                    $this->persistBundleCssFile($site, $dbPostType, $relativePath, $content, $manifestSlice, $cssChunksForNext);
                }
            });

            $this->syncCssManifestFromOptimizedTable($site);
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessStylesOptimizer: generateOptimizedCssFilesFromCssGroups failed', [
                'site_id' => $siteId,
                'error'   => $e->getMessage(),
            ]);

            return ['success' => false, 'css_chunks' => []];
        }

        return ['success' => true, 'css_chunks' => $cssChunksForNext];
    }

    private function deduplicateCssGroups(array &$cssGroups): void
    {
        $blockTracker = [];
        $sourceMap = [];

        $processSource = function ($srcName, $cssStr) use (&$blockTracker, &$sourceMap) {
            if (empty(trim((string)$cssStr))) return;
            $blocks = \App\Addons\WpHeadless\Services\WpHeadlessStylesFilterHelper::extractCssBlocks($cssStr);
            $sourceMap[$srcName] = [];
            foreach ($blocks as $b) {
                $hash = md5(\App\Addons\WpHeadless\Services\WpHeadlessStylesFilterHelper::minifyCss($b['full']));
                if (!isset($blockTracker[$hash])) {
                    $blockTracker[$hash] = ['full' => $b['full'], 'sources' => []];
                }
                if (!in_array($srcName, $blockTracker[$hash]['sources'], true)) {
                    $blockTracker[$hash]['sources'][] = $srcName;
                }
                $sourceMap[$srcName][] = $hash;
            }
        };

        // 1. Quét block từ các source
        if (isset($cssGroups['home'])) $processSource('home', $cssGroups['home']);
        if (isset($cssGroups['main'])) $processSource('main', $cssGroups['main']);
        foreach (['headers', 'footers'] as $groupKey) {
            if (!empty($cssGroups[$groupKey]) && is_array($cssGroups[$groupKey])) {
                foreach ($cssGroups[$groupKey] as $id => $cssStr) {
                    $processSource($groupKey . '_' . $id, $cssStr);
                }
            }
        }

        // 2. Tách CSS trùng lặp
        $duplicatesToGlobal = [];
        $rebuildSource = function ($srcName) use (&$sourceMap, &$blockTracker, &$duplicatesToGlobal) {
            if (!isset($sourceMap[$srcName])) return '';
            $keptBlocks = [];
            foreach ($sourceMap[$srcName] as $hash) {
                if (count($blockTracker[$hash]['sources']) > 1) {
                    $duplicatesToGlobal[$hash] = $blockTracker[$hash]['full'];
                } else {
                    $keptBlocks[] = $blockTracker[$hash]['full'];
                }
            }
            return implode("\n", $keptBlocks);
        };

        // 3. Cập nhật lại các source đã sạch bóng CSS trùng
        if (isset($cssGroups['home'])) $cssGroups['home'] = $rebuildSource('home');
        if (isset($cssGroups['main'])) $cssGroups['main'] = $rebuildSource('main');
        foreach (['headers', 'footers'] as $groupKey) {
            if (!empty($cssGroups[$groupKey]) && is_array($cssGroups[$groupKey])) {
                foreach ($cssGroups[$groupKey] as $id => $cssStr) {
                    $cssGroups[$groupKey][$id] = $rebuildSource($groupKey . '_' . $id);
                }
            }
        }

        // 4. Nhét các block trùng lặp vào global.css
        if (!empty($duplicatesToGlobal)) {
            $cssGroups['global'] = ($cssGroups['global'] ?? '') . "\n" . implode("\n", $duplicatesToGlobal);
        }
    }

    /**
     * Xóa mọi bản ghi + file CSS public (và Next mirror) của site trước khi ghi lại từ $cssGroups.
     */
    private function deleteAllOptimizedFilesForSite(int $siteId): void
    {
        $rows = WpHeadlessStyleOptimized::where('site_id', $siteId)->get();
        foreach ($rows as $old) {
            $oldPath = trim((string) ($old->path ?? ''));
            if ($oldPath !== '') {
                $full = public_path($oldPath);
                if (File::exists($full)) {
                    @File::delete($full);
                }
                $this->deleteFromNextjsPublic($siteId, $oldPath);
            }
        }
        WpHeadlessStyleOptimized::where('site_id', $siteId)->delete();
    }

    /** @return array<int, array{filename: string, content: string}> */
    public function buildAllCssChunksForNext(Site $site): array
    {
        $siteId = $site->id;
        $this->cleanupOrphanedStyles($site);

        $relativeDir = self::PUBLIC_CSS_DIR . '/' . $siteId;
        $laravelPath = public_path($relativeDir);
        if (File::isDirectory($laravelPath)) {
            File::cleanDirectory($laravelPath);
        }
        File::ensureDirectoryExists(public_path($relativeDir . '/' . self::LAYOUTS_DIR));

        $nextPath = config('wp-headless.nextjs_public_path');
        if (! empty($nextPath)) {
            $nextPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $nextPath), DIRECTORY_SEPARATOR);
            $nextDest = $nextPath . DIRECTORY_SEPARATOR . self::PUBLIC_CSS_DIR . DIRECTORY_SEPARATOR . $siteId;
            if (File::isDirectory($nextDest)) {
                File::cleanDirectory($nextDest);
            }
            File::ensureDirectoryExists($nextDest . DIRECTORY_SEPARATOR . self::LAYOUTS_DIR);
        }

        SiteMeta::where('site_id', $siteId)->where('meta_key', self::META_KEY_CSS_MANIFEST)->delete();

        $cssGroups = $this->buildCssGroups($site);
        $result = $this->generateOptimizedCssFilesFromCssGroups($site, $cssGroups);

        if (!($result['success'] ?? false)) {
            return [];
        }

        $this->setStylesOptimizedAt($site, 'global');

        return $result['css_chunks'] ?? [];
    }

    /**
     * @param array<int, array{filename: string, content: string}> $all
     * @param array<string, mixed> $result
     */
    private function appendCssChunksFromOptimizeResult(array &$all, array $result): void
    {
        if (($result['success'] ?? false) !== true) {
            return;
        }
        if (!isset($result['css_chunks']) || !is_array($result['css_chunks'])) {
            return;
        }
        foreach ($result['css_chunks'] as $c) {
            if (isset($c['filename'], $c['content'])) {
                $all[] = ['filename' => $c['filename'], 'content' => $c['content']];
            }
        }
    }

    private function siteHasHomeTemplates(int $siteId): bool
    {
        return $this->applyExcludeLoopContentTemplates(
            WpHeadlessTemplate::where('site_id', $siteId)->where(function ($q) {
                $q->where('type', 'home')
                    ->orWhere(function ($q2) {
                        $q2->where('type', 'page')
                            ->whereNotNull('template_path')
                            ->whereRaw('LOWER(TRIM(template_path)) = ?', ['home']);
                    });
            })
        )->exists();
    }

    /**
     * Payload cssFiles cho Next POST /api/wp-templates/receive (Observer + WpBridgeController).
     * Full rebuild giống buildAllCssChunksForNext.
     *
     * @return array<int, array{filename: string, content: string}>
     */
    public function buildCssFilesPayloadForNextReceive(Site $site): array
    {
        return $this->buildAllCssChunksForNext($site);
    }

    private function optimizeGlobal(Site $site, int $siteId, array &$existingSignatures = []): array
    {
        $postType = 'global';
        $rawCss = $this->fetchStylesCss($siteId, $postType);
        if ($rawCss === '') {
            return ['success' => false, 'message' => 'No CSS content for global.', 'chunks' => 0, 'urls' => [], 'css_chunks' => []];
        }
        $optimizedCss = $this->computeGlobalCssString($site, $existingSignatures);
        $size = strlen($optimizedCss);
        if ($size === 0 && trim($rawCss) !== '') {
            $optimizedCss = $rawCss;
            $size = strlen($optimizedCss);
        }

        $relativePath = self::PUBLIC_CSS_DIR . '/' . $siteId . '/global.css';

        try {
            $cssChunksForNext = [];
            DB::connection('wp_headless')->transaction(function () use ($site, $siteId, $relativePath, $optimizedCss, &$cssChunksForNext) {
                $this->deleteOldOptimizedRowsAndFiles($siteId, ['global']);
                $manifestSlice = [];
                $this->persistBundleCssFile($site, 'global', $relativePath, $optimizedCss, $manifestSlice, $cssChunksForNext);
            });
            $this->syncCssManifestFromOptimizedTable($site);
            $urls = $this->normalizeManifestPathList(
                WpHeadlessStyleOptimized::where('site_id', $siteId)
                    ->where('post_type', $postType)
                    ->orderBy('chunk_index')
                    ->get()
                    ->map(fn (WpHeadlessStyleOptimized $row) => $this->manifestPathFromOptimizedRow($row))
                    ->filter(static fn (string $p) => $p !== '')
                    ->values()
                    ->all()
            );
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessStylesOptimizer: global save failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage(), 'chunks' => 0, 'urls' => [], 'css_chunks' => []];
        }
        $this->setStylesOptimizedAt($site, 'global');

        return [
            'success'    => true,
            'post_type'  => 'global',
            'size'       => $size,
            'chunks'     => 1,
            'urls'       => $urls ?? [],
            'css_chunks' => $cssChunksForNext ?? [],
        ];
    }

    /** Lấy thời gian lưu cuối từ site_meta (global, ...). */
    private function getStylesOptimizedAt(Site $site): array
    {
        $row = SiteMeta::where('site_id', $site->id)->where('meta_key', self::META_KEY_STYLES_OPTIMIZED_AT)->first();
        $value = $row->meta_value ?? null;
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Lấy cấu hình has_darkmode từ site settings (WP headlessSiteSettings). Mặc định false.
     * Khi false, tối ưu CSS sẽ loại bỏ toàn bộ rule .dark * .
     */
    private function getHasDarkmode(Site $site): bool
    {
        $row = SiteMeta::where('site_id', $site->id)->where('meta_key', self::META_KEY_SITE_SETTINGS)->first();
        $value = $row->meta_value ?? null;
        if ($value === null || $value === '') {
            return false;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return false;
        }
        return (bool) ($decoded['has_darkmode'] ?? false);
    }

    /** Ghi thời gian lưu cuối cho 1 loại (global hoặc post_type) vào site_meta. */
    private function setStylesOptimizedAt(Site $site, string $type): void
    {
        $at = $this->getStylesOptimizedAt($site);
        $at[$type] = time();
        SiteMeta::updateOrCreate(
            ['site_id' => $site->id, 'meta_key' => self::META_KEY_STYLES_OPTIMIZED_AT],
            ['meta_value' => json_encode($at, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * Copy file CSS đã ghi (Laravel public) sang thư mục public của Next.js để Next.js phục vụ local, không proxy.
     */
    /**
     * @param string $pathRelativeToLaravelPublic Đường dẫn tương đối từ public Laravel (vd: wp-headless/1/global.css)
     */
    private function copyToNextjsPublic(string $sourceFullPath, int $siteId, string $pathRelativeToLaravelPublic): void
    {
        $nextPath = config('wp-headless.nextjs_public_path');
        if ($nextPath === null || $nextPath === '') {
            return;
        }
        $nextPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $nextPath), DIRECTORY_SEPARATOR);
        $rel = str_replace('\\', '/', $pathRelativeToLaravelPublic);
        $rel = ltrim($rel, '/');
        $sitePrefix = self::PUBLIC_CSS_DIR . '/' . $siteId . '/';
        if (str_starts_with($rel, $sitePrefix)) {
            $underSite = substr($rel, strlen($sitePrefix));
        } else {
            $underSite = basename($rel);
        }
        $destDir = $nextPath . DIRECTORY_SEPARATOR . self::PUBLIC_CSS_DIR . DIRECTORY_SEPARATOR . $siteId;
        $destPath = $destDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $underSite);
        $parent = dirname($destPath);
        if (!File::isDirectory($parent)) {
            @File::makeDirectory($parent, 0755, true);
        }
        if (file_exists($sourceFullPath)) {
            @copy($sourceFullPath, $destPath);
        }
    }

    /**
     * @param string $pathRelativeToLaravelPublic Đường dẫn tương đối từ public Laravel (vd: wp-headless/1/layouts/x.css)
     */
    private function deleteFromNextjsPublic(int $siteId, string $pathRelativeToLaravelPublic): void
    {
        $nextPath = config('wp-headless.nextjs_public_path');
        if ($nextPath === null || $nextPath === '') {
            return;
        }
        $nextPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $nextPath), DIRECTORY_SEPARATOR);
        $rel = str_replace('\\', '/', $pathRelativeToLaravelPublic);
        $rel = ltrim($rel, '/');
        $sitePrefix = self::PUBLIC_CSS_DIR . '/' . $siteId . '/';
        if (str_starts_with($rel, $sitePrefix)) {
            $underSite = substr($rel, strlen($sitePrefix));
        } else {
            $underSite = basename($rel);
        }
        $filePath = $nextPath . DIRECTORY_SEPARATOR . self::PUBLIC_CSS_DIR . DIRECTORY_SEPARATOR . $siteId . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $underSite);
        if (File::exists($filePath)) {
            @File::delete($filePath);
        }
    }

    /**
     * Gom classes + ids từ wp_headless_templates (classes, body_class, ids).
     */
    private function collectTemplateClassesAndIds(int $siteId, string $postType): array
    {
        if ($postType === 'slider') {
            $rows = $this->applyExcludeLoopContentTemplates(
                WpHeadlessTemplate::where('site_id', $siteId)->where('type', 'slider')
            )->get();
            $fromDbClasses = [];
            $fromDbIds = [];
            foreach ($rows as $t) {
                if (! empty($t->classes) && is_array($t->classes)) {
                    foreach ($t->classes as $c) {
                        $c = trim((string) $c);
                        if ($c !== '') {
                            $fromDbClasses[$c] = true;
                        }
                    }
                }
                if (!empty($t->ids) && is_array($t->ids)) {
                    foreach ($t->ids as $id) {
                        $id = trim((string) $id);
                        if ($id !== '') {
                            $fromDbIds[$id] = true;
                        }
                    }
                }
            }
            if ($fromDbClasses !== [] || $fromDbIds !== []) {
                $ids = array_keys($fromDbIds);
                sort($ids);

                return [
                    'classes' => $this->normalizeClassList(array_keys($fromDbClasses)),
                    'ids'     => $ids,
                ];
            }

            return [
                'classes' => $this->normalizeClassList(self::flickitySliderCssClassTokens()),
                'ids' => [],
            ];
        }

        if ($postType === 'global') {
            $rows = $this->applyExcludeLoopContentTemplates(
                WpHeadlessTemplate::where('site_id', $siteId)
                    ->where('global', true)
                    ->where('type', '!=', 'header')
                    ->where('type', '!=', 'footer')
                    ->where('type', 'not like', 'header_%')
                    ->where('type', 'not like', 'footer_%')
            )->get();
        } elseif ($postType === 'home') {
            $rows = $this->applyExcludeLoopContentTemplates(
                WpHeadlessTemplate::where('site_id', $siteId)->where(function ($q) {
                    $q->where('type', 'home')
                        ->orWhere(function ($q2) {
                            $q2->where('type', 'page')
                                ->whereNotNull('template_path')
                                ->whereRaw('LOWER(TRIM(template_path)) = ?', ['home']);
                        });
                })
            )->get();
        } elseif ($postType === 'header') {
            $rows = $this->applyExcludeLoopContentTemplates(
                WpHeadlessTemplate::where('site_id', $siteId)
                    ->where(function ($q) {
                        $q->where('type', 'header')->orWhere('type', 'like', 'header_%');
                    })
            )->get();
        } elseif ($postType === 'footer') {
            $rows = $this->applyExcludeLoopContentTemplates(
                WpHeadlessTemplate::where('site_id', $siteId)
                    ->where(function ($q) {
                        $q->where('type', 'footer')->orWhere('type', 'like', 'footer_%');
                    })
            )->get();
        } elseif (str_starts_with($postType, 'header_') || str_starts_with($postType, 'footer_')) {
            $rows = $this->applyExcludeLoopContentTemplates(
                WpHeadlessTemplate::where('site_id', $siteId)->where('type', $postType)
            )->get();
        } else {
            $rows = $this->applyExcludeLoopContentTemplates(
                WpHeadlessTemplate::where('site_id', $siteId)->where('type', $postType)
            )->get();
        }

        $allClasses = [];
        $allIds = [];
        foreach ($rows as $t) {
            if (!empty($t->classes) && is_array($t->classes)) {
                foreach ($t->classes as $c) {
                    $c = trim((string) $c);
                    if ($c !== '') {
                        $allClasses[$c] = true;
                    }
                }
            }
            if (!empty($t->body_class) && is_array($t->body_class)) {
                foreach ($t->body_class as $c) {
                    $c = trim((string) $c);
                    if ($c !== '') {
                        $allClasses[$c] = true;
                    }
                }
            }
            if (!empty($t->ids) && is_array($t->ids)) {
                foreach ($t->ids as $id) {
                    $id = trim((string) $id);
                    if ($id !== '') {
                        $allIds[$id] = true;
                    }
                }
            }
        }
        // Bỏ toàn bộ class icon-* để CSS tối ưu không giữ rule cho icon (icon dùng React/Lucide ở Next.js)
        $allClasses = array_filter(array_keys($allClasses), static function ($c) {
            return strpos($c, 'icon-') !== 0;
        });
        $ids = array_keys($allIds);
        sort($ids);

        return [
            'classes' => array_values($allClasses),
            'ids'     => $ids,
        ];
    }

    private function fetchStylesCss(int $siteId, string $postType): string
    {
        // Slider: gộp raw từ global + slider (WP/Flatsome hầu như không enqueue riêng post_type=slider); output sau lọc class vẫn nhỏ, không trùng nội dung file global-*.css.
        $sliderFetch = $postType === 'slider';

        if ($postType === 'global') {
            $postTypes = ['global'];
        } elseif ($sliderFetch) {
            $postTypes = ['slider', 'global'];
        } else {
            $postTypes = array_merge([$postType], $this->inheritedStylePostTypesForFetch($postType));
            // Archive taxonomy: stylesheet trong DB thường trùng key với global — cần raw global để lọc theo class template archive.
            if ($this->isTaxonomyStylePostType($postType)) {
                $postTypes[] = 'global';
            }
        }
        $postTypes = array_values(array_unique($postTypes));

        $styleQuery = WpHeadlessStyle::where('site_id', $siteId)->whereIn('post_type', $postTypes);
        $driver = DB::connection('wp_headless')->getDriverName();
        if ($postTypes !== [] && in_array($driver, ['mysql', 'mariadb'], true)) {
            $placeholders = implode(',', array_fill(0, count($postTypes), '?'));
            $styleQuery->orderByRaw("FIELD(post_type, {$placeholders})", $postTypes);
        }
        $this->applyFileBeforeInlineOrder($styleQuery);
        $rows = $styleQuery->get();

        // Row có parent_id nhưng không có content/url (bản con trùng) → cần lấy CSS từ cha. Load sẵn các parent.
        $parentIds = $rows->pluck('parent_id')->filter()->unique()->values()->all();
        $parentRows = [];
        if ($parentIds !== []) {
            $parentRowsQuery = WpHeadlessStyle::where('site_id', $siteId)->whereIn('id', $parentIds);
            $this->applyFileBeforeInlineOrder($parentRowsQuery);
            $parentRows = $parentRowsQuery->get()->keyBy('id');
        }

        $globalKeys = [];
        if ($postType !== 'global') {
            $globalRowsQuery = WpHeadlessStyle::where('site_id', $siteId)->where('post_type', 'global');
            $this->applyFileBeforeInlineOrder($globalRowsQuery);
            $globalRows = $globalRowsQuery->get();
            foreach ($globalRows as $row) {
                if (($row->style_type ?? '') === 'inline' && ($row->content === null || $row->content === '')) {
                    continue;
                }
                $k = $this->rowStyleKey($row);
                if ($k !== '') {
                    $globalKeys[$k] = true;
                }
            }
        }

        $seen = [];
        $parts = [];

        foreach ($rows as $row) {
            $content = $row->content ?? null;
            $url = $row->url ?? '';
            $hasContent = $content !== null && $content !== '';
            $hasUrl = $url !== '';

            // Bản con (parent_id): không có content/url thì dùng CSS của row cha
            if (($row->parent_id ?? null) !== null && !$hasContent && !$hasUrl) {
                $parent = $parentRows[$row->parent_id] ?? null;
                if ($parent !== null) {
                    // Chỉ slider: bỏ qua cha không phải slider (trừ global — đã merge global vào query).
                    if ($sliderFetch && (($parent->post_type ?? '') !== $postType) && (($parent->post_type ?? '') !== 'global')) {
                        continue;
                    }
                    $parentKey = $this->rowStyleKey($parent);
                    if ($parentKey !== '' && !isset($seen[$parentKey])) {
                        $seen[$parentKey] = true;
                        if (($parent->post_type ?? '') !== 'global' && isset($globalKeys[$parentKey])) {
                            continue;
                        }
                        $parentContent = $parent->content ?? null;
                        $parentUrl = $parent->url ?? '';
                        if ($parentContent !== null && $parentContent !== '') {
                            $parts[] = $parentContent;
                        } elseif ($parentUrl !== '') {
                            $fetched = $this->fetchUrl($parentUrl);
                            if ($fetched !== '') {
                                $parts[] = $fetched;
                            }
                        }
                    }
                }
                continue;
            }

            if (($row->style_type ?? '') === 'inline' && !$hasContent) {
                continue;
            }
            $key = $this->rowStyleKey($row);
            if ($key === '') {
                continue;
            }
            if (($row->post_type ?? '') === 'global') {
                // đã có trong globalKeys từ query riêng
            } else {
                if (isset($globalKeys[$key])) {
                    continue;
                }
            }
            if ($hasContent) {
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $parts[] = $content;
                }
                continue;
            }
            if ($url === '') {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fetched = $this->fetchUrl($url);
            if ($fetched !== '') {
                $parts[] = $fetched;
            }
        }
        return implode("\n", $parts);
    }

    private function applyFileBeforeInlineOrder(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->orderByRaw("CASE WHEN style_type = 'inline' THEN 2 ELSE 1 END")
            ->orderBy('id', 'asc');
    }

    /**
     * WP thường đăng ký stylesheet cho post/product, không cho từng taxonomy — merge các post_type nguồn khi fetch raw.
     *
     * @return list<string>
     */
    private function inheritedStylePostTypesForFetch(string $postType): array
    {
        $postType = strtolower(trim($postType));
        if ($postType === 'home') {
            return ['page', 'global'];
        }
        if ($this->isLayoutPostType($postType)) {
            return ['ux_block', 'page', 'post', 'global'];
        }
        if (in_array($postType, ['page', 'product'], true)) {
            return ['post'];
        }
        if (in_array($postType, ['product_cat', 'product_tag', 'category', 'post_tag'], true) || str_starts_with($postType, 'pa_')) {
            return ['product', 'post', 'global'];
        }

        return [];
    }

    private function isTaxonomyStylePostType(string $postType): bool
    {
        if (in_array($postType, ['product_cat', 'product_tag', 'category', 'post_tag'], true)) {
            return true;
        }

        return str_starts_with($postType, 'pa_');
    }

    /** Key cho dedup: url chuẩn hóa (scheme+host+path, host/scheme lowercase) hoặc inline:md5(content). Trả về '' nếu không có. Cùng format với WpHeadlessSyncService::styleKeyForDedup. */
    private function rowStyleKey($row): string
    {
        $content = $row->content ?? null;
        if ($content !== null && $content !== '') {
            return 'inline:' . md5($content);
        }
        $url = $row->url ?? '';
        if ($url !== '') {
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? '';
            $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
            $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) . '://' : '//';
            return $scheme . $host . $path;
        }
        return '';
    }

    private function fetchUrl(string $url): string
    {
        try {
            $response = Http::timeout(15)->get($url);
            if ($response->successful()) {
                return (string) $response->body();
            }
        } catch (\Throwable $e) {
            Log::debug('WpHeadlessStylesOptimizer: fetch failed ' . $url, ['error' => $e->getMessage()]);
        }
        return '';
    }


    /**
     * Trừ specialBlocks trùng với bundle global đã lọc (optimizeByClasses).
     *
     * @param list<string> $specialBlocks
     * @return list<string>
     */
    private function excludeGlobalSpecialBlocks(int $siteId, array $specialBlocks, bool $stripDarkMode): array
    {
        if ($specialBlocks === []) {
            return [];
        }
        $globalCss = $this->fetchStylesCss($siteId, 'global');
        if (trim($globalCss) === '') {
            return $specialBlocks;
        }
        $td = $this->collectTemplateClassesAndIds($siteId, 'global');

        return WpHeadlessStylesFilterHelper::dedupeSpecialBlocksAgainstGlobalFiltered(
            $globalCss,
            $td['classes'] ?? [],
            $td['ids'] ?? [],
            $stripDarkMode,
            $specialBlocks
        );
    }

    /**
     * Class token cho stub slider + lọc CSS Flickity/Flatsome (public cho WpHeadlessSyncService).
     *
     * @return list<string>
     */
    public static function flickitySliderCssClassTokens(): array
    {
        // Không dùng class quá chung (.slider, .dot) — nếu không mọi rule theme chứa chúng sẽ còn trong slider-0.css.
        return [
            'slider-wrapper',
            'slider-nav-circle',
            'slider-nav-reveal',
            'slider-nav-outside',
            'flickity-enabled',
            'flickity-slider',
            'flickity-viewport',
            'flickity-rtl',
            'flickity-prev-next-button',
            'flickity-page-dots',
            'flickity-button',
            'flickity-button-icon',
            'is-draggable',
            'is-pointer-down',
            'is-selected',
            'is-nav-selected',
        ];
    }
    /**
     * Tách danh sách block thành nhiều chunk, mỗi chunk <= maxBytes (cắt tại ranh giới block).
     * @param list<string> $blocks
     */
    private function chunkBlocksBySize(array $blocks, int $maxBytes): array
    {
        $total = 0;
        foreach ($blocks as $b) {
            $total += strlen($b) + 1;
        }
        if ($total <= $maxBytes) {
            return [implode("\n", $blocks)];
        }
        $chunks = [];
        $current = [];
        $currentSize = 0;
        foreach ($blocks as $full) {
            $fullLen = strlen($full) + 1;
            if ($currentSize + $fullLen > $maxBytes && $current !== []) {
                $chunks[] = implode("\n", $current);
                $current = [];
                $currentSize = 0;
            }
            $current[] = $full;
            $currentSize += $fullLen;
        }
        if ($current !== []) {
            $chunks[] = implode("\n", $current);
        }
        return $chunks;
    }

    /**
     * Đảm bảo thư mục có quyền ghi (Windows dev hay báo Permission denied).
     */
    private function ensureDirectoryWritable(string $dir): void
    {
        clearstatcache(true, $dir);
        if (!is_writable($dir)) {
            @chmod($dir, 0777);
            clearstatcache(true, $dir);
        }
    }

    /**
     * Ghi CSS: (1) ghi đè file, (2) không được thì xóa file cũ rồi ghi, (3) không được thì tạo file mới (tên unique) và trả về đường dẫn mới để lưu DB.
     */
    private function writeCssFileSafe(string $fullPath, string $cssContent): string
    {
        $dir = \dirname($fullPath);
        $this->ensureDirectoryWritable($dir);

        $written = $this->tryPut($fullPath, $cssContent);
        if ($written !== null) {
            return $written;
        }

        if (file_exists($fullPath)) {
            @chmod($fullPath, 0666);
            @unlink($fullPath);
            clearstatcache(true, $fullPath);
        }
        $written = $this->tryPut($fullPath, $cssContent);
        if ($written !== null) {
            return $written;
        }

        $base = pathinfo($fullPath, PATHINFO_FILENAME);
        $newPath = $dir . \DIRECTORY_SEPARATOR . $base . '.' . uniqid('', true) . '.css';
        $written = $this->tryPut($newPath, $cssContent);
        if ($written !== null) {
            return $written;
        }

        throw new \RuntimeException('Failed to write CSS file (tried overwrite, delete+write, and new file): ' . $fullPath);
    }

    private function tryPut(string $path, string $content): ?string
    {
        try {
            if (@file_put_contents($path, $content) !== false) {
                return $path;
            }
        } catch (\Throwable $e) {
            // Permission denied etc. → fallback
        }
        return null;
    }
}
