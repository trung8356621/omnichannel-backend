<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorSeoPayloadService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

class ArticleSeoPreviewController extends Controller
{
    public function __invoke(SeoArticle $article, ArticleEditorSeoPayloadService $seoPayload): JsonResponse
    {
        abort_unless($this->canViewArticle($article), 403);

        $score = $article->countsTowardSeoScore() && $article->seo_score !== null
            ? (int) round((float) $article->seo_score)
            : null;

        return response()->json([
            'article' => [
                'id' => $article->id,
                'title' => (string) $article->title,
                'score' => $score,
                'edit_url' => ArticleResource::panelUrl('edit', ['record' => $article]),
            ],
            'seo' => $seoPayload->forArticle($article),
        ]);
    }

    private function canViewArticle(SeoArticle $article): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return Site::query()
            ->whereKey($article->site_id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
