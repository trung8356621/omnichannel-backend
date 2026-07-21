<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Automation\Contracts\BusinessActionDispatcher;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Http\Requests\ArticleEditorActionRequest;
use App\Addons\SeoContentAi\Http\Requests\ArticleEditorSeoMetaRequest;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorBundleApplyService;
use App\Addons\SeoContentAi\Services\ArticleEditorSavePatchService;
use App\Addons\SeoContentAi\Services\ArticleEditorSeoMetaService;
use App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * REST lưu / đồng bộ bài viết từ React Editor.
 *
 * - POST /api/seo/articles/{article}/save
 * - POST /api/seo/articles/{article}/sync-wp  (manual WordPressManualSyncService)
 * - POST /api/seo/articles/{article}/seo-meta
 *
 * save() / saveSeoMeta() đi qua BusinessActionDispatcher (article.content.update /
 * article.seo_meta.update) — controller không còn ghi trực tiếp qua service.
 */
final class ArticleEditorSyncController extends Controller
{
    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorSavePatchService $savePatch,
        private readonly ArticleEditorSeoMetaService $seoMeta,
        private readonly WordPressManualSyncService $manualSync,
        private readonly BusinessActionDispatcher $actions,
    ) {}

    public function save(ArticleEditorActionRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $bundle = $request->editorBundle();
        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $seoAnalysis = is_array($bundle['seo_analysis'] ?? null) ? $bundle['seo_analysis'] : null;

        $result = $this->actions->dispatch(
            'article.content.update',
            $this->buildContentUpdateInput($article, $bundle, $html),
            $this->buildActionContext($request, $article),
        );

        if (! $result->success) {
            $message = (string) ($result->error['message'] ?? 'Không lưu được bài viết.');

            return response()->json([
                'success' => false,
                'message' => $message,
                'notification' => [
                    'title' => 'Không lưu được nội dung',
                    'body' => $message,
                    'status' => 'warning',
                ],
            ], 422);
        }

        $message = (string) ($result->output['message'] ?? 'Article saved');

        return response()->json([
            'success' => true,
            'message' => $message,
            'reload' => false,
            'patch' => $this->savePatch->build(
                $article->fresh() ?? $article,
                $context,
                $seoAnalysis,
            ),
            'notification' => [
                'title' => 'Article saved',
                'body' => $message,
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
            $result['queued'] = true;
            $result['reload'] = false;
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.manual_sync_in_progress')),
                'status' => 'info',
            ];
        } else {
            $historyUrl = (string) ($result['automation_history_url'] ?? '');
            $result['queued'] = true;
            $result['reload'] = false;
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.manual_sync_queued'))
                    .($historyUrl !== '' ? ' '.__('seo-content-ai::filament.automation.view_progress').': '.$historyUrl : ''),
                'status' => 'info',
            ];
        }

        return response()->json($result, $statusCode);
    }

    public function saveSeoMeta(ArticleEditorSeoMetaRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $result = $this->actions->dispatch(
            'article.seo_meta.update',
            [
                'article_id' => (int) $article->id,
                'focus_keyword' => $request->focusKeyword(),
                'meta_description' => $request->metaDescription(),
                'slug' => $request->slug(),
            ],
            $this->buildActionContext($request, $article),
        );

        if (! $result->success) {
            $message = (string) ($result->error['message'] ?? 'Không lưu được trường SEO.');

            return response()->json([
                'success' => false,
                'message' => $message,
                'notification' => [
                    'title' => 'Không lưu được SEO fields',
                    'body' => $message,
                    'status' => 'warning',
                ],
            ], 422);
        }

        $fresh = $article->fresh(['articleMetas', 'site']) ?? $article;
        $payload = $this->seoMeta->buildResponse(
            $fresh,
            (string) $result->output['focus_keyword'],
            (string) $result->output['meta_description'],
            (string) $result->output['slug'],
        );

        return response()->json([
            'success' => true,
            'message' => 'SEO fields saved',
            ...$payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function buildContentUpdateInput(SeoArticle $article, array $bundle, string $html): array
    {
        $meta = is_array($bundle['article_meta'] ?? null) ? $bundle['article_meta'] : [];
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $input = [
            'article_id' => (int) $article->id,
            'content' => $html,
        ];

        foreach (['title', 'slug', 'seo_meta_description', 'focus_keyword'] as $field) {
            if (array_key_exists($field, $meta)) {
                $input[$field] = (string) $meta[$field];
            }
        }

        foreach (['status', 'post_type', 'visibility', 'publish_day', 'publish_month', 'publish_year', 'publish_hour', 'publish_minute'] as $field) {
            if (array_key_exists($field, $publishBox)) {
                $input[$field] = (string) $publishBox[$field];
            }
        }

        return $input;
    }

    private function buildActionContext(Request $request, SeoArticle $article): ActionContext
    {
        $actor = $request->user();

        return ActionContext::fromArray([
            'origin' => 'article_editor',
            'actor_id' => $actor instanceof User ? (int) $actor->id : null,
            'site_id' => $article->site_id,
            'correlation_id' => (string) ($request->header('X-Correlation-Id') ?: Str::uuid()->toString()),
        ]);
    }
}
