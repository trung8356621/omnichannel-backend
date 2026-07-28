<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CancelSerpCollectionCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $operationRef,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.cancel_collection';
    }
}
