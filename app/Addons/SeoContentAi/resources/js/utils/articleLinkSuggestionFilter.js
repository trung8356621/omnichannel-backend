export function normalizeLinkLabel(text) {
    return String(text ?? '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

/** Bỏ apostrophe / dấu câu khi so khớp từ khóa (KID'S ≈ kids). */
export function normalizePhraseForMatch(text) {
    return String(text ?? '')
        .toLowerCase()
        .replace(/[\u0027\u2018\u2019\u201B\u2032\u0060\u00B4\u02BC\uFF07]/g, '')
        .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

export function normalizeHrefForCompare(href) {
    const value = String(href ?? '').trim();
    if (!value) {
        return '';
    }

    try {
        const url = new URL(value, window.location.origin);
        const pathname = url.pathname.replace(/\/+$/, '') || '/';

        return `${pathname.toLowerCase()}${url.search.toLowerCase()}`;
    } catch {
        return value.replace(/\/+$/, '').toLowerCase();
    }
}

function labelsOverlap(left, right) {
    const a = normalizeLinkLabel(left);
    const b = normalizeLinkLabel(right);
    if (!a || !b) {
        return false;
    }

    if (a === b) {
        return true;
    }

    return a.includes(b) || b.includes(a);
}

function isPhraseAlreadyLinked(phrase, linkedLabels) {
    const normalized = normalizeLinkLabel(phrase);
    if (!normalized) {
        return true;
    }

    return linkedLabels.some((label) => labelsOverlap(label, normalized));
}

function isHrefAlreadyLinked(href, linkedHrefs) {
    const normalized = normalizeHrefForCompare(href);
    if (!normalized) {
        return false;
    }

    return linkedHrefs.includes(normalized);
}

/**
 * @param {Array<{ text?: string, href?: string, target_url?: string }>} suggested
 * @param {Array<{ text?: string, href?: string }>} internal
 */
export function textContainsPhrase(plainText, phrase) {
    const text = normalizePhraseForMatch(plainText);
    const needle = normalizePhraseForMatch(phrase);
    if (!text || !needle) {
        return false;
    }

    return text.includes(needle);
}

/**
 * Domain link list — chỉ gợi ý khi anchor text có trong nội dung bài (plain text).
 *
 * @param {Array<{ text?: string }>} links
 * @param {string} articlePlainText
 */
export function filterDomainLinksInArticleContent(links, articlePlainText) {
    const plain = String(articlePlainText ?? '');
    if (!plain.trim()) {
        return [];
    }

    return (Array.isArray(links) ? links : []).filter((item) => {
        const phrase = String(item?.text ?? '').trim();

        return phrase !== '' && textContainsPhrase(plain, phrase);
    });
}

export function filterSuggestedInternalLinks(suggested, internal) {
    const internalItems = Array.isArray(internal) ? internal : [];
    const linkedLabels = [];
    const linkedHrefs = [];

    internalItems.forEach((item) => {
        const label = normalizeLinkLabel(item?.text);
        if (label) {
            linkedLabels.push(label);
        }

        const href = normalizeHrefForCompare(item?.href);
        if (href) {
            linkedHrefs.push(href);
        }
    });

    const uniqueLabels = [...new Set(linkedLabels)];
    const uniqueHrefs = [...new Set(linkedHrefs)];

    const filtered = [];
    const seenLabels = [...uniqueLabels];
    const seenHrefs = [...uniqueHrefs];

    (Array.isArray(suggested) ? suggested : []).forEach((item) => {
        const phrase = String(item?.text ?? '').trim();
        const href = String(item?.href ?? item?.target_url ?? '').trim();

        if (isPhraseAlreadyLinked(phrase, seenLabels)) {
            return;
        }

        if (isHrefAlreadyLinked(href, seenHrefs)) {
            return;
        }

        filtered.push(item);

        const label = normalizeLinkLabel(phrase);
        if (label) {
            seenLabels.push(label);
        }

        const hrefKey = normalizeHrefForCompare(href);
        if (hrefKey) {
            seenHrefs.push(hrefKey);
        }
    });

    return filtered;
}

export const MAX_INTERNAL_LINK_SLOTS = 10;
/** Số gợi ý hiển thị khi bài còn < 10 link nội bộ (không trừ theo số link đã có). */
export const MAX_VISIBLE_INTERNAL_SUGGESTIONS = 10;

export function isSuggestionExcluded(phrase, excludedLabels) {
    const normalized = normalizeLinkLabel(phrase);
    if (!normalized) {
        return false;
    }

    return (Array.isArray(excludedLabels) ? excludedLabels : []).some((excluded) =>
        labelsOverlap(excluded, normalized),
    );
}

/**
 * @param {Array<{ text?: string, href?: string, target_url?: string, keyword_id?: number, can_insert?: boolean }>} sources
 */
export function mergeSuggestionCatalog(...sources) {
    const seen = new Set();
    const merged = [];

    sources.flat().forEach((item) => {
        const text = String(item?.text ?? '').trim();
        const label = normalizeLinkLabel(text);
        if (!label || seen.has(label)) {
            return;
        }

        seen.add(label);
        const href = String(item?.href ?? item?.target_url ?? '').trim();

        merged.push({
            text,
            href: href || null,
            target_url: String(item?.target_url ?? item?.href ?? '').trim() || null,
            keyword_id: item?.keyword_id ?? null,
            can_insert: item?.can_insert !== false && href !== '',
            is_suggestion: true,
        });
    });

    return merged.sort((left, right) => String(right.text).length - String(left.text).length);
}

/**
 * @param {{
 *   catalog?: Array<{ text?: string, href?: string, target_url?: string, keyword_id?: number, can_insert?: boolean }>,
 *   internal?: Array<{ text?: string, href?: string }>,
 *   excludedLabels?: string[],
 *   articlePlainText?: string,
 *   maxSlots?: number,
 *   skipContentFilter?: boolean,
 * }} options
 */
export function buildVisibleInternalSuggestions({
    catalog = [],
    internal = [],
    excludedLabels = [],
    articlePlainText = '',
    maxSlots = MAX_INTERNAL_LINK_SLOTS,
    skipContentFilter = false,
} = {}) {
    const internalCount = Array.isArray(internal) ? internal.length : 0;
    if (internalCount >= maxSlots) {
        return [];
    }

    let pool = Array.isArray(catalog) ? catalog : [];
    const plain = String(articlePlainText ?? '').trim();
    if (!skipContentFilter && plain !== '') {
        pool = filterDomainLinksInArticleContent(pool, plain);
    }

    const withoutExcluded = pool.filter((item) => {
        const phrase = String(item?.text ?? '').trim();

        return phrase !== '' && !isSuggestionExcluded(phrase, excludedLabels);
    });

    return filterSuggestedInternalLinks(withoutExcluded, internal).slice(
        0,
        MAX_VISIBLE_INTERNAL_SUGGESTIONS,
    );
}
