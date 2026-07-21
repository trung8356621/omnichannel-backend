<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ProductReview\ProductReviewPostSyncReconciler;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\AutomationWordPressContext;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Support\SeoQueueContext;
use Illuminate\Support\Str;

final class SyncArticleToWordPressHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly WordPressArticleSyncService $syncService,
        private readonly BusinessHookEmitter $emitter,
        private readonly ProductReviewPostSyncReconciler $productReviewReconciler,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        if ($context->execution->id <= 0) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::ExecutionClaimFailed->value,
                'wordpress.article.sync requires automation_execution_id.',
            );
        }

        $articleId = (int) ($input['article_id'] ?? 0);
        if ($articleId <= 0) {
            return AutomationActionResult::failure('INVALID_ARTICLE_ID', 'article_id is required.');
        }

        if ($context->subject instanceof SeoArticle && (int) $context->subject->getKey() === $articleId) {
            $article = $context->subject;
        } else {
            $article = SeoArticle::query()->find($articleId);
        }

        if (! $article instanceof SeoArticle) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::SubjectNotFound->value,
                "Article [{$articleId}] not found.",
            );
        }

        $idempotencyKey = hash(
            'sha256',
            ($context->execution->context['idempotency_key'] ?? $context->execution->idempotency_key)
            .'|wordpress.article.sync|'
            .$articleId,
        );

        $eventUuid = (string) ($context->businessEvent->event_uuid
            ?? $context->execution->context['event_uuid']
            ?? '');

        $sideEffect = new AutomationWordPressContext(
            automationExecutionId: (int) $context->execution->id,
            automationNodeExecutionId: $context->nodeExecutionId,
            businessEventUuid: $eventUuid !== '' ? $eventUuid : (string) Str::uuid(),
            idempotencyKey: $idempotencyKey,
            articleId: $articleId,
            siteId: (int) ($context->siteId ?? $article->site_id ?? 0),
            correlationId: (string) ($context->correlationId ?? $context->execution->execution_uuid ?? Str::uuid()),
        );

        $mode = (string) ($settings['mode'] ?? 'sync');
        /** @var array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null $seoOverride */
        $seoOverride = is_array($settings['seo_override'] ?? null) ? $settings['seo_override'] : null;

        $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncStarted, $article, [
            'article_id' => $articleId,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => 'started',
        ]);

        // Queue worker không Auth → actualRole() = content_manager → chặn sync nếu thiếu SeoQueueContext.
        try {
            $result = SeoQueueContext::runWpSyncFromQueue(function () use (
                $mode,
                $article,
                $sideEffect,
                $seoOverride,
                $settings,
            ): array {
                return match ($mode) {
                    'publish' => $this->syncService->publishForArticle($article, $sideEffect, $seoOverride),
                    'seo_meta' => $this->syncService->syncSeoMetaForArticle($article, $sideEffect, $seoOverride ?? []),
                    'slug' => $this->syncService->syncSlugForArticle(
                        $article,
                        $sideEffect,
                        (string) ($settings['slug'] ?? $article->slug ?? ''),
                    ),
                    default => $this->syncService->syncForArticle($article, $sideEffect, $seoOverride),
                };
            });
        } catch (\Throwable $wordpressException) {
            $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncFailed, $article, [
                'article_id' => $articleId,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'error' => $wordpressException->getMessage(),
                'status' => 'failed',
            ]);

            return AutomationActionResult::failure(
                'WORDPRESS_SYNC_EXCEPTION',
                $wordpressException->getMessage(),
                [
                    'article_id' => $articleId,
                    'idempotency_key' => $idempotencyKey,
                    'mode' => $mode,
                    'wp_success' => false,
                    'failed_stage' => 'wordpress.operation',
                    'exception_class' => $wordpressException::class,
                    'exception_message' => $wordpressException->getMessage(),
                ],
            );
        }

        if (! ($result['success'] ?? false)) {
            $errorCode = (string) ($result['error_code'] ?? 'WORDPRESS_SYNC_FAILED');
            $failedStage = (string) ($result['failed_stage'] ?? 'wordpress.operation');
            $message = (string) ($result['message'] ?? 'WordPress sync failed.');

            $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncFailed, $article, [
                'article_id' => $articleId,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'error' => $message,
                'status' => 'failed',
                'error_code' => $errorCode,
                'failed_stage' => $failedStage,
            ]);

            return AutomationActionResult::failure(
                $errorCode,
                $message,
                [
                    'article_id' => $articleId,
                    'idempotency_key' => $idempotencyKey,
                    'mode' => $mode,
                    'wp_success' => false,
                    'failed_stage' => $failedStage,
                    'exception_class' => $result['exception_class'] ?? null,
                    'exception_message' => $result['exception_message'] ?? null,
                    'step_detail' => $result['step_detail'] ?? null,
                ],
            );
        }

        $article = $article->fresh() ?? $article;
        $wpPostId = (int) ($result['wp_post_id'] ?? $article->wp_post_id ?? 0);

        $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSynced, $article, [
            'article_id' => $articleId,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'status' => 'synced',
        ]);

        // Safety net: event có thể SKIPPED_NO_RULE / miss queue — reconciler idempotent.
        $this->productReviewReconciler->reconcileAfterArticleSynced($article, $context->actorId);

        return AutomationActionResult::success(
            output: [
                'article_id' => $articleId,
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'message' => (string) ($result['message'] ?? 'synced'),
                'mode' => $mode,
                'idempotency_key' => $idempotencyKey,
                'trigger_type' => (string) ($context->execution->trigger_type ?? 'event'),
                'wp_success' => true,
            ],
            message: 'WordPress sync completed.',
        );
    }
}
