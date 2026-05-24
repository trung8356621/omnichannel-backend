<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;

final class KeywordFocusAttach
{
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
