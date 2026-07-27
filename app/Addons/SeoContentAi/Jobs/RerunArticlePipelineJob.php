<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Enums\ContentProjectErrorCode;
use App\Addons\SeoContentAi\Enums\SeoProjectRunItemStatus;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoArticleRevision;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectRunItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Services\ArticlePipelineRerunService;
use App\Addons\SeoContentAi\Services\ArticlePipelineRerunStartStepResolver;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPostRunPipeline;
use App\Addons\SeoContentAi\Services\SeoArticleRevisionService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Services\SeoProjectRunItemService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use App\Addons\SeoContentAi\Services\TaskTestInputResolver;
use App\Addons\SeoContentAi\Services\TaskWorkflowTestRunner;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RerunArticlePipelineJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(
        public int $runId,
        public int $articleId,
        public string $fromStep,
        public ?int $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'article-pipeline-rerun:'.$this->articleId.':'.$this->fromStep;
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ArticlePipelineRerunService $rerunService,
        ArticlePipelineRerunStartStepResolver $startStepResolver,
        SeoProjectRunItemService $runItemService,
        SeoProjectWorkflowRunService $workflowRunService,
        TaskTestInputResolver $inputResolver,
        TaskWorkflowTestRunner $workflowRunner,
        SeoArticleRevisionService $revisionService,
        ContentProjectPostRunPipeline $postRunPipeline,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $article = SeoArticle::query()->find($this->articleId);
        if ($article instanceof SeoArticle && (int) ($article->site_id ?? 0) > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection((int) $article->site_id);
            $article = SeoArticle::query()->find($this->articleId);
        }

        $run = SeoProjectRun::query()->find($this->runId);
        if (! $article instanceof SeoArticle || ! $run instanceof SeoProjectRun) {
            Log::warning('seo.article_pipeline_rerun.missing', [
                'run_id' => $this->runId,
                'article_id' => $this->articleId,
            ]);

            return;
        }

        if ($this->userId !== null && $this->userId > 0) {
            $user = User::query()->find($this->userId);
            if ($user !== null) {
                auth()->setUser($user);
            }
        }

        $fromStep = $rerunService->normalizeFromStep($this->fromStep);
        $rerunService->writeRerunMeta($article, array_merge($rerunService->readRerunMeta($article), [
            'run_id' => (int) $run->id,
            'status' => ArticlePipelineRerunService::STATUS_RUNNING,
            'from' => $fromStep,
            'started_at' => now()->toIso8601String(),
        ]));

        $task = $rerunService->resolveProjectTask($article);
        $project = $run->project ?? ($task?->project);
        if (! $task instanceof SeoProjectTask || ! $project instanceof SeoProject) {
            $this->failRun($run, null, $article, $rerunService, $runItemService, ArticlePipelineRerunService::BLOCK_NO_PROJECT);

            return;
        }

        $seoTask = null;
        $settings = is_array($run->settings) ? $run->settings : [];
        $sourceNodeId = trim((string) ($settings['start_node_id'] ?? ''));
        $sourceNodeId = $sourceNodeId !== '' ? $sourceNodeId : null;

        $resolved = $startStepResolver->resolve(
            $task,
            $fromStep,
            $sourceNodeId,
        );
        $startStepResolver->logResolution($resolved, [
            'article_id' => (int) $article->id,
            'run_id' => (int) $run->id,
            'project_id' => (int) $project->id,
            'task_id' => (int) $task->id,
            'from_step' => $fromStep,
            'phase' => 'job',
        ]);

        if (! $resolved['ok'] || ! $resolved['seo_task'] instanceof SeoTask || $resolved['resolved_node_id'] === null) {
            $this->failRun(
                $run,
                null,
                $article,
                $rerunService,
                $runItemService,
                (string) ($resolved['message']
                    ?? 'Workflow của bài viết đã thay đổi và không còn bước tương ứng. Vui lòng chọn lại bước bắt đầu.'),
            );

            return;
        }

        $seoTask = $resolved['seo_task'];
        $startNodeId = (string) $resolved['resolved_node_id'];

        $run->update([
            'settings' => array_merge($settings, [
                'semantic_key' => $resolved['semantic_key'],
                'resolved_node_id' => $startNodeId,
                'source_node_id' => $resolved['source_node_id'],
                'resolution_strategy' => $resolved['strategy'],
                'start_node_id' => $startNodeId,
            ]),
        ]);

        $action = $runItemService->resolveAction($task);
        $claim = $runItemService->claimForExecution($run, (int) $task->id, $action, forceRetry: true);
        $runItem = $claim['run_item'] ?? null;
        if (! $runItem instanceof SeoProjectRunItem || ($claim['outcome'] ?? '') !== 'claimed') {
            if (! $runItem instanceof SeoProjectRunItem) {
                $runItem = $runItemService->prepareOperation($run, $project, $task);
            }
            $runItem->update([
                'status' => SeoProjectRunItemStatus::Processing->value,
                'started_at' => now(),
                'message' => 'Đang chạy lại pipeline ('.$fromStep.').',
            ]);
        }

        if (! $runItem instanceof SeoProjectRunItem) {
            $this->failRun($run, null, $article, $rerunService, $runItemService, 'Không tạo được run item.');

            return;
        }

        $snapshot = $revisionService->buildArticleCompareSnapshot($article);
        $revision = $revisionService->captureAfterSave(
            $article,
            (string) $snapshot['title'],
            (string) $snapshot['content'],
            is_array($snapshot['seo_meta'] ?? null) ? $snapshot['seo_meta'] : [],
            $this->userId,
            true,
        );

        if (! $revision instanceof \App\Addons\SeoContentAi\Models\SeoArticleRevision) {
            throw new \RuntimeException('Không tạo được revision rollback cho pipeline rerun.');
        }

        $projectSiteId = (int) ($project->site_id ?? 0);

        try {
            $context = $inputResolver->resolveForProjectTask(
                $task,
                static function ($builder) use ($projectSiteId): void {
                    if ($projectSiteId > 0) {
                        $builder->where('site_id', $projectSiteId);
                    }
                },
            );

            if (! $context->article instanceof SeoArticle) {
                $context = $context->withArticle($article);
            }

            $seedOutline = $fromStep === ArticlePipelineRerunService::FROM_ARTICLE;
            try {
                $steps = $workflowRunner->runFromNodeId(
                    $seoTask,
                    $context,
                    $startNodeId,
                    $seedOutline,
                );
            } catch (\InvalidArgumentException $exception) {
                $message = $exception->getMessage();
                if (str_contains($message, 'Không tìm thấy bước bắt đầu')) {
                    $message = 'Workflow của bài viết đã thay đổi và không còn bước tương ứng. Vui lòng chọn lại bước bắt đầu.';
                }
                $this->failRun($run, $runItem, $article, $rerunService, $runItemService, $message);

                return;
            }

            $failed = collect($steps)->first(
                static fn (array $step): bool => in_array((string) ($step['status'] ?? ''), ['failed', 'error'], true),
            );

            if (is_array($failed)) {
                $this->rollbackAndFail(
                    $article,
                    $revision,
                    $revisionService,
                    $run,
                    $runItem,
                    $rerunService,
                    $runItemService,
                    (string) ($failed['message'] ?? 'Bước pipeline thất bại.'),
                    $steps,
                );

                return;
            }

            $freshArticle = SeoArticle::query()->find((int) $article->id) ?? $article;
            $workflowRunner->applyParsedMetaFromSteps($freshArticle, $steps);

            $post = $postRunPipeline->apply($task, $run, $freshArticle, $runItem);
            $message = 'Đã chạy lại quy trình từ '.$fromStep.'.'.(string) ($post['message_suffix'] ?? '');

            $runItemService->markSuccess(
                $runItem,
                (int) $freshArticle->id,
                $message,
                [
                    'rerun_from_step' => $fromStep,
                    'start_node_id' => $startNodeId,
                    'steps' => $steps,
                    'revision_id' => (int) $revision->id,
                ],
            );

            $freshArticle->articleMetas()->updateOrCreate(
                ['meta_key' => 'content_project_run'],
                [
                    'meta_value' => json_encode([
                        'run_id' => (int) $run->id,
                        'project_id' => (int) $run->project_id,
                        'task_id' => (int) $task->id,
                        'ran_at' => now()->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE),
                ],
            );

            $workflowRunService->markRunCompletedQuietly($run->fresh() ?? $run);

            $rerunService->writeRerunMeta($freshArticle, [
                'run_id' => (int) $run->id,
                'project_id' => (int) $run->project_id,
                'task_id' => (int) $task->id,
                'from' => $fromStep,
                'status' => ArticlePipelineRerunService::STATUS_COMPLETED,
                'completed_at' => now()->toIso8601String(),
                'message' => $message,
                'revision_id' => (int) $revision->id,
            ]);
        } catch (Throwable $exception) {
            Log::error('seo.article_pipeline_rerun.failed', [
                'run_id' => (int) $run->id,
                'article_id' => (int) $article->id,
                'message' => $exception->getMessage(),
            ]);

            $this->rollbackAndFail(
                $article,
                $revision,
                $revisionService,
                $run,
                $runItem,
                $rerunService,
                $runItemService,
                $exception->getMessage(),
                [],
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function rollbackAndFail(
        SeoArticle $article,
        SeoArticleRevision $revision,
        SeoArticleRevisionService $revisionService,
        SeoProjectRun $run,
        SeoProjectRunItem $runItem,
        ArticlePipelineRerunService $rerunService,
        SeoProjectRunItemService $runItemService,
        string $message,
        array $steps,
    ): void {
        try {
            $revisionService->restoreRevisionToArticle($article->fresh() ?? $article, $revision);
        } catch (Throwable $restoreError) {
            Log::error('seo.article_pipeline_rerun.rollback_failed', [
                'article_id' => (int) $article->id,
                'revision_id' => (int) $revision->id,
                'message' => $restoreError->getMessage(),
            ]);
        }

        $runItemService->markFailed(
            $runItem,
            ContentProjectErrorCode::ExternalWorkflowFailed,
            $message,
            message: $message,
            outputSnapshot: [
                'rerun_from_step' => $this->fromStep,
                'steps' => $steps,
                'revision_id' => (int) $revision->id,
                'rolled_back' => true,
            ],
        );

        $synced = $runItemService->syncMirrorAndCounters($run->fresh() ?? $run, false);
        $synced->update([
            'status' => SeoProjectRun::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        $rerunService->writeRerunMeta($article->fresh() ?? $article, [
            'run_id' => (int) $run->id,
            'from' => $rerunService->normalizeFromStep($this->fromStep),
            'status' => ArticlePipelineRerunService::STATUS_FAILED,
            'failed_at' => now()->toIso8601String(),
            'message' => $message,
            'revision_id' => (int) $revision->id,
        ]);
    }

    private function failRun(
        SeoProjectRun $run,
        ?SeoProjectRunItem $runItem,
        SeoArticle $article,
        ArticlePipelineRerunService $rerunService,
        SeoProjectRunItemService $runItemService,
        string $message,
    ): void {
        if ($runItem instanceof SeoProjectRunItem) {
            $runItemService->markFailed(
                $runItem,
                ContentProjectErrorCode::ExternalWorkflowFailed,
                $message,
                message: $message,
            );
        }

        $synced = $runItemService->syncMirrorAndCounters($run->fresh() ?? $run, false);
        $synced->update([
            'status' => SeoProjectRun::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        $rerunService->writeRerunMeta($article, [
            'run_id' => (int) $run->id,
            'from' => $rerunService->normalizeFromStep($this->fromStep),
            'status' => ArticlePipelineRerunService::STATUS_FAILED,
            'failed_at' => now()->toIso8601String(),
            'message' => $message,
        ]);
    }
}
