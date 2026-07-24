<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Jobs\RerunArticlePipelineJob;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ContentProjectRunSettings;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tạo SeoProjectRun mới (1 task) để chạy lại pipeline từ outline|article.
 */
final class ArticlePipelineRerunService
{
    public const FROM_OUTLINE = 'outline';

    public const FROM_ARTICLE = 'article';

    public const META_KEY = 'content_project_rerun';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const BLOCK_NO_PROJECT = 'Bài viết phải được gắn vào Content Project trước khi chạy lại quy trình.';

    public function __construct(
        private readonly SeoProjectWorkflowRunService $workflowRunService,
        private readonly SeoProjectRunItemService $runItemService,
        private readonly SeoProjectWorkflowStepCatalogService $catalog,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     blocked?: bool,
     *     busy?: bool,
     *     message: string,
     *     run_id?: int|null,
     *     run_url?: string|null,
     *     status?: string|null
     * }
     */
    public function queue(SeoArticle $article, string $fromStep, ?int $userId = null): array
    {
        $fromStep = $this->normalizeFromStep($fromStep);

        if (! SeoAccessControl::canAccessManagerFeatures()) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Bạn không có quyền chạy lại quy trình bài viết.',
            ];
        }

        if (! SeoAccessControl::canAccessArticle($article)) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Bạn không có quyền truy cập bài viết này.',
            ];
        }

        if ($article->trashed()) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Không thể chạy lại quy trình cho bài viết đã xóa.',
            ];
        }

        $task = $this->resolveProjectTask($article);
        if (! $task instanceof SeoProjectTask) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => self::BLOCK_NO_PROJECT,
            ];
        }

        $project = $task->project ?? SeoProject::query()->find((int) $task->project_id);
        if (! $project instanceof SeoProject) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => self::BLOCK_NO_PROJECT,
            ];
        }

        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Bạn không có quyền chạy Content Project của bài viết này.',
            ];
        }

        if ($task->archived_at !== null || (string) $task->status === 'archived') {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Task Content Project đang archive — không chạy lại quy trình.',
            ];
        }

        $startNodeId = $this->catalog->firstPromptNodeIdForKind(
            $task,
            $fromStep === self::FROM_ARTICLE ? 'content' : 'outline',
        );
        if ($startNodeId === null || $startNodeId === '') {
            return [
                'success' => false,
                'blocked' => true,
                'message' => $fromStep === self::FROM_ARTICLE
                    ? 'Workflow chưa có bước viết bài (content) để chạy lại.'
                    : 'Workflow chưa có bước dàn ý (outline) để chạy lại.',
            ];
        }

        $lockKey = $this->lockKey((int) $article->id, $fromStep);
        $lock = Cache::lock($lockKey, 30);
        if (! $lock->get()) {
            return [
                'success' => false,
                'busy' => true,
                'message' => 'Yêu cầu chạy lại đang được xử lý. Vui lòng đợi.',
            ];
        }

        try {
            if ($this->findActiveRerun((int) $article->id, $fromStep) instanceof SeoProjectRun) {
                return [
                    'success' => false,
                    'busy' => true,
                    'message' => 'Đã có lần chạy lại đang chờ hoặc đang chạy cho phạm vi này.',
                ];
            }

            $sourceRunId = $this->resolveSourceRunId($article);
            $baseSettings = $this->baseSettingsFromSource($sourceRunId);

            $run = DB::connection('omi_seo_ai')->transaction(function () use (
                $project,
                $task,
                $article,
                $fromStep,
                $sourceRunId,
                $baseSettings,
                $startNodeId,
            ): SeoProjectRun {
                $run = $this->workflowRunService->startRun(
                    $project,
                    SeoProjectRun::MODE_FULL,
                    $baseSettings,
                );

                $settings = is_array($run->settings) ? $run->settings : [];
                $run->update([
                    'settings' => array_merge($settings, [
                        'run_type' => 'rerun',
                        'rerun_from_step' => $fromStep,
                        'source_run_id' => $sourceRunId,
                        'article_id' => (int) $article->id,
                        'start_node_id' => $startNodeId,
                    ]),
                ]);

                if ((int) ($task->article_id ?? 0) !== (int) $article->id) {
                    $task->article_id = (int) $article->id;
                    $task->save();
                }

                $this->runItemService->prepareOperation($run->fresh() ?? $run, $project, $task->fresh() ?? $task);
                $this->runItemService->syncMirrorAndCounters($run->fresh() ?? $run, false);

                return $run->fresh() ?? $run;
            });

            $this->writeRerunMeta($article, [
                'run_id' => (int) $run->id,
                'project_id' => (int) $project->id,
                'task_id' => (int) $task->id,
                'from' => $fromStep,
                'status' => self::STATUS_RUNNING,
                'queued_at' => now()->toIso8601String(),
                'started_at' => now()->toIso8601String(),
                'message' => null,
            ]);

            @set_time_limit(0);

            // Chạy ngay trong request (không đưa vào queue worker).
            RerunArticlePipelineJob::dispatchSync(
                (int) $run->id,
                (int) $article->id,
                $fromStep,
                $userId,
            );

            $final = $this->statusPayload($article->fresh() ?? $article);
            $finalStatus = (string) ($final['status'] ?? self::STATUS_FAILED);
            $ok = $finalStatus === self::STATUS_COMPLETED;

            return [
                'success' => $ok,
                'message' => $ok
                    ? (string) ($final['message'] ?: 'Đã chạy lại quy trình thành công.')
                    : (string) ($final['message'] ?: 'Chạy lại quy trình thất bại.'),
                'run_id' => (int) $run->id,
                'run_url' => SeoProjectResource::getUrl('view-run', ['run' => $run->id]),
                'status' => $finalStatus !== '' ? $finalStatus : self::STATUS_FAILED,
            ];
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array{status: string|null, run_id: int|null, run_url: string|null, from: string|null, message: string|null, busy: bool}
     */
    public function statusPayload(SeoArticle $article): array
    {
        $meta = $this->readRerunMeta($article);
        $runId = (int) ($meta['run_id'] ?? 0);
        $status = isset($meta['status']) ? (string) $meta['status'] : null;
        $busy = in_array($status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);

        return [
            'status' => $status,
            'run_id' => $runId > 0 ? $runId : null,
            'run_url' => $runId > 0
                ? SeoProjectResource::getUrl('view-run', ['run' => $runId])
                : null,
            'from' => isset($meta['from']) ? (string) $meta['from'] : null,
            'message' => isset($meta['message']) ? (string) $meta['message'] : null,
            'busy' => $busy,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function writeRerunMeta(SeoArticle $article, array $payload): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function readRerunMeta(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', self::META_KEY)?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function resolveProjectTask(SeoArticle $article): ?SeoProjectTask
    {
        $task = SeoProjectTask::query()
            ->where('article_id', (int) $article->id)
            ->orderByDesc('id')
            ->first();

        if ($task instanceof SeoProjectTask) {
            return $task;
        }

        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', 'content_project_run')?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $taskId = (int) ($decoded['task_id'] ?? 0);
        if ($taskId <= 0) {
            return null;
        }

        $task = SeoProjectTask::query()->find($taskId);
        if (! $task instanceof SeoProjectTask) {
            return null;
        }

        if ((int) ($task->article_id ?? 0) === 0) {
            $task->article_id = (int) $article->id;
            $task->save();
        }

        return (int) ($task->article_id ?? 0) === (int) $article->id ? $task : null;
    }

    public function normalizeFromStep(string $fromStep): string
    {
        $fromStep = trim(mb_strtolower($fromStep));

        return match ($fromStep) {
            self::FROM_ARTICLE, 'content' => self::FROM_ARTICLE,
            default => self::FROM_OUTLINE,
        };
    }

    public function lockKey(int $articleId, string $fromStep): string
    {
        return 'seo:article-pipeline-rerun:'.$articleId.':'.$this->normalizeFromStep($fromStep);
    }

    public function findActiveRerun(int $articleId, string $fromStep): ?SeoProjectRun
    {
        $fromStep = $this->normalizeFromStep($fromStep);

        return SeoProjectRun::query()
            ->where('status', SeoProjectRun::STATUS_RUNNING)
            ->where('settings->run_type', 'rerun')
            ->where('settings->article_id', $articleId)
            ->where('settings->rerun_from_step', $fromStep)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Đánh dấu các rerun run còn RUNNING (request cũ đứt) thành failed — tránh khóa UI/idempotency.
     */
    public function abandonStaleActiveRuns(int $articleId): int
    {
        $runs = SeoProjectRun::query()
            ->where('status', SeoProjectRun::STATUS_RUNNING)
            ->where('settings->run_type', 'rerun')
            ->where('settings->article_id', $articleId)
            ->get();

        $count = 0;
        foreach ($runs as $run) {
            if (! $run instanceof SeoProjectRun) {
                continue;
            }

            $run->update([
                'status' => SeoProjectRun::STATUS_FAILED,
                'error_message' => 'Rerun bị gián đoạn (stale).',
                'finished_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    private function resolveSourceRunId(SeoArticle $article): ?int
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', 'content_project_run')?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $runId = (int) ($decoded['run_id'] ?? 0);

        return $runId > 0 ? $runId : null;
    }

    /**
     * @return array{generate_post_images: bool, settings_version: int}
     */
    private function baseSettingsFromSource(?int $sourceRunId): array
    {
        if ($sourceRunId === null || $sourceRunId <= 0) {
            return ContentProjectRunSettings::defaults()->toArray();
        }

        $source = SeoProjectRun::query()->find($sourceRunId);
        if (! $source instanceof SeoProjectRun) {
            return ContentProjectRunSettings::defaults()->toArray();
        }

        return ContentProjectRunSettings::fromRun($source)->toArray();
    }
}
