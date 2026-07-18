<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Runtime;

use App\Addons\SeoContentAi\Automation\Contracts\ActionExecutionLoggerContract;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Enums\ActionRunStatus;
use App\Addons\SeoContentAi\Automation\Models\AutomationActionRun;
use App\Addons\SeoContentAi\Automation\Support\SensitivePayloadRedactor;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ActionExecutionLogger implements ActionExecutionLoggerContract
{
    public function __construct(
        private readonly SensitivePayloadRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function start(
        ActionContext $context,
        string $actionKey,
        ?string $entityType,
        ?int $entityId,
        array $input,
    ): void {
        try {
            AutomationActionRun::query()->updateOrCreate(
                ['execution_id' => $context->executionId],
                [
                    'correlation_id' => $context->correlationId,
                    'causation_id' => $context->causationId,
                    'action_key' => $actionKey,
                    'origin' => $context->origin,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'team_id' => $context->teamId,
                    'site_id' => $context->siteId,
                    'status' => ActionRunStatus::Running->value,
                    'attempt' => 1,
                    'idempotency_key' => is_string($context->metadata['idempotency_key'] ?? null)
                        ? (string) $context->metadata['idempotency_key']
                        : null,
                    'input_json' => $this->redactor->redact($input),
                    'started_at' => now(),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('automation.action_run.log_start_failed', [
                'execution_id' => $context->executionId,
                'action_key' => $actionKey,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function finish(string $executionId, ActionResult $result): void
    {
        try {
            $run = AutomationActionRun::query()->where('execution_id', $executionId)->first();
            if ($run === null) {
                return;
            }

            $run->update([
                'status' => $result->status->value,
                'output_json' => $this->redactor->redact($result->output),
                'warning_json' => $result->warnings !== [] ? $result->warnings : null,
                'error_json' => $result->error,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('automation.action_run.log_finish_failed', [
                'execution_id' => $executionId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
