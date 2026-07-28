<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class BuildTopicalMapCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>|null  $approvedClusterRefs
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly ?int $maxDepth = null,
        public readonly ?string $mode = null,
        public readonly bool $includeReviewedClusters = false,
        public readonly ?array $approvedClusterRefs = null,
        public readonly bool $preserveManualTopics = true,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.build_topical_map';
    }
}
