<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class AddGscQueriesToKeywordWorkspaceCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly string $workspaceRef,
        public readonly array $queryRefs = [],
        public readonly bool $keepDuplicates = false
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.add_queries_to_workspace';
    }
}
