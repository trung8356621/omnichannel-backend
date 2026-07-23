<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Enums\KeywordReviewSource;
use App\Addons\SeoContentAi\Enums\KeywordReviewStatus;
use App\Addons\SeoContentAi\Http\Requests\KeywordReviewRequest;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\KeywordReviewReasonService;
use App\Addons\SeoContentAi\Services\KeywordReviewService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Support\RuntimeLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class KeywordReviewController extends Controller
{
    public function __construct(
        private readonly KeywordReviewService $reviewService,
        private readonly KeywordReviewReasonService $reasonService,
    ) {}

    public function reasons(Request $request): JsonResponse
    {
        abort_unless(SeoAccessControl::canReviewKeywords(), 403);

        $workspaceId = SeoAccessControl::accountSiteOwnerId();
        $this->reasonService->ensureDefaultReasons($workspaceId, (int) ($request->user()?->id ?? 0));

        $reasons = $this->reasonService->activeReasonsForWorkspace($workspaceId)
            ->map(static fn ($reason): array => [
                'id' => (int) $reason->id,
                'name' => (string) $reason->name,
                'default_severity' => (string) $reason->default_severity,
                'description' => $reason->description,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'reasons' => $reasons,
            'can_override_severity' => SeoAccessControl::canOverrideKeywordReviewSeverity(),
        ]);
    }

    public function review(KeywordReviewRequest $request, Keyword $keyword): JsonResponse
    {
        abort_unless(SeoAccessControl::canReviewKeywords(), 403);

        try {
            $this->reviewService->assertKeywordAccessible($keyword);

            $articleId = $request->integer('article_id');
            if ($articleId > 0) {
                $article = SeoArticle::query()->find($articleId);
                if ($article instanceof SeoArticle) {
                    $this->reviewService->assertArticleAccessible($article);
                }
            }

            $severity = KeywordReviewStatus::from((string) $request->input('severity'));
            $source = KeywordReviewSource::tryFrom((string) $request->input('source', ''))
                ?? KeywordReviewSource::ArticleSuggestion;

            $reasonId = $request->integer('reason_id');
            $reasonId = $reasonId > 0 ? $reasonId : null;

            $result = $this->reviewService->submitReview(
                $keyword,
                $reasonId,
                $severity,
                $request->input('note'),
                $request->input('custom_reason_text'),
                (int) ($request->user()?->id ?? 0),
                $source,
                $articleId > 0 ? $articleId : null,
                SeoAccessControl::canOverrideKeywordReviewSeverity(),
                $source === KeywordReviewSource::ArticleSuggestion,
            );

            return response()->json([
                'success' => true,
                'keyword' => $this->serializeKeyword($result['keyword']),
            ]);
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function restore(Request $request, Keyword $keyword): JsonResponse
    {
        abort_unless(SeoAccessControl::canRestoreKeywords(), 403);

        try {
            $this->reviewService->assertKeywordAccessible($keyword);

            $source = KeywordReviewSource::tryFrom((string) $request->input('source', ''))
                ?? KeywordReviewSource::KeywordsTable;

            $restored = $this->reviewService->restoreKeyword(
                $keyword,
                (int) ($request->user()?->id ?? 0),
                $source,
                $request->input('note'),
            );

            return response()->json([
                'success' => true,
                'keyword' => $this->serializeKeyword($restored),
            ]);
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeKeyword(Keyword $keyword): array
    {
        $keyword->loadMissing('reviewReason');

        return [
            'id' => (int) $keyword->id,
            'phrase' => (string) $keyword->phrase,
            'review_status' => (string) $keyword->review_status,
            'review_reason' => $keyword->reviewReason?->name ?? $keyword->review_note,
            'review_note' => $keyword->review_note,
            'reviewed_at' => optional($keyword->reviewed_at)?->toIso8601String(),
        ];
    }
}
