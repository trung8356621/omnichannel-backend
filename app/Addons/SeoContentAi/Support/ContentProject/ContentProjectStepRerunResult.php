<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\ContentProject;

/**
 * @phpstan-type RerunResultArray array{
 *     success: bool,
 *     status: string,
 *     message: string,
 *     run_id: int,
 *     run_item_id: ?int,
 *     source_run_item_id: ?int,
 *     task_id: int,
 *     article_id: ?int,
 *     target_node_id: string,
 *     target_execution_role: ?string,
 *     rerun_mode: string,
 *     execution_type: string,
 *     node_ids: list<string>
 * }
 */
final class ContentProjectStepRerunResult
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_INVALID = 'invalid';

    /**
     * @param  list<string>  $nodeIds
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $message,
        public readonly int $runId,
        public readonly int $taskId,
        public readonly string $targetNodeId,
        public readonly ?string $targetExecutionRole,
        public readonly string $rerunMode,
        public readonly string $executionType = 'rerun',
        public readonly ?int $runItemId = null,
        public readonly ?int $sourceRunItemId = null,
        public readonly ?int $articleId = null,
        public readonly array $nodeIds = [],
    ) {}

    /**
     * @return RerunResultArray
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'message' => $this->message,
            'run_id' => $this->runId,
            'run_item_id' => $this->runItemId,
            'source_run_item_id' => $this->sourceRunItemId,
            'task_id' => $this->taskId,
            'article_id' => $this->articleId,
            'target_node_id' => $this->targetNodeId,
            'target_execution_role' => $this->targetExecutionRole,
            'rerun_mode' => $this->rerunMode,
            'execution_type' => $this->executionType,
            'node_ids' => $this->nodeIds,
        ];
    }
}
