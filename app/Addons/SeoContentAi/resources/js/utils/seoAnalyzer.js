import { normalizeArticleSlug } from './articleSlugUtils';
import { isFaqPlaceholderHtml } from './editorHtmlUtils';
import {
    containsKeywordPhrase,
    normalizePhrase,
} from './keywordPhraseMatcher';
import {
    buildViolationLines,
    isRuleEnabled,
    resolveRuleMessage,
    sanitizeViolations,
    scoreFromViolations,
} from './seoScoreCalculator';
import { DEFAULT_WIKI_TRUST_DOMAINS, isWikiTrustUrl, normalizeDomainHost, resolveLinkHost } from './wikiTrustDomains';

const RULE_KEYS = {
    missingFocusKeyword: 'missing_focus_keyword',
    h2Missing: 'h2_missing',
    contentLengthLow: 'content_length_low',
    imageRatioMissing: 'image_ratio_missing',
    imageRatioPoor: 'image_ratio_poor',
    imageRatioLow: 'image_ratio_low',
    imageRatioSuboptimal: 'image_ratio_suboptimal',
    imageAltMissing: 'image_alt_missing',
    wikiTrustMissing: 'wiki_trust_missing',
    faqMissing: 'faq_missing',
    keywordMissingInTitle: 'keyword_missing_in_title',
    keywordMissingInMeta: 'keyword_missing_in_meta',
    keywordMissingInSlug: 'keyword_missing_in_slug',
    keywordMissingInIntro: 'keyword_missing_in_intro',
    featuredSnippetMissing: 'featured_snippet_missing',
    featuredSnippetBelowGood: 'featured_snippet_below_good',
    featuredSnippetBelowExcellent: 'featured_snippet_below_excellent',
};

export function resolveScoringMessage(key, messages = {}, params = {}) {
    let template = resolveRuleMessage(key, [], messages);
    if (template === key && String(key).startsWith('seo_rules.')) {
        template = String(messages?.[key] ?? key);
    }
    Object.entries(params).forEach(([name, value]) => {
        template = template.replaceAll(`:${name}`, String(value));
    });

    return template;
}

function normalizeFocusKeyword(raw) {
    const value = String(raw ?? '').trim();
    if (value === '') {
        return '';
    }

    if (value.includes(',')) {
        return value.split(',')[0]?.trim() ?? '';
    }

    return value;
}

function slugContainsFocusKeyword(slug, focusKeyword) {
    const keywordSlug = normalizeArticleSlug(normalizeFocusKeyword(focusKeyword));
    const articleSlug = normalizeArticleSlug(slug);

    if (keywordSlug === '' || articleSlug === '') {
        return false;
    }

    return articleSlug.includes(keywordSlug);
}

function countWords(html) {
    const text = String(html ?? '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (text === '') {
        return 0;
    }

    const matches = text.match(/[\p{L}][\p{L}\p{N}\-]*/gu);

    return matches ? matches.length : 0;
}

function countWordsForImageRatio(html) {
    const text = String(html ?? '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (text === '') {
        return 0;
    }

    return text.split(/\s+/u).filter(Boolean).length;
}

function sliceFirstWords(html, wordLimit) {
    const text = String(html ?? '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (text === '') {
        return '';
    }

    const matches = text.match(/[\p{L}][\p{L}\p{N}\-]*/gu) ?? [];

    return matches.slice(0, Math.max(1, wordLimit)).join(' ');
}

function countH2Tags(html) {
    if (typeof document === 'undefined') {
        return (String(html ?? '').match(/<h2\b[^>]*>/gi) ?? []).length;
    }

    const container = document.createElement('div');
    container.innerHTML = String(html ?? '');

    return container.querySelectorAll('h2').length;
}

function isSpecialSchemeLink(href) {
    const lower = String(href ?? '').trim().toLowerCase();
    if (lower.startsWith('javascript:')) {
        return true;
    }

    const match = lower.match(/^([a-z][a-z0-9+.-]*):/i);
    if (!match) {
        return false;
    }

    return ['tel', 'mailto', 'sms', 'fax', 'callto', 'geo', 'skype', 'whatsapp', 'viber', 'data', 'cid'].includes(
        match[1].toLowerCase(),
    );
}

function isInternalLink(href, domain) {
    const value = String(href ?? '').trim();
    if (value.startsWith('/')) {
        return true;
    }

    const host = resolveLinkHost(value.startsWith('//') ? `https:${value}` : value);
    const normalizedDomain = normalizeDomainHost(domain);

    return host !== '' && normalizedDomain !== '' && host === normalizedDomain;
}

function normalizeLinkHrefForDedup(href) {
    return String(href ?? '').trim().toLowerCase().replace(/\/+$/, '');
}

