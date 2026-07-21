<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ProductReview\ProductReviewReconciliationService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/seo/articles/{article}/product-reviews/reconcile
 * Safety net khi mở editor — không gọi WordPress, không block render.
 */
final class ArticleProductReviewReconcileController extends Controller
{
    public function __construct(
        private readonly ProductReviewReconciliationService $reconciliation,
    ) {}

    public function __invoke(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $actorId = $request->user()?->id !== null ? (int) $request->user()->id : null;
        $report = $this->reconciliation->reconcileForArticle($article, $actorId, dryRun: false);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }
}
