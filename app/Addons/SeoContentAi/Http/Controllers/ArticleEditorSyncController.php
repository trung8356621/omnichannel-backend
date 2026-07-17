<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Http\Requests\ArticleEditorActionRequest;
use App\Addons\SeoContentAi\Http\Requests\ArticleEditorSeoMetaRequest;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorBundleApplyService;
use App\Addons\SeoContentAi\Services\ArticleEditorPersistService;
use App\Addons\SeoContentAi\Services\ArticleEditorSavePatchService;
use App\Addons\SeoContentAi\Services\ArticleEditorSeoMetaService;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * REST lưu / đồng bộ bài viết từ React Editor (thay Livewire payload HTML lớn).
 *
 * - POST /api/seo/articles/{article}/save
 * - POST /api/seo/articles/{article}/sync-wp
 * - POST /api/seo/articles/{article}/seo-meta
 */
final class ArticleEditorSyncController extends Controller
{
    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorPersistService $persist,
        private readonly ArticleEditorSavePatchService $savePatch,
        private readonly ArticleEditorSeoMetaService $seoMeta,
        private readonly ArticleWpSyncQueueService $syncQueue,
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
            'reload' => false,
            'patch' => $this->savePatch->build(
                $article->fresh() ?? $article,
                $context,
                $seoAnalysis,
            ),
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

        $result = $this->syncQueue->enqueueFromEditorBundle($article, $request->editorBundle());
        $status = ($result['success'] ?? false) ? 200 : 422;

        if ($result['success'] ?? false) {
            $result['queued'] = true;
            $result['reload'] = false;
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.article_list.sync_queued_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.article_list.sync_queued_body')),
                'status' => 'info',
            ];
        } else {
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.article_list.sync_queue_failed_title'),
                'body' => (string) ($result['message'] ?? ''),
                'status' => 'danger',
            ];
        }

        return response()->json($result, $status);
    }

    public function saveSeoMeta(ArticleEditorSeoMetaRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $payload = $this->seoMeta->save(
            $article,
            $request->focusKeyword(),
            $request->metaDescription(),
            $request->slug(),
        );

        return response()->json([
            'success' => true,
            'message' => 'SEO fields saved',
            ...$payload,
        ]);
    }
}
