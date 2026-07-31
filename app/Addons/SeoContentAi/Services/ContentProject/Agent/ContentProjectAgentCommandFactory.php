<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Agent;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\MoveProjectItemScheduleCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ResumeProjectExecutionCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\StartReviewCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\StopProjectExecutionCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\AnalyzeKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\AttachClusterToTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\DetachClusterFromTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\MoveTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ReviewTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\SaveTopicalMapVersionCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\UpdateTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Build Application Command từ capability + validated input.
 */
final class ContentProjectAgentCommandFactory
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function build(string $capability, array $input, int $resolvedSiteId): ContentProjectCommand
    {
        if ($capability === 'content_project.rerun_items') {
            $capability = 'content_project.rerun';
        }

        return match ($capability) {
            'content_project.create' => $this->buildCreate($input, $resolvedSiteId),
            'content_project.update' => new UpdateContentProjectCommand(
                $this->projectRef($input),
                is_array($input['attributes'] ?? null)
                    ? $input['attributes']
                    : array_filter([
                        'name' => $input['project_name'] ?? $input['name'] ?? null,
                        'description' => $input['description'] ?? null,
                    ], static fn (mixed $v): bool => $v !== null && $v !== ''),
            ),
            'content_project.add_items' => new AddContentProjectItemsCommand(
                $this->projectRef($input),
                is_array($input['items'] ?? null) ? $input['items'] : [],
            ),
            'content_project.update_item' => new UpdateContentProjectItemCommand(
                $this->itemRef($input),
                is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
            ),
            'content_project.generate' => new GenerateProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (string) ($input['mode'] ?? 'full'),
            ),
            'content_project.rerun' => new RerunProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (string) ($input['mode'] ?? 'full'),
            ),
            'content_project.start_review' => new StartReviewCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.approve' => new ApproveProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.schedule' => new ScheduleProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                Carbon::parse((string) ($input['scheduled_at'] ?? now()->addHour()->toIso8601String())),
                (bool) ($input['dry_run'] ?? false),
            ),
            'content_project.auto_schedule' => new AutoScheduleProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                is_array($input['options'] ?? null) ? $input['options'] : [],
                (bool) ($input['dry_run'] ?? false),
            ),
            'content_project.unschedule' => new UnscheduleProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.move_schedule' => new MoveProjectItemScheduleCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                Carbon::parse((string) ($input['scheduled_at'] ?? now()->addHour()->toIso8601String())),
            ),
            'content_project.publish_now' => new PublishProjectItemsNowCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'content_project.retry_publish' => new RetryProjectItemPublishingCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.skip_publish' => new SkipProjectItemPublishingCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.cancel_publish' => new CancelProjectItemPublishingCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'content_project.archive' => new ArchiveContentProjectCommand(
                $this->projectRef($input),
                isset($input['note']) ? (string) $input['note'] : null,
                (bool) ($input['confirm_waiting_publish'] ?? false),
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'content_project.archive_items' => new ArchiveProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                isset($input['note']) ? (string) $input['note'] : null,
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'content_project.restore' => new RestoreContentProjectCommand(
                $this->projectRef($input),
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'content_project.stop_execution' => new StopProjectExecutionCommand(
                $this->projectRef($input),
                isset($input['execution_ref']) ? (string) $input['execution_ref'] : null,
                isset($input['reason']) ? (string) $input['reason'] : null,
            ),
            'content_project.resume_execution' => new ResumeProjectExecutionCommand(
                $this->projectRef($input),
                isset($input['execution_ref']) ? (string) $input['execution_ref'] : null,
            ),

            // Keyword Intelligence — additive.
            'keyword_intelligence.create_workspace' => new CreateKeywordWorkspaceCommand(
                array_merge(
                    is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
                    ['site_id' => $resolvedSiteId],
                ),
            ),
            'keyword_intelligence.import_keywords' => new ImportKeywordsCommand(
                $this->workspaceRef($input),
                is_array($input['keywords'] ?? null) ? $input['keywords'] : [],
                (bool) ($input['preview'] ?? false),
                (bool) ($input['keep_duplicates'] ?? false),
                (string) ($input['source'] ?? 'manual'),
            ),
            'keyword_intelligence.analyze_workspace' => new AnalyzeKeywordWorkspaceCommand(
                $this->workspaceRef($input),
                isset($input['clustering_strategy']) ? (string) $input['clustering_strategy'] : null,
            ),
            'keyword_intelligence.approve_keywords' => new ApproveKeywordsCommand(
                $this->workspaceRef($input),
                $this->keywordRefs($input),
                (bool) ($input['approve'] ?? true),
            ),
            'keyword_intelligence.approve_clusters' => new ApproveKeywordClustersCommand(
                $this->workspaceRef($input),
                $this->clusterRefs($input),
                (bool) ($input['approve'] ?? true),
            ),
            'keyword_intelligence.build_topical_map' => new BuildTopicalMapCommand(
                $this->workspaceRef($input),
                isset($input['max_depth']) ? (int) $input['max_depth'] : null,
                isset($input['mode']) ? (string) $input['mode'] : null,
                (bool) ($input['include_reviewed_clusters'] ?? false),
                is_array($input['approved_cluster_refs'] ?? null) ? $input['approved_cluster_refs'] : null,
                (bool) ($input['preserve_manual_topics'] ?? true),
            ),
            'keyword_intelligence.create_topic' => new CreateTopicCommand(
                $this->workspaceRef($input),
                is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
                isset($input['parent_topic_ref']) ? (string) $input['parent_topic_ref'] : null,
            ),
            'keyword_intelligence.update_topic' => new UpdateTopicCommand(
                $this->workspaceRef($input),
                (string) ($input['topic_ref'] ?? ''),
                is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
            ),
            'keyword_intelligence.move_topic' => new MoveTopicCommand(
                $this->workspaceRef($input),
                (string) ($input['topic_ref'] ?? ''),
                isset($input['new_parent_topic_ref']) ? (string) $input['new_parent_topic_ref'] : null,
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'keyword_intelligence.attach_cluster' => new AttachClusterToTopicCommand(
                $this->workspaceRef($input),
                (string) ($input['topic_ref'] ?? ''),
                (string) ($input['cluster_ref'] ?? ''),
                (string) ($input['relationship'] ?? 'primary'),
            ),
            'keyword_intelligence.detach_cluster' => new DetachClusterFromTopicCommand(
                $this->workspaceRef($input),
                (string) ($input['topic_ref'] ?? ''),
                (string) ($input['cluster_ref'] ?? ''),
            ),
            'keyword_intelligence.review_topical_map' => new ReviewTopicalMapCommand(
                $this->workspaceRef($input),
                (string) ($input['map_version_ref'] ?? ''),
            ),
            // Agent NEVER gets allowBlockingOverride — hard-coded false.
            'keyword_intelligence.approve_topical_map' => new ApproveTopicalMapCommand(
                $this->workspaceRef($input),
                (string) ($input['map_version_ref'] ?? ''),
                false,
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'keyword_intelligence.save_map_version' => new SaveTopicalMapVersionCommand(
                $this->workspaceRef($input),
                isset($input['mode']) ? (string) $input['mode'] : null,
            ),
            'keyword_intelligence.preview_convert' => new PreviewContentProjectFromClustersCommand(
                $this->workspaceRef($input),
                $this->clusterRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
            ),
            'keyword_intelligence.preview_content_project' => new PreviewContentProjectFromTopicalMapCommand(
                $this->workspaceRef($input),
                (string) ($input['map_version_ref'] ?? ''),
                (string) ($input['policy'] ?? 'new_only'),
                is_array($input['cluster_refs'] ?? null) ? $input['cluster_refs'] : null,
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
            ),
            'keyword_intelligence.convert_to_content_project' => new CreateContentProjectFromKeywordClustersCommand(
                $this->workspaceRef($input),
                $this->clusterRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            // Creates from approved map only — converter rejects draft.
            'keyword_intelligence.create_content_project' => new CreateContentProjectFromTopicalMapCommand(
                $this->workspaceRef($input),
                (string) ($input['map_version_ref'] ?? ''),
                (string) ($input['policy'] ?? 'new_only'),
                is_array($input['cluster_refs'] ?? null) ? $input['cluster_refs'] : null,
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
                isset($input['idempotency_key']) ? (string) $input['idempotency_key'] : null,
            ),
            'keyword_intelligence.archive_workspace' => new ArchiveKeywordWorkspaceCommand(
                $this->workspaceRef($input),
            ),

            // SERP Intelligence — additive.
            'serp_intelligence.create_queries' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\CreateSerpQueriesCommand(
                $this->workspaceRef($input),
                is_array($input['queries'] ?? null) ? $input['queries'] : [],
                isset($input['provider_key']) ? (string) $input['provider_key'] : null,
            ),
            'serp_intelligence.collect' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\CollectSerpSnapshotsCommand(
                $this->workspaceRef($input),
                $this->serpQueryRefs($input),
                isset($input['provider_key']) ? (string) $input['provider_key'] : null,
            ),
            'serp_intelligence.import_snapshot' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ImportSerpSnapshotCommand(
                $this->workspaceRef($input),
                $this->serpQueryRef($input),
                (string) ($input['payload'] ?? ''),
                (string) ($input['format'] ?? 'json'),
                (bool) ($input['preview'] ?? false),
            ),
            'serp_intelligence.analyze_snapshot' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\AnalyzeSerpSnapshotCommand(
                $this->workspaceRef($input),
                $this->serpSnapshotRef($input),
            ),
            'serp_intelligence.fetch_page_evidence' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\FetchSerpPageEvidenceCommand(
                $this->workspaceRef($input),
                $this->serpSnapshotRef($input),
                $this->serpResultRefs($input),
            ),
            'serp_intelligence.validate_cluster' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ValidateClusterWithSerpCommand(
                $this->workspaceRef($input),
                $this->clusterRef($input),
            ),
            'serp_intelligence.approve_evidence' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApproveSerpClusterEvidenceCommand(
                $this->workspaceRef($input),
                $this->serpEvidenceRef($input),
            ),
            'serp_intelligence.apply_intent' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApplySerpIntentSuggestionCommand(
                $this->workspaceRef($input),
                $this->serpEvidenceRef($input),
                (bool) ($input['preview'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'serp_intelligence.apply_content_action' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApplySerpContentActionSuggestionCommand(
                $this->workspaceRef($input),
                $this->serpEvidenceRef($input),
                (bool) ($input['preview'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'serp_intelligence.review_gap' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ReviewSerpContentGapCommand(
                $this->workspaceRef($input),
                $this->serpGapRef($input),
                (string) ($input['action'] ?? 'review'),
            ),
            'serp_intelligence.add_feature_keywords' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\AddSerpFeatureKeywordsCommand(
                $this->workspaceRef($input),
                $this->serpSnapshotRef($input),
                $this->serpFeatureRefs($input),
            ),
            'serp_intelligence.preview_cluster_split' => new \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\PreviewSplitClusterFromSerpEvidenceCommand(
                $this->workspaceRef($input),
                $this->serpEvidenceRef($input),
                (bool) ($input['dry_run'] ?? true),
            ),

            // GSC Intelligence — additive Phase 5.
            'gsc_intelligence.create_property' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CreateGscPropertyCommand(
                $resolvedSiteId,
                is_array($input['attributes'] ?? null) ? $input['attributes'] : $input,
            ),
            'gsc_intelligence.update_property' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UpdateGscPropertyCommand(
                $this->gscPropertyRef($input),
                is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
            ),
            'gsc_intelligence.pause_property' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\PauseGscPropertyCommand(
                $this->gscPropertyRef($input),
            ),
            'gsc_intelligence.resume_property' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ResumeGscPropertyCommand(
                $this->gscPropertyRef($input),
            ),
            'gsc_intelligence.archive_property' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ArchiveGscPropertyCommand(
                $this->gscPropertyRef($input),
            ),
            'gsc_intelligence.sync_performance' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\SyncGscPerformanceDataCommand(
                $this->gscPropertyRef($input),
                isset($input['date_from']) ? (string) $input['date_from'] : null,
                isset($input['date_to']) ? (string) $input['date_to'] : null,
                isset($input['provider_key']) ? (string) $input['provider_key'] : null,
                is_array($input['options'] ?? null) ? $input['options'] : [],
            ),
            'gsc_intelligence.cancel_sync' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CancelGscSyncCommand(
                $this->gscPropertyRef($input),
                $this->gscSyncRunRef($input),
            ),
            'gsc_intelligence.import_performance' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ImportGscPerformanceDataCommand(
                $this->gscPropertyRef($input),
                (string) ($input['payload'] ?? ''),
                (bool) ($input['preview'] ?? false),
            ),
            'gsc_intelligence.repair_date_range' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RepairGscDateRangeCommand(
                $this->gscPropertyRef($input),
                isset($input['date_from']) ? (string) $input['date_from'] : null,
                isset($input['date_to']) ? (string) $input['date_to'] : null,
            ),
            'gsc_intelligence.map_query' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\MapGscQueryCommand(
                $this->gscPropertyRef($input),
                (string) ($input['normalized_query'] ?? ''),
                isset($input['keyword_ref']) ? (string) $input['keyword_ref'] : null,
                isset($input['cluster_ref']) ? (string) $input['cluster_ref'] : null,
                isset($input['topic_ref']) ? (string) $input['topic_ref'] : null,
            ),
            'gsc_intelligence.unmap_query' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UnmapGscQueryCommand(
                $this->gscPropertyRef($input),
                $this->gscQueryMappingRef($input),
            ),
            'gsc_intelligence.map_page' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\MapGscPageCommand(
                $this->gscPropertyRef($input),
                (string) ($input['normalized_page'] ?? ''),
                isset($input['article_ref']) ? (string) $input['article_ref'] : null,
            ),
            'gsc_intelligence.unmap_page' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UnmapGscPageCommand(
                $this->gscPropertyRef($input),
                $this->gscPageMappingRef($input),
            ),
            'gsc_intelligence.rebuild_aggregates' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RebuildGscAggregatesCommand(
                $this->gscPropertyRef($input),
                isset($input['date_from']) ? (string) $input['date_from'] : null,
                isset($input['date_to']) ? (string) $input['date_to'] : null,
            ),
            'gsc_intelligence.detect_opportunities' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\DetectGscOpportunitiesCommand(
                $this->gscPropertyRef($input),
                isset($input['date_from']) ? (string) $input['date_from'] : null,
                isset($input['date_to']) ? (string) $input['date_to'] : null,
            ),
            'gsc_intelligence.approve_opportunity' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ApproveGscOpportunityCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRef($input),
            ),
            'gsc_intelligence.reject_opportunity' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RejectGscOpportunityCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRef($input),
            ),
            'gsc_intelligence.ignore_opportunity' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\IgnoreGscOpportunityCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRef($input),
            ),
            'gsc_intelligence.resolve_opportunity' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ResolveGscOpportunityCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRef($input),
                isset($input['resolution_code']) ? (string) $input['resolution_code'] : null,
            ),
            'gsc_intelligence.preview_add_queries' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\PreviewAddGscQueriesToKeywordWorkspaceCommand(
                $this->gscPropertyRef($input),
                $this->workspaceRef($input),
                $this->gscQueryMappingRefs($input),
            ),
            'gsc_intelligence.add_queries_to_workspace' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\AddGscQueriesToKeywordWorkspaceCommand(
                $this->gscPropertyRef($input),
                $this->workspaceRef($input),
                $this->gscQueryMappingRefs($input),
                (bool) ($input['keep_duplicates'] ?? false),
            ),
            'gsc_intelligence.preview_create_content_project' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\PreviewCreateContentProjectFromGscOpportunitiesCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
            ),
            'gsc_intelligence.create_content_project' => new \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CreateContentProjectFromGscOpportunitiesCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
                isset($input['idempotency_key']) ? (string) $input['idempotency_key'] : null,
            ),
            'site.discover' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\DiscoverSiteCommand($resolvedSiteId),
            'site.sync' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RunSiteSyncCommand(
                $resolvedSiteId,
                (string) ($input['mode'] ?? 'delta'),
                (bool) ($input['force_snapshot'] ?? false),
            ),
            'site.sync_keywords' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\SyncSiteKeywordsCommand($resolvedSiteId),
            'site.sync_links' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\SyncSiteLinksCommand($resolvedSiteId),
            'site.discover_contacts' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\DiscoverSiteContactsCommand($resolvedSiteId),
            'site.refresh_snapshot' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RefreshSiteSnapshotCommand($resolvedSiteId),
            'site.resume_sync' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ResumeSiteSyncCommand(
                $resolvedSiteId,
                (int) ($input['run_id'] ?? 0),
            ),
            'site.retry_sync_step' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RetrySiteSyncStepCommand(
                $resolvedSiteId,
                (int) ($input['run_id'] ?? 0),
                (string) ($input['step_key'] ?? ''),
            ),
            'site.cancel_sync' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\CancelSiteSyncCommand(
                $resolvedSiteId,
                (int) ($input['run_id'] ?? 0),
            ),
            'site.reconcile' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ReconcileSiteSyncCommand(
                $resolvedSiteId,
                (string) ($input['mode'] ?? 'standard'),
            ),
            'site.requeue_inbound_event' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RequeueSiteSyncInboundEventCommand(
                $resolvedSiteId,
                (int) ($input['event_id'] ?? 0),
            ),
            'site.preview_bootstrap' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\PreviewBootstrapSiteSyncCommand($resolvedSiteId),
            'site.bootstrap' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\BootstrapSiteSyncCommand(
                $resolvedSiteId,
                (bool) ($input['force'] ?? false),
            ),
            'site.backfill_v2' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\BackfillSiteSyncV2Command(
                $resolvedSiteId,
                (bool) ($input['dry_run'] ?? true),
                is_array($input['only'] ?? null) ? $input['only'] : ['all'],
                (int) ($input['batch'] ?? 200),
                isset($input['resume_id']) ? (int) $input['resume_id'] : null,
                (bool) ($input['force'] ?? false),
            ),
            'site.validate_handshake' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ValidateSiteSyncHandshakeCommand($resolvedSiteId),
            'site.generate_diagnostic' => new \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\GenerateSiteSyncDiagnosticCommand($resolvedSiteId),
            default => throw new InvalidArgumentException('Unsupported agent capability: '.$capability),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function buildCreate(array $input, int $resolvedSiteId): CreateContentProjectCommand
    {
        $attributes = is_array($input['attributes'] ?? null) ? $input['attributes'] : [];
        $attributes['site_id'] = $resolvedSiteId;
        unset($attributes['site_ref']);

        $tasksData = is_array($input['tasksData'] ?? null)
            ? $input['tasksData']
            : (is_array($input['tasks_data'] ?? null) ? $input['tasks_data'] : []);

        return new CreateContentProjectCommand($attributes, $tasksData);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function projectRef(array $input): string
    {
        $ref = trim((string) ($input['project_ref'] ?? ''));
        ContentProjectPublicRef::resolveProjectIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function itemRef(array $input): string
    {
        $ref = trim((string) ($input['item_ref'] ?? ''));
        ContentProjectPublicRef::resolveItemIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function itemRefs(array $input): array
    {
        $raw = $input['item_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            ContentProjectPublicRef::resolveItemIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function workspaceRef(array $input): string
    {
        $ref = trim((string) ($input['workspace_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function keywordRefs(array $input): array
    {
        $raw = $input['keyword_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveKeywordIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function clusterRefs(array $input): array
    {
        $raw = $input['cluster_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveClusterIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function clusterRef(array $input): string
    {
        $ref = trim((string) ($input['cluster_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveClusterIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function serpQueryRef(array $input): string
    {
        $ref = trim((string) ($input['query_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveSerpQueryIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function serpQueryRefs(array $input): array
    {
        $raw = $input['query_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveSerpQueryIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function serpSnapshotRef(array $input): string
    {
        $ref = trim((string) ($input['snapshot_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveSerpSnapshotIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function serpEvidenceRef(array $input): string
    {
        $ref = trim((string) ($input['evidence_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveSerpClusterEvidenceIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function serpGapRef(array $input): string
    {
        $ref = trim((string) ($input['gap_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveSerpContentGapIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function serpResultRefs(array $input): array
    {
        $raw = $input['result_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveSerpResultIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function serpFeatureRefs(array $input): array
    {
        $raw = $input['feature_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveSerpFeatureIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscPropertyRef(array $input): string
    {
        $ref = trim((string) ($input['property_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscPropertyIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscSyncRunRef(array $input): string
    {
        $ref = trim((string) ($input['sync_run_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscSyncRunIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscQueryMappingRef(array $input): string
    {
        $ref = trim((string) ($input['mapping_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscQueryMappingIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function gscQueryMappingRefs(array $input): array
    {
        $raw = $input['query_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveGscQueryMappingIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscPageMappingRef(array $input): string
    {
        $ref = trim((string) ($input['mapping_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscPageMappingIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscOpportunityRef(array $input): string
    {
        $ref = trim((string) ($input['opportunity_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscOpportunityIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function gscOpportunityRefs(array $input): array
    {
        $raw = $input['opportunity_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveGscOpportunityIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }
}
