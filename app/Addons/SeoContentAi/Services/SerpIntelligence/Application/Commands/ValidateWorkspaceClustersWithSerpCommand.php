<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ValidateWorkspaceClustersWithSerpCommand implements ContentProjectCommand
{
    /** @param list<string>|null $clusterRefs */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly ?array $clusterRefs = null,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.validate_workspace_clusters';
    }
}
