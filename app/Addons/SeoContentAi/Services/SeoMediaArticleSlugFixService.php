<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fix local media slugs for an article: disk + media row + article URL references.
 */
final class SeoMediaArticleSlugFixService
{
    public function __construct(
        private readonly SeoMediaStorageService $storage,
        private readonly SeoMediaUrlReplacementService $urlReplacement,
    ) {}

    /**
     * @param  list<array{seo_media_id?: int|null, url?: string|null, new_slug: string, old_slug?: string|null}>  $items
     * @return array{
     *     success: bool,
     *     message: string,
     *     replacements: list<array<string, mixed>>,
     *     article_updated: bool,
     *     media_updated: bool,
     *     skipped_count?: int,
     *     skipped?: list<array<string, mixed>>,
     *     remaining_old_refs?: list<string>
     * }
     */
    public function fixSlugs(SeoArticle $article, array $items): array
    {
        // Request mới sau save: refresh để rewrite đúng body/meta mới nhất.
        $article->refresh();

        $queue = $this->normalizeItems($items);
        if ($queue === []) {
            return [
                'success' => false,
                'message' => 'Không có ảnh local hợp lệ để đổi slug.',
                'replacements' => [],
                'article_updated' => false,
                'media_updated' => false,
                'skipped_count' => 0,
                'skipped' => [],
            ];
        }

        $replacements = [];
        $urlMap = [];
        $pendingDeletes = [];
        $skipped = [];

        try {
            DB::connection('omi_seo_ai')->transaction(function () use (
                $article,
                $queue,
                &$replacements,
                &$urlMap,
                &$pendingDeletes,
                &$skipped,
            ): void {
                $tempToken = 'seo-ren-'.Str::lower(Str::random(8));
                $interim = [];

                foreach ($queue as $index => $item) {
                    $media = $this->resolveMedia($article, $item);
                    if (! $media instanceof SeoMedia) {
                        $skipped[] = [
                            'index' => $index,
                            'seo_media_id' => $item['seo_media_id'],
                            'url' => $item['url'],
                            'new_slug' => $item['new_slug'],
                            'reason' => 'not_found',
                        ];
                        continue;
                    }

                    try {
                        $oldUrl = $media->publicUrl();
                        $oldPath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
                        $oldSlug = (string) ($media->slug ?? '');
                        $tempSlug = $tempToken.'-'.($index + 1);

                        $tempMedia = $this->storage->renameBySlug($media, $tempSlug, copyThenDelete: true);
                        $interim[] = [
                            'media' => $tempMedia,
                            'final_slug' => $item['new_slug'],
                            'old_url' => $oldUrl,
                            'old_path' => $oldPath,
                            'old_slug' => $oldSlug,
                            'temp_path' => ltrim(str_replace('\\', '/', (string) $tempMedia->path), '/'),
                            'item' => $item,
                            'index' => $index,
                        ];
                    } catch (Throwable $e) {
                        $skipped[] = [
                            'index' => $index,
                            'seo_media_id' => $item['seo_media_id'],
                            'url' => $item['url'],
                            'new_slug' => $item['new_slug'],
                            'reason' => $e->getMessage() !== '' ? $e->getMessage() : 'rename_phase1_failed',
                        ];
                    }
                }

                foreach ($interim as $state) {
                    /** @var SeoMedia $media */
                    $media = $state['media'];

                    try {
                        $beforeFinalPath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
                        $renamed = $this->storage->renameBySlug($media, $state['final_slug'], copyThenDelete: true);
                        $newUrl = $renamed->publicUrl();
                        $newPath = ltrim(str_replace('\\', '/', (string) $renamed->path), '/');

                        $replacements[] = [
                            'media_id' => (int) $renamed->id,
                            'old_url' => $state['old_url'],
                            'new_url' => $newUrl,
                            'old_path' => $state['old_path'],
                            'new_path' => $newPath,
                            'old_slug' => $state['old_slug'],
                            'new_slug' => (string) $renamed->slug,
                        ];

                        if ($state['old_url'] !== '' && $newUrl !== '') {
                            $urlMap[$state['old_url']] = $newUrl;
                        }
                        if ($state['old_path'] !== '' && $newPath !== '') {
                            $urlMap['/storage/'.$state['old_path']] = '/storage/'.$newPath;
                        }

                        foreach ([$state['old_path'], $beforeFinalPath] as $stalePath) {
                            if ($stalePath !== '' && $stalePath !== $newPath) {
                                $pendingDeletes[$stalePath] = true;
                            }
                        }
                    } catch (Throwable $e) {
                        $item = is_array($state['item'] ?? null) ? $state['item'] : [];
                        $skipped[] = [
                            'index' => (int) ($state['index'] ?? -1),
                            'seo_media_id' => $item['seo_media_id'] ?? null,
                            'url' => $item['url'] ?? $state['old_url'] ?? '',
                            'new_slug' => $state['final_slug'] ?? ($item['new_slug'] ?? ''),
                            'reason' => $e->getMessage() !== '' ? $e->getMessage() : 'rename_phase2_failed',
                        ];
                    }
                }

                if ($urlMap === []) {
                    return;
                }

                $rewrite = $this->urlReplacement->rewriteArticleReferences($article, $urlMap);
                if ($rewrite['remaining_old_refs'] !== []) {
                    throw new \RuntimeException(
                        'Article vẫn còn URL ảnh cũ sau khi đổi slug: '
                        .implode(', ', array_slice($rewrite['remaining_old_refs'], 0, 3))
                    );
                }
            });
        } catch (Throwable $e) {
            Log::error('seo_media_article_slug_fix.failed', [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0),
                'error' => $e->getMessage(),
                'items' => array_map(static fn (array $row): array => [
                    'seo_media_id' => $row['seo_media_id'] ?? null,
                    'url' => $row['url'] ?? null,
                    'new_slug' => $row['new_slug'] ?? null,
                ], $queue),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Không đổi được slug ảnh.',
                'replacements' => [],
                'article_updated' => false,
                'media_updated' => false,
                'skipped_count' => count($skipped),
                'skipped' => $skipped,
            ];
        }

        $disk = Storage::disk('public');
        foreach (array_keys($pendingDeletes) as $path) {
            if ($path !== '' && $disk->exists($path)) {
                $disk->delete($path);
            }
        }

        $fresh = $article->fresh() ?? $article;
        $remaining = $urlMap === []
            ? []
            : $this->urlReplacement->findRemainingOldRefs((string) ($fresh->body ?? ''), $urlMap);

        $skippedCount = count($skipped);
        $message = 'Đã cập nhật slug cho '.count($replacements).' ảnh.';
        if ($skippedCount > 0) {
            $message .= ' Bỏ qua '.$skippedCount.' ảnh thiếu/lỗi.';
        }

        Log::info('seo_media_article_slug_fix.completed', [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0),
            'replacement_count' => count($replacements),
            'skipped_count' => $skippedCount,
            'remaining_old_refs' => $remaining,
        ]);

