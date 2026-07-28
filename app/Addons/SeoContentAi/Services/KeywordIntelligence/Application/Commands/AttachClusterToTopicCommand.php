<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class AttachClusterToTopicCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $topicRef,
        public readonly string $clusterRef,
        public readonly string $relationship = 'primary',
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.attach_cluster';
    }
}
