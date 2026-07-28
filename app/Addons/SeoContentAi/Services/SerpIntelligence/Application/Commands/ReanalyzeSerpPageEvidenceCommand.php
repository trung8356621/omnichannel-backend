<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ReanalyzeSerpPageEvidenceCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $pageEvidenceRef,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.reanalyze_page_evidence';
    }
}
