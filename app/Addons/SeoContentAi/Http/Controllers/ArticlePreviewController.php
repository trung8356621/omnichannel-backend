<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleFaqHtmlRenderer;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class ArticlePreviewController extends Controller
{
    public function __invoke(
        SeoArticle $article,
        ArticleFaqHtmlRenderer $faqRenderer,
        WordPressArticleContentService $wordPressContent,
    ): View|Response|RedirectResponse {
        $article->loadMissing(['site', 'keywords']);

        if ($article->body === null && (int) ($article->wp_post_id ?? 0) > 0) {
            $permalink = trim($wordPressContent->resolvePermalink($article));

            if ($permalink !== '') {
                return redirect()->away($permalink);
            }
        }

        $contentHtml = $faqRenderer->renderBodyWithFaqs($article);
        if ($contentHtml === '') {
            $contentHtml = $wordPressContent->resolveEditorHtml($article);
        }

        return view('seo-content-ai::articles.preview', [
            'article' => $article,
            'contentHtml' => $contentHtml,
            'focusKeyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article),
            'permalink' => $wordPressContent->resolvePermalink($article),
            'editUrl' => ArticleResource::panelUrl('edit', ['record' => $article]),
        ]);
    }
}
