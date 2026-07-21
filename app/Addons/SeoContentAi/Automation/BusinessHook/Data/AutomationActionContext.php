<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Data;

use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationExecution;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\BusinessEvent;
use Illuminate\Database\Eloquent\Model;

final class AutomationActionContext
{
    /**
     * @param  array<string, mixed>  $previousOutputs
     * @param  array<string, mixed>  $subjectData
     */
    public function __construct(
        public readonly BusinessEvent $businessEvent,
        public readonly ?AutomationRule $rule,
        public readonly AutomationExecution $execution,
        public readonly ?Model $subject,
        public readonly array $subjectData,
        public readonly ?int $siteId,
        public readonly ?int $projectId,
        public readonly ?int $actorId,
        public readonly ?string $correlationId,
        public readonly int $automationDepth,
        public readonly array $previousOutputs = [],
        public readonly bool $dryRun = false,
        public readonly ?int $nodeExecutionId = null,
        public readonly ?string $nodeIdempotencyKey = null,
        public readonly ?string $nodeKey = null,
    ) {}
}
