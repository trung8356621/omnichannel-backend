<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities;

use App\Addons\SeoContentAi\Enums\ContentProjectLifecyclePhase;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\MoveProjectItemScheduleCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\StartReviewCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SyncContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\AnalyzeKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromClustersCommand;

/**
 * Agent capability registry — MCP/API Agent chỉ là adapter ngoài registry này.
 */
final class ContentProjectCapabilityRegistry
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return [
            $this->cap(
                'content_project.create',
                'Create a Content Project',
                CreateContentProjectCommand::class,
                'content_project.create',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'attributes' => ['type' => 'object', 'required' => true],
                    'tasksData' => ['type' => 'array', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'content_project.update',
                'Update Content Project metadata',
                UpdateContentProjectCommand::class,
                'content_project.update',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'attributes' => ['type' => 'object', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'content_project.sync_items',
                'Sync full tasks_data payload for a project',
                SyncContentProjectItemsCommand::class,
                'content_project.sync_items',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'tasks_data' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.add_items',
                'Add items to a Content Project',
                AddContentProjectItemsCommand::class,
                'content_project.add_items',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'items' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.update_item',
                'Update a single Content Project item',
                UpdateContentProjectItemCommand::class,
                'content_project.update_item',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'item_ref' => ['type' => 'string', 'required' => true],
                    'attributes' => ['type' => 'object', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'content_project.generate',
                'Generate / start AI workflow for project items',
                GenerateProjectItemsCommand::class,
                'content_project.generate',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                    'mode' => ['type' => 'string', 'enum' => ['full', 'test'], 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                    ContentProjectLifecyclePhase::Failed->value,
                    ContentProjectLifecyclePhase::Review->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.rerun',
                'Rerun AI workflow for selected items',
                RerunProjectItemsCommand::class,
                'content_project.rerun',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                    'mode' => ['type' => 'string', 'enum' => ['full', 'test'], 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Failed->value,
                    ContentProjectLifecyclePhase::Review->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.start_review',
                'Move items into review',
                StartReviewCommand::class,
                'content_project.start_review',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                    ContentProjectLifecyclePhase::Review->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.approve',
                'Approve items for scheduling/publishing',
                ApproveProjectItemsCommand::class,
                'content_project.approve',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.schedule',
                'Schedule items for Publishing Queue',
                ScheduleProjectItemsCommand::class,
                'content_project.schedule',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'scheduled_at' => ['type' => 'string', 'format' => 'date-time', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                    ContentProjectLifecyclePhase::Review->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.auto_schedule',
                'Auto-schedule many items by pattern',
                AutoScheduleProjectItemsCommand::class,
                'content_project.auto_schedule',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                    'options' => ['type' => 'object', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.unschedule',
                'Remove scheduled publish time from items',
                UnscheduleProjectItemsCommand::class,
                'content_project.unschedule',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.move_schedule',
                'Move scheduled publish time for items',
                MoveProjectItemScheduleCommand::class,
                'content_project.move_schedule',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'scheduled_at' => ['type' => 'string', 'format' => 'date-time', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.publish_now',
                'Queue immediate publish for items',
                PublishProjectItemsNowCommand::class,
                'content_project.publish_now',
                riskLevel: 'publish',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                    ContentProjectLifecyclePhase::Failed->value,
                ],
                confirmation: true,
            ),
            $this->cap(
                'content_project.retry_publish',
                'Retry failed publish for an item',
                RetryProjectItemPublishingCommand::class,
                'content_project.retry_publish',
                riskLevel: 'publish',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Failed->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.skip_publish',
                'Skip publish attempt for an item',
                SkipProjectItemPublishingCommand::class,
                'content_project.skip_publish',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Failed->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: true,
            ),
            $this->cap(
                'content_project.cancel_publish',
                'Cancel queued publish for an item',
                CancelProjectItemPublishingCommand::class,
                'content_project.cancel_publish',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: true,
            ),
            $this->cap(
                'content_project.archive',
                'Archive project and destroy AI Workspace',
                ArchiveContentProjectCommand::class,
                'content_project.archive',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'note' => ['type' => 'string', 'required' => false],
                    'confirm_waiting_publish' => ['type' => 'boolean', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'content_project.restore',
                'Restore archived project business flags (new workspace on generate)',
                RestoreContentProjectCommand::class,
                'content_project.restore',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: [ContentProjectLifecyclePhase::Archived->value],
                confirmation: true,
            ),

            // Keyword Intelligence — additive, không phase-gate theo ContentProjectLifecyclePhase.
            $this->cap(
                'keyword_intelligence.create_workspace',
                'Create a Keyword Intelligence workspace',
                CreateKeywordWorkspaceCommand::class,
                'keyword_intelligence.create_workspace',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'attributes' => ['type' => 'object', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.import_keywords',
                'Import keywords into a workspace',
                ImportKeywordsCommand::class,
                'keyword_intelligence.import_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keywords' => ['type' => 'array', 'required' => true],
                    'preview' => ['type' => 'boolean', 'required' => false],
                    'keep_duplicates' => ['type' => 'boolean', 'required' => false],
                    'source' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.analyze_workspace',
                'Run analysis pipeline for a workspace',
                AnalyzeKeywordWorkspaceCommand::class,
                'keyword_intelligence.analyze_workspace',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'clustering_strategy' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.approve_keywords',
                'Approve or reject keywords',
                ApproveKeywordsCommand::class,
                'keyword_intelligence.approve_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                    'approve' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.approve_clusters',
                'Approve or reject keyword clusters',
                ApproveKeywordClustersCommand::class,
                'keyword_intelligence.approve_clusters',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'cluster_refs' => ['type' => 'array', 'required' => true],
                    'approve' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.build_topical_map',
                'Build the topical map from clusters',
                BuildTopicalMapCommand::class,
                'keyword_intelligence.build_topical_map',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'max_depth' => ['type' => 'integer', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.preview_convert',
                'Preview converting clusters into a Content Project',
                PreviewContentProjectFromClustersCommand::class,
                'keyword_intelligence.preview_convert',
                riskLevel: 'write',
                idempotencySupport: false,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'cluster_refs' => ['type' => 'array', 'required' => true],
                    'project_attributes' => ['type' => 'object', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.convert_to_content_project',
                'Convert approved clusters into a Content Project',
                CreateContentProjectFromKeywordClustersCommand::class,
                'keyword_intelligence.convert_to_content_project',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'cluster_refs' => ['type' => 'array', 'required' => true],
                    'project_attributes' => ['type' => 'object', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'keyword_intelligence.archive_workspace',
                'Archive a Keyword Intelligence workspace (read-only afterwards)',
                ArchiveKeywordWorkspaceCommand::class,
                'keyword_intelligence.archive_workspace',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: true,
            ),
        ];
    }

    public function get(string $name): ?array
    {
        if ($name === 'content_project.rerun_items') {
            $name = 'content_project.rerun';
        }

        foreach ($this->all() as $cap) {
            if (($cap['name'] ?? '') === $name) {
                return $cap;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jsonSchema(string $name): ?array
    {
        $cap = $this->get($name);
        if ($cap === null) {
            return null;
        }

        return self::buildJsonSchema($cap);
    }

    /**
     * Pure schema builder — shared with capabilities coming from the
     * canonical registry (core + extensions), which follow the same
     * `input_schema` shape but are not owned by this registry.
     *
     * @param  array<string, mixed>  $cap
     * @return array<string, mixed>
     */
    public static function buildJsonSchema(array $cap): array
    {
        $inputSchema = is_array($cap['input_schema'] ?? null) ? $cap['input_schema'] : [];
        $properties = [];
        $required = [];

        foreach ($inputSchema as $field => $def) {
            if (! is_array($def)) {
                continue;
            }

            $type = (string) ($def['type'] ?? 'string');
            $prop = ['type' => $type];

            if ($type === 'array') {
                $prop['items'] = ['type' => 'string'];
            }

            if (isset($def['enum']) && is_array($def['enum'])) {
                $prop['enum'] = array_values($def['enum']);
            }

            if (isset($def['format']) && is_string($def['format'])) {
                $prop['format'] = $def['format'];
            }

            $properties[$field] = $prop;

            if (($def['required'] ?? false) === true) {
                $required[] = $field;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    public function isAgentWriteExposed(string $name): bool
    {
        if ($name === 'content_project.rerun_items') {
            return true;
        }

        if ($name === 'content_project.sync_items') {
            return false;
        }

        return $this->get($name) !== null;
    }

    /**
     * @param  list<string>|null  $phases
     * @param  array<string, mixed>  $inputSchema
     * @return array<string, mixed>
     */
    private function cap(
        string $name,
        string $description,
        string $handlerCommand,
        string $permission,
        string $riskLevel,
        bool $idempotencySupport,
        bool $dryRunSupport,
        array $inputSchema,
        ?array $phases,
        bool $confirmation,
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => $inputSchema,
            'risk_level' => $riskLevel,
            'idempotency_support' => $idempotencySupport,
            'dry_run_support' => $dryRunSupport,
            'required_permission' => $permission,
            'allowed_lifecycle_phases' => $phases,
            'handler' => $handlerCommand,
            'confirmation_requirement' => $confirmation,
        ];
    }
}
