<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;

final class KeywordFocusAttach
{
    public static function syncMainKeyword(SeoArticle $article, int $siteId, int $userId, string $phrase): void
    {
        $phrase = trim($phrase);
        $article->loadMissing('keywords');

        $previousMainKeywordIds = $article->keywords
            ->filter(fn (Keyword $keyword): bool => (int) ($keyword->pivot->is_main ?? 0) === 1)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($phrase === '') {
            $article->articleMetas()->where('meta_key', 'seo_focus_keyword')->delete();
            $newMainKeywordId = null;
        } else {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_focus_keyword'],
                ['meta_value' => $phrase],
            );

            $newMainKeywordId = self::attachMainKeyword($article, $siteId, $userId, $phrase);
        }

        $detachedKeywordIds = array_values(array_filter(
            $previousMainKeywordIds,
            fn (int $keywordId): bool => $keywordId !== $newMainKeywordId,
        ));

        if ($detachedKeywordIds !== []) {
            $article->keywords()->detach($detachedKeywordIds);
            KeywordOrphanCleanup::deleteUnusedByIds($detachedKeywordIds);
        }

        $article->unsetRelation('keywords');
    }

    public static function attachMainKeyword(SeoArticle $article, int $siteId, int $userId, string $phrase): ?int
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return null;
        }

        $keyword = Keyword::query()->firstOrCreate(
            [
                'site_id' => $siteId,
                'phrase' => $phrase,
                'type' => Keyword::TYPE_FOCUS,
            ],
            [
                'user_id' => $userId,
            ]
        );

        $article->keywords()->syncWithoutDetaching([
            $keyword->id => [
                'weight' => 1.0,
                'is_main' => true,
            ],
        ]);

        return (int) $keyword->id;
    }
}
