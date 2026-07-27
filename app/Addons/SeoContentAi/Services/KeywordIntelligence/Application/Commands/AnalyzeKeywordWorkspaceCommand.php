<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class AnalyzeKeywordWorkspaceCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly ?string $clusteringStrategy = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.analyze_workspace';
    }
}
