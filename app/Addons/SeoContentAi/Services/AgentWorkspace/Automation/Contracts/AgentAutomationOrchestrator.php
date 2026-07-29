<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data\AgentAutomationControlRequest;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionRequest;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionResult;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data\AgentAutomationPreview;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data\AgentAutomationRunRequest;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data\AgentAutomationRunResult;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data\AgentAutomationUpdateRequest;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

interface AgentAutomationOrchestrator
{
    public function previewDefinition(
        AgentWorkspaceContext $context,
        AgentAutomationDefinitionRequest $request,
    ): AgentAutomationPreview;

    public function create(
        AgentWorkspaceContext $context,
        AgentAutomationDefinitionRequest $request,
        bool $explicitSave,
    ): AgentAutomationDefinitionResult;

    public function update(
        AgentWorkspaceContext $context,
        AgentAutomationUpdateRequest $request,
        bool $explicitSave,
    ): AgentAutomationDefinitionResult;

    public function control(
        AgentWorkspaceContext $context,
        AgentAutomationControlRequest $request,
    ): AgentAutomationDefinitionResult;

    public function runNow(
        AgentWorkspaceContext $context,
        AgentAutomationRunRequest $request,
    ): AgentAutomationRunResult;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(AgentWorkspaceContext $context): array;

    /**
     * @return array<string, mixed>|null
     */
    public function get(AgentWorkspaceContext $context, string $automationHashId): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function history(AgentWorkspaceContext $context, string $automationHashId, int $limit = 50): array;

    /**
     * @return array<string, mixed>
     */
    public function approveRun(
        AgentWorkspaceContext $context,
        string $approvalHashId,
        string $rawToken,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(AgentWorkspaceContext $context, string $automationHashId): array;
}
