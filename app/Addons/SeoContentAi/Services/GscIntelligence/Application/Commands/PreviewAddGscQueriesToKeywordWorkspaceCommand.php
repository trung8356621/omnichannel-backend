<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class PreviewAddGscQueriesToKeywordWorkspaceCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>  $queryRefs
     */
    public function __construct(
        public readonly string $propertyRef,
        public readonly string $workspaceRef,
        public readonly array $queryRefs = [],
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.preview_add_queries';
    }
}
