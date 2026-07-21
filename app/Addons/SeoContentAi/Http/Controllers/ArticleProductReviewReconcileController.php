<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ProductReview\ProductReviewEditorLoadService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/seo/articles/{article}/product-reviews/reconcile
 * @deprecated Name kept for editor BC — now reloads WordPress reviews (no legacy schedule).
 */
final class ArticleProductReviewReconcileController extends Controller
{
    public function __construct(
        private readonly ProductReviewEditorLoadService $loader,
    ) {}

    public function __invoke(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $data = $this->loader->loadForArticle($article);

        return response()->json([
            'success' => true,
            'data' => [
                'outcome' => 'wordpress_reload',
                'message' => $data['warning'] ?? 'Đã tải đánh giá từ WordPress.',
                'reviews' => $data,
            ],
        ]);
    }
}
