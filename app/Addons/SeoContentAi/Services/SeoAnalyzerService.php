<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoLink;
use App\Addons\SeoContentAi\Support\CtaKeywordBlacklistFilter;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Addons\SeoContentAi\Support\KeywordOrphanCleanup;
use App\Addons\SeoContentAi\Support\KeywordPhraseMatcher;
use App\Addons\SeoContentAi\Support\KeywordSyncIsolation;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SeoAnalyzerService
{
    public function __construct(
        private readonly CtaKeywordBlacklistFilter $ctaKeywordBlacklistFilter,
        private readonly KeywordPersistenceService $keywordPersistence,
        private readonly WorkflowParserService $workflowParser,
    ) {}

    /**
     * Phân tích SEO tổng hợp theo rule-set nội bộ.
     *
     * @return array{score:int,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    public function analyze(SeoArticle $article, ?string $domainOverride = null): array
    {
        $focusKeyword = $this->resolveFocusKeyword($article);

        if ($focusKeyword === null) {
            return $this->persistScoreResult(
                $article,
                $this->buildScoreResult(0, [], ['Không tìm thấy Focus Keyword (main keyword).'], [])
            );
        }

        return $this->runAnalysis(
            $article,
            $focusKeyword,
            $this->resolveSeoTitle($article),
            (string) ($article->body ?? ''),
            trim((string) ($article->slug ?? '')),
            $this->resolveMetaDescription($article),
            $this->resolveArticleDomain($article, $domainOverride)
        );
    }

    /**
     * Chấm điểm khi đồng bộ từ WordPress: dùng dữ liệu scoring trong payload, không đọc body/slug từ bảng articles.
     *
     * @param  array<string, mixed>  $item
     * @return array{score:int,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    public function analyzeFromSyncItem(SeoArticle $article, array $item, ?string $domainOverride = null): array
    {
        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];
        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];

        $focusKeyword = $this->normalizeFocusKeyword(
            trim((string) ($scoring['focus_keyword'] ?? $seo['focus_keyword'] ?? ''))
        );

        if ($focusKeyword === '') {
            $fromDb = $this->resolveFocusKeyword($article);
            $focusKeyword = $fromDb !== null ? $this->normalizeFocusKeyword($fromDb) : '';
        }

        if ($focusKeyword === '') {
            return $this->persistScoreResult(
                $article,
                $this->buildScoreResult(0, [], ['Không có Focus Keyword từ Rank Math / Yoast SEO.'], [])
            );
        }

        return $this->runAnalysis(
            $article,
            $focusKeyword,
            trim((string) ($scoring['seo_title'] ?? $seo['seo_title'] ?? $item['title'] ?? $article->title ?? '')),
            (string) ($scoring['body'] ?? ''),
            trim((string) ($scoring['slug'] ?? '')),
            trim((string) ($scoring['meta_description'] ?? $seo['meta_description'] ?? '')),
            $this->resolveArticleDomain($article, $domainOverride)
        );
    }

    /**
     * Chấm điểm xem trước (editor realtime) — không ghi DB.
     *
     * @return array{
     *   score:int,
     *   good:array<int,string>,
     *   errors:array<int,string>,
     *   warnings:array<int,string>,
     *   extracted_links:array{internal:array<int,array{href:string,text:string}>,external:array<int,array{href:string,text:string,is_nofollow:bool}>}
     * }
     */
    public function analyzePreview(
        SeoArticle $article,
        string $content,
        ?string $seoTitle = null,
        ?string $slug = null,
        ?string $metaDescription = null,
        ?string $domainOverride = null,
    ): array {
        $focusKeyword = $this->resolveFocusKeyword($article);

        if ($focusKeyword === null) {
            $emptyLinks = ['internal' => [], 'external' => []];
            $domain = $this->resolveArticleDomain($article, $domainOverride);
            $emptyLinks = $this->extractLinks($content, $domain);

            return array_merge(
                $this->buildScoreResult(0, [], ['Không tìm thấy Focus Keyword (main keyword).'], []),
                [
                    'extracted_links' => $emptyLinks,
                    'suggested_internal_links' => app(ArticleInternalLinkSuggestionService::class)->suggest(
                        $article,
                        $content,
                        $emptyLinks['internal'] ?? [],
                    ),
                ],
            );
        }

        $computed = $this->computeAnalysis(
            $article,
            $focusKeyword,
            $seoTitle ?? $this->resolveSeoTitle($article),
            $content,
            $slug ?? trim((string) ($article->slug ?? '')),
            $metaDescription ?? $this->resolveMetaDescription($article),
            $this->resolveArticleDomain($article, $domainOverride),
        );

        $contentBonus = app(ArticleContentSeoBonusService::class)->resolveFromContent($article, $content);

        $extractedLinks = $computed['extractedLinks'];

        return array_merge($computed['scoreData'], [
            'extracted_links' => $extractedLinks,
            'content_bonus' => $contentBonus,
            'suggested_internal_links' => app(ArticleInternalLinkSuggestionService::class)->suggest(
                $article,
                $content,
                $extractedLinks['internal'] ?? [],
            ),
        ]);
    }

    /**
     * Chấm và lưu điểm bằng nội dung editor vừa submit.
     *
     * Luồng đồng bộ WordPress sẽ xóa articles.body sau khi thành công, nên không
     * thể gọi analyze() sau sync vì khi đó nội dung dùng để chấm đã không còn.
     *
     * @return array{
     *   score:int,
     *   good:array<int,string>,
     *   errors:array<int,string>,
     *   warnings:array<int,string>,
     *   extracted_links:array{internal:array<int,array{href:string,text:string}>,external:array<int,array{href:string,text:string,is_nofollow:bool}>},
     *   content_bonus:array<string,mixed>
     * }
     */
    public function analyzeSubmittedContent(
        SeoArticle $article,
        string $content,
        ?string $seoTitle = null,
        ?string $slug = null,
        ?string $metaDescription = null,
        ?string $domainOverride = null,
    ): array {
        $focusKeyword = $this->resolveFocusKeyword($article);
        $domain = $this->resolveArticleDomain($article, $domainOverride);

        if ($focusKeyword === null) {
            $extractedLinks = $this->extractLinks($content, $domain);
            $scoreData = $this->buildScoreResult(0, [], ['Không tìm thấy Focus Keyword (main keyword).'], []);
        } else {
            $computed = $this->computeAnalysis(
                $article,
                $focusKeyword,
                $seoTitle ?? $this->resolveSeoTitle($article),
                $content,
                $slug ?? trim((string) ($article->slug ?? '')),
                $metaDescription ?? $this->resolveMetaDescription($article),
                $domain,
            );
            $scoreData = $computed['scoreData'];
            $extractedLinks = $computed['extractedLinks'];
        }

        $persisted = $this->persistScoreResult($article, $scoreData, $extractedLinks);

        return array_merge($persisted, [
            'extracted_links' => $extractedLinks,
            'content_bonus' => app(ArticleContentSeoBonusService::class)->resolveFromContent($article, $content),
            'suggested_internal_links' => app(ArticleInternalLinkSuggestionService::class)->suggest(
                $article,
                $content,
                $extractedLinks['internal'] ?? [],
            ),
        ]);
    }

    /**
     * @return array{
     *   scoreData: array{score:int,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>},
     *   extractedLinks: array{internal: array<int, mixed>, external: array<int, mixed>}
     * }
     */
    private function computeAnalysis(
        SeoArticle $article,
        string $focusKeyword,
        string $seoTitle,
        string $content,
        string $slug,
        string $metaDescription,
        string $domain
    ): array {
        $good = [];
        $errors = [];
        $warnings = [];
        $totalScore = 100;

        $extractedLinks = $this->extractLinks($content, $domain);

        // Rule 1: Focus keyword trong tiêu đề SEO
        if ($this->containsKeyword($seoTitle, $focusKeyword)) {
            $good[] = $this->scoredLine('Từ khóa chính xuất hiện trong tiêu đề SEO.', 10, true);
        } else {
            $errors[] = $this->scoredLine('Tiêu đề SEO chưa chứa từ khóa chính.', 10, false);
            $totalScore -= 10;
        }

        // Rule 2: Focus keyword trong meta description
        if ($this->containsKeyword($metaDescription, $focusKeyword)) {
            $good[] = $this->scoredLine('Từ khóa chính xuất hiện trong meta description.', 10, true);
        } else {
            $errors[] = $this->scoredLine('Meta description chưa chứa từ khóa chính.', 10, false);
            $totalScore -= 10;
        }

        // Rule 3: Focus keyword trong URL slug (so sánh dạng slug / kebab-case)
        if ($this->slugContainsFocusKeyword($slug, $focusKeyword)) {
            $good[] = $this->scoredLine('URL chứa từ khóa chính.', 5, true);
        } else {
            $errors[] = $this->scoredLine('URL chưa chứa từ khóa chính.', 5, false);
            $totalScore -= 5;
        }

        // Rule 4: Focus keyword trong 10% đầu nội dung
        $firstTenPercent = $this->sliceFirstTenPercentText($content);
        if ($this->containsKeyword($firstTenPercent, $focusKeyword)) {
            $good[] = $this->scoredLine('Từ khóa chính xuất hiện trong 10% đầu nội dung.', 10, true);
        } else {
            $errors[] = $this->scoredLine('Từ khóa chính chưa xuất hiện sớm trong nội dung.', 10, false);
            $totalScore -= 10;
        }

        // Rule 5: Focus keyword xuất hiện trong toàn nội dung
        if ($this->containsKeyword($content, $focusKeyword)) {
            $good[] = $this->scoredLine('Từ khóa chính có xuất hiện trong nội dung.', 10, true);
        } else {
            $errors[] = $this->scoredLine('Nội dung chưa chứa từ khóa chính.', 10, false);
            $totalScore -= 10;
        }

        // Rule 6: Độ dài nội dung
        $wordCount = $this->countWords($content);
        $isEcommerceType = (string) $article->type === 'e-commerce';
        if ($isEcommerceType) {
            if ($wordCount > 500) {
                $good[] = $this->scoredLine("Độ dài nội dung phù hợp cho e-commerce ({$wordCount} từ).", 10, true);
            } else {
                $errors[] = $this->scoredLine("Nội dung e-commerce quá ngắn ({$wordCount} từ, cần > 500).", 10, false);
                $totalScore -= 10;
            }
        } else {
            if ($wordCount < 600) {
                $errors[] = $this->scoredLine("Nội dung quá ngắn ({$wordCount} từ, cần >= 600).", 10, false);
                $totalScore -= 10;
            } elseif ($wordCount <= 1000) {
                $warnings[] = $this->scoredLine("Nội dung trung bình ({$wordCount} từ). Nên > 1000 từ để tối ưu.", 0, false);
            } else {
                $good[] = $this->scoredLine("Độ dài nội dung tốt ({$wordCount} từ).", 0, true);
            }
        }

        // Rule 7: Từ khóa trong H2/H3/H4
        $headingText = $this->extractHeadingText($content);
        if ($this->containsKeyword($headingText, $focusKeyword)) {
            $good[] = $this->scoredLine('Từ khóa chính xuất hiện trong heading phụ (H2/H3/H4).', 5, true);
        } else {
            $errors[] = $this->scoredLine('Heading phụ chưa chứa từ khóa chính.', 5, false);
            $totalScore -= 5;
        }

        // Rule 8: Alt ảnh chứa từ khóa
        $imagesAltText = $this->extractImageAltText($content);
        if ($this->containsKeyword($imagesAltText, $focusKeyword)) {
            $good[] = $this->scoredLine('Có ảnh chứa alt text gồm từ khóa chính.', 5, true);
        } else {
            $errors[] = $this->scoredLine('Chưa có alt text ảnh chứa từ khóa chính.', 5, false);
            $totalScore -= 5;
        }

        // Rule 9: Keyword density (1% - 2.5%)
        $density = $this->calculateKeywordDensity($content, $focusKeyword);
        if ($density >= 1.0 && $density <= 2.5) {
            $good[] = $this->scoredLine('Mật độ từ khóa nằm trong ngưỡng tối ưu (1% - 2.5%).', 5, true);
        } else {
            $warnings[] = $this->scoredLine(sprintf('Mật độ từ khóa hiện tại %.2f%% chưa tối ưu.', $density), 5, false);
            $totalScore -= 5;
        }

        // Rule 10: Slug ngắn gọn
        $slugLength = mb_strlen($slug);
        if ($slugLength > 85) {
            $errors[] = $this->scoredLine('URL quá dài (> 85 ký tự).', 5, false);
            $totalScore -= 5;
        } elseif ($slugLength >= 80) {
            $warnings[] = $this->scoredLine('URL hơi dài (>= 80 ký tự, nên ngắn hơn).', 5, false);
            $totalScore -= 2;
        } else {
            $good[] = $this->scoredLine('URL ngắn gọn (< 80 ký tự).', 5, true);
        }

        // Rule 11: Có internal link
        if (count($extractedLinks['internal']) > 0) {
            $good[] = $this->scoredLine('Có liên kết nội bộ trong nội dung.', 20, true);
        } else {
            $errors[] = $this->scoredLine('Chưa có liên kết nội bộ.', 20, false);
            $totalScore -= 20;
        }

        // Rule 12: Có external link
        if (count($extractedLinks['external']) > 0) {
            $good[] = $this->scoredLine('Có liên kết ngoài trong nội dung.', 10, true);
        } else {
            $errors[] = $this->scoredLine('Chưa có liên kết ngoài.', 10, false);
            $totalScore -= 10;
        }

        // Rule 13: Có FAQ
        if ($this->articleHasFaqs($article, $content)) {
            $good[] = $this->scoredLine('Có phần FAQ trong bài.', 10, true);
        } else {
            $errors[] = $this->scoredLine('Chưa có phần FAQ.', 10, false);
            $totalScore -= 10;
        }

        // Rule 14: Keyword ở 3 từ đầu tiêu đề SEO
        if ($this->keywordInFirstThreeWords($seoTitle, $focusKeyword)) {
            $good[] = $this->scoredLine('Từ khóa chính nằm ở phần đầu tiêu đề.', 5, true);
        } else {
            $warnings[] = $this->scoredLine('Nên đặt từ khóa chính vào 3 từ đầu tiêu đề.', 5, false);
            $totalScore -= 5;
        }

        return [
            'scoreData' => $this->buildScoreResult(max(0, $totalScore), $good, $errors, $warnings),
            'extractedLinks' => $extractedLinks,
        ];
    }

    /**
     * @return array{score:int,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    private function runAnalysis(
        SeoArticle $article,
        string $focusKeyword,
        string $seoTitle,
        string $content,
        string $slug,
        string $metaDescription,
        string $domain
    ): array {
        $computed = $this->computeAnalysis(
            $article,
            $focusKeyword,
            $seoTitle,
            $content,
            $slug,
            $metaDescription,
            $domain,
        );

        return $this->persistScoreResult(
            $article,
            $computed['scoreData'],
            $computed['extractedLinks'],
        );
    }

    /**
     * @param  array<int, string>  $good
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     * @return array{score:int,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    private function buildScoreResult(int $score, array $good, array $errors, array $warnings): array
    {
        return [
            'score' => $score,
            'good' => $good,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function scoredLine(string $message, int $points, bool $passed): string
    {
        $message = rtrim(trim($message), '.');

        if ($points <= 0) {
            return $message;
        }

        $sign = $passed ? '+' : '-';

        return sprintf('%s %s%d', $message, $sign, $points);
    }

    private function articleHasFaqs(SeoArticle $article, string $content): bool
    {
        $article->loadMissing('faqs');
        if ($article->faqs->count() > 0) {
            return true;
        }

        return $this->workflowParser->parseFaqsFromContent($content) !== [];
    }

    /**
     * Bóc tách link trong nội dung bài, gắn keyword mới và gỡ keyword/link outbound cũ của bài này.
     */
    /**
     * @param  array<int, string>  $excludeAnchorPhrases
     */
    public function reconcileKeywordLinksFromContent(
        SeoArticle $article,
        string $content,
        ?string $domainOverride = null,
        array $excludeAnchorPhrases = [],
    ): void {
        if (! $article->countsTowardSeoScore()) {
            return;
        }

        $domain = $this->resolveArticleDomain($article, $domainOverride);
        $extractedLinks = trim($content) === ''
            ? ['internal' => [], 'external' => []]
            : $this->extractLinks($content, $domain);

        $this->persistExtractedLinks($article, $extractedLinks, $excludeAnchorPhrases);
    }

    /**
     * @param  array{score:int,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}  $scoreData
     * @param  array{internal: array<int, mixed>, external: array<int, mixed>}|null  $extractedLinks
     * @return array{score:int,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    private function persistScoreResult(SeoArticle $article, array $scoreData, ?array $extractedLinks = null): array
    {
        if (! $article->countsTowardSeoScore()) {
            return $scoreData;
        }

        $links = $extractedLinks ?? ['internal' => [], 'external' => []];

        $this->storeMeta($article, 'seo_rank_math_score', $scoreData);
        $this->persistExtractedLinks($article, $links);

        $updatePayload = [
            'seo_score' => $scoreData['score'],
        ];

        if ($this->articleHasColumn('internal_link_count')) {
            $updatePayload['internal_link_count'] = count($links['internal']);
        }
        if ($this->articleHasColumn('external_link_count')) {
            $updatePayload['external_link_count'] = count($links['external']);
        }

        $article->update($updatePayload);

        return $scoreData;
    }

    /**
     * @param  array{internal: array<int, mixed>, external: array<int, mixed>}  $extractedLinks
     * @param  array<int, string>  $excludeAnchorPhrases
     */
    private function persistExtractedLinks(
        SeoArticle $article,
        array $extractedLinks,
        array $excludeAnchorPhrases = [],
    ): void {
        if (! KeywordSyncIsolation::allowsContentKeywordPersistence()) {
            return;
        }

        $connection = (new Keyword)->getConnectionName();
        if (! Schema::connection($connection)->hasTable('keyword_link')) {
            return;
        }

        $previousKeywordIds = SeoLink::query()
            ->where('source_article_id', $article->id)
            ->with('keywords')
            ->get()
            ->flatMap(static fn (SeoLink $link): array => $link->keywords->pluck('id')->all())
            ->unique()
            ->values()
            ->all();

        $this->keywordPersistence->detachArticleOutboundLinks((int) $article->id);

        $article->loadMissing('site');
        $siteId = (int) ($article->site_id ?? 0);
        $focusKeyword = $this->resolveFocusKeyword($article);
        $articlePermalink = $focusKeyword !== null && trim($focusKeyword) !== ''
            ? trim(app(WordPressArticleContentService::class)->resolvePermalink($article))
            : '';

        foreach ($extractedLinks['internal'] as $link) {
            $href = (string) ($link['href'] ?? '');
            $anchorText = Keyword::decodePhrase(
                Str::limit(strip_tags((string) ($link['text'] ?? '')), 255, ''),
            );
            $anchorText = $this->normalizeAnchorAgainstFocusKeyword($anchorText, $focusKeyword);
            if ($anchorText === '' || $href === '') {
                continue;
            }

            if (
                $focusKeyword !== null
                && mb_strtolower($anchorText) === mb_strtolower($focusKeyword)
                && $articlePermalink !== ''
                && $this->urlsMatchForCompare($href, $articlePermalink)
            ) {
                continue;
            }

            if ($this->shouldExcludeAnchorPhrase($anchorText, $excludeAnchorPhrases)) {
                $this->keywordPersistence->resolveOrCreateLink(
                    siteId: $siteId,
                    url: $href,
                    type: SeoLink::TYPE_INTERNAL,
                    sourceArticleId: (int) $article->id,
                    isNofollow: (bool) ($link['is_nofollow'] ?? false),
                );

                continue;
            }

            if (
                InternalAnchorKeywordFilter::isUsableAnchorPhrase($anchorText, $href)
                && ! $this->ctaKeywordBlacklistFilter->isBlocked($anchorText)
            ) {
                $keyword = $this->keywordPersistence->upsert(
                    $anchorText,
                    Keyword::TYPE_NORMAL,
                    $siteId,
                    $href,
                    sourceArticleId: (int) $article->id,
                    isNofollow: (bool) ($link['is_nofollow'] ?? false),
                );

                if (
                    $keyword !== null
                    && $focusKeyword !== null
                    && mb_strtolower($anchorText) === mb_strtolower($focusKeyword)
                ) {
                    $this->keywordPersistence->mergeSuffixTruncatedKeywords($keyword, $siteId);
                }
            } else {
                $this->keywordPersistence->resolveOrCreateLink(
                    siteId: $siteId,
                    url: $href,
                    type: SeoLink::TYPE_INTERNAL,
                    sourceArticleId: (int) $article->id,
                    isNofollow: (bool) ($link['is_nofollow'] ?? false),
                );
            }
        }

        foreach ($extractedLinks['external'] as $link) {
            $href = trim((string) ($link['href'] ?? ''));
            if ($href === '') {
                continue;
            }

            $this->keywordPersistence->resolveOrCreateLink(
                siteId: $siteId,
                url: $href,
                type: SeoLink::TYPE_EXTERNAL,
                sourceArticleId: (int) $article->id,
                isNofollow: (bool) ($link['is_nofollow'] ?? false),
            );
        }

        KeywordOrphanCleanup::deleteUnusedByIds($previousKeywordIds);
    }

    private function resolveArticleDomain(SeoArticle $article, ?string $domainOverride = null): string
    {
        if ($domainOverride !== null && trim($domainOverride) !== '') {
            return $this->normalizeDomain($domainOverride);
        }

        if ($article->relationLoaded('site') && $article->site !== null) {
            return $this->normalizeDomain((string) $article->site->domain);
        }

        $site = \App\Models\Site::query()->find($article->site_id);

        return $this->normalizeDomain((string) ($site?->domain ?? ''));
    }

    private function normalizeFocusKeyword(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, ',')) {
            $parts = array_map(static fn (string $part): string => trim($part), explode(',', $raw));

            return $parts[0] ?? '';
        }

        return $raw;
    }

    private function normalizeAnchorAgainstFocusKeyword(string $anchorText, ?string $focusKeyword): string
    {
        $anchorText = Keyword::decodePhrase($anchorText);
        $focusKeyword = Keyword::decodePhrase($focusKeyword ?? '');

        if ($anchorText === '' || $focusKeyword === '') {
            return $anchorText;
        }

        $anchorNorm = mb_strtolower($anchorText);
        $focusNorm = mb_strtolower($focusKeyword);

        if ($anchorNorm === $focusNorm) {
            return $focusKeyword;
        }

        if (str_ends_with($focusNorm, $anchorNorm) && mb_strlen($anchorNorm) < mb_strlen($focusNorm)) {
            return $focusKeyword;
        }

        return $anchorText;
    }

    /**
     * @param  array<int, string>  $excludeAnchorPhrases
     */
    private function shouldExcludeAnchorPhrase(string $anchorText, array $excludeAnchorPhrases): bool
    {
        if ($excludeAnchorPhrases === []) {
            return false;
        }

        $anchorNorm = mb_strtolower(Keyword::decodePhrase($anchorText));
        if ($anchorNorm === '') {
            return false;
        }

        foreach ($excludeAnchorPhrases as $phrase) {
            if ($anchorNorm === mb_strtolower(Keyword::decodePhrase((string) $phrase))) {
                return true;
            }
        }

        return false;
    }

    private function urlsMatchForCompare(string $first, string $second): bool
    {
        $firstNorm = rtrim(strtolower(trim($first)), '/');
        $secondNorm = rtrim(strtolower(trim($second)), '/');

        if ($firstNorm === '' || $secondNorm === '') {
            return false;
        }

        if ($firstNorm === $secondNorm) {
            return true;
        }

        return str_ends_with($firstNorm, $secondNorm) || str_ends_with($secondNorm, $firstNorm);
    }

    private function resolveSeoTitle(SeoArticle $article): string
    {
        return trim((string) ($article->title ?? ''));
    }

    /**
     * Helper gọi tĩnh tiện dùng ở bất cứ luồng nào.
     *
     * Ví dụ:
     * SeoAnalyzerService::analyzeArticle($article);
     */
    public static function analyzeArticle(SeoArticle $article): array
    {
        return app(self::class)->analyze($article);
    }

    public function resolveFocusKeywordForArticle(SeoArticle $article): ?string
    {
        return $this->resolveFocusKeyword($article);
    }

    private function resolveFocusKeyword(SeoArticle $article): ?string
    {
        $article->loadMissing(['keywords', 'articleMetas']);

        $metaKeyword = $article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword');
        if ($metaKeyword && is_string($metaKeyword->meta_value) && trim($metaKeyword->meta_value) !== '') {
            $fromMeta = $this->normalizeFocusKeyword($metaKeyword->meta_value);

            if ($fromMeta !== '') {
                return $fromMeta;
            }
        }

        $main = $article->keywords
            ->filter(function ($keyword): bool {
                return ((int) ($keyword->pivot->is_main ?? 0) === 1) || ((int) ($keyword->is_main ?? 0) === 1);
            })
            ->sortBy(function ($keyword): int {
                return Keyword::isNormalType((string) ($keyword->type ?? '')) ? 0 : 1;
            })
            ->first();

        if (! $main) {
            return null;
        }

        $keyword = $this->normalizeFocusKeyword(
            (string) ($main->phrase ?? $main->keyword ?? $main->name ?? '')
        );

        return $keyword !== '' ? $keyword : null;
    }

    private function resolveMetaDescription(SeoArticle $article): string
    {
        $article->loadMissing('articleMetas');

        $meta = $article->articleMetas
            ->first(function ($m): bool {
                return in_array((string) $m->meta_key, [
                    'meta_description',
                    'seo_meta_description',
                    '_yoast_wpseo_metadesc',
                    'rank_math_description',
                ], true);
            });

        if ($meta && is_string($meta->meta_value) && trim($meta->meta_value) !== '') {
            return trim($meta->meta_value);
        }

        return trim((string) ($article->excerpt ?? ''));
    }

    /**
     * @return array{
     *   internal: array<int, array{href:string,text:string,is_nofollow:bool,offset:int}>,
     *   external: array<int, array{href:string,text:string,is_nofollow:bool,offset:int}>
     * }
     */
    private function extractLinks(string $content, string $domain): array
    {
        $result = [
            'internal' => [],
            'external' => [],
        ];

        if (trim($content) === '') {
            return $result;
        }

        $pattern = '/<a\b([^>]*\bhref\s*=\s*(["\'])([^"\']+)\2[^>]*)>([\s\S]*?)<\/a>/iu';
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return $result;
        }

        foreach ($matches as $match) {
            $offset = (int) ($match[0][1] ?? 0);
            $attrs = (string) ($match[1][0] ?? '');
            $href = trim(html_entity_decode((string) ($match[3][0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($href === '' || str_starts_with($href, '#') || $this->isSpecialSchemeLink($href)) {
                continue;
            }

            $innerHtml = (string) ($match[4][0] ?? '');
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($innerHtml)) ?? '');

            $rel = '';
            if (preg_match('/\brel\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $relMatch) === 1) {
                $rel = strtolower((string) ($relMatch[2] ?? ''));
            }

            $isNoFollow = str_contains($rel, 'nofollow');

            $item = [
                'href' => $href,
                'text' => $text,
                'is_nofollow' => $isNoFollow,
                'offset' => $offset,
            ];

            if ($this->isInternalLink($href, $domain)) {
                $result['internal'][] = $item;

                continue;
            }

            $result['external'][] = $item;
        }

        $result['internal'] = $this->deduplicateLinksByHrefAndText($result['internal']);
        $result['external'] = $this->deduplicateLinksByHrefAndText($result['external']);

        return $result;
    }

    /**
     * Link không phải HTTP(S) — bỏ qua khi đếm internal/external (tel:, mailto:, …).
     */
    private function isSpecialSchemeLink(string $href): bool
    {
        $lower = strtolower($href);

        if (str_starts_with($lower, 'javascript:')) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (! is_string($scheme) || $scheme === '') {
            return false;
        }

        return in_array(strtolower($scheme), [
            'tel',
            'mailto',
            'sms',
            'fax',
            'callto',
            'geo',
            'skype',
            'whatsapp',
            'viber',
            'data',
            'cid',
        ], true);
    }

    private function isInternalLink(string $href, string $domain): bool
    {
        if (str_starts_with($href, '/')) {
            return true;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $host = parse_url($href, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = $this->normalizeDomain($host);

        return $host !== '' && $domain !== '' && $host === $domain;
    }

    private function containsKeyword(string $text, string $keyword): bool
    {
        return KeywordPhraseMatcher::contains($text, $keyword);
    }

    /**
     * So sánh slug bài viết với từ khóa chính đã chuyển sang kebab-case (Str::slug).
     */
    private function slugContainsFocusKeyword(string $slug, string $focusKeyword): bool
    {
        $keywordSlug = Str::slug($this->normalizeFocusKeyword($focusKeyword));
        $articleSlug = Str::slug(trim($slug));

        if ($keywordSlug === '' || $articleSlug === '') {
            return false;
        }

        return str_contains($articleSlug, $keywordSlug);
    }

    /**
     * Gộp link trùng: cùng URL và cùng anchor text chỉ tính một lần.
     *
     * @param  array<int, array<string, mixed>>  $links
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateLinksByHrefAndText(array $links): array
    {
        $seen = [];
        $unique = [];

        foreach ($links as $link) {
            $href = $this->normalizeLinkHrefForDedup((string) ($link['href'] ?? ''));
            $text = mb_strtolower(trim((string) ($link['text'] ?? '')));
            $key = $href."\0".$text;

            if ($href === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $link;
        }

        return $unique;
    }

    private function normalizeLinkHrefForDedup(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }

        return rtrim(mb_strtolower($href), '/');
    }

    private function keywordInFirstThreeWords(string $title, string $keyword): bool
    {
        if (trim($title) === '' || trim($keyword) === '') {
            return false;
        }

        $words = preg_split('/\s+/u', trim($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstThree = implode(' ', array_slice($words, 0, 3));

        return $this->containsKeyword($firstThree, $keyword);
    }

    private function sliceFirstTenPercentText(string $html): string
    {
        $text = trim(strip_tags($html));
        if ($text === '') {
            return '';
        }

        $length = mb_strlen($text);
        $portion = max(1, (int) ceil($length * 0.1));

        return mb_substr($text, 0, $portion);
    }

    private function countWords(string $html): int
    {
        $text = trim(strip_tags($html));
        if ($text === '') {
            return 0;
        }

        preg_match_all('/\pL[\pL\pN\-]*/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function calculateKeywordDensity(string $html, string $keyword): float
    {
        $plainText = trim(strip_tags($html));
        if ($plainText === '' || trim($keyword) === '') {
            return 0.0;
        }

        $totalWords = $this->countWords($plainText);
        if ($totalWords === 0) {
            return 0.0;
        }

        $occurrence = KeywordPhraseMatcher::countOccurrences($plainText, $keyword);
        $keywordWordCount = max(1, KeywordPhraseMatcher::countWords($keyword));

        return (($occurrence * $keywordWordCount) / $totalWords) * 100;
    }

    private function extractHeadingText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//h2|//h3|//h4');

        if ($nodes === false) {
            return '';
        }

        $chunks = [];
        foreach ($nodes as $node) {
            $chunks[] = trim((string) $node->textContent);
        }

        return trim(implode(' ', array_filter($chunks)));
    }

    private function extractImageAltText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $images = $xpath->query('//img[@alt]');

        if ($images === false) {
            return '';
        }

        $alts = [];
        foreach ($images as $img) {
            $alts[] = trim((string) $img->getAttribute('alt'));
        }

        return trim(implode(' ', array_filter($alts)));
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim(Str::lower($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#^www\.#', '', $domain) ?? $domain;
        $domain = trim($domain, '/');

        return $domain;
    }

    /**
     * Ghi meta an toàn cho relation name thực tế.
     *
     * @param  array<string, mixed>  $value
     */
    private function storeMeta(SeoArticle $article, string $key, array $value): void
    {
        $relation = $this->resolveMetaRelation($article);
        $relation->updateOrCreate(
            ['meta_key' => $key],
            ['meta_value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    private function resolveMetaRelation(SeoArticle $article): HasMany
    {
        if (method_exists($article, 'meta')) {
            /** @var HasMany $relation */
            $relation = $article->meta();

            return $relation;
        }

        /** @var HasMany $relation */
        $relation = $article->articleMetas();

        return $relation;
    }

    private function articleHasColumn(string $column): bool
    {
        return Schema::connection('omi_seo_ai')->hasColumn('articles', $column);
    }
}
