<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Enums\Gsc\GscOpportunityStatus;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\IgnoreGscOpportunityCommand;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class IgnoreGscOpportunityHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof IgnoreGscOpportunityCommand) {
            throw new InvalidArgumentException('Expected IgnoreGscOpportunityCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $opportunity = $this->resolveOpportunity($command->opportunityRef, $property);

            $opportunity->status = GscOpportunityStatus::Ignored;
            $opportunity->reviewed_by = $actor->actorId;
            $opportunity->reviewed_at = now();
            $opportunity->save();

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::OPPORTUNITY_IGNORED,
                'GSC opportunity ignored.',
                metadata: ['opportunity_ref' => $opportunity->public_ref],
            );
        });
    }
}
