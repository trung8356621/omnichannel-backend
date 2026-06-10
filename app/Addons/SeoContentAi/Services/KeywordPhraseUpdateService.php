<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\ArticleKeyword;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoArticleLink;
use Illuminate\Support\Facades\DB;

final class KeywordPhraseUpdateService
{
    public function __construct(
        private readonly ArticleWordPressSyncFlagService $syncFlags,
    ) {}

    public function propagate(Keyword $keyword, string $previousPhrase): void
    {
        $previousPhrase = trim($previousPhrase);
        $newPhrase = trim((string) $keyword->phrase);
        if ($previousPhrase === '' || $newPhrase === '' || $previousPhrase === $newPhrase) {
            return;
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($keyword, $previousPhrase, $newPhrase): void {
            if ($keyword->type === Keyword::TYPE_FOCUS) {
                $this->updateMainKeywordMeta($keyword, $newPhrase);

                return;
            }

            if ($keyword->type === Keyword::TYPE_INTERNAL) {
                $this->replaceInternalLinkAnchors($keyword, $previousPhrase, $newPhrase);
            }
        });
    }

    private function updateMainKeywordMeta(Keyword $keyword, string $newPhrase): void
    {
        $articleIds = ArticleKeyword::query()
            ->where('keyword_id', $keyword->id)
            ->where('is_main', true)
            ->pluck('article_id');

        foreach ($articleIds as $articleId) {
            $article = SeoArticle::query()->find((int) $articleId);
            $article?->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_focus_keyword'],
                ['meta_value' => $newPhrase],
            );
        }
    }

    private function replaceInternalLinkAnchors(
        Keyword $keyword,
        string $previousPhrase,
        string $newPhrase,
    ): void {
        $links = SeoArticleLink::query()
            ->where('keyword_id', $keyword->id)
            ->where('type', 'internal')
            ->get();

        foreach ($links->groupBy('article_id') as $articleId => $articleLinks) {
            $article = SeoArticle::query()->find((int) $articleId);
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $urls = $articleLinks
                ->pluck('url')
                ->map(static fn (mixed $url): string => trim((string) $url))
                ->filter()
                ->values()
                ->all();

            $body = trim((string) ($article->body ?? ''));
            if ($body === '') {
                $body = trim((string) $article->articleMetas()
                    ->where('meta_key', 'wp_post_content')
                    ->value('meta_value'));
            }

            $updatedBody = $this->replaceAnchorsInHtml($body, $urls, $previousPhrase, $newPhrase);
            if ($updatedBody !== $body && $updatedBody !== '') {
                $article->forceFill(['body' => $updatedBody])->save();
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_content'],
                    ['meta_value' => $updatedBody],
                );
                $this->syncFlags->markLocalEditPending($article);
            }
        }

        SeoArticleLink::query()
            ->where('keyword_id', $keyword->id)
            ->where('type', 'internal')
            ->update(['anchor_text' => $newPhrase]);
    }

    /**
     * @param  list<string>  $urls
     */
    public function replaceAnchorsInHtml(
        string $html,
        array $urls,
        string $previousPhrase,
        string $newPhrase,
    ): string {
        if ($html === '' || $urls === []) {
            return $html;
        }

        $normalizedUrls = array_map(
            static fn (string $url): string => mb_strtolower(rtrim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '/')),
            $urls,
        );

        $result = preg_replace_callback(
            '/(<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']+)\2[^>]*>)([\s\S]*?)(<\/a>)/iu',
            static function (array $matches) use ($normalizedUrls, $previousPhrase, $newPhrase): string {
                $href = mb_strtolower(rtrim(html_entity_decode(trim((string) $matches[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), '/'));
                $anchor = trim(html_entity_decode(strip_tags((string) $matches[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if (! in_array($href, $normalizedUrls, true) || mb_strtolower($anchor) !== mb_strtolower($previousPhrase)) {
                    return (string) $matches[0];
                }

                return (string) $matches[1]
                    .htmlspecialchars($newPhrase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    .(string) $matches[5];
            },
            $html,
        );

        return is_string($result) ? $result : $html;
    }
}
