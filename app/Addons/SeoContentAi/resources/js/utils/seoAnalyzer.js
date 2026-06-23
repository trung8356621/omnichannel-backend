import { normalizeArticleSlug } from './articleSlugUtils';
import {
    containsKeywordPhrase,
    normalizePhrase,
} from './keywordPhraseMatcher';
import { DEFAULT_WIKI_TRUST_DOMAINS, isWikiTrustUrl, normalizeDomainHost, resolveLinkHost } from './wikiTrustDomains';

const MAX_HEADING = 20;
const MAX_LENGTH = 15;
const MAX_IMAGE_RATIO = 15;
const MAX_WIKI_TRUST = 15;
const MAX_FEATURED_SNIPPET = 10;
const MAX_FAQ_SCHEMA = 10;
const MAX_KEYWORD = 15;

export function resolveScoringMessage(key, messages = {}, params = {}) {
    let template = String(messages?.[key] ?? key);
    Object.entries(params).forEach(([name, value]) => {
        template = template.replaceAll(`:${name}`, String(value));
    });

    return template;
}

function clampScore(score) {
    return Math.max(0, Math.min(100, Math.round(score)));
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

export function calculateTextToImageScore(htmlContent) {
    if (typeof document === 'undefined') {
        return { score: 0, ratio: 0 };
    }

    const container = document.createElement('div');
    container.innerHTML = String(htmlContent ?? '');
    const images = Array.from(container.querySelectorAll('img'));
    const imageCount = images.length;
    const wordCount = countWordsForImageRatio(htmlContent);

    if (wordCount < 10 || imageCount === 0) {
        return { score: 0, ratio: wordCount };
    }

    const wordsPerImage = Math.round(wordCount / imageCount);
    let score = 0;

    if (wordsPerImage >= 250 && wordsPerImage <= 450) {
        score = 15;
    } else if (wordsPerImage > 450 && wordsPerImage <= 800) {
        score = 10;
    } else if (wordsPerImage < 250 && wordsPerImage >= 100) {
        score = 8;
    } else {
        score = 3;
    }

    let missingAlt = 0;
    images.forEach((img) => {
        if (String(img.getAttribute('alt') ?? '').trim() === '') {
            missingAlt += 1;
        }
    });

    if (missingAlt > 0) {
        score = Math.max(0, score - 5);
    }

    return { score, ratio: wordsPerImage };
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

function hasTableNearStart(html) {
    const leading = String(html ?? '').trim().slice(0, 4000);

    return /<table\b/i.test(leading);
}

function hasShortBulletListNearStart(html) {
    if (typeof document === 'undefined') {
        return false;
    }

    const container = document.createElement('div');
    container.innerHTML = String(html ?? '');

    for (const child of Array.from(container.children)) {
        const tag = child.tagName.toLowerCase();
        if (['p', 'div', 'span', 'br'].includes(tag)) {
            const text = String(child.textContent ?? '').trim();
            if (text === '') {
                continue;
            }
        }

        if (!['ul', 'ol'].includes(tag)) {
            break;
        }

        const items = child.querySelectorAll(':scope > li');

        return items.length > 0 && items.length <= 5;
    }

    return false;
}

function hasFeaturedSnippetStructure(html) {
    return hasTableNearStart(html) || hasShortBulletListNearStart(html);
}

function applyCategoryResult(category, messages, good, errors, warnings, reasonKeys) {
    const message = resolveScoringMessage(category.key, messages, category.params ?? {});
    const isPartial = category.key === 'seo.length.partial';

    if (category.passed && !isPartial) {
        good.push(message);

        return;
    }

    if (isPartial) {
        warnings.push(message);
        reasonKeys.push(category.key);

        return;
    }

    if (category.earned > 0) {
        warnings.push(message);
    } else {
        errors.push(message);
    }

    reasonKeys.push(category.key);
}

function scoreHeading(html) {
    const h2Count = countH2Tags(html);
    const passed = h2Count >= 2;

    return {
        max: MAX_HEADING,
        earned: passed ? MAX_HEADING : 0,
        passed,
        key: passed ? 'seo.heading.pass' : 'seo.heading',
        params: { points: MAX_HEADING },
    };
}

function scoreLength(html) {
    const wordCount = countWords(html);

    if (wordCount < 600) {
        return {
            max: MAX_LENGTH,
            earned: 0,
            passed: false,
            key: 'seo.length',
            params: { count: wordCount, points: 0, max: MAX_LENGTH },
        };
    }

    if (wordCount <= 1200) {
        return {
            max: MAX_LENGTH,
            earned: 10,
            passed: false,
            key: 'seo.length.partial',
            params: { count: wordCount, points: 10, max: MAX_LENGTH },
        };
    }

    return {
        max: MAX_LENGTH,
        earned: MAX_LENGTH,
        passed: true,
        key: 'seo.length.pass',
        params: { count: wordCount, points: MAX_LENGTH },
    };
}

function scoreTextToImage(html) {
    const result = calculateTextToImageScore(html);
    const earned = Math.min(MAX_IMAGE_RATIO, Math.max(0, Number(result.score ?? 0)));
    const passed = earned >= MAX_IMAGE_RATIO;

    return {
        max: MAX_IMAGE_RATIO,
        earned,
        passed,
        key: passed ? 'seo.image_ratio.pass' : 'seo.image_ratio',
        params: { ratio: result.ratio ?? 0, points: earned },
    };
}

function scoreWikiTrust(extractedLinks, wikiTrustDomains) {
    const passed = hasWikiTrustExternalLink(extractedLinks, wikiTrustDomains);

    return {
        max: MAX_WIKI_TRUST,
        earned: passed ? MAX_WIKI_TRUST : 0,
        passed,
        key: passed ? 'seo.wiki_trust.pass' : 'seo.wiki_trust',
        params: { points: MAX_WIKI_TRUST },
    };
}

function scoreFeaturedSnippet(html) {
    const passed = hasFeaturedSnippetStructure(html);

    return {
        max: MAX_FEATURED_SNIPPET,
        earned: passed ? MAX_FEATURED_SNIPPET : 0,
        passed,
        key: passed ? 'seo.featured_snippet.pass' : 'seo.featured_snippet',
        params: { points: MAX_FEATURED_SNIPPET },
    };
}

function scoreFaqSchema(faqs) {
    const passed = normalizeFaqs(faqs).length > 0;

    return {
        max: MAX_FAQ_SCHEMA,
        earned: passed ? MAX_FAQ_SCHEMA : 0,
        passed,
        key: passed ? 'seo.faq_schema.pass' : 'seo.faq_schema',
        params: { points: MAX_FAQ_SCHEMA },
    };
}

function scoreKeywordPlacement({ html, keyword, seoTitle, metaDescription, slug }) {
    const inTitle = containsKeywordPhrase(seoTitle, keyword);
    const inMeta = containsKeywordPhrase(metaDescription, keyword);
    const inSlug = slugContainsFocusKeyword(slug, keyword);
    const inFirst100 = containsKeywordPhrase(sliceFirstWords(html, 100), keyword);
    const passedCount = [inTitle, inMeta, inSlug, inFirst100].filter(Boolean).length;
    const earned = Math.round(MAX_KEYWORD * (passedCount / 4));
    const passed = passedCount === 4;

    return {
        max: MAX_KEYWORD,
        earned: passed ? MAX_KEYWORD : earned,
        passed,
        key: passed ? 'seo.keyword_density.pass' : 'seo.keyword_density',
        params: { points: passed ? MAX_KEYWORD : earned },
    };
}

function computeUnifiedScore({
    focusKeyword,
    seoTitle,
    content,
    slug,
    metaDescription,
    siteDomain,
    faqs,
    wikiTrustDomains,
    scoringMessages,
}) {
    const keyword = normalizeFocusKeyword(focusKeyword);
    const good = [];
    const errors = [];
    const warnings = [];
    const reasonKeys = [];
    const breakdown = {};
    let totalScore = 0;

    const extractedLinks = extractLinks(content, siteDomain);

    const categories = {
        heading: scoreHeading(content),
        length: scoreLength(content),
        image_ratio: scoreTextToImage(content),
        wiki_trust: scoreWikiTrust(extractedLinks, wikiTrustDomains),
        featured_snippet: scoreFeaturedSnippet(content),
        faq_schema: scoreFaqSchema(faqs),
        keyword: scoreKeywordPlacement({
            html: content,
            keyword,
            seoTitle,
            metaDescription,
            slug,
        }),
    };

    Object.entries(categories).forEach(([name, category]) => {
        breakdown[name] = category;
        totalScore += category.earned;
        applyCategoryResult(category, scoringMessages, good, errors, warnings, reasonKeys);
    });

    return {
        score: clampScore(totalScore),
        seo_score: clampScore(totalScore),
        reason_keys: [...new Set(reasonKeys)],
        breakdown,
        good,
        errors,
        warnings,
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
} = {}) {
    const keyword = normalizeFocusKeyword(focusKeyword);
    const content = String(html ?? '');

    if (keyword === '') {
        const extractedLinks = extractLinks(content, siteDomain);
        const message = resolveScoringMessage('seo.missing_focus_keyword', scoringMessages);

        return {
            score: 0,
            seo_score: 0,
            reason_keys: ['seo.missing_focus_keyword'],
            breakdown: {},
            good: [],
            errors: [message],
            warnings: [],
            extracted_links: extractedLinks,
        };
    }

    return computeUnifiedScore({
        focusKeyword: keyword,
        seoTitle,
        content,
        slug,
        metaDescription,
        siteDomain,
        faqs,
        wikiTrustDomains,
        scoringMessages,
    });
}

export function buildSeoAnalysisPayload(analysis) {
    if (!analysis || typeof analysis !== 'object') {
        return null;
    }

    return {
        score: clampScore(Number(analysis.score ?? analysis.seo_score ?? 0)),
        seo_score: clampScore(Number(analysis.seo_score ?? analysis.score ?? 0)),
        reason_keys: Array.isArray(analysis.reason_keys) ? analysis.reason_keys : [],
        breakdown: analysis.breakdown ?? {},
        good: Array.isArray(analysis.good) ? analysis.good : [],
        errors: Array.isArray(analysis.errors) ? analysis.errors : [],
        warnings: Array.isArray(analysis.warnings) ? analysis.warnings : [],
        extracted_links: analysis.extracted_links ?? { internal: [], external: [] },
    };
}
