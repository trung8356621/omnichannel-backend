<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Jobs\SyncArticleToWordPressFromQueueJob;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\ManualWordPressContext;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoDisplayTimezone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Testing\Fakes\BusFake;
use Throwable;

final class ArticleWpSyncQueueService
{
    public const QUEUE_NAME = 'seo';

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
    public function enqueueFromEditorBundle(SeoArticle $article, array $bundle, ?ManualWordPressContext $manual = null): array
    {
        return [
            'success' => false,
            'message' => 'Legacy seo queue orchestration removed. Use WordPressManualSyncService / ManualWordPressSyncJob.',
        ];
    }

    public function markProcessing(SeoArticle $article): void
    {
        $payload = $this->readQueueMeta($article);
        if ($payload === []) {
            $payload = [
                'queued_at' => now()->toIso8601String(),
                'operation' => 'wordpress_sync',
            ];
        }

        $payload['status'] = self::STATUS_PROCESSING;
        $payload['stage'] = (string) ($payload['stage'] ?? 'processing');
        $payload['started_at'] = now()->toIso8601String();
        $payload['error'] = null;
        $payload['error_message'] = null;

        $this->writeQueueMeta($article, $payload);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function markQueued(SeoArticle $article, array $meta = []): array
    {
        $payload = array_merge([
            'operation' => 'wordpress_sync',
            'status' => self::STATUS_PENDING,
            'stage' => 'queued',
            'queued_at' => now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'error' => null,
            'error_message' => null,
            'wordpress_post_id' => null,
            'wordpress_permalink' => null,
        ], $meta, [
            'status' => self::STATUS_PENDING,
            'stage' => 'queued',
            'queued_at' => now()->toIso8601String(),
        ]);

        $this->writeQueueMeta($article, $payload);

        return $payload;
    }

    public function isActive(SeoArticle $article): bool
    {
        $status = (string) ($this->readQueueMeta($article)['status'] ?? '');

        return in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeOperation(SeoArticle $article): ?array
    {
        $payload = $this->readQueueMeta($article);
        if ($payload === []) {
            return null;
        }

        $status = (string) ($payload['status'] ?? '');
        if ($status === '') {
            return null;
        }

        return [
            'id' => (string) ($payload['request_id'] ?? $payload['correlation_id'] ?? ('wp-sync-'.(int) $article->id)),
            'article_id' => (int) $article->id,
            'operation' => (string) ($payload['operation'] ?? 'wordpress_sync'),
            'type' => (string) ($payload['operation'] ?? 'wordpress_sync'),
            'status' => $this->mapPublicStatus($status),
            'raw_status' => $status,
            'stage' => (string) ($payload['stage'] ?? $status),
            'error_message' => (string) ($payload['error_message'] ?? $payload['error'] ?? ''),
            'wordpress_post_id' => (int) ($payload['wordpress_post_id'] ?? 0) ?: null,
            'wordpress_permalink' => (string) ($payload['wordpress_permalink'] ?? '') ?: null,
            'queued_at' => $payload['queued_at'] ?? null,
            'started_at' => $payload['started_at'] ?? null,
            'finished_at' => $payload['finished_at'] ?? null,
            'request_id' => $payload['request_id'] ?? null,
            'correlation_id' => $payload['correlation_id'] ?? null,
        ];
    }

    private function mapPublicStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'queued',
            self::STATUS_PROCESSING => 'processing',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'failed',
            default => $status,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markCompleted(SeoArticle $article, array $result = [], bool $emitSyncedEvent = true): void
    {
        $payload = $this->readQueueMeta($article);
        if ($payload === []) {
            $payload = [
                'operation' => 'wordpress_sync',
                'queued_at' => now()->toIso8601String(),
            ];
        }

        $payload['status'] = self::STATUS_COMPLETED;
        $payload['stage'] = 'completed';
        $payload['finished_at'] = now()->toIso8601String();
        $payload['error'] = null;
        $payload['error_message'] = null;
        $payload['result_message'] = (string) ($result['message'] ?? '');
        $payload['wordpress_post_id'] = (int) ($result['wp_post_id'] ?? $result['wordpress_post_id'] ?? $article->wp_post_id ?? 0) ?: null;
        $payload['wordpress_permalink'] = (string) ($result['permalink'] ?? $result['wordpress_permalink'] ?? $article->permalink ?? '') ?: null;

        $this->writeQueueMeta($article, $payload);
        $article = $this->bootstrapArticleDatabase($article);
        $article->articleMetas()->where('meta_key', self::BUNDLE_META_KEY)->delete();

        if (! $emitSyncedEvent) {
            return;
        }

        // Emit only — pending product reviews owned by automation rule on wordpress.synced.
        $emitResult = is_array($result) ? $result : [];
        $emitResult['origin'] = $emitResult['origin'] ?? 'legacy_queue';
        app(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter::class)
            ->wordpressSynced($article, $emitResult);
    }

    public function clearQueueEntry(SeoArticle $article): void
    {
        if ($this->readQueueMeta($article) === []) {
            return;
        }

        $article = $this->bootstrapArticleDatabase($article);
        $article->articleMetas()->whereIn('meta_key', [self::META_KEY, self::BUNDLE_META_KEY])->delete();
        $this->purgeDispatchedJobsForArticle((int) $article->id);
    }

    public function markFailed(SeoArticle $article, string $error, bool $emitFailedEvent = true): void
    {
        $payload = $this->readQueueMeta($article);
        if ($payload === []) {
            $payload = [
                'queued_at' => now()->toIso8601String(),
                'operation' => 'wordpress_sync',
            ];
        }

        $payload['status'] = self::STATUS_FAILED;
        $payload['stage'] = 'failed';
        $payload['finished_at'] = now()->toIso8601String();
        $payload['error'] = $error;
        $payload['error_message'] = $error;

        $this->writeQueueMeta($article, $payload);

        if (! $emitFailedEvent) {
            return;
        }

        app(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter::class)
            ->wordpressSyncFailed($article, $error);
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
    public function resync(SeoArticle $article, ?ManualWordPressContext $manual = null): array
    {
        return [
            'success' => false,
            'message' => 'Legacy seo queue resync removed. Use WordPressManualSyncService / ManualWordPressSyncJob.',
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

        if ($deleted > 0) {
            $this->purgeDispatchedJobsForArticle((int) $article->id);
        }

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

    /**
     * Đăng ngay: ép publish_box.status = published (đè draft/scheduled cũ).
     * Trả về bundle mới — caller phải gán lại (PHP array truyền theo copy).
     *
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function applyPublishImmediatelyToBundle(array $bundle): array
    {
        if (! $this->resolvePublishImmediately($bundle)) {
            return $bundle;
        }

        $now = SeoDisplayTimezone::now();
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $bundle['publish_box'] = array_merge($publishBox, [
            'publish_immediately' => true,
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

    private function dispatchWpSyncJob(int $articleId): bool
    {
        Log::warning('wordpress.legacy_queue.dispatch_blocked', [
            'article_id' => $articleId,
            'message' => 'SyncArticleToWordPressFromQueueJob dispatch disabled — use WordPressManualSyncService.',
        ]);

        return false;
    }

    private function hasPendingWpSyncJob(int $articleId): bool
    {
        try {
            $jobs = DB::connection($this->jobsConnection())
                ->table('jobs')
                ->select(['payload'])
                ->where('queue', self::QUEUE_NAME)
                ->where('payload', 'like', '%SyncArticleToWordPressFromQueueJob%')
                ->get();

            foreach ($jobs as $job) {
                if ($this->extractArticleIdFromJobPayload((string) ($job->payload ?? '')) === $articleId) {
                    return true;
                }
            }
        } catch (Throwable) {
            return true;
        }

        return false;
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