function deduplicateLinksByHrefAndText(links) {
    const seen = new Set();
    const unique = [];

    links.forEach((link) => {
        const href = normalizeLinkHrefForDedup(link.href);
        const text = normalizePhrase(link.text);
        const key = `${href}\0${text}`;

        if (href === '' || seen.has(key)) {
            return;
        }

        seen.add(key);
        unique.push(link);
    });

    return unique;
}

export function extractLinks(content, domain) {
    const result = {
        internal: [],
        external: [],
    };

    const source = String(content ?? '').trim();
    if (source === '') {
        return result;
    }

    const pattern = /<a\b([^>]*\bhref\s*=\s*(["'])([^"']+)\2[^>]*)>([\s\S]*?)<\/a>/giu;
    let match;

    while ((match = pattern.exec(source)) !== null) {
        const attrs = match[1] ?? '';
        const href = String(match[3] ?? '').trim();
        if (href === '' || href.startsWith('#') || isSpecialSchemeLink(href)) {
            continue;
        }

        const innerHtml = match[4] ?? '';
        const text = String(innerHtml).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        const relMatch = attrs.match(/\brel\s*=\s*(["'])([^"']*)\1/i);
        const rel = relMatch ? relMatch[2].toLowerCase() : '';
        const isNofollow = rel.includes('nofollow');
        const offset = match.index ?? 0;

        const item = {
            href,
            text,
            is_nofollow: isNofollow,
            offset,
        };

        if (isInternalLink(href, domain)) {
            result.internal.push(item);
        } else {
            result.external.push(item);
        }
    }

    result.internal = deduplicateLinksByHrefAndText(result.internal);
    result.external = deduplicateLinksByHrefAndText(result.external);

    return result;
}

function calculateTextToImageMetrics(htmlContent) {
    if (typeof document === 'undefined') {
        return { baseScore: 0, missingAlt: 0 };
    }

    const container = document.createElement('div');
    container.innerHTML = String(htmlContent ?? '');
    const images = Array.from(container.querySelectorAll('img'));
    const imageCount = images.length;
    const wordCount = countWordsForImageRatio(htmlContent);

    let missingAlt = 0;
    images.forEach((img) => {
        if (String(img.getAttribute('alt') ?? '').trim() === '') {
            missingAlt += 1;
        }
    });

    if (wordCount < 10 || imageCount === 0) {
        return { baseScore: 0, missingAlt };
    }

    const wordsPerImage = Math.round(wordCount / imageCount);
    let baseScore = 3;

    if (wordsPerImage >= 250 && wordsPerImage <= 450) {
        baseScore = 15;
    } else if (wordsPerImage > 450 && wordsPerImage <= 800) {
        baseScore = 10;
    } else if (wordsPerImage < 250 && wordsPerImage >= 100) {
        baseScore = 8;
    }

    return { baseScore, missingAlt };
}

function hasWikiTrustExternalLink(extractedLinks, wikiTrustDomains) {
    return (extractedLinks.external ?? []).some((link) => isWikiTrustUrl(link.href, wikiTrustDomains));
}

function normalizeFaqs(faqs) {
    if (!Array.isArray(faqs)) {
        return [];
    }

    return faqs.filter((item) => {
        const question = String(item?.question ?? '').trim();
        const answer = String(item?.answer ?? '').trim();

        return question !== '' && answer !== '';
    });
}

function parseFaqHeadingPairs(container) {
    const faqs = [];

    container.querySelectorAll('h3').forEach((heading) => {
        const question = String(heading.textContent ?? '').trim();
        if (question === '') {
            return;
        }

        let answer = '';
        let sibling = heading.nextElementSibling;

        while (sibling) {
            const tag = sibling.tagName.toLowerCase();
            if (['h1', 'h2', 'h3'].includes(tag)) {
                break;
            }

            if (tag === 'p') {
                const text = String(sibling.textContent ?? '').trim();
                if (text !== '') {
                    answer = answer === '' ? text : `${answer} ${text}`;
                }
            }

            sibling = sibling.nextElementSibling;
        }

        if (answer !== '') {
            faqs.push({ question, answer });
        }
    });

    return faqs;
}

function parseFaqsFromHtmlForScoring(html) {
    const source = String(html ?? '').trim();
    if (source === '') {
        return [];
    }

    if (typeof document === 'undefined') {
        if (isFaqPlaceholderHtml(source) || /omi-faq-item/i.test(source)) {
            return [{ question: 'FAQ', answer: 'detected' }];
        }

        return [];
    }

    const container = document.createElement('div');
    container.innerHTML = source;

    const fromAccordion = [];
    container.querySelectorAll('.omi-faq-item').forEach((item) => {
        const question = String(item.querySelector('.omi-faq-item__question')?.textContent ?? '').trim();
        const answer = String(item.querySelector('.omi-faq-item__answer')?.textContent ?? '').trim();

        if (question !== '' && answer !== '') {
            fromAccordion.push({ question, answer });
        }
    });

    if (fromAccordion.length > 0) {
        return fromAccordion;
    }

    const fromHeadings = parseFaqHeadingPairs(container);
    if (fromHeadings.length > 0) {
        return fromHeadings;
    }

    if (isFaqPlaceholderHtml(source)) {
        return [{ question: '[omi_faq]', answer: 'shortcode' }];
    }

    return [];
}

function resolveFaqsForScoring(html, faqs) {
    const normalized = normalizeFaqs(faqs);
    if (normalized.length > 0) {
        return normalized;
    }

    return parseFaqsFromHtmlForScoring(html);
}

function resolveArticleLengthTarget(postType, settings = {}) {
    const normalized = String(postType ?? '').trim();
    const isProduct = normalized === 'product';
    const raw = isProduct ? settings.article_length_product : settings.article_length_default;
    const fallback = isProduct ? 1000 : 2000;
    const parsed = Number.parseInt(String(raw ?? ''), 10);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function countTableDataRows(table) {
    const rows = Array.from(table.querySelectorAll('tr'));
    if (rows.length === 0) {
        return 0;
    }

    const firstCells = rows[0]?.querySelectorAll('th, td')?.length ?? 0;
    const hasHeader = rows[0]?.querySelector('th') !== null;
    const dataRows = hasHeader ? Math.max(0, rows.length - 1) : rows.length;

    return { dataRows, columns: firstCells };
}

function columnCountPasses(colCount, minCols, maxCols) {
    return colCount >= minCols && colCount <= maxCols;
}

function resolveFeaturedSnippetTier(dataRows, thresholds) {
    const rowsMin = Number(thresholds?.rows_min ?? 6);
    const rowsRange = Number(thresholds?.rows_range ?? 8);
    const rowsMax = Number(thresholds?.rows_max ?? 10);

    if (dataRows >= rowsMax) {
        return 'excellent';
    }
    if (dataRows >= rowsRange) {
        return 'good';
    }
    if (dataRows >= rowsMin) {
        return 'average';
    }

    return 'none';
}

function resolveFeaturedSnippetViolation(html, thresholds = {}) {
    const source = String(html ?? '').trim();
    if (source === '' || !/<table\b/i.test(source)) {
        return RULE_KEYS.featuredSnippetMissing;
    }

    if (typeof document === 'undefined') {
        return RULE_KEYS.featuredSnippetMissing;
    }

    const minCols = Number(thresholds?.min_columns ?? 2);
    const maxCols = Number(thresholds?.max_columns ?? 5);
    const container = document.createElement('div');
    container.innerHTML = source;

    let bestTier = 'none';
    const tierRank = { none: 0, average: 1, good: 2, excellent: 3 };

    container.querySelectorAll('table').forEach((table) => {
        const { dataRows, columns } = countTableDataRows(table);
        if (!columnCountPasses(columns, minCols, maxCols)) {
            return;
        }

        const tier = resolveFeaturedSnippetTier(dataRows, thresholds);
        if (tierRank[tier] > tierRank[bestTier]) {
            bestTier = tier;
        }
    });

    if (bestTier === 'excellent') {
        return null;
    }
    if (bestTier === 'good') {
        return RULE_KEYS.featuredSnippetBelowExcellent;
    }
    if (bestTier === 'average') {
        return RULE_KEYS.featuredSnippetBelowGood;
    }

    return RULE_KEYS.featuredSnippetMissing;
}

function resolveImageRatioViolations(html) {
    const { baseScore, missingAlt } = calculateTextToImageMetrics(html);
    const violations = [];

    if (baseScore >= 15) {
        // no ratio violation
    } else if (baseScore >= 10) {
        violations.push(RULE_KEYS.imageRatioSuboptimal);
    } else if (baseScore >= 8) {
        violations.push(RULE_KEYS.imageRatioLow);
    } else if (baseScore >= 3) {
        violations.push(RULE_KEYS.imageRatioPoor);
    } else {
        violations.push(RULE_KEYS.imageRatioMissing);
    }

    if (missingAlt > 0) {
        violations.push(RULE_KEYS.imageAltMissing);
    }

    return violations;
}

function resolveKeywordViolations({ html, keyword, seoTitle, metaDescription, slug }) {
    const violations = [];

    if (!containsKeywordPhrase(seoTitle, keyword)) {
        violations.push(RULE_KEYS.keywordMissingInTitle);
    }
    if (!containsKeywordPhrase(metaDescription, keyword)) {
        violations.push(RULE_KEYS.keywordMissingInMeta);
    }
    if (!slugContainsFocusKeyword(slug, keyword)) {
        violations.push(RULE_KEYS.keywordMissingInSlug);
    }
    if (!containsKeywordPhrase(sliceFirstWords(html, 100), keyword)) {
        violations.push(RULE_KEYS.keywordMissingInIntro);
    }

    return violations;
}

function sanitizeViolationList(violations, seoScoringRules = []) {
    return sanitizeViolations(violations, seoScoringRules).filter((key) => isRuleEnabled(key, seoScoringRules));
}

function computeViolations({
    focusKeyword,
    seoTitle,
    content,
    slug,
    metaDescription,
    siteDomain,
    faqs,
    wikiTrustDomains,
    articleLengthTarget = 2000,
    featuredSnippetThresholds = {},
    seoScoringRules = [],
}) {
    const keyword = normalizeFocusKeyword(focusKeyword);
    const violations = [];
    const extractedLinks = extractLinks(content, siteDomain);

    if (countH2Tags(content) < 2) {
        violations.push(RULE_KEYS.h2Missing);
    }

    if (countWords(content) < Math.max(1, Number(articleLengthTarget) || 2000)) {
        violations.push(RULE_KEYS.contentLengthLow);
    }

    violations.push(...resolveImageRatioViolations(content));

    if (!hasWikiTrustExternalLink(extractedLinks, wikiTrustDomains)) {
        violations.push(RULE_KEYS.wikiTrustMissing);
    }

    if (resolveFaqsForScoring(content, faqs).length === 0) {
        violations.push(RULE_KEYS.faqMissing);
    }

    violations.push(
        ...resolveKeywordViolations({
            html: content,
            keyword,
            seoTitle,
            metaDescription,
            slug,
        }),
    );

    const snippetViolation = resolveFeaturedSnippetViolation(content, featuredSnippetThresholds);
    if (snippetViolation) {
        violations.push(snippetViolation);
    }

    return {
        violations: sanitizeViolationList(violations, seoScoringRules),
        extracted_links: extractedLinks,
    };
}

export function computeSeoAnalysis({
    html = '',
    focusKeyword = '',
    seoTitle = '',
    metaDescription = '',
    slug = '',
    siteDomain = '',
    faqs = [],
    wikiTrustDomains = DEFAULT_WIKI_TRUST_DOMAINS,
    scoringMessages = {},
    seoScoringRules = [],
    postType = 'article',
    articleLengthSettings = {},
    featuredSnippetThresholds = {},
} = {}) {
    const keyword = normalizeFocusKeyword(focusKeyword);
    const content = String(html ?? '');

    if (keyword === '') {
        const extractedLinks = extractLinks(content, siteDomain);
        const violations = isRuleEnabled(RULE_KEYS.missingFocusKeyword, seoScoringRules)
            ? [RULE_KEYS.missingFocusKeyword]
            : [];

        return {
            violations,
            score: scoreFromViolations(violations, seoScoringRules),
            seo_score: scoreFromViolations(violations, seoScoringRules),
            errors: buildViolationLines(violations, seoScoringRules, scoringMessages),
            good: [],
            warnings: [],
            extracted_links: extractedLinks,
        };
    }

    const result = computeViolations({
        focusKeyword: keyword,
        seoTitle,
        content,
        slug,
        metaDescription,
        siteDomain,
        faqs,
        wikiTrustDomains,
        articleLengthTarget: resolveArticleLengthTarget(postType, articleLengthSettings),
        featuredSnippetThresholds,
        seoScoringRules,
    });

    const violations = result.violations;
    const score = scoreFromViolations(violations, seoScoringRules);
    const errors = buildViolationLines(violations, seoScoringRules, scoringMessages);

    return {
        violations,
        score,
        seo_score: score,
        errors,
        good: violations.length === 0 ? [resolveScoringMessage('seo_rules.all_passed', scoringMessages)] : [],
        warnings: [],
        extracted_links: result.extracted_links,
    };
}

export function buildSeoAnalysisPayload(analysis) {
    if (!analysis || typeof analysis !== 'object') {
        return null;
    }

    return {
        violations: Array.isArray(analysis.violations) ? analysis.violations : [],
        extracted_links: analysis.extracted_links ?? { internal: [], external: [] },
    };
}

// Backward compat for tests importing calculateTextToImageScore
export function calculateTextToImageScore(htmlContent) {
    const { baseScore, missingAlt } = calculateTextToImageMetrics(htmlContent);
    let score = baseScore;
    if (missingAlt > 0) {
        score = Math.max(0, score - 5);
    }

    return { score, ratio: 0 };
}
