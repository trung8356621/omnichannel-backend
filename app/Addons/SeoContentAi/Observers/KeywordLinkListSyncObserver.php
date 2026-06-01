<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Observers;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\DomainLinkListKeywordSyncService;

final class KeywordLinkListSyncObserver
{
    private ?string $previousPhrase = null;

    public function updating(Keyword $keyword): void
    {
        if ($keyword->type !== Keyword::TYPE_FOCUS) {
            return;
        }

        if ($keyword->isDirty('phrase')) {
            $this->previousPhrase = trim((string) $keyword->getOriginal('phrase'));
        }
    }

    public function saved(Keyword $keyword): void
    {
        if ($keyword->type !== Keyword::TYPE_FOCUS) {
            return;
        }

        $service = app(DomainLinkListKeywordSyncService::class);
        $siteId = (int) ($keyword->site_id ?? 0);

        if ($this->previousPhrase !== null && $this->previousPhrase !== '') {
            $service->removeLinkFromDomainContext($siteId, $this->previousPhrase);
            $this->previousPhrase = null;
        }

        $phrase = trim((string) ($keyword->phrase ?? ''));
        $targetUrl = trim((string) ($keyword->target_url ?? ''));

        if ($phrase === '' || $targetUrl === '') {
            if ($phrase !== '') {
                $service->removeLinkFromDomainContext($siteId, $phrase);
            }

            return;
        }

        $service->upsertLinkInDomainContext($siteId, $phrase, $targetUrl);
    }

    public function deleted(Keyword $keyword): void
    {
        if ($keyword->type !== Keyword::TYPE_FOCUS) {
            return;
        }

        $phrase = trim((string) ($keyword->phrase ?? ''));
        if ($phrase === '') {
            return;
        }

        app(DomainLinkListKeywordSyncService::class)->removeLinkFromDomainContext(
            (int) ($keyword->site_id ?? 0),
            $phrase,
        );
    }
}
