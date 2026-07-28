<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ResolveGscOpportunityCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly string $opportunityRef,
        public readonly ?string $resolutionCode = null
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.resolve_opportunity';
    }
}
