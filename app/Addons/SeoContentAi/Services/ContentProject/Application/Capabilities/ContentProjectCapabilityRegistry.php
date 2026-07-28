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
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\AnalyzeSelectedKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\AttachClusterToTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CancelKeywordAnalysisCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CancelTopicalMapBuildCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\DeleteEmptyTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\DetachClusterFromTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ExcludeKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\MergeKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\MoveClusterPrimaryTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\MoveKeywordsToClusterCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\MoveTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ReviewCannibalizationIssueCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ReviewTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\SaveTopicalMapVersionCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\SetTopicRelationshipCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\SplitKeywordClusterCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\UpdateKeywordClassificationCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\UpdateTopicCommand;

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
                    'options' => ['type' => 'object', 'required' => false],
                    'idempotency_key' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.analyze_keywords',
                'Analyze selected keywords in a workspace',
                AnalyzeSelectedKeywordsCommand::class,
                'keyword_intelligence.analyze_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.cancel_analysis',
                'Cancel a running keyword analysis operation',
                CancelKeywordAnalysisCommand::class,
                'keyword_intelligence.cancel_analysis',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'operation_ref' => ['type' => 'string', 'required' => true],
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
                'keyword_intelligence.exclude_keywords',
                'Exclude or restore excluded keywords',
                ExcludeKeywordsCommand::class,
                'keyword_intelligence.exclude_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                    'exclude' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.update_keyword',
                'Manually update keyword intent/funnel/business value',
                UpdateKeywordClassificationCommand::class,
                'keyword_intelligence.update_keyword',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                    'search_intent' => ['type' => 'string', 'required' => false],
                    'funnel_stage' => ['type' => 'string', 'required' => false],
                    'business_value' => ['type' => 'number', 'required' => false],
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
                'keyword_intelligence.merge_clusters',
                'Merge keyword clusters (preview/confirm when approved)',
                MergeKeywordClustersCommand::class,
                'keyword_intelligence.merge_clusters',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'source_cluster_refs' => ['type' => 'array', 'required' => true],
                    'target_cluster_ref' => ['type' => 'string', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'keyword_intelligence.split_cluster',
                'Split a keyword cluster into groups',
                SplitKeywordClusterCommand::class,
                'keyword_intelligence.split_cluster',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'source_cluster_ref' => ['type' => 'string', 'required' => true],
                    'groups' => ['type' => 'array', 'required' => true],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'keyword_intelligence.move_keywords',
                'Move keywords into a destination cluster',
                MoveKeywordsToClusterCommand::class,
                'keyword_intelligence.move_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                    'destination_cluster_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.review_cannibalization',
                'Review/ignore/resolve a cannibalization issue',
                ReviewCannibalizationIssueCommand::class,
                'keyword_intelligence.review_cannibalization',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'issue_ref' => ['type' => 'string', 'required' => true],
                    'action' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.build_topical_map',
                'Build the topical map from approved clusters',
                BuildTopicalMapCommand::class,
                'keyword_intelligence.build_topical_map',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'max_depth' => ['type' => 'integer', 'required' => false],
                    'mode' => ['type' => 'string', 'required' => false],
                    'include_reviewed_clusters' => ['type' => 'boolean', 'required' => false],
                    'approved_cluster_refs' => ['type' => 'array', 'required' => false],
                    'preserve_manual_topics' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.create_topic',
                'Create a topic in the topical map',
                CreateTopicCommand::class,
                'keyword_intelligence.create_topic',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'attributes' => ['type' => 'object', 'required' => true],
                    'parent_topic_ref' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.update_topic',
                'Update a topic',
                UpdateTopicCommand::class,
                'keyword_intelligence.update_topic',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'topic_ref' => ['type' => 'string', 'required' => true],
                    'attributes' => ['type' => 'object', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.move_topic',
                'Move a topic under a new parent',
                MoveTopicCommand::class,
                'keyword_intelligence.move_topic',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'topic_ref' => ['type' => 'string', 'required' => true],
                    'new_parent_topic_ref' => ['type' => 'string', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'keyword_intelligence.attach_cluster',
                'Attach a cluster to a topic',
                AttachClusterToTopicCommand::class,
                'keyword_intelligence.attach_cluster',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'topic_ref' => ['type' => 'string', 'required' => true],
                    'cluster_ref' => ['type' => 'string', 'required' => true],
                    'relationship' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.detach_cluster',
                'Detach a cluster from a topic',
                DetachClusterFromTopicCommand::class,
                'keyword_intelligence.detach_cluster',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'topic_ref' => ['type' => 'string', 'required' => true],
                    'cluster_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.review_topical_map',
                'Mark a topical map version as reviewed',
                ReviewTopicalMapCommand::class,
                'keyword_intelligence.review_topical_map',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'map_version_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.approve_topical_map',
                'Approve a topical map version (gates blocking conflicts)',
                ApproveTopicalMapCommand::class,
                'keyword_intelligence.approve_topical_map',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'map_version_ref' => ['type' => 'string', 'required' => true],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'keyword_intelligence.save_map_version',
                'Save a snapshot of the current topical map as a new draft version',
                SaveTopicalMapVersionCommand::class,
                'keyword_intelligence.save_map_version',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'mode' => ['type' => 'string', 'required' => false],
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
                'keyword_intelligence.preview_content_project',
                'Preview converting an approved topical map into a Content Project',
                PreviewContentProjectFromTopicalMapCommand::class,
                'keyword_intelligence.preview_content_project',
                riskLevel: 'write',
                idempotencySupport: false,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'map_version_ref' => ['type' => 'string', 'required' => true],
                    'policy' => ['type' => 'string', 'required' => false],
                    'cluster_refs' => ['type' => 'array', 'required' => false],
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
                'keyword_intelligence.create_content_project',
                'Create a Content Project from an approved topical map',
                CreateContentProjectFromTopicalMapCommand::class,
                'keyword_intelligence.create_content_project',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'map_version_ref' => ['type' => 'string', 'required' => true],
                    'policy' => ['type' => 'string', 'required' => false],
                    'cluster_refs' => ['type' => 'array', 'required' => false],
                    'project_attributes' => ['type' => 'object', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                    'idempotency_key' => ['type' => 'string', 'required' => false],
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

            // SERP Intelligence — additive.
            $this->cap(
                'serp_intelligence.create_queries',
                'Create SERP queries in a keyword workspace',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\CreateSerpQueriesCommand::class,
                'serp_intelligence.create_queries',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'queries' => ['type' => 'array', 'required' => true],
                    'provider_key' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.collect',
                'Collect SERP snapshots for queries',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\CollectSerpSnapshotsCommand::class,
                'serp_intelligence.collect',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'query_refs' => ['type' => 'array', 'required' => true],
                    'provider_key' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.import_snapshot',
                'Import a SERP snapshot from manual payload',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ImportSerpSnapshotCommand::class,
                'serp_intelligence.import_snapshot',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'query_ref' => ['type' => 'string', 'required' => true],
                    'payload' => ['type' => 'string', 'required' => true],
                    'format' => ['type' => 'string', 'required' => false],
                    'preview' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.analyze_snapshot',
                'Analyze a completed SERP snapshot',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\AnalyzeSerpSnapshotCommand::class,
                'serp_intelligence.analyze_snapshot',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'snapshot_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.fetch_page_evidence',
                'Fetch page evidence for SERP results (allowlisted URLs only)',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\FetchSerpPageEvidenceCommand::class,
                'serp_intelligence.fetch_page_evidence',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'snapshot_ref' => ['type' => 'string', 'required' => true],
                    'result_refs' => ['type' => 'array', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.validate_cluster',
                'Validate a keyword cluster using SERP overlap',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ValidateClusterWithSerpCommand::class,
                'serp_intelligence.validate_cluster',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'cluster_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.approve_evidence',
                'Approve SERP cluster evidence',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApproveSerpClusterEvidenceCommand::class,
                'serp_intelligence.approve_evidence',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'evidence_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.apply_intent',
                'Apply SERP intent suggestion to cluster (confirmation when manual override)',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApplySerpIntentSuggestionCommand::class,
                'serp_intelligence.apply_intent',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'evidence_ref' => ['type' => 'string', 'required' => true],
                    'preview' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'serp_intelligence.apply_content_action',
                'Apply SERP content action suggestion to cluster',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApplySerpContentActionSuggestionCommand::class,
                'serp_intelligence.apply_content_action',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'evidence_ref' => ['type' => 'string', 'required' => true],
                    'preview' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'serp_intelligence.review_gap',
                'Review a SERP content gap',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ReviewSerpContentGapCommand::class,
                'serp_intelligence.review_gap',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'gap_ref' => ['type' => 'string', 'required' => true],
                    'action' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.add_feature_keywords',
                'Promote SERP feature keywords into workspace keyword candidates',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\AddSerpFeatureKeywordsCommand::class,
                'serp_intelligence.add_feature_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'snapshot_ref' => ['type' => 'string', 'required' => true],
                    'feature_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.preview_cluster_split',
                'Preview splitting a cluster from SERP evidence',
                \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\PreviewSplitClusterFromSerpEvidenceCommand::class,
                'serp_intelligence.preview_cluster_split',
                riskLevel: 'write',
                idempotencySupport: false,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'evidence_ref' => ['type' => 'string', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),

            // GSC Intelligence — additive Phase 5.
            $this->cap('gsc_intelligence.create_property', 'Create a GSC property for site', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CreateGscPropertyCommand::class, 'gsc_intelligence.create_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['attributes' => ['type' => 'object', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.update_property', 'Update GSC property metadata', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UpdateGscPropertyCommand::class, 'gsc_intelligence.update_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'attributes' => ['type' => 'object', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.pause_property', 'Pause GSC property sync', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\PauseGscPropertyCommand::class, 'gsc_intelligence.pause_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.resume_property', 'Resume GSC property sync', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ResumeGscPropertyCommand::class, 'gsc_intelligence.resume_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.archive_property', 'Archive GSC property', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ArchiveGscPropertyCommand::class, 'gsc_intelligence.archive_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.sync_performance', 'Sync GSC performance data', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\SyncGscPerformanceDataCommand::class, 'gsc_intelligence.sync_performance', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'date_from' => ['type' => 'string', 'required' => false], 'date_to' => ['type' => 'string', 'required' => false], 'provider_key' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.cancel_sync', 'Cancel in-flight GSC sync run', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CancelGscSyncCommand::class, 'gsc_intelligence.cancel_sync', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'sync_run_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.import_performance', 'Import GSC performance CSV', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ImportGscPerformanceDataCommand::class, 'gsc_intelligence.import_performance', riskLevel: 'write', idempotencySupport: true, dryRunSupport: true, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'payload' => ['type' => 'string', 'required' => true], 'preview' => ['type' => 'boolean', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.repair_date_range', 'Compute GSC repair date ranges', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RepairGscDateRangeCommand::class, 'gsc_intelligence.repair_date_range', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'date_from' => ['type' => 'string', 'required' => false], 'date_to' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.map_query', 'Manually map GSC query to keyword workspace entity', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\MapGscQueryCommand::class, 'gsc_intelligence.map_query', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'normalized_query' => ['type' => 'string', 'required' => true], 'keyword_ref' => ['type' => 'string', 'required' => false], 'cluster_ref' => ['type' => 'string', 'required' => false], 'topic_ref' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.unmap_query', 'Unmap GSC query mapping', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UnmapGscQueryCommand::class, 'gsc_intelligence.unmap_query', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'mapping_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.map_page', 'Manually map GSC page to article', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\MapGscPageCommand::class, 'gsc_intelligence.map_page', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'normalized_page' => ['type' => 'string', 'required' => true], 'article_ref' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.unmap_page', 'Unmap GSC page mapping', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UnmapGscPageCommand::class, 'gsc_intelligence.unmap_page', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'mapping_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.rebuild_aggregates', 'Rebuild GSC performance aggregates', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RebuildGscAggregatesCommand::class, 'gsc_intelligence.rebuild_aggregates', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'date_from' => ['type' => 'string', 'required' => false], 'date_to' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.detect_opportunities', 'Detect GSC content opportunities', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\DetectGscOpportunitiesCommand::class, 'gsc_intelligence.detect_opportunities', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'date_from' => ['type' => 'string', 'required' => false], 'date_to' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.approve_opportunity', 'Approve GSC opportunity', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ApproveGscOpportunityCommand::class, 'gsc_intelligence.approve_opportunity', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.reject_opportunity', 'Reject GSC opportunity', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RejectGscOpportunityCommand::class, 'gsc_intelligence.reject_opportunity', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.ignore_opportunity', 'Ignore GSC opportunity', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\IgnoreGscOpportunityCommand::class, 'gsc_intelligence.ignore_opportunity', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.resolve_opportunity', 'Resolve GSC opportunity', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ResolveGscOpportunityCommand::class, 'gsc_intelligence.resolve_opportunity', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_ref' => ['type' => 'string', 'required' => true], 'resolution_code' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.preview_add_queries', 'Preview adding GSC queries to keyword workspace', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\PreviewAddGscQueriesToKeywordWorkspaceCommand::class, 'gsc_intelligence.preview_add_queries', riskLevel: 'write', idempotencySupport: false, dryRunSupport: true, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'workspace_ref' => ['type' => 'string', 'required' => true], 'query_refs' => ['type' => 'array', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.add_queries_to_workspace', 'Add GSC queries to keyword workspace', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\AddGscQueriesToKeywordWorkspaceCommand::class, 'gsc_intelligence.add_queries_to_workspace', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'workspace_ref' => ['type' => 'string', 'required' => true], 'query_refs' => ['type' => 'array', 'required' => false], 'keep_duplicates' => ['type' => 'boolean', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.preview_create_content_project', 'Preview content project from GSC opportunities', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\PreviewCreateContentProjectFromGscOpportunitiesCommand::class, 'gsc_intelligence.preview_create_content_project', riskLevel: 'write', idempotencySupport: false, dryRunSupport: true, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_refs' => ['type' => 'array', 'required' => true], 'project_attributes' => ['type' => 'object', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.create_content_project', 'Create content project from approved GSC opportunities', \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CreateContentProjectFromGscOpportunitiesCommand::class, 'gsc_intelligence.create_content_project', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_refs' => ['type' => 'array', 'required' => true], 'project_attributes' => ['type' => 'object', 'required' => false], 'confirmation_token' => ['type' => 'string', 'required' => false]], phases: null, confirmation: true),
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
