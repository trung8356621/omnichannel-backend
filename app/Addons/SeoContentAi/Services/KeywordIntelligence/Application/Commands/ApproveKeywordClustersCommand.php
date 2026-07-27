<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ApproveKeywordClustersCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>  $clusterRefs
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $clusterRefs,
        public readonly bool $approve = true,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.approve_clusters';
    }
}
