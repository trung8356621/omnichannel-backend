<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Enums\KeywordMetaKey;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\KeywordMeta;
use App\Addons\SeoContentAi\Models\KeywordTag;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\KeywordMetaRepository;
use App\Addons\SeoContentAi\Services\KeywordPersistenceService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use Illuminate\Support\Collection;

final class KeywordFocusAttach
{
    public static function syncMainKeyword(SeoArticle $article, int $siteId, int $_userId, string $phrase): void
    {
        $phrase = Keyword::decodePhrase(trim($phrase));
        $articleId = (int) $article->id;

        if ($phrase === '') {
            $article->articleMetas()->where('meta_key', 'seo_focus_keyword')->delete();
            self::clearMainArticleMetaForArticle($articleId);

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_focus_keyword'],
            ['meta_value' => $phrase],
        );

        self::attachMainKeyword($article, $siteId, $phrase);
    }

    public static function attachMainKeyword(SeoArticle $article, int $siteId, string $phrase): ?int
    {
        $phrase = Keyword::decodePhrase($phrase);
        if ($phrase === '') {
            return null;
        }

        $metaRepository = app(KeywordMetaRepository::class);
        $persistence = app(KeywordPersistenceService::class);
        $permalink = trim(app(WordPressArticleContentService::class)->resolvePermalink($article));
        $targetUrl = $permalink !== '' ? $permalink : null;

        $keyword = $persistence->upsert(
            $phrase,
            Keyword::TYPE_NORMAL,
            $siteId,
            $targetUrl,
            targetArticleId: (int) $article->id,
        );

        if ($keyword === null) {
            return null;
        }

        $keywordId = (int) $keyword->id;
        $persistence->mergeSuffixTruncatedKeywords($keyword, $siteId);

        self::clearMainArticleMetaForArticle((int) $article->id, exceptKeywordId: $keywordId);
        $metaRepository->setMainArticleId($keywordId, (int) $article->id);

        return $keywordId;
    }

    public static function syncFocusKeywordsFromArticles(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        $synced = 0;
        $userId = (int) (auth()->id() ?? 0);

        SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereHas('articleMetas', static fn ($query) => $query
                ->where('meta_key', 'seo_focus_keyword')
                ->whereNotNull('meta_value')
                ->where('meta_value', '!=', ''))
            ->with(['articleMetas'])
            ->orderBy('id')
            ->chunkById(100, function ($articles) use ($siteId, $userId, &$synced): void {
                foreach ($articles as $article) {
                    if (! $article instanceof SeoArticle) {
                        continue;
                    }

                    $phrase = trim((string) ($article->articleMetas
                        ->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''));

                    if ($phrase === '') {
                        continue;
                    }

                    self::syncMainKeyword($article, $siteId, $userId, $phrase);
                    $synced++;
                }
            });

        return $synced;
    }

    public static function isFocusKeywordForSite(Keyword $keyword, int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $mainArticleId = app(KeywordMetaRepository::class)->getMainArticleId((int) $keyword->id);
        if ($mainArticleId === null) {
            return false;
        }

        return SeoArticle::query()
            ->whereKey($mainArticleId)
            ->where('site_id', $siteId)
            ->exists();
    }

    public static function phraseMatchesFocusOnSite(Keyword $keyword, int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $phraseNorm = mb_strtolower(Keyword::decodePhrase((string) $keyword->phrase));
        if ($phraseNorm === '') {
            return false;
        }

        return SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereHas('articleMetas', static fn ($query) => $query
                ->where('meta_key', 'seo_focus_keyword')
                ->whereRaw('LOWER(meta_value) = ?', [$phraseNorm]))
            ->exists();
    }

    private static function clearMainArticleMetaForArticle(int $articleId, ?int $exceptKeywordId = null): void
    {
        if ($articleId <= 0) {
            return;
        }

        $query = KeywordMeta::query()
            ->where('meta_key', KeywordMetaKey::MainArticleId->value)
            ->where('meta_value', (string) $articleId);

        if ($exceptKeywordId !== null && $exceptKeywordId > 0) {
            $query->where('keyword_id', '!=', $exceptKeywordId);
        }

        $staleKeywordIds = $query->pluck('keyword_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $query->delete();

        if ($staleKeywordIds !== []) {
            KeywordOrphanCleanup::deleteUnusedByIds($staleKeywordIds);
        }
    }
}
