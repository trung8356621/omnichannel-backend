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
