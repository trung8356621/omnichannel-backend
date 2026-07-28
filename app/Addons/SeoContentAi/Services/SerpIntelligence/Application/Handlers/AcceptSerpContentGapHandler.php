<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Enums\Serp\SerpContentGapStatus;
use App\Addons\SeoContentAi\Models\SeoSerpContentGap;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\AcceptSerpContentGapCommand;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use InvalidArgumentException;

final class AcceptSerpContentGapHandler extends AbstractSerpIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AcceptSerpContentGapCommand) {
            throw new InvalidArgumentException('Expected AcceptSerpContentGapCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $gap = $this->resolveGap($command->gapRef);
            $gap->status = SerpContentGapStatus::Accepted;
            $gap->reviewed_at = now();
            $gap->save();

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::GAP_ACCEPTED,
                'Content gap accepted.',
                metadata: ['workspace_ref' => $workspace->public_ref, 'gap_ref' => $gap->public_ref],
            );
        });
    }

    protected function resolveGap(string $gapRef): SeoSerpContentGap
    {
        $id = KeywordIntelligencePublicRef::resolveSerpContentGapIdStrict($gapRef);
        $gap = SeoSerpContentGap::query()->find($id);
        if (! $gap instanceof SeoSerpContentGap) {
            throw new InvalidArgumentException('Content gap not found.');
        }

        return $gap;
    }
}
