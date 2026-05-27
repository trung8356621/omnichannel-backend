<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class ArticlePostImagesService
{
    public const META_KEY = 'wp_post_images';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function countForArticle(SeoArticle $article): int
    {
        $cached = $this->getMetaJson($article);
        if ($cached !== []) {
            return count($this->normalizeList($cached));
        }

        return count($this->resolveForArticle($article));
    }

    public function resolveForArticle(SeoArticle $article): array
    {
        $cached = $this->getMetaJson($article);
        if ($cached !== []) {
            return $this->enrichWithSeoMediaUrls($article, $this->normalizeList($cached));
        }

        $html = trim((string) app(WordPressArticleContentService::class)->resolveEditorHtml($article));
        if ($html === '') {
            return [];
        }

        return $this->enrichWithSeoMediaUrls($article, $this->extractFromHtml($html));
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     */
    public function persistForArticle(SeoArticle $article, array $images): void
    {
        $normalized = $this->normalizeList($images);
        if ($normalized === []) {
            $article->articleMetas()->where('meta_key', self::META_KEY)->delete();

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            [
                'meta_value' => json_encode(
                    $normalized,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
            ],
        );
    }

    public function syncFromHtml(SeoArticle $article, string $html): void
    {
        $existing = $this->normalizeList($this->getMetaJson($article));
        $extracted = $this->extractFromHtml($html);
        $merged = $this->mergePreservingWpIds($existing, $extracted);

        $this->persistForArticle($article, $merged);
    }

    /**
     * @param  array<int, array<string, mixed>>  $fromSync
     */
    public function importFromSyncItem(SeoArticle $article, array $fromSync): void
    {
        $images = $fromSync['post_images'] ?? null;
        if (! is_array($images) || $images === []) {
            return;
        }

        $this->persistForArticle($article, $this->normalizeList($images));
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    public function normalizeList(array $images): array
    {
        $result = [];

        foreach ($images as $index => $image) {
            if (! is_array($image)) {
                continue;
            }

            $src = trim((string) ($image['src'] ?? ''));
            if ($src === '') {
                continue;
            }

            $wpId = (int) ($image['wp_attachment_id'] ?? $image['wp_id'] ?? 0);
            $slug = trim((string) ($image['slug'] ?? ''));
            if ($slug === '') {
                $slug = $this->slugFromUrl($src);
            }

            $wpUrl = trim((string) ($image['wp_url'] ?? $image['wordpress_url'] ?? $image['source_url'] ?? ''));
            $localSrc = trim((string) ($image['local_src'] ?? ''));
            if ($localSrc === '' && $this->isLocalSeoMediaSrc($src)) {
                $localSrc = $src;
            }
            if ($wpUrl === '' && ! $this->isLocalSeoMediaSrc($src)) {
                $wpUrl = $src;
            }

            $result[] = [
                'key' => trim((string) ($image['key'] ?? '')) !== ''
                    ? (string) $image['key']
                    : ($wpId > 0 ? 'wp_' . $wpId : 'img_' . $index),
                'block_id' => trim((string) ($image['block_id'] ?? '')),
                'wp_attachment_id' => $wpId > 0 ? $wpId : null,
                'src' => $src,
                'slug' => $slug,
                'alt' => (string) ($image['alt'] ?? ''),
                'title' => (string) ($image['title'] ?? ''),
                'caption' => (string) ($image['caption'] ?? ''),
                'align' => (string) ($image['align'] ?? 'none'),
                'wp_url' => $wpUrl,
                'local_src' => $localSrc,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractFromHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $items = [];
        $seen = [];

        $this->collectFromGutenbergComments($html, $items, $seen);

        $internalErrors = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $html . '</div>';
        if (@$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            $xpath = new DOMXPath($doc);
            $nodes = $xpath->query('//img');
            if ($nodes !== false) {
                foreach ($nodes as $img) {
                    if (! $img instanceof DOMElement) {
                        continue;
                    }

                    $src = trim((string) $img->getAttribute('src'));
                    if ($src === '') {
                        continue;
                    }

                    $srcKey = $this->normalizeSrcKey($src);
                    if (isset($seen[$srcKey])) {
                        continue;
                    }
                    $seen[$srcKey] = true;

                    $figure = $img->parentNode instanceof DOMElement
                    && strtolower($img->parentNode->tagName) === 'figure'
                        ? $img->parentNode
                        : null;

                    $wpId = $this->resolveAttachmentIdFromImg($img, $src);

                    $items[] = [
                        'key' => $wpId > 0 ? 'wp_' . $wpId : 'src_' . md5($srcKey),
                        'block_id' => '',
                        'wp_attachment_id' => $wpId > 0 ? $wpId : null,
                        'src' => $src,
                        'slug' => $this->slugFromUrl($src),
                        'alt' => trim((string) $img->getAttribute('alt')),
                        'title' => trim((string) $img->getAttribute('title')),
                        'caption' => $figure
                            ? trim((string) $figure->getElementsByTagName('figcaption')->item(0)?->textContent)
                            : '',
                        'align' => $this->alignFromElement($figure ?? $img),
                    ];
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        return $this->normalizeList($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $extracted
     * @return array<int, array<string, mixed>>
     */
    private function mergePreservingWpIds(array $existing, array $extracted): array
    {
        if ($existing === []) {
            return $extracted;
        }

        $byWpId = [];
        $bySrc = [];
        foreach ($existing as $row) {
            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            if ($wpId > 0) {
                $byWpId[$wpId] = $row;
            }
            $srcKey = $this->normalizeSrcKey((string) ($row['src'] ?? ''));
            if ($srcKey !== '') {
                $bySrc[$srcKey] = $row;
            }
        }

        $merged = [];
        foreach ($extracted as $row) {
            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            $srcKey = $this->normalizeSrcKey((string) ($row['src'] ?? ''));

            $base = null;
            if ($wpId > 0 && isset($byWpId[$wpId])) {
                $base = $byWpId[$wpId];
            } elseif ($srcKey !== '' && isset($bySrc[$srcKey])) {
                $base = $bySrc[$srcKey];
            }

            if ($base !== null) {
                $row['wp_attachment_id'] = $row['wp_attachment_id'] ?? $base['wp_attachment_id'];
                if (trim((string) ($row['slug'] ?? '')) === '' && filled($base['slug'] ?? null)) {
                    $row['slug'] = $base['slug'];
                }
                if (trim((string) ($row['wp_url'] ?? '')) === '' && filled($base['wp_url'] ?? null)) {
                    $row['wp_url'] = $base['wp_url'];
                }
                if (trim((string) ($row['local_src'] ?? '')) === '' && filled($base['local_src'] ?? null)) {
                    $row['local_src'] = $base['local_src'];
                }
            }

            $merged[] = $row;
        }

        return $this->normalizeList($merged);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, true>  $seen
     */
    private function collectFromGutenbergComments(string $html, array &$items, array &$seen): void
    {
        if (! preg_match_all('/<!--\s*wp:image\s+(\{.*?\})\s*-->/s', $html, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $json = json_decode((string) ($match[1] ?? ''), true);
            if (! is_array($json)) {
                continue;
            }

            $wpId = (int) ($json['id'] ?? 0);
            if ($wpId <= 0) {
                continue;
            }

            $key = 'wp_' . $wpId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'key' => $key,
                'block_id' => '',
                'wp_attachment_id' => $wpId,
                'src' => '',
                'slug' => '',
                'alt' => '',
                'title' => '',
                'caption' => '',
                'align' => 'none',
            ];
        }
    }

    private function resolveAttachmentIdFromImg(DOMElement $img, string $src): int
    {
        $class = (string) $img->getAttribute('class');
        if (preg_match('/\bwp-image-(\d+)\b/', $class, $m)) {
            return (int) $m[1];
        }

        $dataId = (int) $img->getAttribute('data-id');
        if ($dataId > 0) {
            return $dataId;
        }

        return 0;
    }

    private function alignFromElement(DOMElement $el): string
    {
        $class = (string) $el->getAttribute('class');
        if (str_contains($class, 'alignfull')) {
            return 'full';
        }
        if (str_contains($class, 'alignright')) {
            return 'right';
        }
        if (str_contains($class, 'aligncenter')) {
            return 'center';
        }
        if (str_contains($class, 'alignleft')) {
            return 'left';
        }

        return 'none';
    }

    private function slugFromUrl(string $src): string
    {
        $path = (string) parse_url($src, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        $filename = basename($path);

        return pathinfo($filename, PATHINFO_FILENAME) ?: '';
    }

    private function normalizeSrcKey(string $src): string
    {
        $path = (string) parse_url($src, PHP_URL_PATH);

        return strtolower(rtrim($path, '/'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithSeoMediaUrls(SeoArticle $article, array $images): array
    {
        if ($images === []) {
            return [];
        }

        $wpIds = array_values(array_unique(array_filter(
            array_map(
                static fn (array $row): int => (int) ($row['wp_attachment_id'] ?? 0),
                $images
            ),
            static fn (int $id): bool => $id > 0,
        )));

        if ($wpIds === []) {
            return $images;
        }

        $medias = SeoMedia::query()
            ->where('site_id', (int) $article->site_id)
            ->whereIn('wp_attachment_id', $wpIds)
            ->get();

        $byWpId = [];
        foreach ($medias as $media) {
            $wpId = (int) ($media->wp_attachment_id ?? 0);
            if ($wpId <= 0) {
                continue;
            }

            $wpUrl = trim((string) ($media->getAttribute('wp_url') ?? ''));
            $localSrc = trim((string) $media->publicUrl());

            if (! isset($byWpId[$wpId])) {
                $byWpId[$wpId] = [
                    'wp_url' => $wpUrl,
                    'local_src' => $localSrc,
                ];
            } else {
                if ($byWpId[$wpId]['wp_url'] === '' && $wpUrl !== '') {
                    $byWpId[$wpId]['wp_url'] = $wpUrl;
                }
                if ($byWpId[$wpId]['local_src'] === '' && $localSrc !== '') {
                    $byWpId[$wpId]['local_src'] = $localSrc;
                }
            }
        }

        return array_map(function (array $row) use ($byWpId): array {
            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            if ($wpId <= 0 || ! isset($byWpId[$wpId])) {
                return $row;
            }

            $fromMedia = $byWpId[$wpId];
            if (trim((string) ($row['wp_url'] ?? '')) === '' && $fromMedia['wp_url'] !== '') {
                $row['wp_url'] = $fromMedia['wp_url'];
            }
            if (trim((string) ($row['local_src'] ?? '')) === '' && $fromMedia['local_src'] !== '') {
                $row['local_src'] = $fromMedia['local_src'];
            }

            return $row;
        }, $images);
    }

    private function isLocalSeoMediaSrc(string $src): bool
    {
        return str_contains(strtolower($src), '/storage/uploads/seo_media/');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getMetaJson(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', self::META_KEY)?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
