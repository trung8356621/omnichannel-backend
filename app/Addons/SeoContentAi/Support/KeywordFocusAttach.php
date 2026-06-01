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

        foreach ($article->keywords as $keyword) {
            if ((int) ($keyword->pivot->is_main ?? 0) === 1) {
                $article->keywords()->updateExistingPivot($keyword->id, ['is_main' => false]);
            }
        }

        if ($phrase === '') {
            $article->articleMetas()->where('meta_key', 'seo_focus_keyword')->delete();

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_focus_keyword'],
            ['meta_value' => $phrase],
        );

        self::attachMainKeyword($article, $siteId, $userId, $phrase);
    }

    public static function attachMainKeyword(SeoArticle $article, int $siteId, int $userId, string $phrase): void
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return;
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
    }
}
