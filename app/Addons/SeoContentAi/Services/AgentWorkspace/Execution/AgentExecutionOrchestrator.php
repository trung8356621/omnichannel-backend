<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Execution;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos\AgentExecutionCancellation;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos\AgentExecutionConfirmation;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos\AgentExecutionPreview;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRequest;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRetry;

interface AgentExecutionOrchestrator
{
    public function preview(AgentExecutionRequest $request): AgentExecutionPreview;

    public function execute(AgentExecutionRequest $request): AgentExecutionResult;

    public function confirm(AgentExecutionConfirmation $confirmation): AgentExecutionResult;

    public function cancel(AgentExecutionCancellation $cancellation): AgentExecutionResult;

    public function retry(AgentExecutionRetry $retry): AgentExecutionResult;
}
