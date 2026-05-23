<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoGeneratedImage;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class GeneratedImageLibraryService
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
        ?string $filterMonth = null,
        int $page = 1,
        int $perPage = 50,
        ?string $search = null,
    ): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $filterMonth = trim((string) $filterMonth);

        $query = SeoGeneratedImage::query()
            ->where('site_id', $site->id)
            ->orderByDesc('created_at');

        if ($filterMonth !== '') {
            try {
                $date = Carbon::createFromFormat('Y-m', $filterMonth);
            } catch (\Throwable) {
                return [
                    'images' => [],
                    'total' => 0,
                    'total_pages' => 1,
                    'page' => $page,
                    'error' => 'Tháng lọc không hợp lệ.',
                ];
            }

            $query->whereBetween('created_at', [
                $date->copy()->startOfMonth(),
                $date->copy()->endOfMonth(),
            ]);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $term = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(static function ($builder) use ($term): void {
                $builder
                    ->where('slug', 'like', $term)
                    ->orWhere('alt', 'like', $term)
                    ->orWhere('title', 'like', $term);
            });
        }

        $total = (int) $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $rows = $query
            ->forPage($page, $perPage)
            ->get();

        $images = $rows->map(static function (SeoGeneratedImage $row): array {
            return [
                'id' => (string) $row->id,
                'wp_attachment_id' => (int) ($row->wp_attachment_id ?? 0) ?: null,
                'url' => (string) $row->url,
                'slug' => (string) $row->slug,
                'title' => (string) ($row->title ?? ''),
                'alt' => (string) ($row->alt ?? ''),
                'date' => $row->created_at?->toIso8601String() ?? '',
            ];
        })->values()->all();

        return [
            'images' => $images,
            'total' => $total,
            'total_pages' => $totalPages,
            'page' => $page,
            'error' => null,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updateSlug(Site $site, int $imageId, string $newSlug): array
    {
        $newSlug = Str::slug($newSlug);
        if ($imageId <= 0 || $newSlug === '') {
            return [
                'success' => false,
                'message' => 'ID hoặc slug không hợp lệ.',
            ];
        }

        $image = SeoGeneratedImage::query()
            ->where('site_id', $site->id)
            ->whereKey($imageId)
            ->first();

        if ($image === null) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy ảnh Gen trong hệ thống.',
            ];
        }

        $image->update(['slug' => $newSlug]);

        return [
            'success' => true,
            'message' => 'Đã cập nhật slug ảnh Gen.',
        ];
    }
}
