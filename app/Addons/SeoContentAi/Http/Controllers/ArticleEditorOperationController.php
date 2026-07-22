<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\SeoMediaArticleSlugFixService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArticleEditorOperationController extends Controller
{
    public function __construct(
        private readonly ArticleWpSyncQueueService $syncQueue,
        private readonly SeoMediaArticleSlugFixService $slugFix,
    ) {}

    public function status(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $active = $this->syncQueue->activeOperation($article);

        $rawStatus = (string) ($active['raw_status'] ?? '');
        $publicStatus = (string) ($active['status'] ?? '');

        return response()->json([
            'success' => true,
            'article_id' => (int) $article->id,
            'operation' => $active,
            // activeOperation() map pending → queued; so sánh raw + public.
            'has_active_operation' => $active !== null
                && (
                    in_array($rawStatus, [
                        ArticleWpSyncQueueService::STATUS_PENDING,
                        ArticleWpSyncQueueService::STATUS_PROCESSING,
                    ], true)
                    || in_array($publicStatus, ['queued', 'processing'], true)
                ),
        ]);
    }

    public function fixMediaSlugs(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.seo_media_id' => ['nullable', 'integer', 'min:1'],
            'items.*.url' => ['nullable', 'string', 'max:2048'],
            'items.*.src' => ['nullable', 'string', 'max:2048'],
            'items.*.new_slug' => ['required', 'string', 'regex:/^[a-z0-9\-]+$/i', 'max:200'],
            'items.*.old_slug' => ['nullable', 'string', 'max:200'],
        ]);

        $result = $this->slugFix->fixSlugs($article, $validated['items']);
        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}
