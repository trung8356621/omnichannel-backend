<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Models\Site;
use Illuminate\Support\Carbon;

class SeoMediaLibraryService
{
    /**
     * @return array{
     *     images: list<array<string, mixed>>,
     *     total: int,
     *     total_pages: int,
     *     page: int,
     *     error: string|null,
     * }
     */
    public function fetch(
        Site $site,
        ?string $month,
        int $page = 1,
        ?string $search = null,
        int $perPage = 48,
        ?int $articleId = null,
    ): array {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $rows = $this->queryMedia($site, $month, $search, $articleId)->get();

        $merged = $rows
            ->map(fn (SeoMedia $media): array => $this->mapMediaItem($media))
            ->sortByDesc('sort_at')
            ->values();

        $total = $merged->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $images = $merged->slice($offset, $perPage)->values()->all();

        return [
            'images' => $images,
            'total' => $total,
            'total_pages' => $totalPages,
            'page' => $page,
            'error' => null,
        ];
    }

    public function renameLocalBySlug(SeoMedia $media, string $newSlug): void
    {
        app(SeoMediaStorageService::class)->renameBySlug($media, $newSlug);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SeoMedia>
     */
    /**
     * Gắn ảnh test prompt (chưa có site) với bài viết đang mở — tối đa vài bản ghi mới nhất.
     */
    public function assignRecentOrphanMediaToArticle(Site $site, int $articleId): int
    {
        if ($articleId <= 0) {
            return 0;
        }

        $ids = SeoMedia::query()
            ->whereNull('site_id')
            ->whereNull('article_id')
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->where('created_at', '>=', now()->subHours(24))
            ->where('path', 'not like', '%placeholder-loading%')
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'completed');
            })
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return SeoMedia::query()
            ->whereIn('id', $ids)
            ->update([
                'site_id' => $site->id,
                'article_id' => $articleId,
            ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SeoMedia>
     */
    private function queryMedia(Site $site, ?string $month, ?string $search, ?int $articleId = null)
    {
        $query = SeoMedia::query();

        if ($articleId !== null && $articleId > 0) {
            $query->where(function ($q) use ($site, $articleId): void {
                $q->where('site_id', $site->id)
                    ->orWhere('article_id', $articleId);
            });
        } else {
            $query->where('site_id', $site->id);
        }

        $query->orderByDesc('id');

        $query->where(function ($statusQuery): void {
            $statusQuery->whereNull('status')
                ->orWhere('status', 'completed');
        });

        $this->applyMonthFilter($query, 'created_at', $month);

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('slug', 'like', $like)
                    ->orWhere('filename', 'like', $like)
                    ->orWhere('alt_text', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyMonthFilter($query, string $column, ?string $month): void
    {
        if (! filled($month)) {
            return;
        }

        try {
            $start = Carbon::createFromFormat('Y-m', (string) $month)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->whereBetween($column, [$start, $end]);
        } catch (\Throwable) {
            // ignore invalid month
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMediaItem(SeoMedia $media): array
    {
        $createdAt = $media->created_at;
        $source = (string) $media->source;
        $kind = str_starts_with($source, 'ai_') ? 'generated' : 'local';
        $alt = filled($media->alt_text) ? (string) $media->alt_text : (string) $media->slug;

        return [
            'kind' => $kind,
            'id' => (int) $media->id,
            'seo_media_id' => (int) $media->id,
            'article_id' => $media->firstArticleId(),
            'wp_attachment_id' => $media->wp_attachment_id !== null ? (int) $media->wp_attachment_id : null,
            'slug' => (string) $media->slug,
            'url' => $media->publicUrl(),
            'title' => '',
            'alt' => $alt,
            'source' => $source,
            'ai_generator' => filled($media->ai_generator) ? (string) $media->ai_generator : null,
            'created_at' => $createdAt?->toIso8601String(),
            'sort_at' => $createdAt?->timestamp ?? 0,
        ];
    }
}
