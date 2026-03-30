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
    /*
     * ─── TÓM TẮT QUY TRÌNH ─────────────────────────────────────────────────────
     *
     * 1. global-*.css
     *    - Chứa CSS đặc biệt: HTML_TAGS (html, body, div...), @font-face, @keyframes,
     *      @layer, @charset, @import, :root, *, và (nếu có class) rule theo class template global=1.
     *    - Có thể chạy khi không có class → chỉ xuất special blocks.
     *    - Toàn bộ CSS lưu vào file (path); GET /templates trả về globalCssChunks = URL file global.
     *
     * 2. post_type (post, page, category, header, footer...) chạy manual hoặc từ WordPress "Làm mới CSS tối ưu".
     *    - Header/footer: file riêng header-0.css, footer-0.css (không gộp class vào từng post_type).
     *
     * 3. post_type khác global
     *    - Lấy classes từ template; lọc raw CSS (post_type + global);
     *    - Output = specialBlocks + reset + classBlocks; ghi file post_type-0.css, ...
     */
    private const MAX_CHUNK_BYTES = 100 * 1024; // 100 KB

    /** CSS reset mặc định: luôn đặt đầu output (*, ::before, ::after, html). */
    private const DEFAULT_CSS_RESET = '';

    /**
     * Danh sách ID selector loại bỏ khỏi CSS tối ưu vì đã xử lý bằng component Next.js.
     * Ví dụ: #reviews, #commentform.
     */
    private const EXCLUDED_ID_SELECTORS = [
        'reviews',
        'commentform',
        'review_form',
        'ez-toc-container'
    ];

    /** Thư mục public chứa file CSS tối ưu (relative từ public_path). */
    public const PUBLIC_CSS_DIR = 'wp-headless';

    /** site_meta key: JSON { global: timestamp, ... } — dùng cho ensureGlobalOptimized (throttle). */
    private const META_KEY_STYLES_OPTIMIZED_AT = 'wp_headless_styles_optimized_at';

    /** site_meta key: site settings từ WP (headlessSiteSettings), có has_darkmode. */
    private const META_KEY_SITE_SETTINGS = 'wp_site_settings';

    /**
     * Tạo CSS đã tối ưu cho post_type: lấy classes từ templates, lọc rules trong CSS (post_type + global),
     * ghi ra file public (WordPress lấy trực tiếp qua URL), lưu path vào wp_headless_styles_optimized.
     * Mặc định chạy global trước (nếu cần), lưu thời gian chạy cuối vào wp_options và throttle.
     * Nếu tổng size > 100 KB thì tách thành nhiều file (chunk_index 0, 1, ...).
     */
    public function optimize(Site $site, string $postType): array
    {
        $siteId = $site->id;

        $isGlobal = $postType === 'global';
        if (!$isGlobal) {
            $this->ensureGlobalOptimized($site);
        }

        if ($isGlobal) {
            return $this->optimizeGlobal($site, $siteId);
        }

        $classes = $this->collectTemplateClasses($siteId, $postType);
        if (empty($classes)) {
            return ['success' => false, 'message' => 'No template classes found for post_type.', 'chunks' => 0, 'urls' => []];
        }

        $rawCss = $this->fetchStylesCss($siteId, $postType);
        if ($rawCss === '') {
            return ['success' => false, 'message' => 'No CSS content for post_type + global.', 'chunks' => 0, 'urls' => []];
        }

        $stripDarkMode = !$this->getHasDarkmode($site);
        $filtered = $this->filterCssByClasses($rawCss, $classes, $stripDarkMode, true);
        $specialBlocks = $filtered['specialBlocks'] ?? [];
        $classBlocks = $filtered['blocks'];
        // Lấy specialBlocks nhưng loại trừ block đã có trong global.css (tránh trùng).
        $specialBlocksNotInGlobal = $this->excludeGlobalSpecialBlocks($siteId, $specialBlocks, $stripDarkMode);
        $allBlocks = array_merge(
            $specialBlocksNotInGlobal,
            [self::DEFAULT_CSS_RESET],
            $classBlocks
        );
        $allBlocks = array_map(fn(string $b) => $this->removeExcludedIdRulesFromCss($b), $allBlocks);
        $allBlocks = array_map(fn(string $b) => $this->minifyCss($b), $allBlocks);
        $allBlocks = array_values(array_filter($allBlocks, static fn(string $b) => trim($b) !== ''));
        $optimizedCss = implode("\n", $allBlocks);
        $size = strlen($optimizedCss);

        $chunks = $this->chunkBlocksBySize($allBlocks, self::MAX_CHUNK_BYTES);

        try {
            $cssChunksForNext = [];
            DB::connection('wp_headless')->transaction(function () use ($siteId, $postType, $chunks, &$cssChunksForNext) {
                $oldRows = WpHeadlessStyleOptimized::where('site_id', $siteId)->where('post_type', $postType)->get();
                foreach ($oldRows as $old) {
                    $oldPath = trim((string) ($old->path ?? ''));
                    if ($oldPath === '') {
                        continue;
                    }
                    $oldFullPath = public_path($oldPath);
                    if (File::exists($oldFullPath)) {
                        @File::delete($oldFullPath);
                    }
                    $this->deleteFromNextjsPublic($siteId, basename($oldPath));
                }
                WpHeadlessStyleOptimized::where('site_id', $siteId)->where('post_type', $postType)->delete();

                foreach ($chunks as $index => $cssContent) {
                    $filename = $postType . '-' . $index . '.css';
                    $relativeDir = self::PUBLIC_CSS_DIR . '/' . $siteId;
                    $fullPath = public_path($relativeDir . '/' . $filename);
                    $writtenPath = $this->writeCssFileSafe($fullPath, $cssContent);
                    $actualFilename = basename($writtenPath);
                    $relativePath = $relativeDir . '/' . $actualFilename;
                    $this->copyToNextjsPublic($writtenPath, $siteId, $actualFilename);

                    WpHeadlessStyleOptimized::create([
                        'site_id'     => $siteId,
                        'post_type'   => $postType,
                        'chunk_index' => $index,
                        'path'        => $relativePath,
                        'size'        => strlen($cssContent),
                    ]);
                    $cssChunksForNext[] = ['filename' => $actualFilename, 'content' => $cssContent];
                }
            });

            $urls = WpHeadlessStyleOptimized::where('site_id', $siteId)
                ->where('post_type', $postType)
                ->orderBy('chunk_index')
                ->get()
                ->pluck('public_url')
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessStylesOptimizer: save failed', ['site_id' => $siteId, 'post_type' => $postType, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage(), 'chunks' => 0, 'urls' => [], 'css_chunks' => []];
        }

        return [
            'success'     => true,
            'post_type'   => $postType,
            'size'        => $size,
            'chunks'      => count($chunks),
            'urls'        => $urls ?? [],
            'css_chunks'  => $cssChunksForNext ?? [],
        ];
    }

    /**
     * Tối ưu CSS trực tiếp theo danh sách class bóc từ HTML (không phụ thuộc template classes trong DB).
     * Dùng cho ux_builder ở Next.js: tạo 1 file CSS cố định theo URI và trả về inline CSS.
     *
     * @param array<int, string> $classes
     */
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
            return ['success' => false, 'message' => 'No CSS content for post_type + global.'];
        }

        $stripDarkMode = !$this->getHasDarkmode($site);
        $filtered = $this->filterCssByClasses($rawCss, $allowedClasses, $stripDarkMode, true);
        $specialBlocks = is_array($filtered['specialBlocks'] ?? null) ? $filtered['specialBlocks'] : [];
        $classBlocks = is_array($filtered['blocks'] ?? null) ? $filtered['blocks'] : [];
        $specialBlocksNotInGlobal = $this->excludeGlobalSpecialBlocks($siteId, $specialBlocks, $stripDarkMode);
        $allBlocks = array_merge(
            $specialBlocksNotInGlobal,
            [self::DEFAULT_CSS_RESET],
            $classBlocks
        );
        $allBlocks = array_map(fn(string $b) => $this->removeExcludedIdRulesFromCss($b), $allBlocks);
        $allBlocks = array_map(fn(string $b) => $this->minifyCss($b), $allBlocks);
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

    /**
     * Đảm bảo đã tạo file global-*.css (chạy 1 lần khi bất kỳ postType nào được optimize).
     * Dùng site_meta thời gian lưu cuối, không so sánh file (file có thể cũ).
     */
    public function ensureGlobalOptimized(Site $site): void
    {
        $at = $this->getStylesOptimizedAt($site);
        if (($at['global'] ?? 0) > 0) {
            return;
        }
        $this->optimize($site, 'global');
    }

    /**
     * Thu thập toàn bộ CSS chunks (filename + content) để đẩy thẳng cho Next.js (không lưu file trên Laravel).
     * Đầu tiên: dọn thư mục CSS site + bản ghi wp_headless_styles_optimized, rồi optimize global và từng post_type (luôn gồm header, footer).
     *
     * @return array<int, array{filename: string, content: string}>
     */
    public function buildAllCssChunksForNext(Site $site): array
    {
        $siteId = $site->id;
        $all = [];

        $relativeDir = self::PUBLIC_CSS_DIR . '/' . $siteId;
        $laravelPath = public_path($relativeDir);
        if (File::isDirectory($laravelPath)) {
            File::cleanDirectory($laravelPath);
        }
        $nextPath = config('wp-headless.nextjs_public_path');
        if (! empty($nextPath)) {
            $nextPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $nextPath), DIRECTORY_SEPARATOR);
            $nextDest = $nextPath . DIRECTORY_SEPARATOR . self::PUBLIC_CSS_DIR . DIRECTORY_SEPARATOR . $siteId;
            if (File::isDirectory($nextDest)) {
                File::cleanDirectory($nextDest);
            }
        }
        WpHeadlessStyleOptimized::where('site_id', $siteId)->delete();

        $globalResult = $this->optimize($site, 'global');
        if (isset($globalResult['css_chunks']) && is_array($globalResult['css_chunks'])) {
            foreach ($globalResult['css_chunks'] as $c) {
                if (isset($c['filename'], $c['content'])) {
                    $all[] = ['filename' => $c['filename'], 'content' => $c['content']];
                }
            }
        }

        $postTypes = WpHeadlessTemplate::where('site_id', $siteId)
            ->whereNotNull('type')
            ->where('type', 'not like', 'sidebar_%')
            ->distinct()
            ->pluck('type')
            ->values()
            ->all();

        $postTypes = array_values(array_unique(array_merge(['header', 'footer'], $postTypes)));

        $skip = ['global'];
        foreach ($postTypes as $postType) {
            if (in_array($postType, $skip, true)) {
                continue;
            }
            $result = $this->optimize($site, $postType);
            if (isset($result['css_chunks']) && is_array($result['css_chunks'])) {
                foreach ($result['css_chunks'] as $c) {
                    if (isset($c['filename'], $c['content'])) {
                        $all[] = ['filename' => $c['filename'], 'content' => $c['content']];
                    }
                }
            }
        }

        return $all;
    }

    /**
     * Global CSS: chỉ lấy specialBlocks (HTML_TAGS, :root, *, @font-face, @keyframes, @layer, @charset, @import) từ toàn bộ global style.
     * Không lọc theo class; toàn bộ lưu vào file.
     */
    private function optimizeGlobal(Site $site, int $siteId): array
    {
        $postType = 'global';
        $rawCss = $this->fetchStylesCss($siteId, $postType);
        if ($rawCss === '') {
            return ['success' => false, 'message' => 'No CSS content for global.', 'chunks' => 0, 'urls' => []];
        }
        $stripDarkMode = !$this->getHasDarkmode($site);
        $filtered = $this->filterCssByClasses($rawCss, [], $stripDarkMode);
        $specialBlocks = $filtered['specialBlocks'] ?? [];
        $specialBlocks = array_map(fn(string $b) => $this->removeExcludedIdRulesFromCss($b), $specialBlocks);
        $allBlocks = array_map(fn(string $b) => $this->minifyCss($b), $specialBlocks);
        $allBlocks = array_values(array_filter($allBlocks, static fn(string $b) => trim($b) !== ''));
        $optimizedCss = implode("\n", $allBlocks);
        $size = strlen($optimizedCss);
        if ($size === 0 && trim($rawCss) !== '') {
            $optimizedCss = $rawCss;
            $size = strlen($optimizedCss);
            $allBlocks = [$rawCss];
        }
        $chunks = $this->chunkBlocksBySize($allBlocks, self::MAX_CHUNK_BYTES);

        try {
            $cssChunksForNext = [];
            DB::connection('wp_headless')->transaction(function () use ($siteId, $postType, $chunks, &$cssChunksForNext) {
                $oldRows = WpHeadlessStyleOptimized::where('site_id', $siteId)->where('post_type', $postType)->get();
                foreach ($oldRows as $old) {
                    $oldPath = trim((string) ($old->path ?? ''));
                    if ($oldPath === '') {
                        continue;
                    }
                    $oldFullPath = public_path($oldPath);
                    if (File::exists($oldFullPath)) {
                        @File::delete($oldFullPath);
                    }
                    $this->deleteFromNextjsPublic($siteId, basename($oldPath));
                }
                WpHeadlessStyleOptimized::where('site_id', $siteId)->where('post_type', $postType)->delete();
                foreach ($chunks as $index => $cssContent) {
                    $filename = $postType . '-' . $index . '.css';
                    $relativeDir = self::PUBLIC_CSS_DIR . '/' . $siteId;
                    $fullPath = public_path($relativeDir . '/' . $filename);
                    $writtenPath = $this->writeCssFileSafe($fullPath, $cssContent);
                    $actualFilename = basename($writtenPath);
                    $relativePath = $relativeDir . '/' . $actualFilename;
                    $this->copyToNextjsPublic($writtenPath, $siteId, $actualFilename);
                    WpHeadlessStyleOptimized::create([
                        'site_id'     => $siteId,
                        'post_type'   => $postType,
                        'chunk_index' => $index,
                        'path'        => $relativePath,
                        'size'        => strlen($cssContent),
                    ]);
                    $cssChunksForNext[] = ['filename' => $actualFilename, 'content' => $cssContent];
                }
            });
            $urls = WpHeadlessStyleOptimized::where('site_id', $siteId)
                ->where('post_type', $postType)
                ->orderBy('chunk_index')
                ->get()
                ->pluck('public_url')
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('WpHeadlessStylesOptimizer: global save failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage(), 'chunks' => 0, 'urls' => [], 'css_chunks' => []];
        }
        $this->setStylesOptimizedAt($site, 'global');
        return [
            'success'    => true,
            'post_type'  => 'global',
            'size'       => $size,
            'chunks'     => count($chunks),
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
    private function copyToNextjsPublic(string $sourceFullPath, int $siteId, string $filename): void
    {
        $nextPath = config('wp-headless.nextjs_public_path');
        if ($nextPath === null || $nextPath === '') {
            return;
        }
        $nextPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $nextPath), DIRECTORY_SEPARATOR);
        $destDir = $nextPath . DIRECTORY_SEPARATOR . self::PUBLIC_CSS_DIR . DIRECTORY_SEPARATOR . $siteId;
        if (!File::isDirectory($destDir)) {
            @File::makeDirectory($destDir, 0755, true);
        }
        $destPath = $destDir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($sourceFullPath)) {
            @copy($sourceFullPath, $destPath);
        }
    }

    /**
     * Xóa file CSS khỏi thư mục public Next.js (khi Laravel xóa chunk cũ).
     */
    private function deleteFromNextjsPublic(int $siteId, string $filename): void
    {
        $nextPath = config('wp-headless.nextjs_public_path');
        if ($nextPath === null || $nextPath === '') {
            return;
        }
        $nextPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $nextPath), DIRECTORY_SEPARATOR);
        $filePath = $nextPath . DIRECTORY_SEPARATOR . self::PUBLIC_CSS_DIR . DIRECTORY_SEPARATOR . $siteId . DIRECTORY_SEPARATOR . $filename;
        if (File::exists($filePath)) {
            @File::delete($filePath);
        }
    }

    /**
     * Gom classes từ wp_headless_templates (fields classes, body_class).
     * Header/footer: gom mọi bản ghi header / header_* hoặc footer / footer_* khi postType tương ứng.
     * header_$id / footer_$id (manual): chỉ template type = postType (ví dụ header_123).
     */
    private function collectTemplateClasses(int $siteId, string $postType): array
    {
        if ($postType === 'header') {
            $rows = WpHeadlessTemplate::where('site_id', $siteId)
                ->where(function ($q) {
                    $q->where('type', 'header')->orWhere('type', 'like', 'header_%');
                })
                ->get();
        } elseif ($postType === 'footer') {
            $rows = WpHeadlessTemplate::where('site_id', $siteId)
                ->where(function ($q) {
                    $q->where('type', 'footer')->orWhere('type', 'like', 'footer_%');
                })
                ->get();
        } elseif (str_starts_with($postType, 'header_') || str_starts_with($postType, 'footer_')) {
            $rows = WpHeadlessTemplate::where('site_id', $siteId)->where('type', $postType)->get();
        } else {
            $rows = WpHeadlessTemplate::where('site_id', $siteId)
                ->where('global', true)
                ->where('type', '!=', 'header')
                ->where('type', '!=', 'footer')
                ->where('type', 'not like', 'header_%')
                ->where('type', 'not like', 'footer_%')
                ->get();

            $postTypeTemplate = WpHeadlessTemplate::where('site_id', $siteId)->where('type', $postType)->first();
            if ($postTypeTemplate !== null && !$rows->contains('id', $postTypeTemplate->id)) {
                $rows->push($postTypeTemplate);
            }
            if ($postType === 'home') {
                $pageTemplate = WpHeadlessTemplate::where('site_id', $siteId)->where('type', 'page')->first();
                if ($pageTemplate !== null && !$rows->contains('id', $pageTemplate->id)) {
                    $rows->push($pageTemplate);
                }
            }
        }

        $allClasses = [];
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
        }
        // Bỏ toàn bộ class icon-* để CSS tối ưu không giữ rule cho icon (icon dùng React/Lucide ở Next.js)
        $allClasses = array_filter(array_keys($allClasses), static function ($c) {
            return strpos($c, 'icon-') !== 0;
        });
        return array_values($allClasses);
    }

    /**
     * Loại trừ khỏi $specialBlocks những block đã có trong global.css (so sánh theo signature minified).
     * Trả về mảng block không trùng global để gộp vào CSS post_type.
     */
    private function excludeGlobalSpecialBlocks(int $siteId, array $specialBlocks, bool $stripDarkMode = false): array
    {
        if ($specialBlocks === []) {
            return [];
        }
        $globalRawCss = $this->fetchStylesCss($siteId, 'global');
        if ($globalRawCss === '') {
            return $specialBlocks;
        }
        $globalFiltered = $this->filterCssByClasses($globalRawCss, [], $stripDarkMode);
        $globalSpecials = $globalFiltered['specialBlocks'] ?? [];
        $globalSignatures = [];
        foreach ($globalSpecials as $b) {
            $sig = md5($this->minifyCss($b));
            $globalSignatures[$sig] = true;
        }
        $out = [];
        foreach ($specialBlocks as $block) {
            $sig = md5($this->minifyCss($block));
            if (!isset($globalSignatures[$sig])) {
                $out[] = $block;
            }
        }
        return $out;
    }

    /**
     * Lấy toàn bộ nội dung CSS từ wp_headless_styles (post_type + global), đã dedupe theo url/content.
     * (1) Bỏ qua style_type = inline mà content = null.
     * (2) Đã vào global thì không thêm lại ở post_type/taxonomy (xóa trùng).
     * (3) Row có parent_id (bản con trùng CSS): nếu không có content/url thì lấy CSS từ row cha để tối ưu đủ.
     * Với postType = global: chỉ lấy row post_type = global (không gộp header/footer), thứ tự theo sort_order.
     */
    private function fetchStylesCss(int $siteId, string $postType): string
    {
        // Khi build cho header hoặc footer, ta lấy CSS từ toàn bộ các Row để vét hết các style rớt vãi
        if (in_array($postType, ['header', 'footer'], true)) {
            $rows = WpHeadlessStyle::where('site_id', $siteId)
                ->orderBy('sort_order')
                ->get();
        } else {
            $postTypes = [$postType, 'global'];
            if ($postType === 'home') {
                $postTypes[] = 'page';
            }
            if (in_array($postType, ['page', 'product'], true)) {
                $postTypes[] = 'post';
            }
            $postTypes = array_values(array_unique($postTypes));
            $orderCase = "CASE WHEN post_type = 'global' THEN 0 WHEN post_type = 'page' THEN 1 WHEN post_type = 'post' THEN 2 ELSE 3 END";
            $rows = WpHeadlessStyle::where('site_id', $siteId)
                ->whereIn('post_type', $postTypes)
                ->orderByRaw($orderCase)
                ->orderBy('sort_order')
                ->get();
        }

        // Row có parent_id nhưng không có content/url (bản con trùng) → cần lấy CSS từ cha. Load sẵn các parent.
        $parentIds = $rows->pluck('parent_id')->filter()->unique()->values()->all();
        $parentRows = [];
        if ($parentIds !== []) {
            $parentRows = WpHeadlessStyle::where('site_id', $siteId)->whereIn('id', $parentIds)->get()->keyBy('id');
        }

        // Với post_type khác global: build globalKeys từ toàn bộ row global (cả file và inline có content) để tránh style trùng.
        $globalKeys = [];
        if ($postType !== 'global') {
            $globalRows = WpHeadlessStyle::where('site_id', $siteId)->where('post_type', 'global')->get();
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

    /** Thẻ HTML thuần (selector không chỉ định class/id) luôn giữ. */
    private const HTML_TAGS = [
        'a',
        'abbr',
        'address',
        'area',
        'article',
        'aside',
        'b',
        'bdi',
        'bdo',
        'blockquote',
        'body',
        'br',
        'button',
        'canvas',
        'caption',
        'cite',
        'code',
        'col',
        'colgroup',
        'data',
        'datalist',
        'dd',
        'del',
        'details',
        'dfn',
        'dialog',
        'div',
        'dl',
        'dt',
        'em',
        'embed',
        'fieldset',
        'figcaption',
        'figure',
        'footer',
        'form',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'head',
        'header',
        'hr',
        'html',
        'i',
        'iframe',
        'img',
        'input',
        'ins',
        'kbd',
        'label',
        'legend',
        'li',
        'link',
        'main',
        'map',
        'mark',
        'meta',
        'meter',
        'nav',
        'noscript',
        'object',
        'ol',
        'optgroup',
        'option',
        'output',
        'p',
        'picture',
        'pre',
        'progress',
        'q',
        'rp',
        'rt',
        'ruby',
        's',
        'samp',
        'section',
        'select',
        'slot',
        'small',
        'source',
        'span',
        'strong',
        'style',
        'sub',
        'summary',
        'sup',
        'svg',
        'table',
        'tbody',
        'td',
        'template',
        'textarea',
        'tfoot',
        'th',
        'thead',
        'time',
        'title',
        'tr',
        'track',
        'u',
        'ul',
        'var',
        'video',
        'wbr',
    ];

    /**
     * Kiểm tra selector (một selector đơn) có class .dark hoặc .*-dark (ví dụ .bg-dark, .text-dark).
     * Dùng khi stripDarkMode để loại bỏ rule dark mode.
     */
    private function selectorContainsDarkMode(string $selector): bool
    {
        return (bool) preg_match('/(\.dark\b|\.\w+-dark\b)/', $selector);
    }

    /**
     * Loại nội dung trong :not(), :is(), :has(), :where() khỏi selector trước khi trích class.
     * Chỉ cần kiểm tra class "dương" (phần áp style), không bắt buộc class trong :not(.transparent) v.v.
     */
    private function stripPseudoClassArgsFromSelector(string $singleSelector): string
    {
        $len = strlen($singleSelector);
        $out = '';
        $i = 0;
        $pseudoNames = ['not', 'is', 'has', 'where'];
        while ($i < $len) {
            if ($singleSelector[$i] === ':' && $i + 1 < $len) {
                $matched = false;
                foreach ($pseudoNames as $name) {
                    $nlen = strlen($name);
                    if ($i + 1 + $nlen < $len
                        && strcasecmp(substr($singleSelector, $i + 1, $nlen), $name) === 0
                        && preg_match('/^\s*\(/', substr($singleSelector, $i + 1 + $nlen))) {
                        $start = $i;
                        $i += 1 + $nlen;
                        while ($i < $len && preg_match('/\s/', $singleSelector[$i])) {
                            $i++;
                        }
                        if ($i < $len && $singleSelector[$i] === '(') {
                            $depth = 1;
                            $i++;
                            while ($i < $len && $depth > 0) {
                                $ch = $singleSelector[$i];
                                if ($ch === '(') {
                                    $depth++;
                                } elseif ($ch === ')') {
                                    $depth--;
                                } elseif (($ch === '"' || $ch === "'") && ($i === 0 || $singleSelector[$i - 1] !== '\\')) {
                                    $quote = $ch;
                                    $i++;
                                    while ($i < $len) {
                                        if ($singleSelector[$i] === $quote && $singleSelector[$i - 1] !== '\\') {
                                            $i++;
                                            break;
                                        }
                                        $i++;
                                    }
                                    continue;
                                }
                                $i++;
                            }
                            $out .= ' '; // giữ độ dài tương đối
                            $matched = true;
                            break;
                        }
                    }
                }
                if ($matched) {
                    continue;
                }
            }
            $out .= $singleSelector[$i];
            $i++;
        }
        return $out;
    }

    /**
     * Trích danh sách tên class (không có dấu chấm) từ một selector đơn.
     * Bỏ qua class nằm trong :not(), :is(), :has(), :where() — chỉ lấy class "dương".
     * Ví dụ ".header:not(.transparent) .nav" → ['header', 'nav'].
     */
    private function extractClassesFromSelector(string $singleSelector): array
    {
        $singleSelector = $this->stripPseudoClassArgsFromSelector($singleSelector);
        if (preg_match_all('/\.([a-zA-Z_][a-zA-Z0-9_-]*)/', $singleSelector, $m)) {
            return array_values(array_unique($m[1]));
        }
        return [];
    }

    /**
     * Kiểm tra mọi class "dương" trong selector đều có trong danh sách allowed (cha con đều phải có trong classes).
     * Class trong :not(), :is(), :has(), :where() không tính. Selector chỉ có tag HTML (không có class) trả về false.
     */
    private function selectorAllClassesInList(string $singleSelector, array $allowedClasses): bool
    {
        $classesInSelector = $this->extractClassesFromSelector($singleSelector);
        if ($classesInSelector === []) {
            return false;
        }
        $allowedSet = array_flip(array_map('strtolower', $allowedClasses));
        foreach ($classesInSelector as $cls) {
            if (!isset($allowedSet[strtolower($cls)])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Chỉ giữ lại những selector có toàn bộ class "dương" (cha + con) nằm trong $allowedClasses; loại bỏ selector thừa.
     * Selector chỉ có tag HTML + class như .nav > li > a được giữ khi .nav có trong list.
     * Trả về chuỗi selector đã gộp lại hoặc '' nếu không còn selector nào.
     */
    private function filterSelectorsToAllowedClassesOnly(string $selectorList, array $allowedClasses): string
    {
        if ($allowedClasses === []) {
            return '';
        }
        $selectors = $this->splitSelectorList($selectorList);
        $kept = [];
        foreach ($selectors as $sel) {
            if ($this->selectorAllClassesInList($sel, $allowedClasses)) {
                $kept[] = $sel;
            }
        }
        return $kept === [] ? '' : implode(',', $kept);
    }

    /**
     * Kiểm tra selector có ít nhất một class nằm trong $allowedClasses (dùng cho trường hợp có #, bỏ qua kiểm tra cha con).
     */
    private function selectorHasAtLeastOneClassInList(string $selectorList, array $allowedClasses): bool
    {
        if ($allowedClasses === []) {
            return false;
        }
        foreach ($allowedClasses as $c) {
            $esc = preg_quote($c, '/');
            if (preg_match('/\.' . $esc . '(?:\s|[,>+~\[:"\']|$)/', $selectorList)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Kiểm tra selector có chứa id thuộc danh sách loại trừ hay không (vd: #reviews, #commentform).
     */
    private function selectorContainsExcludedId(string $selectorList): bool
    {
        foreach (self::EXCLUDED_ID_SELECTORS as $id) {
            $esc = preg_quote((string) $id, '/');
            if (preg_match('/#' . $esc . '(?:[^a-zA-Z0-9_-]|$)/i', $selectorList)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lọc nội dung CSS (body): rule có # thì giữ nguyên; không có # thì chỉ giữ selector có toàn bộ class trong $allowedClasses (bỏ dư thừa).
     * Dùng cho body bên trong @media / @supports.
     */
    private function filterCssBodyToAllowedClassesOnly(string $cssBody, array $allowedClasses, bool $allowIdSelectors = true): string
    {
        if ($allowedClasses === []) {
            return '';
        }
        $blocks = $this->extractCssBlocks($cssBody);
        $kept = [];
        foreach ($blocks as $block) {
            if ($this->selectorContainsExcludedId($block['selector'])) {
                continue;
            }
            if ($allowIdSelectors && str_contains($block['selector'], '#')) {
                $kept[] = $block['full'];
            } else {
                $filteredSelector = $this->filterSelectorsToAllowedClassesOnly($block['selector'], $allowedClasses);
                if ($filteredSelector !== '') {
                    $kept[] = $filteredSelector . '{' . $block['body'] . '}';
                }
            }
        }
        return implode("\n", $kept);
    }

    /**
     * Tách chuỗi selector list (nhiều selector cách nhau bởi dấu phẩy) thành từng selector đơn.
     * Không tách dấu phẩy nằm trong [], () hoặc chuỗi " '.
     */
    private function splitSelectorList(string $selectorList): array
    {
        $len = strlen($selectorList);
        $current = '';
        $result = [];
        $inDouble = false;
        $inSingle = false;
        $depthBracket = 0;
        $depthParen = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $selectorList[$i];
            if ($inDouble) {
                $current .= $ch;
                if ($ch === '"' && ($i === 0 || $selectorList[$i - 1] !== '\\')) {
                    $inDouble = false;
                }
                continue;
            }
            if ($inSingle) {
                $current .= $ch;
                if ($ch === "'" && ($i === 0 || $selectorList[$i - 1] !== '\\')) {
                    $inSingle = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $current .= $ch;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                $current .= $ch;
                continue;
            }
            if ($ch === '[') {
                $depthBracket++;
                $current .= $ch;
                continue;
            }
            if ($ch === ']') {
                $depthBracket--;
                $current .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depthParen++;
                $current .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depthParen--;
                $current .= $ch;
                continue;
            }
            if ($ch === ',' && $depthBracket === 0 && $depthParen === 0) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $result[] = $trimmed;
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $result[] = $trimmed;
        }
        return $result;
    }

    /**
     * Loại bỏ chỉ những selector có .dark hoặc .*-dark khỏi danh sách selector, giữ lại rule với phần còn lại.
     * Trả về chuỗi selector đã gộp lại, hoặc '' nếu toàn bộ đều bị loại.
     */
    private function filterSelectorsRemoveDark(string $selectorList): string
    {
        $selectors = $this->splitSelectorList($selectorList);
        $kept = [];
        foreach ($selectors as $sel) {
            if ($this->selectorContainsDarkMode($sel)) {
                continue;
            }
            $kept[] = $sel;
        }
        return $kept === [] ? '' : implode(',', $kept);
    }

    /**
     * Giữ lại: (1) rule có selector chứa class → blocks; (2) CSS đặc biệt (thẻ HTML, :root, *, @font-face, @keyframes, @layer, @charset, @import) → specialBlocks.
     * Với global/header/footer khi không có class: chỉ xuất specialBlocks (HTML_TAGS, @font-face, @keyframes, ...).
     * Khi $stripDarkMode = true: chỉ loại bỏ từng selector có .dark hoặc .*-dark trong danh sách selector, giữ lại rule với các selector còn lại (ví dụ .a,.b-dark,.c → .a,.c).
     * @return array{blocks: list<string>, specialBlocks: list<string>}
     */
    private function filterCssByClasses(string $css, array $classes, bool $stripDarkMode = false, bool $allowIdSelectors = true): array
    {
        $selectorClassRegex = null;
        if (!empty($classes)) {
            $classPatterns = [];
            foreach ($classes as $c) {
                $esc = preg_quote($c, '/');
                $classPatterns[] = '\.' . $esc . '(?:\s|[,>+~\[:"\']|$)';
            }
            $selectorClassRegex = '/' . implode('|', $classPatterns) . '/';
        }

        $blocks = $this->extractCssBlocks($css);
        $classBlocks = [];
        $specialBlocks = [];
        foreach ($blocks as $block) {
            $selector = $block['selector'];
            $body = $block['body'];
            $full = $block['full'];
            $selTrim = trim($selector);

            if ($stripDarkMode && $this->selectorContainsDarkMode($selector)) {
                $filteredSelector = $this->filterSelectorsRemoveDark($selector);
                if ($filteredSelector === '') {
                    continue;
                }
                $selector = $filteredSelector;
                $full = $filteredSelector . '{' . $body . '}';
                $selTrim = trim($selector);
            }

            if (str_starts_with($selTrim, '@')) {
                if (preg_match('/^@(?:font-face|(?:-\w+-)?keyframes|charset|import|layer)\b/i', $selTrim)) {
                    if (preg_match('/^@font-face\b/i', $selTrim) && preg_match('/fl-icons/i', $full)) {
                        continue;
                    }
                    $specialBlocks[] = $full;
                } elseif (preg_match('/^@(?:media|supports)\b/i', $selTrim)) {
                    if ($stripDarkMode && $this->selectorContainsDarkMode($body)) {
                        $filteredBody = $this->stripDarkModeFromCss($body);
                        if ($filteredBody !== '') {
                            $full = $selector . '{' . $filteredBody . '}';
                            $body = $filteredBody;
                        } else {
                            continue;
                        }
                    }
                    if (preg_match('/@(?:-\w+-)?keyframes\b/i', $body)) {
                        $specialBlocks[] = $full;
                    } elseif ($selectorClassRegex !== null && preg_match($selectorClassRegex, $body)) {
                        $filteredBody = $this->filterCssBodyToAllowedClassesOnly($body, $classes, $allowIdSelectors);
                        if ($filteredBody !== '') {
                            $classBlocks[] = $selector . '{' . $filteredBody . '}';
                        }
                    }
                } elseif ($selectorClassRegex !== null && preg_match($selectorClassRegex, $body)) {
                    $filteredBody = $this->filterCssBodyToAllowedClassesOnly($body, $classes, $allowIdSelectors);
                    if ($filteredBody !== '') {
                        $classBlocks[] = $selector . '{' . $filteredBody . '}';
                    }
                }
                continue;
            }

            if ($this->selectorContainsExcludedId($selector)) {
                continue;
            }

            // Có id (#): chỉ giữ nguyên rule khi cho phép id selector.
            if ($allowIdSelectors && str_contains($selector, '#')) {
                $classBlocks[] = $full;
                continue;
            }

            // Không có #: chỉ giữ selector có toàn bộ class (cha + con) trong list, bỏ dư thừa như .col-hover-blur .col-inner khi col-hover-blur không có trong list.
            if ($selectorClassRegex !== null && preg_match($selectorClassRegex, $selector)) {
                $filteredSelector = $this->filterSelectorsToAllowedClassesOnly($selector, $classes);
                if ($filteredSelector !== '') {
                    $classBlocks[] = $filteredSelector . '{' . $body . '}';
                }
                continue;
            }

            if (preg_match('/^\*(\s|[,>+~\[:"\']|$)/', $selTrim) || preg_match('/^:root\b/i', $selTrim)) {
                $specialBlocks[] = $full;
                continue;
            }

            foreach (array_map('trim', explode(',', $selector)) as $part) {
                if ($part === '' || (isset($part[0]) && ($part[0] === '.' || $part[0] === '#'))) {
                    continue;
                }
                $firstToken = preg_split('/[.#\s]/', $part, 2)[0];
                $firstToken = trim($firstToken);
                if ($firstToken === '*' || strcasecmp($firstToken, ':root') === 0) {
                    $specialBlocks[] = $full;
                    break;
                }
                if (!in_array(strtolower($firstToken), self::HTML_TAGS, true)) {
                    continue;
                }
                if (preg_match_all('/\.([a-zA-Z_][a-zA-Z0-9_-]*)/', $part, $m)) {
                    $classesInSelector = array_map('strtolower', $m[1]);
                    $classesSet = array_flip(array_map('strtolower', $classes));
                    foreach ($classesInSelector as $cls) {
                        if (!isset($classesSet[$cls])) {
                            continue 2;
                        }
                    }
                }
                $specialBlocks[] = $full;
                break;
            }
        }
        return ['blocks' => $classBlocks, 'specialBlocks' => $specialBlocks];
    }

    /**
     * Loại bỏ chỉ những selector có .dark hoặc .*-dark trong mỗi rule; giữ lại rule với phần selector còn lại.
     * Dùng cho nội dung trong @media khi stripDarkMode.
     */
    private function stripDarkModeFromCss(string $css): string
    {
        $blocks = $this->extractCssBlocks($css);
        $kept = [];
        foreach ($blocks as $block) {
            if ($this->selectorContainsDarkMode($block['selector'])) {
                $filteredSelector = $this->filterSelectorsRemoveDark($block['selector']);
                if ($filteredSelector === '') {
                    continue;
                }
                $kept[] = $filteredSelector . '{' . $block['body'] . '}';
            } else {
                $kept[] = $block['full'];
            }
        }
        return implode("\n", $kept);
    }

    /**
     * Lọc hậu kỳ: loại mọi CSS rule có selector chứa id nằm trong EXCLUDED_ID_SELECTORS,
     * kể cả khi rule nằm trong @media/@supports lồng nhau.
     */
    private function removeExcludedIdRulesFromCss(string $css): string
    {
        $blocks = $this->extractCssBlocks($css);
        if ($blocks === []) {
            return $css;
        }
        $kept = [];
        foreach ($blocks as $block) {
            $selector = trim((string) ($block['selector'] ?? ''));
            $body = (string) ($block['body'] ?? '');
            $full = (string) ($block['full'] ?? '');

            if ($selector === '') {
                continue;
            }

            if (preg_match('/^@(?:media|supports)\b/i', $selector)) {
                $filteredBody = $this->removeExcludedIdRulesFromCss($body);
                if (trim($filteredBody) !== '') {
                    $kept[] = $selector . '{' . $filteredBody . '}';
                }
                continue;
            }

            if ($this->selectorContainsExcludedId($selector)) {
                continue;
            }

            $kept[] = $full;
        }
        return implode("\n", $kept);
    }

    /**
     * Tách CSS thành các block: selector { body }, @rule { ... }, hoặc @import/@charset ...; (không có {}).
     */
    private function extractCssBlocks(string $css): array
    {
        $blocks = [];
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            $i = $this->skipWhitespaceAndComments($css, $i);
            if ($i >= $len) {
                break;
            }
            // @import và @charset không có {} — đọc đến ; (bỏ qua ; trong chuỗi).
            if (preg_match('/^@(?:charset|import)\s/i', substr($css, $i))) {
                $end = $this->findSemicolonOutsideString($css, $i);
                if ($end !== null) {
                    $full = trim(substr($css, $i, $end - $i + 1));
                    if ($full !== '') {
                        $blocks[] = ['selector' => $full, 'body' => '', 'full' => $full];
                    }
                    $i = $end + 1;
                    continue;
                }
            }
            if ($css[$i] === '}') {
                $i++;
                continue;
            }
            $start = $i;
            $depth = 0;
            $inDouble = false;
            $inSingle = false;
            $open = -1;
            while ($i < $len) {
                $ch = $css[$i];
                if ($inDouble) {
                    if ($ch === '"' && ($i === 0 || $css[$i - 1] !== '\\')) {
                        $inDouble = false;
                    }
                    $i++;
                    continue;
                }
                if ($inSingle) {
                    if ($ch === "'" && ($i === 0 || $css[$i - 1] !== '\\')) {
                        $inSingle = false;
                    }
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inDouble = true;
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $inSingle = true;
                    $i++;
                    continue;
                }
                if ($ch === '{') {
                    if ($depth === 0) {
                        $open = $i;
                    }
                    $depth++;
                    $i++;
                    continue;
                }
                if ($ch === '}') {
                    $depth--;
                    if ($depth === 0 && $open >= 0) {
                        $block = substr($css, $start, $i - $start + 1);
                        $selector = trim(substr($css, $start, $open - $start));
                        $body = substr($css, $open + 1, $i - $open - 1);
                        $blocks[] = ['selector' => $selector, 'body' => $body, 'full' => $block];
                        $i++;
                        break;
                    }
                    $i++;
                    continue;
                }
                $i++;
            }
        }
        return $blocks;
    }

    private function skipWhitespaceAndComments(string $css, int $i): int
    {
        $len = strlen($css);
        while ($i < $len) {
            if (preg_match('/^\s+/', substr($css, $i), $m)) {
                $i += strlen($m[0]);
                continue;
            }
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            break;
        }
        return $i;
    }

    /** Tìm vị trí dấu ; tiếp theo không nằm trong chuỗi " hoặc '. Trả về null nếu không thấy. */
    private function findSemicolonOutsideString(string $css, int $start): ?int
    {
        $len = strlen($css);
        $inDouble = false;
        $inSingle = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $css[$i];
            if ($inDouble) {
                if ($ch === '"' && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inDouble = false;
                }
                continue;
            }
            if ($inSingle) {
                if ($ch === "'" && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inSingle = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                continue;
            }
            if ($ch === ';') {
                return $i;
            }
        }
        return null;
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
     * Minify một block CSS: bỏ comment, gộp khoảng trắng, bỏ space thừa quanh { } : ; ,
     */
    private function minifyCss(string $css): string
    {
        $css = preg_replace('/\/\*[\s\S]*?\*\//u', '', $css);
        $css = preg_replace('/[\r\n]+/u', ' ', $css);
        $css = preg_replace('/\s+/u', ' ', $css);
        $css = trim($css);
        $css = preg_replace('/\s*([{}:;,])\s*/u', '$1', $css);
        return $css;
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
