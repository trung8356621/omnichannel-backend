<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoSerpFeature;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\AddSerpFeatureKeywordsCommand;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use InvalidArgumentException;

final class AddSerpFeatureKeywordsHandler extends AbstractSerpIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AddSerpFeatureKeywordsCommand) {
            throw new InvalidArgumentException('Expected AddSerpFeatureKeywordsCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $snapshot = $this->resolveSnapshot($command->snapshotRef);
            $added = [];

            foreach ($command->featureRefs as $featureRef) {
                $featureId = KeywordIntelligencePublicRef::resolveSerpFeatureIdStrict((string) $featureRef);
                $feature = SeoSerpFeature::query()
                    ->where('snapshot_id', $snapshot->id)
                    ->where('id', $featureId)
                    ->first();

                if (! $feature instanceof SeoSerpFeature) {
                    continue;
                }

                $metadata = is_array($feature->metadata) ? $feature->metadata : [];
                $metadata['promoted_to_keywords'] = true;
                $metadata['promoted_at'] = date('c');
                $feature->metadata = $metadata;
                $feature->save();
                $added[] = $feature->public_ref;
            }

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::FEATURE_KEYWORDS_ADDED,
                'Feature keywords queued.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'snapshot_ref' => $snapshot->public_ref,
                    'feature_refs' => $added,
                ],
            );
        });
    }
}
