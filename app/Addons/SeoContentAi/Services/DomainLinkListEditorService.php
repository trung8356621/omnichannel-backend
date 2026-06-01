<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\Keyword;
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
            ->where('site_id', $siteId)
            ->where('type', Keyword::TYPE_FOCUS)
            ->whereIn('phrase', $phrases)
            ->withCount(['articlesViaInternalLink as linked_articles_count'])
            ->get(['id', 'phrase', 'target_url'])
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
}
