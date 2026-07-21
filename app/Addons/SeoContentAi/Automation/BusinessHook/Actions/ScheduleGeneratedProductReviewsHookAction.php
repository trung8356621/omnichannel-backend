<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ProductReview\PendingProductReviewReconciler;

/**
 * After article.product_reviews_generated — schedule each review (random delay) or wait for WP sync.
 * No WordPress HTTP.
 */
final class ScheduleGeneratedProductReviewsHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly PendingProductReviewReconciler $reconciler,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $articleId = (int) ($input['article_id'] ?? $context->subject?->getKey() ?? 0);
        if ($articleId <= 0) {
            return AutomationActionResult::failure('INVALID_ARTICLE_ID', 'article_id is required.');
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::SubjectNotFound->value,
                "Article [{$articleId}] not found.",
            );
        }

        $reviewIds = [];
        $rawIds = $input['review_ids'] ?? ($context->businessEvent->payload['review_ids'] ?? []);
        if (is_string($rawIds) && $rawIds !== '') {
            $decoded = json_decode($rawIds, true);
            $rawIds = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $rawIds) ?: [];
        }
        if (is_array($rawIds)) {
            $reviewIds = array_values(array_filter(array_map('intval', $rawIds), static fn (int $id): bool => $id > 0));
        }

        $result = $reviewIds === []
            ? $this->reconciler->reconcileForArticle(
                article: $article,
                settings: $settings,
                reviewIds: null,
                publishIntent: \App\Addons\SeoContentAi\Enums\ProductReviewPublishIntent::GeneratedReview->value,
                actorId: $context->actorId,
            )
            : $this->reconciler->scheduleGeneratedReviews(
                article: $article,
                reviewIds: $reviewIds,
                settings: $settings,
                actorId: $context->actorId,
            );

        $payload = $result->toArray();
        if ($result->outcome === 'WAITING_FOR_ARTICLE_SYNC') {
            return AutomationActionResult::success($payload, $result->message ?? 'WAITING_FOR_ARTICLE_SYNC');
        }

        return AutomationActionResult::success($payload, $result->message ?? 'scheduled');
    }
}
