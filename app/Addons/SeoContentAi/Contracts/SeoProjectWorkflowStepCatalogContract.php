<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Contracts;

use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoTask;

/**
 * Narrow catalog surface for article-pipeline start-step resolution / tests.
 */
interface SeoProjectWorkflowStepCatalogContract
{
    public function resolveSeoTaskForStepRetry(SeoProjectTask $projectTask): ?SeoTask;

    public function firstPromptNodeIdForKind(SeoProjectTask $projectTask, string $kind): ?string;

    /**
     * @return array<string, mixed>|null
     */
    public function findStep(SeoProjectTask $projectTask, string $nodeId): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listRerunnableSteps(SeoProjectTask $projectTask): array;
}
