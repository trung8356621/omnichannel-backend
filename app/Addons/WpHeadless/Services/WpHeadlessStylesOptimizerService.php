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

    /** Thư mục public chứa file CSS tối ưu (relative từ public_path). */
    public const PUBLIC_CSS_DIR = 'wp-headless';

    /**
     * Tạo CSS đã tối ưu cho post_type: lấy classes từ templates, lọc rules trong CSS (post_type + global),
     * ghi ra file public (WordPress lấy trực tiếp qua URL), lưu path vào wp_headless_styles_optimized.
     * Nếu tổng size > 100 KB thì tách thành nhiều file (chunk_index 0, 1, ...).
     */
    public function optimize(Site $site, string $postType): array
    {
        $siteId = $site->id;
        $classes = $this->collectTemplateClasses($siteId, $postType);
        if (empty($classes)) {
            return ['success' => false, 'message' => 'No template classes found for post_type.', 'chunks' => 0, 'urls' => []];
        }

        $rawCss = $this->fetchStylesCss($siteId, $postType);
        if ($rawCss === '') {
            return ['success' => false, 'message' => 'No CSS content for post_type + global.', 'chunks' => 0, 'urls' => []];
        }

        $filtered = $this->filterCssByClasses($rawCss, $classes);
        $optimizedCss = implode("\n", $filtered['blocks']);
        $size = strlen($optimizedCss);
        $chunks = $this->chunkBlocksBySize($filtered['blocks'], self::MAX_CHUNK_BYTES);

        try {
            $dir = public_path(self::PUBLIC_CSS_DIR . '/' . $siteId);
            $existing = WpHeadlessStyleOptimized::where('site_id', $siteId)->where('post_type', $postType)->get();
            foreach ($existing as $row) {
                $p = $row->path ? public_path($row->path) : null;
                if ($p && File::isFile($p)) {
                    File::delete($p);
                }
            }

            DB::connection('wp_headless')->transaction(function () use ($siteId, $postType, $chunks, $dir) {
                WpHeadlessStyleOptimized::where('site_id', $siteId)->where('post_type', $postType)->delete();
                if (!File::isDirectory($dir)) {
                    File::makeDirectory($dir, 0755, true);
                }
                foreach ($chunks as $index => $content) {
                    $filename = $postType . '-' . $index . '.css';
                    $relativePath = self::PUBLIC_CSS_DIR . '/' . $siteId . '/' . $filename;
                    $fullPath = $dir . '/' . $filename;
                    File::put($fullPath, $content);
                    WpHeadlessStyleOptimized::create([
                        'site_id'     => $siteId,
                        'post_type'   => $postType,
                        'chunk_index' => $index,
                        'path'        => $relativePath,
                        'content'     => null,
                        'size'        => strlen($content),
                    ]);
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
     * Gom classes từ template post_type + header + footer (layout chung).
     * Bao gồm cả body_class từ template (get_body_class() WordPress) để CSS tối ưu giữ selector .body-class.
     */
    private function collectTemplateClasses(int $siteId, string $postType): array
    {
        $types = array_unique(['header', 'footer', $postType]);
        $allClasses = [];
        foreach ($types as $type) {
            $t = WpHeadlessTemplate::where('site_id', $siteId)->where('type', $type)->first();
            if (!$t) {
                continue;
            }
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
     */
    private function fetchStylesCss(int $siteId, string $postType): string
    {
        $rows = WpHeadlessStyle::where('site_id', $siteId)
            ->whereIn('post_type', [$postType, 'global'])
            ->orderBy('post_type') // global trước
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

    /**
     * Giữ lại chỉ các rule có selector chứa ít nhất một class trong $classes.
     * @return array{blocks: list<string>}
     */
    private function filterCssByClasses(string $css, array $classes): array
    {
        if (empty($classes)) {
            return ['blocks' => []];
        }
        $classPatterns = [];
        foreach ($classes as $c) {
            $esc = preg_quote($c, '/');
            $classPatterns[] = '\.' . $esc . '(?:\s|[,>+~\[:"\']|$)';
        }
        $selectorClassRegex = '/' . implode('|', $classPatterns) . '/';

        $blocks = $this->extractCssBlocks($css);
        $kept = [];
        foreach ($blocks as $block) {
            $selector = $block['selector'];
            $body = $block['body'];
            $full = $block['full'];

            if (str_starts_with(trim($selector), '@')) {
                if (preg_match($selectorClassRegex, $body)) {
                    $kept[] = $full;
                }
                continue;
            }
            if (preg_match($selectorClassRegex, $selector)) {
                $kept[] = $full;
            }
        }
        return ['blocks' => $kept];
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
