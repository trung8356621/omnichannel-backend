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
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\AutomationWordPressContext;
use App\Addons\SeoContentAi\Services\WordPress\SyncArticleToWordPressPipeline;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\SeoQueueContext;
use Illuminate\Support\Str;

/**
 * wordpress.article.sync — article/product + media only. No product review orchestration.
 */
final class SyncArticleToWordPressHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly SyncArticleToWordPressPipeline $pipeline,
        private readonly BusinessHookEmitter $emitter,
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
        $slug = (string) ($settings['slug'] ?? $article->slug ?? '');

        $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncStarted, $article, [
            'article_id' => $articleId,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => 'started',
        ]);

        try {
            $result = SeoQueueContext::runWpSyncFromQueue(function () use (
                $mode,
                $article,
                $sideEffect,
                $seoOverride,
                $slug,
            ): array {
                return $this->pipeline->run($article, $sideEffect, $mode, $seoOverride, $slug);
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
                ],
            );
        }

        if (! ($result['success'] ?? false)) {
            $errorCode = (string) ($result['error_code'] ?? 'WORDPRESS_SYNC_FAILED');
            $message = (string) ($result['message'] ?? 'WordPress sync failed.');

            $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncFailed, $article, [
                'article_id' => $articleId,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'error' => $message,
                'status' => 'failed',
                'error_code' => $errorCode,
            ]);

            return AutomationActionResult::failure($errorCode, $message, [
                'article_id' => $articleId,
                'idempotency_key' => $idempotencyKey,
                'mode' => $mode,
                'wp_success' => false,
            ]);
        }

        $article = $article->fresh() ?? $article;
        $wpPostId = (int) ($result['wp_post_id'] ?? $article->wp_post_id ?? 0);

        $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSynced, $article, [
            'article_id' => $articleId,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'status' => 'synced',
            'origin' => (string) ($context->execution->trigger_type ?? 'event'),
            'automation_execution_id' => (int) $context->execution->id,
            'sync_operation_id' => $idempotencyKey,
        ], [], $idempotencyKey);

        return AutomationActionResult::success(
            output: [
                'article_id' => $articleId,
                'post_type' => ArticlePostTypeResolver::resolve($article),
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'wordpress_connection_id' => (int) ($article->site_id ?? 0) ?: null,
                'sync_status' => 'completed',
                'message' => (string) ($result['message'] ?? 'synced'),
                'mode' => $mode,
                'idempotency_key' => $idempotencyKey,
                'wp_success' => true,
            ],
            message: 'WordPress article sync completed.',
        );
    }
}
