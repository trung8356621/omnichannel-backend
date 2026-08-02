<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorLinksPayloadService;
use App\Addons\SeoContentAi\Services\ArticleEditorSeoPayloadService;
use App\Addons\SeoContentAi\Services\ArticleEditorSupplementalImagesService;
use App\Addons\SeoContentAi\Services\ArticleFaqEditorService;
use App\Addons\SeoContentAi\Services\ArticleFaqExtractDebugService;
use App\Addons\SeoContentAi\Services\ArticleMediaLocalService;
use App\Addons\SeoContentAi\Services\ArticlePostImagesService;
use App\Addons\SeoContentAi\Services\PromptLoaiSanPhamOptionsService;
use App\Addons\SeoContentAi\Services\SeoCreateArticleSettingsService;
use App\Addons\SeoContentAi\Services\SeoPromptSettingsService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use App\Addons\SeoContentAi\Services\WordPressMediaCapabilityResolver;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Http\Controllers\Controller;
use App\Services\SeoEngineService;
use App\Support\RuntimeLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2 lazy bootstrap endpoints — everything the initial editor render no
 * longer embeds inline (SEO summary, images, FAQs, meta extras, links, scoring
 * settings, media picker config). Plain HTTP controller (not Livewire) so it can
 * be fetched independently of the editor's own request/response cycle.
 *
 * Routes: see Providers/SeoPanelProvider.php (`api/seo/articles/{article}/editor/*`).
 */
final class ArticleEditorLazyPayloadController extends Controller
{
    public function seoSummary(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => app(ArticleEditorSeoPayloadService::class)->forEditorSeoSummary($article),
        ]);
    }

    public function images(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => app(ArticlePostImagesService::class)->resolveForArticle($article),
        ]);
    }

    public function faqs(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $items = app(ArticleFaqEditorService::class)->payloadForArticle($article);

        return response()->json([
            'success' => true,
            'data' => [
                // Contract: never null — empty FAQ is valid empty state.
                'cached' => false,
                'cached_at' => null,
                'items' => $items,
                'count' => count($items),
                'can_generate' => app(SeoCreateArticleSettingsService::class)->getRenewFaqPromptId() !== null,
                // Legacy keys for older clients.
                'faqs' => $items,
                'extract_debug' => app(ArticleFaqExtractDebugService::class)->get($article),
                'can_generate_faq' => app(SeoCreateArticleSettingsService::class)->getRenewFaqPromptId() !== null,
                'can_import_markdown_faq' => SeoAccessControl::canAccessManagerFeatures(),
            ],
            'message' => null,
        ]);
    }

    /**
     * Light FAQ count only — no FAQ rows (shortcode badge / summary).
     */
    public function faqsCount(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $count = (int) $article->faqs()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'count' => $count,
                'can_generate' => app(SeoCreateArticleSettingsService::class)->getRenewFaqPromptId() !== null,
            ],
        ]);
    }

    /**
     * Product gallery / category options / supplemental images — the parts of the
     * old eager `getEditorMetaPayload()` that only the Images/Product panels need.
     */
    public function meta(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $siteId = (int) $article->site_id;
        $supportsProductGallery = $this->supportsProductGallery($article);

        try {
            $productGallery = $supportsProductGallery
                ? app(ArticleMediaLocalService::class)->resolveProductAlbum($article)
                : [];
            $featuredImageUrl = $supportsProductGallery
                ? (string) ($productGallery[0]['url'] ?? '')
                : (string) (app(WordPressArticleContentService::class)->resolveFeaturedImageUrl($article) ?? '');

            $productCategoryOptions = $siteId > 0
                ? app(PromptLoaiSanPhamOptionsService::class)->productCategoryOptionsForSite($siteId)
                : [];

            $supplemental = app(ArticleEditorSupplementalImagesService::class)
                ->forArticle($article, $featuredImageUrl, $productGallery);
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'action' => 'editor.meta',
                'article_id' => (int) $article->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'supports_product_gallery' => $supportsProductGallery,
                    'product_category_options' => [],
                    'product_gallery' => [],
                    'supplemental_images' => [],
                    'warning' => 'Không tải đủ meta images — thử lại sau.',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'supports_product_gallery' => $supportsProductGallery,
                'product_category_options' => collect($productCategoryOptions)
                    ->map(static fn (string $label, int $id): array => ['id' => $id, 'label' => $label])
                    ->values()
                    ->all(),
                'product_gallery' => collect($productGallery)
                    ->map(static fn (array $item): array => [
                        'url' => (string) ($item['url'] ?? ''),
                        'id' => max(0, (int) ($item['id'] ?? 0)),
                    ])
                    ->filter(static fn (array $item): bool => $item['url'] !== '')
                    ->values()
                    ->all(),
                'supplemental_images' => $supplemental,
            ],
        ]);
    }

    public function links(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => app(ArticleEditorLinksPayloadService::class)->base($article),
        ]);
    }

    public function linksSuggestions(SeoArticle $article, Request $request): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $submitted = $this->submittedEditorContent($request);
        $mode = strtolower(trim((string) $request->input('mode', 'full')));
        $service = app(ArticleEditorLinksPayloadService::class);

        if ($mode === 'fallback') {
            $existing = $request->input('existing_internal', []);
            if (! is_array($existing)) {
                $existing = [];
            }

            return response()->json([
                'success' => true,
                'data' => $service->withFallbackOnly($article, $submitted, $existing),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $service->withSuggestions($article, $submitted),
        ]);
    }

    private function submittedEditorContent(Request $request): ?string
    {
        $content = $request->input('content');
        if (! is_string($content)) {
            return null;
        }

        $content = trim($content);

        return $content !== '' ? $content : null;
    }

    /**
     * Scoring rules / messages — heavy static registries kept out of the initial
     * bootstrap; loaded once alongside (or right after) the SEO summary fetch.
     */
    public function settings(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $promptSettings = app(SeoPromptSettingsService::class);

        return response()->json([
            'success' => true,
            'data' => [
                'seo_scoring_rules' => SeoScoringRulesRegistry::publicRulesForClient(),
                'seo_rule_messages' => SeoScoringRulesRegistry::messagesForLocale(),
                'seo_scoring_messages' => SeoEngineService::scoringMessagesForLocale(),
                'featured_snippet_thresholds' => $promptSettings->getFeaturedSnippetThresholds(),
                'article_length_product' => $promptSettings->resolveArticleLengthTarget('product'),
                'article_length_default' => $promptSettings->resolveArticleLengthTarget('article'),
            ],
        ]);
    }

    public function mediaPickerConfig(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $article->loadMissing('site');
        $capability = app(WordPressMediaCapabilityResolver::class)->forSite($article->site);

        return response()->json([
            'success' => true,
            'data' => [
                'articleId' => (int) $article->id,
                'siteId' => (int) $article->site_id,
                'endpoint' => route('seo.articles.media-picker', ['article' => $article->id]),
                // BC alias — site-level WP media library, NOT article wp_post_id.
                'wordPressLinked' => $capability['available'],
                'wordpress_media_available' => $capability['available'],
                'wordpress_media_unavailable_reason' => $capability['reason'],
            ],
        ]);
    }

    private function supportsProductGallery(SeoArticle $article): bool
    {
        $postType = strtolower(trim(SeoProjectTask::normalizePostType(ArticlePostTypeResolver::resolve($article))));
        $isProduct = in_array($postType, ['product', 'e-commerce'], true);

        return $isProduct && ! app(WordPressArticleContentService::class)->isTaxonomyRecord($article);
    }
}
