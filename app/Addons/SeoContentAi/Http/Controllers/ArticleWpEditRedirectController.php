<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleByWpIdResolver;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\WordPressSiteUrlMatcher;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Redirect từ wp_id WordPress sang editor Filament (không đổi URL editor hiện có).
 *
 * GET /seo/articles/wp-edit-redirect?wp_id=123&type=product&site_url=https://example.com/
 */
final class ArticleWpEditRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        WordPressSiteUrlMatcher $siteMatcher,
        ArticleByWpIdResolver $articleResolver,
    ): RedirectResponse {
        $wpId = (int) $request->query('wp_id', 0);
        $type = (string) $request->query('type', 'article');
        $siteUrl = trim((string) $request->query('site_url', ''));

        if ($wpId <= 0 || $siteUrl === '') {
            abort(404);
        }

        $site = $siteMatcher->resolveSiteBySiteUrl($siteUrl);
        if (! $site instanceof Site) {
            abort(404);
        }

        $article = $articleResolver->resolve($site, $wpId, $type);
        if (! $article instanceof SeoArticle) {
            abort(404, 'Chưa có bài SEO tương ứng với ID WordPress này. Hãy đồng bộ domain trước.');
        }

        abort_unless($this->canEditArticle($article), 403);

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId > 0) {
            SeoAccessControl::setGlobalSiteId($siteId);
        }

        return redirect()->to(
            ArticleResource::panelUrl('edit', ['record' => $article->id])
        );
    }

    private function canEditArticle(SeoArticle $article): bool
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
