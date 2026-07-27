<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Agent\Mcp;

use App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanGateway;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;

/**
 * MCP tool catalog — built from canonical (core + enabled extension) write
 * caps + hardcoded read schemas.
 */
final class ContentProjectMcpToolCatalog
{
    /** @var list<string> */
    private const EXCLUDED_WRITE = [
        'content_project.sync_items',
    ];

    public function __construct(
        private readonly CanonicalCapabilityRegistry $registry,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listTools(): array
    {
        $tools = [];

        foreach ($this->registry->all() as $cap) {
            $name = (string) ($cap['name'] ?? '');
            if ($name === '' || in_array($name, self::EXCLUDED_WRITE, true)) {
                continue;
            }

            if (! $this->registry->isAgentWriteExposed($name)) {
                continue;
            }

            $schema = ContentProjectCapabilityRegistry::buildJsonSchema($cap);

            $toolName = $name === 'content_project.rerun' ? 'content_project.rerun_items' : $name;

            $tools[] = [
                'name' => $toolName,
                'description' => (string) ($cap['description'] ?? $toolName),
                'inputSchema' => $schema,
            ];
        }

        foreach ($this->readToolDefinitions() as $readTool) {
            $tools[] = $readTool;
        }

        foreach ($this->planToolDefinitions() as $planTool) {
            $tools[] = $planTool;
        }

        return $tools;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readToolDefinitions(): array
    {
        return [
            $this->readTool('content_project.list_projects', 'List content projects for site context.', []),
            $this->readTool('content_project.get_project', 'Get a content project by project_ref.', ['project_ref']),
            $this->readTool('content_project.list_items', 'List items for a project.', ['project_ref']),
            $this->readTool('content_project.get_item', 'Get a single item by item_ref.', ['item_ref']),
            $this->readTool('content_project.get_status', 'Get lifecycle status and allowed capabilities.', ['project_ref']),
            $this->readTool('content_project.get_publishing_queue', 'Get publishing queue for a project.', ['project_ref']),
            $this->readTool('content_project.get_timeline', 'Get business timeline for a project.', ['project_ref']),
            $this->readTool('content_project.get_daily_report', 'Get daily ops report.', []),
            $this->readTool('content_project.get_site_health', 'Get site health snapshot.', []),
            $this->readTool('content_project.get_operation', 'Get operation log entry by operation_ref.', ['operation_ref']),

            // Keyword Intelligence — additive read surface.
            $this->readTool('keyword_intelligence.list_workspaces', 'List keyword workspaces for site context.', []),
            $this->readTool('keyword_intelligence.get_workspace', 'Get a keyword workspace by workspace_ref.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.list_keywords', 'List keywords in a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.list_clusters', 'List keyword clusters in a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.get_topical_map', 'Get latest topical map version for a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.get_cannibalization', 'Get cannibalization risks for a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.get_analysis_operation', 'Get keyword analysis operation by operation_ref.', ['operation_ref']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planToolDefinitions(): array
    {
        $defs = [
            ['content_project.plan', 'Create agent plan draft from objective.', ['objective']],
            ['content_project.confirm_plan', 'Confirm plan before execution.', ['plan_ref']],
            ['content_project.start_plan', 'Start confirmed plan execution.', ['plan_ref']],
            ['content_project.pause_plan', 'Pause running plan.', ['plan_ref']],
            ['content_project.resume_plan', 'Resume paused plan.', ['plan_ref']],
            ['content_project.cancel_plan', 'Cancel plan and pending steps.', ['plan_ref']],
            ['content_project.get_agent_plan', 'Get plan by plan_ref.', ['plan_ref']],
            ['content_project.list_agent_plans', 'List recent agent plans.', []],
            ['content_project.retry_plan_step', 'Retry failed plan step.', ['plan_ref', 'step_ref']],
            ['content_project.get_agent_policy', 'Preview automation policy for tenant/site.', []],
            ['content_project.approve_agent_action', 'Approve pending agent action.', ['approval_ref']],
            ['content_project.reject_agent_action', 'Reject pending agent approval.', ['approval_ref']],
            ['content_project.list_pending_approvals', 'List pending approvals.', []],
        ];

        $tools = [];
        foreach ($defs as [$name, $description, $required]) {
            $properties = [
                'objective' => ['type' => 'string'],
                'plan_ref' => ['type' => 'string'],
                'step_ref' => ['type' => 'string'],
                'approval_ref' => ['type' => 'string'],
                'state_fingerprint' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
                'constraints' => ['type' => 'object'],
            ];

            $tools[] = [
                'name' => $name,
                'description' => $description,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ];
        }

        return $tools;
    }

    public function isPlanTool(string $name): bool
    {
        return in_array($name, ContentProjectAgentPlanGateway::PLAN_TOOLS, true);
    }

    /**
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function readTool(string $name, string $description, array $required): array
    {
        $properties = [];
        foreach (['project_ref', 'item_ref', 'operation_ref', 'date', 'workspace_ref'] as $field) {
            $properties[$field] = ['type' => 'string'];
        }

        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];
    }
}
