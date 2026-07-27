<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\StopProjectExecutionCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\RunEngine\ContentProjectRunEngine;
use App\Addons\SeoContentAi\Support\RunEngine\ContentProjectRunEngineFeature;
use InvalidArgumentException;

final class StopProjectExecutionHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectRunEngine $runEngine,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof StopProjectExecutionCommand) {
            throw new InvalidArgumentException('Expected StopProjectExecutionCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $run = $this->resolveRun($projectId, $command->executionRef);
            if (! $run instanceof SeoProjectRun) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No active execution found.',
                    $projectId,
                );
            }

            if ((string) $run->status !== SeoProjectRun::STATUS_RUNNING) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::LIFECYCLE_INVALID,
                    'Execution is not running.',
                    $projectId,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                        'status' => (string) $run->status,
                    ],
                );
            }

            $reason = $command->reason ?? 'Stopped by user.';

            if (ContentProjectRunEngineFeature::enabledFor($run)) {
                $this->runEngine->requestStop(
                    $run,
                    $actor->actorId,
                    $reason,
                );
            } else {
                $run->forceFill([
                    'status' => SeoProjectRun::STATUS_STOPPING,
                ])->saveQuietly();
            }

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::EXECUTION_STOPPED,
                'Execution stopping.',
                $projectId,
                metadata: [
                    'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                    'reason' => $reason,
                    'status' => (string) ($run->fresh()?->status ?? SeoProjectRun::STATUS_STOPPING),
                ],
            );
        });
    }

    private function resolveRun(int $projectId, string|int|null $executionRef): ?SeoProjectRun
    {
        if ($executionRef !== null) {
            $runId = $this->resolveExecutionId($executionRef);

            return SeoProjectRun::query()
                ->where('project_id', $projectId)
                ->whereKey($runId)
                ->first();
        }

        return SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->where('status', SeoProjectRun::STATUS_RUNNING)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveExecutionId(string|int $executionRef): int
    {
        if (is_int($executionRef) || ctype_digit((string) $executionRef)) {
            return (int) $executionRef;
        }

        return ContentProjectPublicRef::decodeExecution((string) $executionRef);
    }
}
