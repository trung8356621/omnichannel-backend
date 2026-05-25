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
    public function fetch(Site $site, ?string $month, int $page = 1, ?string $search = null, int $perPage = 48): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $rows = $this->queryMedia($site, $month, $search)->get();

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
    private function queryMedia(Site $site, ?string $month, ?string $search)
    {
        $query = SeoMedia::query()
            ->where('site_id', $site->id)
            ->orderByDesc('id');

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
            'article_id' => $media->article_id !== null ? (int) $media->article_id : null,
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
