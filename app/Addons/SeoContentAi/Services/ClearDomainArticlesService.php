<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

final class ClearDomainArticlesService
{
    /**
     * Xóa vĩnh viễn mọi bản ghi nội dung SEO (articles) của domain — không xóa trên WordPress.
     *
     * @return array{success: bool, deleted: int, message: string}
     */
    public function clear(Site $site): array
    {
        $siteId = (int) $site->getKey();

        $query = SeoArticle::query()
            ->withTrashed()
            ->where('site_id', $siteId);

        $count = (clone $query)->count();

        if ($count === 0) {
            return [
                'success' => true,
                'deleted' => 0,
                'message' => 'Domain chưa có bản ghi nội dung nào.',
            ];
        }

        $connection = (new SeoArticle())->getConnectionName();

        DB::connection($connection)->transaction(function () use ($query, $connection): void {
            $query->orderBy('id')->chunkById(100, function ($articles) use ($connection): void {
                $ids = $articles->pluck('id')->all();

                if ($ids !== []) {
                    DB::connection($connection)
                        ->table('seo_prompt_resultables')
                        ->where('prompt_resultable_type', SeoArticle::class)
                        ->whereIn('prompt_resultable_id', $ids)
                        ->delete();
                }

                foreach ($articles as $article) {
                    $article->forceDelete();
                }
            });
        });

        return [
            'success' => true,
            'deleted' => $count,
            'message' => sprintf('Đã xóa vĩnh viễn %d bản ghi nội dung SEO của domain.', $count),
        ];
    }

    public function countForSite(Site $site): int
    {
        return SeoArticle::query()
            ->withTrashed()
            ->where('site_id', (int) $site->getKey())
            ->count();
    }
}
