<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CollectSerpSnapshotsCommand implements ContentProjectCommand
{
    /** @param list<string> $queryRefs */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $queryRefs,
        public readonly ?string $providerKey = null,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.collect';
    }
}
