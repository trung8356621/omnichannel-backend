<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Observers;

use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\KeywordPhraseUpdateService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;

/**
 * Technical invariants only — domain link list sync owned by Automation Rule
 * on keyword.saved → keyword.domain_link_list.sync.
 */
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
        if (! \App\Addons\SeoContentAi\Support\KeywordSyncIsolation::allowsKeywordObserverSync()) {
            $this->previousPhrase = null;

            return;
        }

        if ($this->previousPhrase !== null && $this->previousPhrase !== '') {
            app(KeywordPhraseUpdateService::class)->propagate($keyword, $this->previousPhrase);
        }

        $previousPhrase = $this->previousPhrase;
        $this->previousPhrase = null;

        if ($keyword->type !== Keyword::TYPE_NORMAL) {
            return;
        }

        $siteId = (int) (SeoAccessControl::globalSiteId() ?? $keyword->resolveSiteId() ?? 0);
        $phrase = trim((string) ($keyword->phrase ?? ''));
        if ($siteId <= 0 || $phrase === '') {
            return;
        }

        $targetUrl = trim((string) ($keyword->targetUrlForSite($siteId) ?? ''));
        $operation = $targetUrl === '' ? 'remove' : 'upsert';

        app(BusinessHookEmitter::class)->keywordSaved($keyword, [
            'keyword_id' => (int) $keyword->id,
            'site_id' => $siteId,
            'phrase' => $phrase,
            'target_url' => $targetUrl,
            'previous_phrase' => (string) ($previousPhrase ?? ''),
            'operation' => $operation,
        ]);
    }

    public function deleted(Keyword $keyword): void
    {
        if (! \App\Addons\SeoContentAi\Support\KeywordSyncIsolation::allowsKeywordObserverSync()) {
            return;
        }

        if ($keyword->type !== Keyword::TYPE_NORMAL) {
            return;
        }

        $phrase = trim((string) ($keyword->phrase ?? ''));
        $siteId = (int) ($keyword->resolveSiteId() ?? 0);
        if ($phrase === '' || $siteId <= 0) {
            return;
        }

        app(BusinessHookEmitter::class)->keywordSaved($keyword, [
            'keyword_id' => (int) $keyword->id,
            'site_id' => $siteId,
            'phrase' => $phrase,
            'target_url' => '',
            'previous_phrase' => '',
            'operation' => 'remove',
        ]);
    }
}
