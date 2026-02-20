<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Services;

use App\Addons\WpHeadless\Models\WpHeadlessStyle;
use App\Addons\WpHeadless\Models\WpHeadlessStyleOptimized;
use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class WpHeadlessStylesOptimizerService
{
    private const MAX_CHUNK_BYTES = 100 * 1024; // 100 KB

    /** CSS reset mặc định: luôn đặt đầu output (*, ::before, ::after, html). */
    private const DEFAULT_CSS_RESET = '';

    /** Thư mục public chứa file CSS tối ưu (relative từ public_path). */
    public const PUBLIC_CSS_DIR = 'wp-headless';

    /**
     * Tạo CSS đã tối ưu cho post_type: lấy classes từ templates, lọc rules trong CSS (post_type + global),
     * ghi ra file public (WordPress lấy trực tiếp qua URL), lưu path vào wp_headless_styles_optimized.
     * Mặc định chạy global trước (nếu cần), lưu thời gian chạy cuối vào wp_options và throttle.
     * Nếu tổng size > 100 KB thì tách thành nhiều file (chunk_index 0, 1, ...).
     */
    public function optimize(Site $site, string $postType): array
    {
        $siteId = $site->id;

        // global = 1 không optimize trực tiếp (không tạo chunk/row cho post_type='global')
        if ($postType === 'global') {
            return [
                'success'   => true,
                'post_type' => $postType,
                'size'      => 0,
                'chunks'    => 0,
                'urls'      => [],
            ];
        }

        $classes = $this->collectTemplateClasses($siteId, $postType);
        if (empty($classes)) {
            return ['success' => false, 'message' => 'No template classes found for post_type.', 'chunks' => 0, 'urls' => []];
        }

        $rawCss = $this->fetchStylesCss($siteId, $postType);
        if ($rawCss === '') {
            return ['success' => false, 'message' => 'No CSS content for post_type + global.', 'chunks' => 0, 'urls' => []];
        }

        $filtered = $this->filterCssByClasses($rawCss, $classes);
        $specialBlocks = $filtered['specialBlocks'] ?? [];
        $classBlocks = $filtered['blocks'];
        // Nối CSS đặc biệt (HTML_TAGS, :root, *, @font-face, @keyframes) vào đầu ở cuối quá trình tối ưu
        $allBlocks = array_merge($specialBlocks, [self::DEFAULT_CSS_RESET], $classBlocks);
        $optimizedCss = implode("\n", $allBlocks);
        $size = strlen($optimizedCss);
        $chunks = $this->chunkBlocksBySize($allBlocks, self::MAX_CHUNK_BYTES);

        try {
            DB::connection('wp_headless')->transaction(function () use ($siteId, $postType, $chunks) {
                WpHeadlessStyleOptimized::where('site_id', $siteId)->where('post_type', $postType)->delete();

                $dir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, public_path(self::PUBLIC_CSS_DIR . '/' . $siteId)), DIRECTORY_SEPARATOR);
                if (!File::isDirectory($dir)) {
                    File::makeDirectory($dir, 0755, true);
                }

                $ds = DIRECTORY_SEPARATOR;
                foreach ($chunks as $index => $cssContent) {
                    $filename = $postType . '-' . $index . '.css';
                    $relativePath = self::PUBLIC_CSS_DIR . '/' . $siteId . '/' . $filename;
                    $fullPath = $dir . $ds . $filename;

                    // Ghi vào file tạm rồi rename; trên Windows nếu đích tồn tại có thể rename thất bại → xóa đích trước hoặc ghi đè trực tiếp
                    $tmpPath = $dir . $ds . $filename . '.tmp.' . uniqid('', true);
                    File::put($tmpPath, $cssContent);
                    @unlink($fullPath);
                    if (!@rename($tmpPath, $fullPath)) {
                        if (@file_put_contents($fullPath, $cssContent) === false) {
                            @unlink($tmpPath);
                            throw new \RuntimeException('Failed to write CSS file: ' . $fullPath);
                        }
                        @unlink($tmpPath);
                    }

                    WpHeadlessStyleOptimized::create([
                        'site_id'     => $siteId,
                        'post_type'   => $postType,
                        'chunk_index' => $index,
                        'path'        => $relativePath,
                        'content'     => null,
                        'size'        => strlen($cssContent),
                    ]);
                }
            });

            // Xóa file CSS cũ không còn trong DB (số chunk giảm)
            $normalizePath = static fn(string $path) => rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
            $currentPaths = WpHeadlessStyleOptimized::where('site_id', $siteId)->where('post_type', $postType)
                ->get()->pluck('path')->filter()->map(fn($p) => $normalizePath(public_path($p)))->values()->all();
            $dir = $normalizePath(public_path(self::PUBLIC_CSS_DIR . '/' . $siteId));
            $existingFiles = glob($dir . DIRECTORY_SEPARATOR . $postType . '-*.css') ?: [];
            foreach ($existingFiles as $f) {
                $fNorm = $normalizePath($f);
                if (!in_array($fNorm, $currentPaths, true)) {
                    @File::delete($f);
                }
            }

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
            return ['success' => false, 'message' => $e->getMessage(), 'chunks' => 0, 'urls' => []];
        }

        return [
            'success'   => true,
            'post_type' => $postType,
            'size'      => $size,
            'chunks'    => count($chunks),
            'urls'      => $urls ?? [],
        ];
    }

    /**
     * Gom classes: global = 0 (post_type) merge với toàn bộ classes/bodyClass của global = 1.
     * Lấy tất cả template global=1 (header, footer, sidebars) + template type = postType (global=0).
     */
    private function collectTemplateClasses(int $siteId, string $postType): array
    {
        $rows = WpHeadlessTemplate::where('site_id', $siteId)->where('global', true)->get();
        $postTypeTemplate = WpHeadlessTemplate::where('site_id', $siteId)->where('type', $postType)->first();
        if ($postTypeTemplate !== null && !$rows->contains('id', $postTypeTemplate->id)) {
            $rows->push($postTypeTemplate);
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
        return array_keys($allClasses);
    }

    /**
     * Lấy toàn bộ nội dung CSS từ wp_headless_styles (post_type + global), đã dedupe theo url/content.
     * Luôn sắp xếp global trước để CSS global nằm trước CSS post_type trong output.
     */
    private function fetchStylesCss(int $siteId, string $postType): string
    {
        $rows = WpHeadlessStyle::where('site_id', $siteId)
            ->whereIn('post_type', [$postType, 'global'])
            ->orderByRaw("CASE WHEN post_type = 'global' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->get();

        $seen = [];
        $parts = [];
        foreach ($rows as $row) {
            if ($row->content !== null && $row->content !== '') {
                $key = 'inline:' . md5($row->content);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $parts[] = $row->content;
                }
                continue;
            }
            $url = $row->url ?? '';
            if ($url === '') {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $content = $this->fetchUrl($url);
            if ($content !== '') {
                $parts[] = $content;
            }
        }
        return implode("\n", $parts);
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
     * Giữ lại: (1) rule có selector chứa class → blocks; (2) CSS đặc biệt (thẻ HTML, :root, *, @font-face, @keyframes) → specialBlocks, nối vào đầu ở cuối quá trình.
     * @return array{blocks: list<string>, specialBlocks: list<string>}
     */
    private function filterCssByClasses(string $css, array $classes): array
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

            if (str_starts_with($selTrim, '@')) {
                if (preg_match('/^@(?:font-face|keyframes|charset|import)\b/i', $selTrim)) {
                    $specialBlocks[] = $full;
                } elseif ($selectorClassRegex !== null && preg_match($selectorClassRegex, $body)) {
                    $classBlocks[] = $full;
                }
                continue;
            }

            if ($selectorClassRegex !== null && preg_match($selectorClassRegex, $selector)) {
                $classBlocks[] = $full;
                continue;
            }

            if (preg_match('/^\*(\s|[,>+~\[:"\']|$)/', $selTrim) || preg_match('/^:root\b/i', $selTrim)) {
                $specialBlocks[] = $full;
                continue;
            }

            if (str_contains($selector, '#')) {
                $classBlocks[] = $full;
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
     * Tách CSS thành các block: selector { body } hoặc @rule { ... } (brace-matching).
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
}
