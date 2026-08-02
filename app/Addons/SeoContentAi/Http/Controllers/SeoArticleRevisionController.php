<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Http\Requests\SeoArticleRevisionRestoreRequest;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoArticleRevision;
use App\Addons\SeoContentAi\Services\SeoArticleRevisionService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class SeoArticleRevisionController extends Controller
{
    public function __construct(
        private readonly SeoArticleRevisionService $revisionService,
    ) {}

    public function compare(SeoArticle $article): View
    {
        abort_unless($this->canAccessArticle($article), 403);

        $article->loadMissing('articleMetas');
        $revisions = $this->revisionService->listForArticle((int) $article->id);

        return view('seo-content-ai::articles.revisions-compare', [
            'article' => $article,
            'editUrl' => ArticleResource::panelUrl('edit', ['record' => $article]),
            'restoreUrl' => route('seo.articles.revisions.restore', ['article' => $article]),
            'revisionDetailUrlTemplate' => route('seo.articles.revisions.show', [
                'article' => $article,
                'revision' => '__REVISION__',
            ]),
            'revisions' => $revisions->map(
                fn (SeoArticleRevision $revision): array => $this->revisionOption($revision),
            )->values()->all(),
            'current' => $this->revisionService->buildArticleCompareSnapshot($article),
        ]);
    }

    public function restore(
        SeoArticleRevisionRestoreRequest $request,
        SeoArticle $article,
    ): RedirectResponse {
        abort_unless(ArticleResource::canEdit($article), 403);

        try {
            app(\App\Addons\SeoContentAi\Services\ArticleEditor\ArticleEditorSessionService::class)
                ->assertNoActiveEditorSession($article, 'revision_restore');
        } catch (\App\Addons\SeoContentAi\Services\ArticleEditor\ArticleEditorSessionException $exception) {
            return redirect()
                ->back()
                ->with('seo_revision_restore_blocked', $exception->getMessage());
        }

        $revisionId = (int) $request->validated('revision_id');
        $revision = $this->revisionService->findForArticle((int) $article->id, $revisionId);
        abort_if($revision === null, 404);

        $this->revisionService->restoreRevisionToArticle($article, $revision);

        return redirect()
            ->to(ArticleResource::panelUrl('edit', ['record' => $article->fresh()]))
            ->with('seo_revision_restored', 'Đã khôi phục nội dung từ phiên bản lịch sử. Hãy kiểm tra và lưu lại nếu cần.');
    }

    /**
     * JSON detail endpoint for compare page (also used by legacy API path).
     */
    public function show(SeoArticle $article, int $revision): JsonResponse
    {
        abort_unless($this->canAccessArticle($article), 403);

        $record = $this->revisionService->findForArticle((int) $article->id, $revision);
        abort_if($record === null, 404);

        return response()->json([
            'success' => true,
            'revision' => $this->revisionService->buildRevisionComparePayload($record),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionOption(SeoArticleRevision $revision): array
    {
        $createdAt = $revision->created_at;

        return [
            'id' => (int) $revision->id,
            'label' => ($createdAt !== null
                ? $createdAt->timezone(config('app.timezone'))->format('d/m/Y H:i')
                : '—').' - bởi '.(
                    trim((string) ($revision->user?->name ?? '')) !== ''
                        ? trim((string) $revision->user->name)
                        : 'Hệ thống'
                ),
        ];
    }

    private function canAccessArticle(SeoArticle $article): bool
    {
        return SeoAccessControl::canAccessArticle($article);
    }
}
