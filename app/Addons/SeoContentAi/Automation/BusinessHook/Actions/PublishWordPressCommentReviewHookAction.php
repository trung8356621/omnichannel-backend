<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter;
use App\Addons\SeoContentAi\Enums\ArticleProductReviewStatus;
use App\Addons\SeoContentAi\Enums\ProductReviewPublishIntent;
use App\Addons\SeoContentAi\Models\ArticleProductReview;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ProductReview\WordPressCommentReviewPublisher;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\AutomationWordPressContext;
use App\Addons\SeoContentAi\Support\SeoQueueContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class PublishWordPressCommentReviewHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly WordPressCommentReviewPublisher $publisher,
        private readonly BusinessHookEmitter $emitter,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        if ($context->execution->id <= 0) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::ExecutionClaimFailed->value,
                'wordpress.comment_review.publish requires automation_execution_id.',
            );
        }

        $reviewId = (int) ($input['review_id'] ?? 0);
        $articleId = (int) ($input['article_id'] ?? 0);
        $siteId = (int) ($input['site_id'] ?? 0);
        $connectionId = (int) ($input['connection_id'] ?? 0);
        $publishIntent = (string) ($input['publish_intent'] ?? ProductReviewPublishIntent::GeneratedReview->value);

        if ($reviewId <= 0 || $articleId <= 0) {
            return AutomationActionResult::failure('INVALID_INPUT', 'review_id and article_id are required.');
        }

        if ($siteId <= 0 || $connectionId <= 0) {
            return AutomationActionResult::failure('INVALID_CANONICAL_IDS', 'site_id and connection_id are required.');
        }

        if (ProductReviewPublishIntent::tryFrom($publishIntent) === null) {
            return AutomationActionResult::failure('INVALID_PUBLISH_INTENT', 'publish_intent is invalid.');
        }

        $review = ArticleProductReview::query()->find($reviewId);
        if (! $review instanceof ArticleProductReview) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::SubjectNotFound->value,
                "Review [{$reviewId}] not found.",
            );
        }

        if ((int) $review->article_id !== $articleId
            || (int) $review->site_id !== $siteId
            || (int) $review->connection_id !== $connectionId
        ) {
            return AutomationActionResult::failure(
                'REVIEW_SCOPE_MISMATCH',
                'review_id does not match site_id/connection_id/article_id.',
            );
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::SubjectNotFound->value,
                "Article [{$articleId}] not found.",
            );
        }

        if ((int) ($article->site_id ?? 0) !== $siteId) {
            return AutomationActionResult::failure(
                'ARTICLE_SITE_MISMATCH',
                'article site_id does not match input site_id.',
            );
        }

        if ($review->status === ArticleProductReviewStatus::Cancelled) {
            return AutomationActionResult::failure('REVIEW_CANCELLED', 'Review đã cancelled.');
        }

        if ($review->status === ArticleProductReviewStatus::Published
            && $review->last_error_code === 'LOCAL_FINALIZE_FAILED'
        ) {
            // fall through to publish which dedups
        }

        if ($review->status === ArticleProductReviewStatus::Publishing
            && $review->last_error_code === 'LOCAL_FINALIZE_FAILED'
        ) {
            $reconcile = $this->publisher->reconcileLocalFinalize($review, $article);
            if ($reconcile['success'] ?? false) {
                $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressCommentReviewPublished, $article, [
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'wp_post_id' => (int) ($article->wp_post_id ?? 0) ?: null,
                    'wp_comment_id' => $reconcile['wp_comment_id'] ?? null,
                    'status' => ArticleProductReviewStatus::Published->value,
                    'deduplicated' => true,
                ]);

                return AutomationActionResult::success([
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'wp_post_id' => (int) ($article->wp_post_id ?? 0) ?: null,
                    'wp_comment_id' => $reconcile['wp_comment_id'] ?? null,
                    'status' => ArticleProductReviewStatus::Published->value,
                    'deduplicated' => true,
                ], 'Reconciled local finalize.');
            }
        }

        $idempotencyKey = hash(
            'sha256',
            implode('|', [
                $connectionId,
                $articleId,
                $reviewId,
                (string) $review->content_hash,
                (string) ($article->wp_post_id ?? $input['wp_post_id'] ?? ''),
            ]),
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
            siteId: $siteId,
            correlationId: (string) ($context->correlationId ?? $context->execution->execution_uuid ?? Str::uuid()),
        );

        $result = SeoQueueContext::runWpSyncFromQueue(
            fn (): array => $this->publisher->publish($review->fresh() ?? $review, $article->fresh() ?? $article, $sideEffect),
        );

        if (($result['outcome'] ?? '') === 'SKIPPED_WAITING_FOR_ARTICLE') {
            return AutomationActionResult::success([
                'review_id' => $reviewId,
                'article_id' => $articleId,
                'wp_post_id' => null,
                'wp_comment_id' => null,
                'status' => ArticleProductReviewStatus::PendingArticle->value,
                'outcome' => 'SKIPPED_WAITING_FOR_ARTICLE',
                'deduplicated' => false,
                'publish_intent' => $publishIntent,
            ], (string) ($result['message'] ?? 'Waiting for article wp_post_id.'));
        }

        if (! ($result['success'] ?? false)) {
            $errorCode = (string) ($result['error_code'] ?? 'WORDPRESS_REVIEW_PUBLISH_FAILED');
            $message = (string) ($result['message'] ?? 'Publish review failed.');

            Log::warning('product_review.publish_failed', [
                'review_id' => $reviewId,
                'article_id' => $articleId,
                'error_code' => $errorCode,
                'message' => $message,
                'automation_execution_id' => (int) $context->execution->id,
            ]);

            if ($errorCode !== 'LOCAL_FINALIZE_FAILED') {
                $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressCommentReviewPublishFailed, $article, [
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'error' => $message,
                    'error_code' => $errorCode,
                    'status' => 'failed',
                ]);
            }

            return AutomationActionResult::failure($errorCode, $message, [
                'review_id' => $reviewId,
                'article_id' => $articleId,
                'wp_post_id' => $result['wp_post_id'] ?? null,
                'wp_comment_id' => $result['wp_comment_id'] ?? null,
                'status' => $result['status'] ?? ArticleProductReviewStatus::Failed->value,
                'remote_response' => $result['remote_response'] ?? null,
            ]);
        }

        $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressCommentReviewPublished, $article, [
            'review_id' => $reviewId,
            'article_id' => $articleId,
            'wp_post_id' => $result['wp_post_id'] ?? null,
            'wp_comment_id' => $result['wp_comment_id'] ?? null,
            'status' => ArticleProductReviewStatus::Published->value,
            'deduplicated' => (bool) ($result['deduplicated'] ?? false),
        ]);

        return AutomationActionResult::success([
            'review_id' => $reviewId,
            'article_id' => $articleId,
            'wp_post_id' => $result['wp_post_id'] ?? null,
            'wp_comment_id' => $result['wp_comment_id'] ?? null,
            'status' => ArticleProductReviewStatus::Published->value,
            'deduplicated' => (bool) ($result['deduplicated'] ?? false),
            'publish_intent' => $publishIntent,
        ], (string) ($result['message'] ?? 'published'));
    }
}
