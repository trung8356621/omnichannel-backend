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
use App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * REST lưu / đồng bộ bài viết từ React Editor.
 *
 * - POST /api/seo/articles/{article}/save
 * - POST /api/seo/articles/{article}/sync-wp  (manual Automation trigger)
 * - POST /api/seo/articles/{article}/seo-meta
 */
final class ArticleEditorSyncController extends Controller
{
    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorPersistService $persist,
        private readonly ArticleEditorSavePatchService $savePatch,
        private readonly ArticleEditorSeoMetaService $seoMeta,
        private readonly WordPressManualSyncService $manualSync,
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

        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $result = $this->manualSync->enqueueFromEditorBundle(
            $article,
            $request->editorBundle(),
            $actor,
            'article_editor.sync_wordpress',
        );
        $statusCode = ($result['success'] ?? false) ? 200 : 422;
        $dispatchStatus = (string) ($result['status'] ?? (($result['success'] ?? false) ? 'dispatched' : 'blocked'));

        if ($dispatchStatus === 'blocked') {
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.wp_sync_blocked_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.wp_sync_blocked_body')),
                'status' => 'danger',
            ];
        } elseif ($dispatchStatus === 'deduplicated') {
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.gate.deduplicated_title'),
                'body' => (string) ($result['message'] ?? ''),
                'status' => 'info',
            ];
        } else {
            $historyUrl = (string) ($result['automation_history_url'] ?? '');
            $result['queued'] = true;
            $result['reload'] = false;
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.gate.dispatched_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.article_list.sync_queued_body'))
                    .($historyUrl !== '' ? ' '.__('seo-content-ai::filament.automation.view_progress').': '.$historyUrl : ''),
                'status' => 'info',
            ];
        }

        return response()->json($result, $statusCode);
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
