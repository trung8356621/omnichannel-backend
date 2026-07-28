<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class AddSerpFeatureKeywordsCommand implements ContentProjectCommand
{
    /** @param list<string> $featureRefs */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $snapshotRef,
        public readonly array $featureRefs,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.add_feature_keywords';
    }
}
