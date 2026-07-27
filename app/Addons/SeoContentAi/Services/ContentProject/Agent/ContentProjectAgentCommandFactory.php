<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Agent;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\MoveProjectItemScheduleCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\StartReviewCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\AnalyzeKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ApproveKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromKeywordClustersCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromClustersCommand;
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
                is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
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
            'content_project.restore' => new RestoreContentProjectCommand(
                $this->projectRef($input),
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
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
            ),
            'keyword_intelligence.preview_convert' => new PreviewContentProjectFromClustersCommand(
                $this->workspaceRef($input),
                $this->clusterRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
            ),
            'keyword_intelligence.convert_to_content_project' => new CreateContentProjectFromKeywordClustersCommand(
                $this->workspaceRef($input),
                $this->clusterRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'keyword_intelligence.archive_workspace' => new ArchiveKeywordWorkspaceCommand(
                $this->workspaceRef($input),
            ),
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
}
