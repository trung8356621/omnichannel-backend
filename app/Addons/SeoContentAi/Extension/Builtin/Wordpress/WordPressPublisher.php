<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Builtin\Wordpress;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\ArticlePublishPayload;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\ContentPublisher;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\PublishResult;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\ManualWordPressContext;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Built-in WordPress publisher — Application chỉ resolve qua PublisherResolver / ContentPublisherRegistry.
 * At-least-once + idempotent: reconcile wp_post_id / external_reference trước khi tạo mới.
 */
final class WordPressPublisher implements ContentPublisher
{
    public function __construct(
        private readonly WordPressArticleSyncService $syncService,
    ) {}

    public function publish(ArticlePublishPayload $payload): PublishResult
    {
        if ($payload->wpPostId !== null && $payload->wpPostId > 0) {
            $this->recordAttempt($payload, 'published', null, $payload->wpPostId);

            return new PublishResult(
                success: true,
                wpPostId: $payload->wpPostId,
                message: 'Already published (wp_post_id present).',
                alreadyPublished: true,
                externalReference: $payload->externalReference,
            );
        }

        $existing = $this->findByExternalReference($payload->siteId, $payload->externalReference);
        if ($existing !== null && $existing > 0) {
            $this->stampArticleWpPost($payload->articleId, $existing);
            $this->recordAttempt($payload, 'published', null, $existing);

            return new PublishResult(
                success: true,
                wpPostId: $existing,
                message: 'Reconciled existing WordPress post.',
                alreadyPublished: true,
                externalReference: $payload->externalReference,
            );
        }

        $article = SeoArticle::query()->find($payload->articleId);
        if (! $article instanceof SeoArticle) {
            return new PublishResult(false, null, 'Article not found.');
        }

        $freshWp = (int) ($article->wp_post_id ?? 0);
        if ($freshWp > 0) {
            $this->recordAttempt($payload, 'published', null, $freshWp);

            return new PublishResult(
                true,
                $freshWp,
                'Already published on article.',
                true,
                $payload->externalReference,
            );
        }

        // Queue/system: ghi attempt + để runner emit business event (Automation WP context).
        if ($payload->actorUserId === null || $payload->actorUserId <= 0) {
            $this->recordAttempt($payload, 'requested', null);

            return new PublishResult(
                success: true,
                wpPostId: null,
                message: 'Publish delivery requested via queue event.',
                alreadyPublished: false,
                externalReference: $payload->externalReference,
                deliveryRequested: true,
            );
        }

        try {
            $sideEffect = new ManualWordPressContext(
                userId: $payload->actorUserId,
                requestId: $payload->attemptRef,
                articleId: $payload->articleId,
                siteId: $payload->siteId,
                reason: 'content_project.publish:'.$payload->attemptRef,
                correlationId: $payload->idempotencyKey ?? $payload->attemptRef,
            );

            $result = $this->syncService->publishForArticle($article, $sideEffect);
            $wpPostId = (int) ($article->fresh()?->wp_post_id ?? 0);
            if ($wpPostId <= 0 && is_array($result)) {
                $wpPostId = (int) ($result['wp_post_id'] ?? 0);
            }

            if ($wpPostId <= 0 && is_array($result) && ! ($result['success'] ?? false)) {
                $message = (string) ($result['message'] ?? 'WordPress publish failed.');
                $this->recordAttempt($payload, 'failed', $message);

                return new PublishResult(false, null, $message, externalReference: $payload->externalReference);
            }

            if ($wpPostId <= 0) {
                $this->recordAttempt($payload, 'failed', 'publish returned no wp_post_id');

                return new PublishResult(false, null, 'WordPress publish did not return wp_post_id.');
            }

            $this->recordAttempt($payload, 'published', null, $wpPostId);

            return new PublishResult(
                success: true,
                wpPostId: $wpPostId,
                message: 'Published to WordPress.',
                externalReference: $payload->externalReference,
            );
        } catch (Throwable $e) {
            $reconciled = $this->findByExternalReference($payload->siteId, $payload->externalReference);
            if ($reconciled !== null && $reconciled > 0) {
                $this->stampArticleWpPost($payload->articleId, $reconciled);
                $this->recordAttempt($payload, 'published', null, $reconciled);

                return new PublishResult(
                    true,
                    $reconciled,
                    'Reconciled after error/timeout.',
                    true,
                    $payload->externalReference,
                );
            }

            $fresh = (int) (SeoArticle::query()->find($payload->articleId)?->wp_post_id ?? 0);
            if ($fresh > 0) {
                $this->recordAttempt($payload, 'published', null, $fresh);

                return new PublishResult(true, $fresh, 'Reconciled article wp_post_id after error.', true, $payload->externalReference);
            }

            $this->recordAttempt($payload, 'failed', $e->getMessage());
            RuntimeLogger::warning('content_publisher.wordpress_failed', [
                'article_id' => $payload->articleId,
                'attempt_ref' => $payload->attemptRef,
                'message' => $e->getMessage(),
            ]);

            return new PublishResult(false, null, $e->getMessage());
        }
    }

    public function findByExternalReference(int $siteId, string $externalReference): ?int
    {
        unset($siteId);

        if ($externalReference === '' || ! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_publish_attempts')) {
            return null;
        }

        $row = DB::connection('omi_seo_ai')->table('seo_content_project_publish_attempts')
            ->where('external_reference', $externalReference)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->orderByDesc('id')
            ->first();

        return $row !== null ? (int) $row->wp_post_id : null;
    }

    private function stampArticleWpPost(int $articleId, int $wpPostId): void
    {
        SeoArticle::query()->whereKey($articleId)->update([
            'wp_post_id' => $wpPostId,
        ]);
    }

    private function recordAttempt(
        ArticlePublishPayload $payload,
        string $status,
        ?string $error,
        ?int $wpPostId = null,
    ): void {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_publish_attempts')) {
            return;
        }

        try {
            DB::connection('omi_seo_ai')->table('seo_content_project_publish_attempts')->updateOrInsert(
                ['attempt_ref' => $payload->attemptRef],
                [
                    'project_id' => $payload->projectId,
                    'task_id' => $payload->taskId,
                    'article_id' => $payload->articleId,
                    'external_reference' => $payload->externalReference,
                    'wp_post_id' => $wpPostId,
                    'status' => $status,
                    'idempotency_key' => $payload->idempotencyKey,
                    'last_error' => $error,
                    'requested_at' => now(),
                    'completed_at' => $status === 'published' ? now() : null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        } catch (Throwable) {
            // never break publish path
        }
    }
}
