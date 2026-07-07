<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Http\Requests\ArticleEditorActionRequest;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorBundleApplyService;
use App\Addons\SeoContentAi\Services\ArticleEditorPersistService;
use App\Addons\SeoContentAi\Services\ArticleEditorSyncOrchestrator;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * REST lưu / đồng bộ bài viết từ React Editor (thay Livewire payload HTML lớn).
 *
 * - POST /api/seo/articles/{article}/save
 * - POST /api/seo/articles/{article}/sync-wp
 */
final class ArticleEditorSyncController extends Controller
{
    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorPersistService $persist,
        private readonly ArticleEditorSyncOrchestrator $syncOrchestrator,
    ) {}

    public function save(ArticleEditorActionRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $bundle = $request->editorBundle();
        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $seoAnalysis = is_array($bundle['seo_analysis'] ?? null) ? $bundle['seo_analysis'] : null;

        $result = $this->persist->persistLocal(
            $article->fresh() ?? $article,
            $context,
            $html,
            deferSeoAnalysis: true,
            seoAnalysis: $seoAnalysis,
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Không lưu được bài viết.'),
                'notification' => [
                    'title' => 'Không lưu được nội dung',
                    'body' => (string) ($result['message'] ?? ''),
                    'status' => 'warning',
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => (string) ($result['message'] ?? 'Article saved'),
            'reload' => true,
            'clear_local_state' => false,
            'seo_analysis_pending' => true,
            'notification' => [
                'title' => 'Article saved',
                'body' => (string) ($result['message'] ?? ''),
                'status' => 'success',
            ],
        ]);
    }

    public function syncWp(ArticleEditorActionRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $result = $this->syncOrchestrator->syncFromEditorBundle($article, $request->editorBundle());
        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}
