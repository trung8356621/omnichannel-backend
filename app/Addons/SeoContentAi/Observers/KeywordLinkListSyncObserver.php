<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Observers;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\DomainLinkListKeywordSyncService;
use App\Addons\SeoContentAi\Services\KeywordPhraseUpdateService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;

final class KeywordLinkListSyncObserver
{
    private ?string $previousPhrase = null;

    public function updating(Keyword $keyword): void
    {
        if ($keyword->isDirty('phrase')) {
            $this->previousPhrase = trim((string) $keyword->getOriginal('phrase'));
        }
    }

    public function saved(Keyword $keyword): void
    {
        if ($this->previousPhrase !== null && $this->previousPhrase !== '') {
            app(KeywordPhraseUpdateService::class)->propagate($keyword, $this->previousPhrase);
        }

        if ($keyword->type !== Keyword::TYPE_NORMAL) {
            $this->previousPhrase = null;

            return;
        }

        $service = app(DomainLinkListKeywordSyncService::class);
        $siteId = (int) (SeoAccessControl::globalSiteId() ?? $keyword->resolveSiteId() ?? 0);

        if ($this->previousPhrase !== null && $this->previousPhrase !== '' && $siteId > 0) {
            $service->removeLinkFromDomainContext($siteId, $this->previousPhrase);
            $this->previousPhrase = null;
        }

        $phrase = trim((string) ($keyword->phrase ?? ''));
        $targetUrl = trim((string) ($keyword->targetUrlForSite($siteId) ?? ''));

        if ($siteId <= 0 || $phrase === '') {
            return;
        }

        if ($targetUrl === '') {
            $service->removeLinkFromDomainContext($siteId, $phrase);

            return;
        }

        $service->upsertLinkInDomainContext($siteId, $phrase, $targetUrl);
    }

    public function deleted(Keyword $keyword): void
    {
        if ($keyword->type !== Keyword::TYPE_NORMAL) {
            return;
        }

        $phrase = trim((string) ($keyword->phrase ?? ''));
        $siteId = (int) ($keyword->resolveSiteId() ?? 0);
        if ($phrase === '' || $siteId <= 0) {
            return;
        }

        app(DomainLinkListKeywordSyncService::class)->removeLinkFromDomainContext($siteId, $phrase);
    }
}
