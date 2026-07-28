<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ArchiveSerpQueriesCommand implements ContentProjectCommand
{
    /** @param list<string> $queryRefs */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $queryRefs,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.archive_queries';
    }
}
