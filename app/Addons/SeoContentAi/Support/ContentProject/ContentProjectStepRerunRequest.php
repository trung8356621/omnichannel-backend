<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\ContentProject;

use App\Addons\SeoContentAi\Enums\ContentProjectStepRerunMode;

/**
 * Typed request cho «Chạy lại bằng cấu hình hiện tại».
 */
final class ContentProjectStepRerunRequest
{
    public function __construct(
        public readonly int $projectRunId,
        public readonly int $projectTaskId,
        public readonly ?int $articleId,
        public readonly string $targetNodeId,
        public readonly ?string $targetExecutionRole,
        public readonly ContentProjectStepRerunMode $mode,
        public readonly ?int $requestedBy,
        public readonly ?string $expectedArticleUpdatedAt = null,
        public readonly bool $allowPartialBulk = false,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $mode = ContentProjectStepRerunMode::tryFromMixed($payload['mode'] ?? null)
            ?? ContentProjectStepRerunMode::SingleStep;

        $articleId = isset($payload['article_id']) ? (int) $payload['article_id'] : null;
        if ($articleId !== null && $articleId <= 0) {
            $articleId = null;
        }

        $role = trim((string) ($payload['target_execution_role'] ?? ''));

        return new self(
            projectRunId: (int) ($payload['project_run_id'] ?? 0),
            projectTaskId: (int) ($payload['project_task_id'] ?? $payload['task_id'] ?? 0),
            articleId: $articleId,
            targetNodeId: trim((string) ($payload['target_node_id'] ?? '')),
            targetExecutionRole: $role !== '' ? $role : null,
            mode: $mode,
            requestedBy: isset($payload['requested_by']) ? (int) $payload['requested_by'] : null,
            expectedArticleUpdatedAt: isset($payload['expected_article_updated_at'])
                ? trim((string) $payload['expected_article_updated_at'])
                : null,
            allowPartialBulk: (bool) ($payload['allow_partial_bulk'] ?? false),
        );
    }
}
