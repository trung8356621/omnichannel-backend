<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use Illuminate\Support\Facades\Log;

/**
 * Safe URL/path replacement for local SEO media references in article body + metas.
 */
final class SeoMediaUrlReplacementService
{
    /**
     * Build old→new map including relative/absolute/encoded path variants.
     *
     * @return array<string, string>
     */
    public function buildVariantMap(string $oldUrl, string $newUrl): array
    {
        $oldUrl = trim($oldUrl);
        $newUrl = trim($newUrl);
        if ($oldUrl === '' || $newUrl === '' || $oldUrl === $newUrl) {
            return [];
        }

        $map = [];
        $this->putPair($map, $oldUrl, $newUrl);

        $oldPath = $this->storagePathFromUrl($oldUrl);
        $newPath = $this->storagePathFromUrl($newUrl);
        if ($oldPath !== '' && $newPath !== '') {
            $this->putPair($map, '/storage/'.$oldPath, '/storage/'.$newPath);
            $this->putPair($map, $oldPath, $newPath);
            $this->putPair($map, rawurlencode($oldPath), rawurlencode($newPath));
            $this->putPair($map, str_replace(' ', '%20', $oldPath), str_replace(' ', '%20', $newPath));
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $urlMap  canonical old_url => new_url
     * @return array<string, string>
     */
    public function expandUrlMap(array $urlMap): array
    {
        $expanded = [];
        foreach ($urlMap as $oldUrl => $newUrl) {
            foreach ($this->buildVariantMap((string) $oldUrl, (string) $newUrl) as $from => $to) {
                $this->putPair($expanded, $from, $to);
            }
        }

        uksort($expanded, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $expanded;
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    public function replaceInText(string $text, array $urlMap): string
    {
        if ($text === '' || $urlMap === []) {
            return $text;
        }

        $expanded = $this->expandUrlMap($urlMap);
        foreach ($expanded as $old => $new) {
            if ($old === '' || $new === '' || $old === $new) {
                continue;
            }
            $text = str_replace($old, $new, $text);
        }

        return $text;
    }

    /**
     * @param  array<string, string>  $urlMap  canonical old_url => new_url
     * @return array{article_updated: bool, remaining_old_refs: list<string>}
     */
    public function rewriteArticleReferences(SeoArticle $article, array $urlMap): array
    {
        if ($urlMap === []) {
            return ['article_updated' => false, 'remaining_old_refs' => []];
        }

        $article = $article->fresh(['articleMetas']) ?? $article;
        $updated = false;

        $body = (string) ($article->body ?? '');
        $nextBody = $this->replaceInText($body, $urlMap);
        if ($nextBody !== $body) {
            $article->body = $nextBody;
            $updated = true;
        }

        $metaKeys = [
            ArticleMediaLocalService::META_FEATURED_URL,
            ArticleMediaLocalService::META_PRODUCT_GALLERY,
        ];

        foreach ($article->articleMetas as $meta) {
            $key = (string) ($meta->meta_key ?? '');
            if (! in_array($key, $metaKeys, true)) {
                continue;
            }

            $raw = (string) ($meta->meta_value ?? '');
            if ($raw === '') {
                continue;
            }

            $next = $this->replaceInText($raw, $urlMap);
            if ($next === $raw) {
                continue;
            }

            $meta->meta_value = $next;
            $meta->save();
            $updated = true;
        }

        if ($updated) {
            $article->save();
        }

        $remaining = $this->findRemainingOldRefs(
            (string) (($article->fresh() ?? $article)->body ?? ''),
            $urlMap,
        );

        if ($remaining !== []) {
            Log::warning('seo_media_url_replacement.remaining_old_refs', [
                'article_id' => (int) $article->id,
                'remaining' => $remaining,
            ]);
        }

        return [
            'article_updated' => $updated,
            'remaining_old_refs' => $remaining,
        ];
    }

    /**
     * @param  array<string, string>  $urlMap
     * @return list<string>
     */
    public function findRemainingOldRefs(string $haystack, array $urlMap): array
    {
        if ($haystack === '' || $urlMap === []) {
            return [];
        }

        $remaining = [];
        foreach ($urlMap as $oldUrl => $_) {
            $oldUrl = trim((string) $oldUrl);
            if ($oldUrl === '') {
                continue;
            }

            $path = $this->storagePathFromUrl($oldUrl);
            $needles = array_filter([
                $oldUrl,
                $path !== '' ? '/storage/'.$path : '',
                $path,
            ]);

            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    $remaining[] = $oldUrl;
                    break;
                }
            }
        }

        return array_values(array_unique($remaining));
    }

    public function storagePathFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $path = $url;
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $parsed = parse_url($url, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }

        $path = rawurldecode(trim($path));
        if (str_starts_with($path, '/storage/')) {
            return ltrim(substr($path, strlen('/storage/')), '/');
        }

        if (str_starts_with($path, 'storage/')) {
            return ltrim(substr($path, strlen('storage/')), '/');
        }

        if (str_contains($path, 'uploads/seo_media/')) {
            return ltrim($path, '/');
        }

        return '';
    }

    /**
     * @param  array<string, string>  $map
     */
    private function putPair(array &$map, string $from, string $to): void
    {
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }

        $map[$from] = $to;
    }
}
