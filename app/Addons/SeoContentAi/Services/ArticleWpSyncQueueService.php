<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Jobs\SyncArticleToWordPressFromQueueJob;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoDisplayTimezone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ArticleWpSyncQueueService
{
    public const META_KEY = 'wp_sync_queue';

    public const BUNDLE_META_KEY = 'wp_sync_queue_bundle';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorPersistService $persist,
        private readonly SeoDatabaseConnectionService $databaseConnection,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{success: bool, message: string, queued?: bool, queue?: array<string, mixed>}
     */
    public function enqueueFromEditorBundle(SeoArticle $article, array $bundle): array
    {
        $article->refresh();
        $existing = $this->readQueueMeta($article);

        if (in_array((string) ($existing['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            return [
                'success' => false,
                'message' => 'Bài viết đang trong hàng đợi đồng bộ. Vui lòng đợi hoặc xem tab Queue.',
            ];
        }

        $context = $this->buildContextForQueue($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $this->persist->persistLocalSilent($article->fresh() ?? $article, $context, $html);

        $article = $article->fresh() ?? $article;
        $now = now();

        $queuePayload = [
            'status' => self::STATUS_PENDING,
            'queued_at' => $now->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'error' => null,
            'user_id' => Auth::id(),
            'publish_immediately' => $this->resolvePublishImmediately($bundle),
            'scheduled_at' => $context->resolvePublishAtForSave()?->toIso8601String(),
        ];

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => json_encode($queuePayload, JSON_UNESCAPED_UNICODE)],
        );

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::BUNDLE_META_KEY],
            ['meta_value' => json_encode($this->compactBundleForQueue($bundle), JSON_UNESCAPED_UNICODE)],
        );

        SyncArticleToWordPressFromQueueJob::dispatch((int) $article->id);

        return [
            'success' => true,
            'message' => 'Đã đưa bài viết vào hàng đợi đồng bộ WordPress.',
            'queued' => true,
            'queue' => $queuePayload,
        ];
    }

    public function markProcessing(SeoArticle $article): void
    {
        $payload = $this->readQueueMeta($article);
        if ($payload === []) {
            return;
        }

        $payload['status'] = self::STATUS_PROCESSING;
        $payload['started_at'] = now()->toIso8601String();
        $payload['error'] = null;

        $this->writeQueueMeta($article, $payload);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markCompleted(SeoArticle $article, array $result = []): void
    {
        $payload = $this->readQueueMeta($article);
        if ($payload === []) {
            return;
        }

        $payload['status'] = self::STATUS_COMPLETED;
        $payload['finished_at'] = now()->toIso8601String();
        $payload['error'] = null;
        $payload['result_message'] = (string) ($result['message'] ?? '');

        $this->writeQueueMeta($article, $payload);
        $article = $this->bootstrapArticleDatabase($article);
        $article->articleMetas()->where('meta_key', self::BUNDLE_META_KEY)->delete();
        $this->purgeDispatchedJobsForArticle((int) $article->id);
    }

    public function clearQueueEntry(SeoArticle $article): void
    {
        if ($this->readQueueMeta($article) === []) {
            return;
        }

        $article = $this->bootstrapArticleDatabase($article);
        $article->articleMetas()->whereIn('meta_key', [self::META_KEY, self::BUNDLE_META_KEY])->delete();
    }

    public function markFailed(SeoArticle $article, string $error): void
    {
        $payload = $this->readQueueMeta($article);
        if ($payload === []) {
            $payload = [
                'queued_at' => now()->toIso8601String(),
            ];
        }

        $payload['status'] = self::STATUS_FAILED;
        $payload['finished_at'] = now()->toIso8601String();
        $payload['error'] = $error;

        $this->writeQueueMeta($article, $payload);
    }

    public function retry(SeoArticle $article): bool
    {
        $status = (string) ($this->readQueueMeta($article)['status'] ?? '');
        if ($status !== self::STATUS_FAILED) {
            return false;
        }

        return ($this->resync($article)['success'] ?? false);
    }

    /**
     * @return array{success: bool, message: string, queued?: bool}
     */
    public function resync(SeoArticle $article): array
    {
        $payload = $this->readQueueMeta($article);
        if ($payload === []) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy mục hàng đợi đồng bộ.',
            ];
        }

        if (! in_array((string) ($payload['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_PROCESSING], true)) {
            return [
                'success' => false,
                'message' => 'Chỉ có thể đồng bộ lại bài đang chờ, đang xử lý hoặc thất bại.',
            ];
        }

        $bundle = $this->readQueueBundle($article);
        if ($bundle === []) {
            return [
                'success' => false,
                'message' => 'Thiếu dữ liệu đồng bộ trong article_meta.',
            ];
        }

        $article = $this->bootstrapArticleDatabase($article);
        $now = now();

        $payload['status'] = self::STATUS_PENDING;
        $payload['queued_at'] = $now->toIso8601String();
        $payload['started_at'] = null;
        $payload['finished_at'] = null;
        $payload['error'] = null;
        $payload['user_id'] = Auth::id();
        $this->writeQueueMeta($article, $payload);

        SyncArticleToWordPressFromQueueJob::dispatch((int) $article->id);

        return [
            'success' => true,
            'message' => 'Đã đưa lại vào hàng đợi đồng bộ WordPress.',
            'queued' => true,
        ];
    }

    public function cancel(SeoArticle $article): bool
    {
        $payload = $this->readQueueMeta($article);
        if ($payload === []) {
            return false;
        }

        $status = (string) ($payload['status'] ?? '');
        if (! in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_PROCESSING, self::STATUS_COMPLETED], true)) {
            return false;
        }

        $article = $this->bootstrapArticleDatabase($article);
        $deleted = $article->articleMetas()
            ->whereIn('meta_key', [self::META_KEY, self::BUNDLE_META_KEY])
            ->delete();

        return $deleted > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function readQueueMeta(SeoArticle $article): array
    {
        $raw = $this->readQueueMetaRaw($article);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function readQueueBundle(SeoArticle $article): array
    {
        $raw = $this->readQueueBundleRaw($article);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function queueStatusLabel(SeoArticle $article): ?string
    {
        $status = (string) ($this->readQueueMeta($article)['status'] ?? '');
        if ($status === '') {
            return null;
        }

        return match ($status) {
            self::STATUS_PENDING => __('seo-content-ai::filament.article_list.queue_status_pending'),
            self::STATUS_PROCESSING => __('seo-content-ai::filament.article_list.queue_status_processing'),
            self::STATUS_COMPLETED => __('seo-content-ai::filament.article_list.queue_status_completed'),
            self::STATUS_FAILED => __('seo-content-ai::filament.article_list.queue_status_failed'),
            default => $status,
        };
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function prepareBundleForImmediateSync(array $bundle): array
    {
        $now = SeoDisplayTimezone::now();
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $bundle['publish_box'] = array_merge($publishBox, [
            'publish_immediately' => false,
            'status' => 'published',
            'publish_day' => $now->format('d'),
            'publish_month' => $now->format('m'),
            'publish_year' => $now->format('Y'),
            'publish_hour' => $now->format('H'),
            'publish_minute' => $now->format('i'),
        ]);

        return $bundle;
    }

    private function bootstrapArticleDatabase(SeoArticle $article): SeoArticle
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();

        $article = $article->fresh() ?? $article;
        if ((int) ($article->site_id ?? 0) <= 0) {
            return $article;
        }

        $this->databaseConnection->bootstrapSeoDatabaseConnection((int) $article->site_id);
        $fresh = SeoArticle::query()->find($article->getKey());

        return $fresh instanceof SeoArticle ? $fresh : $article;
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function buildContextForQueue(SeoArticle $article, array $bundle): ArticleEditorSaveContext
    {
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        if ($this->resolvePublishImmediately($bundle)) {
            $scheduledAt = SeoDisplayTimezone::now()->addMinutes(5);
            $publishBox = array_merge($publishBox, [
                'status' => 'scheduled',
                'publish_day' => $scheduledAt->format('d'),
                'publish_month' => $scheduledAt->format('m'),
                'publish_year' => $scheduledAt->format('Y'),
                'publish_hour' => $scheduledAt->format('H'),
                'publish_minute' => $scheduledAt->format('i'),
            ]);
            $bundle['publish_box'] = $publishBox;
        }

        return ArticleEditorSaveContext::fromBundle($article, $bundle);
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function resolvePublishImmediately(array $bundle): bool
    {
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        return filter_var($publishBox['publish_immediately'] ?? true, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function compactBundleForQueue(array $bundle): array
    {
        return [
            'html' => (string) ($bundle['html'] ?? ''),
            'seo_analysis' => $bundle['seo_analysis'] ?? null,
            'article_meta' => is_array($bundle['article_meta'] ?? null) ? $bundle['article_meta'] : [],
            'publish_box' => is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [],
            'category_ids' => $bundle['category_ids'] ?? null,
            'featured_image' => $bundle['featured_image'] ?? null,
            'product_album' => $bundle['product_album'] ?? null,
            'faqs' => $bundle['faqs'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeQueueMeta(SeoArticle $article, array $payload): void
    {
        $article = $this->bootstrapArticleDatabase($article);
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );
    }

    private function readQueueMetaRaw(SeoArticle $article): string
    {
        if (isset($article->wp_sync_queue_meta) && is_string($article->wp_sync_queue_meta)) {
            return trim($article->wp_sync_queue_meta);
        }

        $article = $this->bootstrapArticleDatabase($article);
        $article->unsetRelation('articleMetas');

        return trim((string) $article->articleMetas()
            ->where('meta_key', self::META_KEY)
            ->value('meta_value') ?? '');
    }

    private function readQueueBundleRaw(SeoArticle $article): string
    {
        if (isset($article->wp_sync_queue_bundle) && is_string($article->wp_sync_queue_bundle)) {
            return trim($article->wp_sync_queue_bundle);
        }

        $article = $this->bootstrapArticleDatabase($article);
        $article->unsetRelation('articleMetas');

        return trim((string) $article->articleMetas()
            ->where('meta_key', self::BUNDLE_META_KEY)
            ->value('meta_value') ?? '');
    }

    public function purgeDispatchedJobsForArticle(int $articleId): void
    {
        if ($articleId <= 0) {
            return;
        }

        try {
            $connection = $this->jobsConnection();

            foreach (
                DB::connection($connection)
                    ->table('jobs')
                    ->select(['id', 'payload'])
                    ->where('payload', 'like', '%SyncArticleToWordPressFromQueueJob%')
                    ->cursor() as $job
            ) {
                if ($this->extractArticleIdFromJobPayload((string) ($job->payload ?? '')) !== $articleId) {
                    continue;
                }

                DB::connection($connection)->table('jobs')->where('id', $job->id)->delete();
            }
        } catch (Throwable) {
            // Queue table may be unavailable in some environments.
        }
    }

    public function extractArticleIdFromJobPayload(string $payload): ?int
    {
        if (preg_match('/s:\d+:"articleId";i:(\d+)/', $payload, $matches) === 1) {
            $articleId = (int) $matches[1];

            return $articleId > 0 ? $articleId : null;
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return null;
        }

        $command = $decoded['data']['command'] ?? null;
        if (! is_string($command) || $command === '') {
            return null;
        }

        $job = @unserialize($command, ['allowed_classes' => [SyncArticleToWordPressFromQueueJob::class]]);
        if ($job instanceof SyncArticleToWordPressFromQueueJob) {
            return $job->articleId > 0 ? $job->articleId : null;
        }

        return null;
    }

    private function jobsConnection(): string
    {
        $connection = (string) config('queue.connections.'.config('queue.default').'.connection');

        return $connection !== '' ? $connection : (string) config('database.default');
    }
}
