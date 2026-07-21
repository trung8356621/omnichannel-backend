<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ProductReview;

use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ProductReview\Data\ProductReviewReconciliationResult;
use Illuminate\Support\Facades\Log;

/**
 * After article WP sync success — idempotent schedule pending reviews (event may be lost).
 */
final class ProductReviewPostSyncReconciler
{
    public const RULE_CODE = 'publish-pending-product-reviews-after-article-sync';

    public function __construct(
        private readonly ProductReviewReconciliationService $reconciliation,
    ) {}

    public function reconcileAfterArticleSynced(SeoArticle $article, ?int $actorId = null): ?ProductReviewReconciliationResult
    {
        $article = $article->fresh() ?? $article;
        if ((int) ($article->wp_post_id ?? 0) <= 0) {
            return null;
        }

        $rule = AutomationRule::query()->where('code', self::RULE_CODE)->first();
        if (! $rule instanceof AutomationRule || ! (bool) $rule->is_enabled) {
            Log::debug('product_review.post_sync_reconcile.skipped_rule', [
                'article_id' => (int) $article->id,
                'reason' => $rule === null ? 'rule_missing' : 'rule_disabled',
            ]);

            return null;
        }

        $report = $this->reconciliation->reconcileForArticle($article, $actorId, dryRun: false);
        Log::info('product_review.post_sync_reconcile.done', $report);

        return new ProductReviewReconciliationResult(
            articleId: (int) $article->id,
            foundReviewIds: [],
            queuedReviewIds: is_array($report['queued_review_ids'] ?? null) ? $report['queued_review_ids'] : [],
            outcome: (string) ($report['outcome'] ?? 'OK'),
            message: (string) ($report['message'] ?? ''),
        );
    }
}
