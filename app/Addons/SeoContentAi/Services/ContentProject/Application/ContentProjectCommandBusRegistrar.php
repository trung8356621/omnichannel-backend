<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
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
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ScheduleProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\SkipProjectItemPublishingHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\StartReviewHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\StopProjectExecutionHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\SyncContentProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\UnscheduleProjectItemsHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\UpdateContentProjectHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\UpdateContentProjectItemHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\AnalyzeKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\AnalyzeKeywordWorkspaceHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ApproveKeywordClustersHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ApproveKeywordsHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ArchiveKeywordWorkspaceHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\BuildTopicalMapHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\CreateContentProjectFromKeywordClustersHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\CreateKeywordWorkspaceHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\ImportKeywordsHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers\PreviewContentProjectFromClustersHandler;
use Illuminate\Contracts\Foundation\Application;

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
            RestoreContentProjectCommand::class => RestoreContentProjectHandler::class,

            // Keyword Intelligence — additive, không đổi các entry Content Project ở trên.
            CreateKeywordWorkspaceCommand::class => CreateKeywordWorkspaceHandler::class,
            ImportKeywordsCommand::class => ImportKeywordsHandler::class,
            AnalyzeKeywordWorkspaceCommand::class => AnalyzeKeywordWorkspaceHandler::class,
            ApproveKeywordsCommand::class => ApproveKeywordsHandler::class,
            ApproveKeywordClustersCommand::class => ApproveKeywordClustersHandler::class,
            BuildTopicalMapCommand::class => BuildTopicalMapHandler::class,
            PreviewContentProjectFromClustersCommand::class => PreviewContentProjectFromClustersHandler::class,
            CreateContentProjectFromKeywordClustersCommand::class => CreateContentProjectFromKeywordClustersHandler::class,
            ArchiveKeywordWorkspaceCommand::class => ArchiveKeywordWorkspaceHandler::class,
        ];

        foreach ($map as $command => $handler) {
            $bus->register($command, $this->app->make($handler));
        }
    }
}
