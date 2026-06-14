<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\KeywordPhraseMatcher;
use App\Models\Site;

final class DomainLinkListEditorService
{
    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
    ) {}

    /**
     * Link list của domain kèm số bài đã chèn link (anchor) tương ứng.
     *
     * @return list<array{
     *     text: string,
     *     href: string,
     *     target_url: string,
     *     keyword_id: int|null,
     *     article_count: int,
     *     can_insert: bool,
     * }>
     */
    public function forSite(Site|int|null $site): array
    {
        if ($site === null) {
            return [];
        }

        $site = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($site === null) {
            return [];
        }

        $siteId = (int) $site->getKey();
        $links = $this->promptContext->getForSite($site)['links'] ?? [];
        if ($links === []) {
            return [];
        }

        $phrases = [];
        foreach ($links as $row) {
            $phrase = trim((string) ($row['keyword'] ?? ''));
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        $keywordsByPhrase = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_NORMAL)
            ->whereIn('phrase', $phrases)
            ->with(['links' => static fn ($query) => $query->where('seo_links.site_id', $siteId)])
            ->withCount([
                'links as linked_articles_count' => static fn ($linkQuery) => $linkQuery
                    ->where('seo_links.site_id', $siteId)
                    ->whereNotNull('seo_links.source_article_id'),
            ])
            ->get(['id', 'phrase'])
            ->keyBy(fn (Keyword $keyword): string => mb_strtolower(trim((string) $keyword->phrase)));

        $items = [];

        foreach ($links as $row) {
            $phrase = trim((string) ($row['keyword'] ?? ''));
            $href = trim((string) ($row['link'] ?? ''));
            if ($phrase === '' || $href === '') {
                continue;
            }

            /** @var Keyword|null $keyword */
            $keyword = $keywordsByPhrase->get(mb_strtolower($phrase));

            $items[] = [
                'text' => $phrase,
                'href' => $href,
                'target_url' => $href,
                'keyword_id' => $keyword !== null ? (int) $keyword->id : null,
                'article_count' => (int) ($keyword->linked_articles_count ?? 0),
                'can_insert' => true,
            ];
        }

        return $items;
    }

    /**
     * Chỉ trả link domain khi cụm anchor có trong nội dung bài (giống gợi ý internal link).
     *
     * @return list<array{
     *     text: string,
     *     href: string,
     *     target_url: string,
     *     keyword_id: int|null,
     *     article_count: int,
     *     can_insert: bool,
     * }>
     */
    public function forArticle(SeoArticle $article, ?string $contentHtml = null): array
    {
        $items = $this->forSite($article->site);
        if ($items === []) {
            return [];
        }

        $plainText = $this->plainTextFromHtml($contentHtml ?? (string) ($article->body ?? ''));
        if ($plainText === '') {
            return [];
        }

        $filtered = [];
        foreach ($items as $item) {
            if ($this->textContainsPhrase($plainText, (string) $item['text'])) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function textContainsPhrase(string $text, string $phrase): bool
    {
        return KeywordPhraseMatcher::contains($text, $phrase);
    }
}
