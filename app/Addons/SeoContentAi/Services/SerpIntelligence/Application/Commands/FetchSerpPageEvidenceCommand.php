<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class FetchSerpPageEvidenceCommand implements ContentProjectCommand
{
    /** @param list<string> $resultRefs */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $snapshotRef,
        public readonly array $resultRefs = [],
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.fetch_page_evidence';
    }
}
