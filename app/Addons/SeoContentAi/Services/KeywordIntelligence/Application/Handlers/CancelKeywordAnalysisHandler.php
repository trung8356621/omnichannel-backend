<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordAnalysisOperation;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CancelKeywordAnalysisCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use InvalidArgumentException;

final class CancelKeywordAnalysisHandler extends AbstractKeywordIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CancelKeywordAnalysisCommand) {
            throw new InvalidArgumentException('Expected CancelKeywordAnalysisCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $opId = KeywordIntelligencePublicRef::resolveOperationIdStrict($command->operationRef);
            $operation = SeoKeywordAnalysisOperation::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $opId)
                ->first();

            if (! $operation instanceof SeoKeywordAnalysisOperation) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::NOT_FOUND,
                    'Analysis operation not found.',
                );
            }

            if (in_array((string) $operation->status, ['completed', 'failed', 'cancelled'], true)) {
                return ContentProjectActionResult::ok(
                    KeywordIntelligenceActionCodes::ANALYSIS_CANCELLED,
                    'Operation already finished.',
                    metadata: ['operation_ref' => $operation->public_ref, 'status' => $operation->status],
                );
            }

            $operation->cancel_requested = true;
            if ($operation->isFillable('cancel_requested') || array_key_exists('cancel_requested', $operation->getAttributes()) || true) {
                $operation->setAttribute('cancel_requested', true);
            }
            $operation->status = 'cancelled';
            $operation->result_code = KeywordIntelligenceActionCodes::ANALYSIS_CANCELLED;
            $operation->save();

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::ANALYSIS_CANCELLED,
                'Analysis cancellation requested.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'operation_ref' => $operation->public_ref,
                ],
            );
        });
    }
}
