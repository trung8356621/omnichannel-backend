<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Enums\Gsc\GscOpportunityStatus;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ApproveGscOpportunityCommand;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class ApproveGscOpportunityHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ApproveGscOpportunityCommand) {
            throw new InvalidArgumentException('Expected ApproveGscOpportunityCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $opportunity = $this->resolveOpportunity($command->opportunityRef, $property);

            $opportunity->status = GscOpportunityStatus::Accepted;
            $opportunity->reviewed_by = $actor->actorId;
            $opportunity->reviewed_at = now();
            $opportunity->save();

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::OPPORTUNITY_APPROVED,
                'GSC opportunity approved.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'opportunity_ref' => $opportunity->public_ref,
                    'reviewed' => true,
                ],
            );
        });
    }
}
