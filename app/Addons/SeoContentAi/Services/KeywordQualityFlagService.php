<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoLinkMap;

final class KeywordQualityFlagService
{
    public function __construct(
        private readonly KeywordMetaRepository $metaRepository,
    ) {}

    public function recomputeForLinkMap(SeoLinkMap $linkMap): void
    {
        $keyword = $linkMap->keyword;
        if (! $keyword instanceof Keyword) {
            return;
        }

        $this->recomputeForKeywordFromMaps((int) $keyword->id);
    }

    public function recomputeForKeywordFromMaps(int $keywordId): void
    {
        if ($keywordId <= 0) {
            return;
        }

        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            return;
        }

        $maps = SeoLinkMap::query()
            ->where('keyword_id', $keywordId)
            ->get(['anchor_text', 'context_before']);

        $flags = [];
        $phrase = Keyword::decodePhrase((string) $keyword->phrase);

        if ($this->isDangerPhrase($phrase)) {
            $flags[] = 'danger';
        }

        if ($this->isWarningPhrase($phrase)) {
            $flags[] = 'warning';
        }

        foreach ($maps as $map) {
            if (! $map instanceof SeoLinkMap) {
                continue;
            }

            $contextBefore = trim((string) ($map->context_before ?? ''));
            if ($this->isDangerContextBefore($contextBefore) && ! in_array('danger', $flags, true)) {
                $flags[] = 'danger';
            }
        }

        $this->metaRepository->setQualityFlags($keywordId, $flags);
    }

    public function recomputeForSite(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        $keywordIds = SeoLinkMap::query()
            ->whereHas('sourceArticle', static fn ($query) => $query->where('site_id', $siteId))
            ->distinct()
            ->pluck('keyword_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        foreach ($keywordIds as $keywordId) {
            $this->recomputeForKeywordFromMaps($keywordId);
        }

        return count($keywordIds);
    }

    public function isDangerPhrase(string $phrase): bool
    {
        return mb_strlen(trim($phrase)) < 3;
    }

    public function isWarningPhrase(string $phrase): bool
    {
        $words = preg_split('/\s+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) > 7;
    }

    public function isDangerContextBefore(string $contextBefore): bool
    {
        $contextBefore = trim($contextBefore);

        return $contextBefore === '' || mb_strlen($contextBefore) < 3;
    }
}
