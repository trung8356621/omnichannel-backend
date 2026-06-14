<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\Keyword;

final class KeywordOrphanCleanup
{
    /**
     * @param  iterable<int, int|string>  $keywordIds
     */
    public static function deleteUnusedByIds(iterable $keywordIds): void
    {
        $ids = collect($keywordIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Keyword::query()
            ->whereKey($ids->all())
            ->get()
            ->each(function (Keyword $keyword): void {
                if ($keyword->parent_id !== null) {
                    return;
                }

                if (
                    $keyword->articleKeywords()->exists()
                    || $keyword->links()->exists()
                    || $keyword->children()->exists()
                ) {
                    return;
                }

                $keyword->delete();
            });
    }
}
