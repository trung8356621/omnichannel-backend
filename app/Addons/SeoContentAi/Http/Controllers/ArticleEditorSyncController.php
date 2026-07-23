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
use App\Addons\SeoContentAi\Services\ArticleEditorSeoPayloadService;
use App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RuntimeLogger;
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
        $html = (string) ($bundle['html'] ?? '');
        $seoAnalysis = is_array($bundle['seo_analysis'] ?? null) ? $bundle['seo_analysis'] : null;

        // Conflict-gated content write first — avoid side-effect writes when 409.
        $result = $this->actions->dispatch(
            'article.content.update',
            $this->buildContentUpdateInput($article, $bundle, $html),
            $this->buildActionContext($request, $article),
        );

        if (! $result->success) {
            $code = (string) ($result->error['code'] ?? '');
            $message = (string) ($result->error['message'] ?? 'Không lưu được bài viết.');

            if (in_array($code, ['conflict_updated_at', 'conflict_content_hash'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'conflict' => $result->error,
                    'notification' => [
                        'title' => 'Xung đột khi lưu',
                        'body' => $message,
                        'status' => 'warning',
                    ],
                ], 409);
            }

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

        $savedArticle = $article->fresh() ?? $article;
        $this->bundleApply->apply($savedArticle, $bundle, $context);

        $message = (string) ($result->output['message'] ?? 'Article saved');

        return response()->json([
            'success' => true,
            'message' => $message,
            'reload' => false,
            'patch' => $this->savePatch->build(
                $savedArticle->fresh() ?? $savedArticle,
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
            $result['data'] = null;
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.wp_sync_blocked_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.wp_sync_blocked_body')),
                'status' => 'danger',
            ];
        } elseif ($dispatchStatus === 'deduplicated') {
            $result['queued'] = true;
            $result['reload'] = false;
            $result['close_editor'] = true;
            $result['already_queued'] = true;
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.manual_sync_already_queued')),
                'status' => 'info',
            ];
        } else {
            $result['queued'] = true;
            $result['reload'] = false;
            $result['close_editor'] = true;
            $result['already_queued'] = false;
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.manual_sync_queued')),
                'status' => 'success',
            ];
        }

        return response()->json($result, $statusCode);
    }

    /**
     * Full SEO + link catalogs — on-demand when Links panel opens / Refresh.
     * Not used for initial editor bootstrap (see forEditorBootstrap).
     */
    public function seoPayload(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => app(ArticleEditorSeoPayloadService::class)->forArticle($article),
        ]);
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

        $output = is_array($result->output) ? $result->output : [];
        $fresh = $article->fresh(['articleMetas', 'site']) ?? $article;

        try {
            $payload = $this->seoMeta->buildResponse(
                $fresh,
                (string) ($output['focus_keyword'] ?? $request->focusKeyword()),
                (string) ($output['meta_description'] ?? $request->metaDescription()),
                (string) ($output['slug'] ?? $request->slug()),
            );
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'action' => 'article.seo_meta.update',
                'article_id' => (int) $article->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'SEO fields đã lưu nhưng không dựng được preview response.',
                'error_code' => 'seo_meta_response_failed',
            ], 500);
        }

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

        foreach (['expected_updated_at', 'expected_content_hash'] as $field) {
            if (array_key_exists($field, $bundle) && $bundle[$field] !== null && $bundle[$field] !== '') {
                $input[$field] = (string) $bundle[$field];
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
            'site_id' => $article->site_id !== null ? (int) $article->site_id : null,
            'correlation_id' => (string) ($request->header('X-Correlation-Id') ?: Str::uuid()->toString()),
        ]);
    }
}