        // Tất cả bị skip vẫn success — client tiếp tục, không dừng cả batch.
        return [
            'success' => true,
            'message' => $message,
            'replacements' => $replacements,
            'article_updated' => $urlMap !== [],
            'media_updated' => $replacements !== [],
            'skipped_count' => $skippedCount,
            'skipped' => $skipped,
            'remaining_old_refs' => $remaining,
        ];
    }

    /**
     * Rename one media and rewrite references for a single article when provided.
     *
     * @return array{media: SeoMedia, replacement: array<string, mixed>, article_updated: bool}
     */
    public function renameOne(SeoMedia $media, string $newSlug, ?SeoArticle $article = null): array
    {
        $oldUrl = $media->publicUrl();
        $oldPath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        $oldSlug = (string) ($media->slug ?? '');

        $renamed = $this->storage->renameBySlug($media, $newSlug, copyThenDelete: true);
        $newUrl = $renamed->publicUrl();
        $newPath = ltrim(str_replace('\\', '/', (string) $renamed->path), '/');

        $replacement = [
            'media_id' => (int) $renamed->id,
            'old_url' => $oldUrl,
            'new_url' => $newUrl,
            'old_path' => $oldPath,
            'new_path' => $newPath,
            'old_slug' => $oldSlug,
            'new_slug' => (string) $renamed->slug,
        ];

        $articleUpdated = false;
        if ($article instanceof SeoArticle) {
            $urlMap = [
                $oldUrl => $newUrl,
                '/storage/'.$oldPath => '/storage/'.$newPath,
            ];
            $rewrite = $this->urlReplacement->rewriteArticleReferences($article, $urlMap);
            $articleUpdated = $rewrite['article_updated'];
            if ($rewrite['remaining_old_refs'] !== []) {
                throw new \RuntimeException(
                    'Article vẫn còn URL ảnh cũ sau khi đổi slug.'
                );
            }
        }

        if ($oldPath !== '' && $oldPath !== $newPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        return [
            'media' => $renamed,
            'replacement' => $replacement,
            'article_updated' => $articleUpdated,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{seo_media_id: int|null, url: string, new_slug: string, old_slug: string}>
     */
    private function normalizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $newSlug = Str::slug((string) ($item['new_slug'] ?? ''));
            if ($newSlug === '') {
                continue;
            }

            $out[] = [
                'seo_media_id' => ((int) ($item['seo_media_id'] ?? 0)) > 0
                    ? (int) $item['seo_media_id']
                    : null,
                'url' => trim((string) ($item['url'] ?? $item['src'] ?? '')),
                'new_slug' => $newSlug,
                'old_slug' => trim((string) ($item['old_slug'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param  array{seo_media_id: int|null, url: string, new_slug: string, old_slug: string}  $item
     */
    private function resolveMedia(SeoArticle $article, array $item): ?SeoMedia
    {
        $id = (int) ($item['seo_media_id'] ?? 0);
        if ($id > 0) {
            $media = SeoMedia::query()->find($id);
            if ($media instanceof SeoMedia) {
                return $media;
            }
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        $path = $this->urlReplacement->storagePathFromUrl($url);
        if ($path === '') {
            return null;
        }

        $siteId = (int) ($article->site_id ?? 0);
        $query = SeoMedia::query()->where('path', $path);
        if ($siteId > 0) {
            $query->where(function ($q) use ($siteId): void {
                $q->where('site_id', $siteId)->orWhereNull('site_id');
            });
        }

        return $query->first();
    }
}
