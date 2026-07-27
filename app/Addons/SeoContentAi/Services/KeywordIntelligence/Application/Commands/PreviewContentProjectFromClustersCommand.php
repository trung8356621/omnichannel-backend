<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class PreviewContentProjectFromClustersCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>  $clusterRefs
     * @param  array<string, mixed>  $projectAttributes
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $clusterRefs,
        public readonly array $projectAttributes = [],
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.preview_convert';
    }
}
