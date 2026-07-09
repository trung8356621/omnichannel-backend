<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Enums\KeywordMetaKey;
use App\Addons\SeoContentAi\Enums\SeoLinkMapType;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoLink;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\CtaKeywordBlacklistFilter;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Addons\SeoContentAi\Support\KeywordOrphanCleanup;
use App\Addons\SeoContentAi\Support\KeywordPhraseMatcher;
use App\Addons\SeoContentAi\Support\KeywordSyncIsolation;
use App\Addons\SeoContentAi\Support\SeoLinkMapLinkTypeClassifier;
use App\Addons\SeoContentAi\Services\SeoScoringCalculator;
use App\Addons\SeoContentAi\Services\SeoScoringEngine;
use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;
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
        private readonly SeoScoringEngine $scoringEngine,
        private readonly SeoPromptSettingsService $promptSettings,
    ) {}

    /**
     * Phân tích SEO tổng hợp theo rule-set nội bộ.
     *
     * @return array{score:int,violations:list<string>,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    public function analyze(SeoArticle $article, ?string $domainOverride = null): array
    {
        $focusKeyword = $this->resolveFocusKeyword($article);

        if ($focusKeyword === null) {
            return $this->persistScoreResult(
                $article,
                $this->buildScoreResult([SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD])
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
     * @return array{score:int,violations:list<string>,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    public function analyzeFromSyncItem(SeoArticle $article, array $item, ?string $domainOverride = null): array
    {
        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];
        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];

        $focusKeyword = Keyword::normalizeFocusPhrase(
            trim((string) ($scoring['focus_keyword'] ?? $seo['focus_keyword'] ?? '')),
        );

        if ($focusKeyword === '') {
            $fromDb = $this->resolveFocusKeyword($article);
            $focusKeyword = $fromDb !== null ? Keyword::normalizeFocusPhrase($fromDb) : '';
        }

        if ($focusKeyword === '') {
            return $this->persistScoreResult(
                $article,
                $this->buildScoreResult([SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD])
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
                $this->buildScoreResult([SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD]),
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
            $scoreData = $this->buildScoreResult([SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD]);
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
     * Lưu kết quả chấm điểm do editor JS gửi kèm khi save.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *   score:int,
     *   good:array<int,string>,
     *   errors:array<int,string>,
     *   warnings:array<int,string>,
     *   extracted_links:array{internal:array<int,mixed>,external:array<int,mixed>},
     *   content_bonus:array<string,mixed>|null,
     *   suggested_internal_links:array<int,mixed>
     * }
     */
    public function persistClientAnalysis(SeoArticle $article, string $content, array $payload): array
    {
        $scoreData = $this->sanitizeClientScorePayload($payload);
        $extractedLinks = $this->sanitizeClientExtractedLinks($payload['extracted_links'] ?? null, $content, $article);

        $persisted = $this->persistScoreResult($article, $scoreData, $extractedLinks);

        $contentBonus = is_array($payload['content_bonus'] ?? null)
            ? $payload['content_bonus']
            : app(ArticleContentSeoBonusService::class)->resolveFromContent($article, $content);

        return array_merge($persisted, [
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
     * @param  array<string, mixed>  $payload
     * @return array{score:int,violations:list<string>,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    private function sanitizeClientScorePayload(array $payload): array
    {
        $violations = is_array($payload['violations'] ?? null)
            ? SeoScoringRulesRegistry::sanitizeViolations($payload['violations'])
            : [];

        if ($violations === [] && is_array($payload['reason_keys'] ?? null)) {
            $violations = SeoScoringRulesRegistry::sanitizeViolations($payload['reason_keys']);
        }

        return $this->buildScoreResult($violations);
    }

    /**
     * @return array<int, string>
     */
    private function sanitizeScoreLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        $result = [];
        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    /**
     * @return array{internal: array<int, mixed>, external: array<int, mixed>}
     */
    private function sanitizeClientExtractedLinks(mixed $links, string $content, SeoArticle $article): array
    {
        if (is_array($links) && is_array($links['internal'] ?? null) && is_array($links['external'] ?? null)) {
            return [
                'internal' => array_values($links['internal']),
                'external' => array_values($links['external']),
            ];
        }

        $domain = $this->resolveArticleDomain($article, null);

        return $this->extractLinks($content, $domain);
    }

    /**
     * @return array{
     *   scoreData: array{score:int,violations:list<string>,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>},
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
        $extractedLinks = $this->extractLinks($content, $domain);

        $violations = $this->scoringEngine->analyzeViolations(
            $content,
            $focusKeyword,
            $this->resolveFaqsForScoring($article, $content),
            [
                'seo_title' => $seoTitle,
                'meta_description' => $metaDescription,
                'slug' => $slug,
                'domain' => $domain,
                'article_length_target' => $this->promptSettings->resolveArticleLengthTarget(
                    ArticlePostTypeResolver::resolve($article),
                ),
                'featured_snippet_thresholds' => $this->promptSettings->getFeaturedSnippetThresholds(),
            ],
        );

        $scoreData = $this->buildScoreResult($violations);

        return [
            'scoreData' => $scoreData,
            'extractedLinks' => $extractedLinks,
        ];
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function resolveFaqsForScoring(SeoArticle $article, string $content): array
    {
        $dbFaqs = $article->resolveFaqs();
        $content = trim($content);

        if ($content === '') {
            return $dbFaqs;
        }

        $contentFaqs = $this->workflowParser->parseFaqsFromContent($content);

        if (count($contentFaqs) > count($dbFaqs)) {
            return $contentFaqs;
        }

        return $dbFaqs;
    }

    /**
     * @return array{score:int,violations:list<string>,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
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
     * @param  list<string>  $violations
     * @return array{score:int,violations:list<string>,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    private function buildScoreResult(array $violations): array
    {
        $violations = SeoScoringRulesRegistry::sanitizeViolations($violations);
        $score = SeoScoringCalculator::scoreFromViolations($violations);
        $lines = SeoScoringCalculator::violationLines($violations);

        return [
            'score' => $score,
            'violations' => $violations,
            'good' => $violations === [] ? [__('seo_rules.all_passed')] : [],
            'errors' => array_map(
                static fn (array $line): string => sprintf('-%d: %s', $line['deduction'], $line['message']),
                $lines,
            ),
            'warnings' => [],
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

    /**
     * Bóc tách link trong nội dung bài, gắn keyword mới và gỡ keyword/link outbound cũ của bài này.
     *
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
     * @param  array{score:int,violations:list<string>,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}  $scoreData
     * @param  array{internal: array<int, mixed>, external: array<int, mixed>}|null  $extractedLinks
     * @return array{score:int,violations:list<string>,good:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    private function persistScoreResult(SeoArticle $article, array $scoreData, ?array $extractedLinks = null): array
    {
        if (! $article->countsTowardSeoScore()) {
            return $scoreData;
        }

        $links = $extractedLinks ?? ['internal' => [], 'external' => []];
        $violations = SeoScoringRulesRegistry::sanitizeViolations($scoreData['violations'] ?? []);
        $score = SeoScoringCalculator::scoreFromViolations($violations);

        $this->storeMeta($article, SeoScoringRulesRegistry::META_KEY_VIOLATIONS, $violations);
        $this->persistExtractedLinks($article, $links);

        $updatePayload = [
            'seo_score' => $score,
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
            $anchorText = Keyword::preparePhraseForStorage(
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
        $article->loadMissing(['articleMetas']);

        $metaKeyword = $article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword');
        if ($metaKeyword && is_string($metaKeyword->meta_value) && trim($metaKeyword->meta_value) !== '') {
            $fromMeta = Keyword::normalizeFocusPhrase($metaKeyword->meta_value);

            if ($fromMeta !== '') {
                return $fromMeta;
            }
        }

        $mainKeyword = Keyword::query()
            ->whereHas(
                'metas',
                static fn ($query) => $query
                    ->where('meta_key', KeywordMetaKey::MainArticleId->value)
                    ->where('meta_value', (string) $article->id),
            )
            ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', [Keyword::TYPE_NORMAL])
            ->first();

        if ($mainKeyword === null) {
            return null;
        }

        $keyword = Keyword::normalizeFocusPhrase((string) $mainKeyword->phrase);

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
    public function extractLinks(string $content, string $domain): array
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
        $keywordSlug = Str::slug(Keyword::normalizeFocusPhrase($focusKeyword));
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

    private function countH2Tags(string $html): int
    {
        if (trim($html) === '') {
            return 0;
        }

        if (preg_match_all('/<h2\b[^>]*>/iu', $html, $matches) === false) {
            return 0;
        }

        return count($matches[0] ?? []);
    }

    /**
     * @return array{score:int,ratio:int,msg:string}
     */
    private function calculateTextToImageScore(string $htmlContent): array
    {
        if (trim($htmlContent) === '') {
            return ['score' => 0, 'ratio' => 0, 'msg' => 'Bài viết quá ngắn'];
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $images = $dom->getElementsByTagName('img');
        $imageCount = $images->length;

        $textContent = strip_tags($htmlContent);
        $textContent = preg_replace('/\s+/u', ' ', trim($textContent)) ?? '';
        $wordCount = $textContent === '' ? 0 : count(explode(' ', $textContent));

        if ($wordCount < 10) {
            return ['score' => 0, 'ratio' => 0, 'msg' => 'Bài viết quá ngắn'];
        }

        if ($imageCount === 0) {
            return [
                'score' => 0,
                'ratio' => $wordCount,
                'msg' => 'Cảnh báo: Bài viết không có hình ảnh minh họa (0 điểm)',
            ];
        }

        $wordsPerImage = (int) round($wordCount / $imageCount);
        $score = 0;
        $msg = '';

        if ($wordsPerImage >= 250 && $wordsPerImage <= 450) {
            $score = 15;
            $msg = "Tuyệt vời: Mật độ ảnh lý tưởng ({$wordsPerImage} từ/ảnh)";
        } elseif ($wordsPerImage > 450 && $wordsPerImage <= 800) {
            $score = 10;
            $msg = "Khá ổn: Bài viết hơi dài, nên chèn thêm ảnh ({$wordsPerImage} từ/ảnh)";
        } elseif ($wordsPerImage < 250 && $wordsPerImage >= 100) {
            $score = 8;
            $msg = "Mật độ ảnh hơi dày ({$wordsPerImage} từ/ảnh)";
        } else {
            $score = 3;
            $msg = "Cảnh báo: Tỷ lệ phân bổ từ và ảnh chưa hợp lý ({$wordsPerImage} từ/ảnh)";
        }

        $missingAlt = 0;
        foreach ($images as $img) {
            $alt = trim((string) $img->getAttribute('alt'));
            if ($alt === '') {
                $missingAlt++;
            }
        }

        if ($missingAlt > 0) {
            $score = max(0, $score - 5);
            $msg .= " - Phát hiện {$missingAlt} ảnh thiếu thẻ ALT!";
        }

        return [
            'score' => $score,
            'ratio' => $wordsPerImage,
            'msg' => $msg,
        ];
    }

    /**
     * @param  array{internal: array<int, mixed>, external: array<int, mixed>}  $extractedLinks
     */
    private function hasWikiTrustExternalLink(array $extractedLinks): bool
    {
        foreach ($extractedLinks['external'] as $link) {
            $href = trim((string) ($link['href'] ?? ''));
            if ($href === '') {
                continue;
            }

            if (SeoLinkMapLinkTypeClassifier::forUnresolvedUrl($href) === SeoLinkMapType::WikiTrust) {
                return true;
            }
        }

        return false;
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
