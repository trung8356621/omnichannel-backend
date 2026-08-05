<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\ContentProject\Publishing\PublishDueItemOutcome;
use App\Addons\SeoContentAi\Services\ContentProject\Publishing\PublishDueItemService;
use InvalidArgumentException;

final class RetryProjectItemPublishingHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly PublishDueItemService $dueItemService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RetryProjectItemPublishingCommand) {
            throw new InvalidArgumentException('Expected RetryProjectItemPublishingCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($projectId, $itemIds): ContentProjectActionResult {
                    $outcomes = $this->dueItemService->executeMany($itemIds, PublishDueItemService::TRIGGER_RETRY_NOW);
                    $summary = $this->summarize($outcomes);

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_PUBLISH_RETRIED,
                        $summary['message'],
                        $projectId,
                        $itemIds,
                        metadata: $summary,
                    );
                },
            );
        });
    }

    /**
     * @param  list<PublishDueItemOutcome>  $outcomes
     * @return array<string, mixed>
     */
    private function summarize(array $outcomes): array
    {
        $started = 0;
        $retryWait = 0;
        $failed = 0;
        $skipped = 0;
        $published = 0;

        foreach ($outcomes as $o) {
            match ($o->outcome) {
                PublishDueItemOutcome::AWAITING_DELIVERY => $started++,
                PublishDueItemOutcome::PUBLISHED => $published++,
                PublishDueItemOutcome::RETRY_WAIT => $retryWait++,
                PublishDueItemOutcome::FAILED, PublishDueItemOutcome::ERROR => $failed++,
                default => $skipped++,
            };
        }

        $total = count($outcomes);

        return [
            'affected_count' => $total,
            'started' => $started,
            'published' => $published,
            'retry_wait' => $retryWait,
            'failed' => $failed,
            'skipped' => $skipped,
            'outcomes' => array_map(static fn (PublishDueItemOutcome $o): array => $o->toLogArray(), $outcomes),
            'message' => sprintf(
                'Đã thử lại %d bài: %d đang xuất bản, %d chờ thử lại, %d lỗi%s.',
                $total,
                $started + $published,
                $retryWait,
                $failed,
                $skipped > 0 ? ", {$skipped} bỏ qua" : '',
            ),
        ];
    }
}
