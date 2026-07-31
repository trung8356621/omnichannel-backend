<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\MoveProjectItemScheduleCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ProcessScheduledProjectItemPublishCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ResumeProjectExecutionCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\StartReviewCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\StopProjectExecutionCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SyncContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\AddContentProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ApproveProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ArchiveContentProjectHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ArchiveProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\AutoScheduleProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\CancelProjectItemPublishingHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\CreateContentProjectHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\MoveProjectItemScheduleHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\PublishProjectItemsNowHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\RestoreContentProjectHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ResumeProjectExecutionHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\RetryProjectItemPublishingHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\RerunProjectItemStepHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ScheduleProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\SkipProjectItemPublishingHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\StartReviewHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\StopProjectExecutionHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\SyncContentProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\UnscheduleProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\UpdateContentProjectHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\UpdateContentProjectItemHandler;
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
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\AnalyzeKeywordWorkspaceHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\AnalyzeSelectedKeywordsHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ApproveKeywordClustersHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ApproveKeywordsHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ApproveTopicalMapHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ArchiveKeywordWorkspaceHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\AttachClusterToTopicHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\BuildTopicalMapHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\CancelKeywordAnalysisHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\CancelTopicalMapBuildHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\CreateContentProjectFromKeywordClustersHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\CreateContentProjectFromTopicalMapHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\CreateKeywordWorkspaceHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\CreateTopicHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\DeleteEmptyTopicHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\DetachClusterFromTopicHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ExcludeKeywordsHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ImportKeywordsHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\MergeKeywordClustersHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\MoveClusterPrimaryTopicHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\MoveKeywordsToClusterHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\MoveTopicHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\PreviewContentProjectFromClustersHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\PreviewContentProjectFromTopicalMapHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ReviewCannibalizationIssueHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ReviewTopicalMapHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\SaveTopicalMapVersionHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\SetTopicRelationshipHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\SplitKeywordClusterHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\UpdateKeywordClassificationHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\UpdateTopicHandler;
use App\Support\RuntimeLogger;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class ContentProjectCommandBusRegistrar
{
    public function __construct(private readonly Application $app) {}

    public function register(ContentProjectCommandBus $bus): void
    {
        $map = [
            CreateContentProjectCommand::class => CreateContentProjectHandler::class,
            UpdateContentProjectCommand::class => UpdateContentProjectHandler::class,
            SyncContentProjectItemsCommand::class => SyncContentProjectItemsHandler::class,
            AddContentProjectItemsCommand::class => AddContentProjectItemsHandler::class,
            UpdateContentProjectItemCommand::class => UpdateContentProjectItemHandler::class,
            GenerateProjectItemsCommand::class => GenerateProjectItemsHandler::class,
            RerunProjectItemsCommand::class => RerunProjectItemsHandler::class,
            RerunProjectItemStepCommand::class => RerunProjectItemStepHandler::class,
            StartReviewCommand::class => StartReviewHandler::class,
            ApproveProjectItemsCommand::class => ApproveProjectItemsHandler::class,
            ScheduleProjectItemsCommand::class => ScheduleProjectItemsHandler::class,
            AutoScheduleProjectItemsCommand::class => AutoScheduleProjectItemsHandler::class,
            UnscheduleProjectItemsCommand::class => UnscheduleProjectItemsHandler::class,
            MoveProjectItemScheduleCommand::class => MoveProjectItemScheduleHandler::class,
            PublishProjectItemsNowCommand::class => PublishProjectItemsNowHandler::class,
            ProcessScheduledProjectItemPublishCommand::class => ProcessScheduledProjectItemPublishHandler::class,
            StopProjectExecutionCommand::class => StopProjectExecutionHandler::class,
            ResumeProjectExecutionCommand::class => ResumeProjectExecutionHandler::class,
            RetryProjectItemPublishingCommand::class => RetryProjectItemPublishingHandler::class,
            SkipProjectItemPublishingCommand::class => SkipProjectItemPublishingHandler::class,
            CancelProjectItemPublishingCommand::class => CancelProjectItemPublishingHandler::class,
            ArchiveContentProjectCommand::class => ArchiveContentProjectHandler::class,
            ArchiveProjectItemsCommand::class => ArchiveProjectItemsHandler::class,
            RestoreContentProjectCommand::class => RestoreContentProjectHandler::class,

            // Keyword Intelligence — additive, không đổi các entry Content Project ở trên.
            CreateKeywordWorkspaceCommand::class => CreateKeywordWorkspaceHandler::class,
            ImportKeywordsCommand::class => ImportKeywordsHandler::class,
            AnalyzeKeywordWorkspaceCommand::class => AnalyzeKeywordWorkspaceHandler::class,
            AnalyzeSelectedKeywordsCommand::class => AnalyzeSelectedKeywordsHandler::class,
            CancelKeywordAnalysisCommand::class => CancelKeywordAnalysisHandler::class,
            ApproveKeywordsCommand::class => ApproveKeywordsHandler::class,
            ExcludeKeywordsCommand::class => ExcludeKeywordsHandler::class,
            UpdateKeywordClassificationCommand::class => UpdateKeywordClassificationHandler::class,
            ApproveKeywordClustersCommand::class => ApproveKeywordClustersHandler::class,
            MergeKeywordClustersCommand::class => MergeKeywordClustersHandler::class,
            SplitKeywordClusterCommand::class => SplitKeywordClusterHandler::class,
            MoveKeywordsToClusterCommand::class => MoveKeywordsToClusterHandler::class,
            ReviewCannibalizationIssueCommand::class => ReviewCannibalizationIssueHandler::class,
            BuildTopicalMapCommand::class => BuildTopicalMapHandler::class,
            CancelTopicalMapBuildCommand::class => CancelTopicalMapBuildHandler::class,
            CreateTopicCommand::class => CreateTopicHandler::class,
            UpdateTopicCommand::class => UpdateTopicHandler::class,
            MoveTopicCommand::class => MoveTopicHandler::class,
            DeleteEmptyTopicCommand::class => DeleteEmptyTopicHandler::class,
            AttachClusterToTopicCommand::class => AttachClusterToTopicHandler::class,
            DetachClusterFromTopicCommand::class => DetachClusterFromTopicHandler::class,
            MoveClusterPrimaryTopicCommand::class => MoveClusterPrimaryTopicHandler::class,
            SetTopicRelationshipCommand::class => SetTopicRelationshipHandler::class,
            ReviewTopicalMapCommand::class => ReviewTopicalMapHandler::class,
            ApproveTopicalMapCommand::class => ApproveTopicalMapHandler::class,
            SaveTopicalMapVersionCommand::class => SaveTopicalMapVersionHandler::class,
            PreviewContentProjectFromClustersCommand::class => PreviewContentProjectFromClustersHandler::class,
            PreviewContentProjectFromTopicalMapCommand::class => PreviewContentProjectFromTopicalMapHandler::class,
            CreateContentProjectFromKeywordClustersCommand::class => CreateContentProjectFromKeywordClustersHandler::class,
            CreateContentProjectFromTopicalMapCommand::class => CreateContentProjectFromTopicalMapHandler::class,
            ArchiveKeywordWorkspaceCommand::class => ArchiveKeywordWorkspaceHandler::class,

            // SERP Intelligence — additive.
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\CreateSerpQueriesCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\CreateSerpQueriesHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\UpdateSerpQueryCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\UpdateSerpQueryHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ArchiveSerpQueriesCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ArchiveSerpQueriesHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\CollectSerpSnapshotsCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\CollectSerpSnapshotsHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\CancelSerpCollectionCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\CancelSerpCollectionHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ImportSerpSnapshotCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ImportSerpSnapshotHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\AnalyzeSerpSnapshotCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\AnalyzeSerpSnapshotHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\FetchSerpPageEvidenceCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\FetchSerpPageEvidenceHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ReanalyzeSerpPageEvidenceCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ReanalyzeSerpPageEvidenceHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ValidateClusterWithSerpCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ValidateClusterWithSerpHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ValidateWorkspaceClustersWithSerpCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ValidateWorkspaceClustersWithSerpHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApproveSerpClusterEvidenceCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ApproveSerpClusterEvidenceHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\RejectSerpClusterEvidenceCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\RejectSerpClusterEvidenceHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApplySerpIntentSuggestionCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ApplySerpIntentSuggestionHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApplySerpPageTypeSuggestionCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ApplySerpPageTypeSuggestionHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApplySerpContentActionSuggestionCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ApplySerpContentActionSuggestionHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ReviewSerpContentGapCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ReviewSerpContentGapHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\AcceptSerpContentGapCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\AcceptSerpContentGapHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\IgnoreSerpContentGapCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\IgnoreSerpContentGapHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ResolveSerpContentGapCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\ResolveSerpContentGapHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\PreviewSplitClusterFromSerpEvidenceCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\PreviewSplitClusterFromSerpEvidenceHandler::class,
            \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\AddSerpFeatureKeywordsCommand::class => \App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers\AddSerpFeatureKeywordsHandler::class,

            // GSC Intelligence — additive Phase 5.
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CreateGscPropertyCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\CreateGscPropertyHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UpdateGscPropertyCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\UpdateGscPropertyHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\PauseGscPropertyCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\PauseGscPropertyHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ResumeGscPropertyCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\ResumeGscPropertyHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ArchiveGscPropertyCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\ArchiveGscPropertyHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\SyncGscPerformanceDataCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\SyncGscPerformanceDataHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CancelGscSyncCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\CancelGscSyncHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ImportGscPerformanceDataCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\ImportGscPerformanceDataHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RepairGscDateRangeCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\RepairGscDateRangeHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\MapGscQueryCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\MapGscQueryHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UnmapGscQueryCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\UnmapGscQueryHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\MapGscPageCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\MapGscPageHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UnmapGscPageCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\UnmapGscPageHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RebuildGscAggregatesCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\RebuildGscAggregatesHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\DetectGscOpportunitiesCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\DetectGscOpportunitiesHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ApproveGscOpportunityCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\ApproveGscOpportunityHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RejectGscOpportunityCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\RejectGscOpportunityHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\IgnoreGscOpportunityCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\IgnoreGscOpportunityHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\ResolveGscOpportunityCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\ResolveGscOpportunityHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\PreviewAddGscQueriesToKeywordWorkspaceCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\PreviewAddGscQueriesToKeywordWorkspaceHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\AddGscQueriesToKeywordWorkspaceCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\AddGscQueriesToKeywordWorkspaceHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\PreviewCreateContentProjectFromGscOpportunitiesCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\PreviewCreateContentProjectFromGscOpportunitiesHandler::class,
            \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CreateContentProjectFromGscOpportunitiesCommand::class => \App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers\CreateContentProjectFromGscOpportunitiesHandler::class,

            // Site Sync V2 — additive; shared handler.
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RunSiteSyncCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ForceFullSiteSyncCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\QueueMissingSeoScoresCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RetryFailedSeoScoresCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RequeueAllSeoScoresCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\DiscoverSiteCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\SyncSiteKeywordsCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\SyncSiteLinksCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\DiscoverSiteContactsCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RefreshSiteSnapshotCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ResumeSiteSyncCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RetrySiteSyncStepCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\CancelSiteSyncCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ReconcileSiteSyncCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RequeueSiteSyncInboundEventCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\PreviewBootstrapSiteSyncCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\BootstrapSiteSyncCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\BackfillSiteSyncV2Command::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ValidateSiteSyncHandshakeCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\GenerateSiteSyncDiagnosticCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\AcceptSiteProfileSuggestionCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RejectSiteProfileSuggestionCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\PreviewSiteSyncCutoverCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\EnterSiteSyncShadowModeCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ExitSiteSyncShadowModeCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ActivateSiteSyncV2Command::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RollbackSiteSyncToLegacyCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\GenerateSiteSyncComparisonReportCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\PreviewSiteSyncRepairCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ExecuteSiteSyncRepairCommand::class => \App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers\SiteSyncCutoverCommandHandler::class,
        ];

        foreach ($map as $command => $handler) {
            try {
                // Lazy proxy: tránh make() toàn bộ DI graph khi boot CommandBus
                // (Site Sync Cutover/Comparison từng Fatal giữa vòng register).
                $bus->register($command, new class($this->app, $handler) implements \App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler
                {
                    /**
                     * @param  \Illuminate\Contracts\Foundation\Application  $app
                     * @param  class-string  $handlerClass
                     */
                    public function __construct(
                        private readonly mixed $app,
                        private readonly string $handlerClass,
                    ) {}

                    public function handle(
                        \App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand $command,
                        \App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext $actor,
                    ): \App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult {
                        /** @var \App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler $resolved */
                        $resolved = $this->app->make($this->handlerClass);

                        return $resolved->handle($command, $actor);
                    }
                });
            } catch (Throwable $e) {
                // One broken additive handler (KI/SERP/GSC/…) must not kill
                // Content Project publish scheduler DI (seo:publish-scheduled-articles).
                RuntimeLogger::warning('content_project_command_bus_handler_skipped', [
                    'command' => $command,
                    'handler' => $handler,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
