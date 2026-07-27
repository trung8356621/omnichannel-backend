<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordClusterStatus;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordCluster;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use InvalidArgumentException;

final class ApproveKeywordClustersHandler extends AbstractKeywordIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ApproveKeywordClustersCommand) {
            throw new InvalidArgumentException('Expected ApproveKeywordClustersCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $updated = [];
            $missing = [];

            foreach ($command->clusterRefs as $ref) {
                try {
                    $id = KeywordIntelligencePublicRef::resolveClusterIdStrict((string) $ref);
                } catch (InvalidArgumentException) {
                    $missing[] = $ref;

                    continue;
                }

                $cluster = SeoKeywordCluster::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $id)
                    ->first();

                if (! $cluster instanceof SeoKeywordCluster) {
                    $missing[] = $ref;

                    continue;
                }

                $cluster->status = $command->approve
                    ? KeywordClusterStatus::Approved->value
                    : KeywordClusterStatus::Excluded->value;
                $cluster->save();
                $updated[] = $cluster->public_ref;
            }

            if ($updated === []) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::NOT_FOUND,
                    'No matching clusters found.',
                    errors: $missing !== [] ? ['cluster_refs' => $missing] : [],
                );
            }

            return ContentProjectActionResult::ok(
                $command->approve ? KeywordIntelligenceActionCodes::CLUSTERS_APPROVED : KeywordIntelligenceActionCodes::CLUSTERS_EXCLUDED,
                $command->approve ? 'Clusters approved.' : 'Clusters excluded.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'updated_cluster_refs' => $updated,
                    'missing_cluster_refs' => $missing,
                ],
            );
        });
    }
}
