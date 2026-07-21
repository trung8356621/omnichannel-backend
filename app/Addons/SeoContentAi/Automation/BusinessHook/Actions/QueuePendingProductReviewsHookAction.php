<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Enums\ProductReviewPublishIntent;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ProductReview\PendingProductReviewReconciler;

/**
 * After wordpress.synced — DB reconcile all pending reviews, schedule delayed publish.
 * No WordPress HTTP.
 */
final class QueuePendingProductReviewsHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly PendingProductReviewReconciler $reconciler,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $articleId = (int) ($input['article_id'] ?? 0);
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

        $result = $this->reconciler->reconcileForArticle(
            article: $article,
            settings: $settings,
            reviewIds: null,
            publishIntent: ProductReviewPublishIntent::PublishAfterArticle->value,
            actorId: $context->actorId,
            dryRun: false,
        );

        return AutomationActionResult::success(
            $result->toArray(),
            $result->message ?? sprintf('Queued %d pending product reviews.', count($result->queuedReviewIds)),
        );
    }
}
