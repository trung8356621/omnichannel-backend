import { normalizeLinkText } from './articleLinkScroll';
import { findPlainTextRangeInRoot, wrapTextRangeWithLink } from './articlePlainTextRange';

/**
 * Bọc lần xuất hiện đầu tiên của cụm từ (chưa nằm trong thẻ a) thành link nội bộ.
 * Hỗ trợ cụm từ nằm trong thẻ b/strong hoặc cắt qua nhiều text node.
 *
 * @param {string} html
 * @param {string} phrase
 * @param {string} href
 * @returns {{ html: string, replaced: boolean }}
 */
export function wrapFirstPlainTextWithLink(html, phrase, href) {
    const target = normalizeLinkText(phrase);
    const url = String(href ?? '').trim();

    if (!target || !url || !html) {
        return { html, replaced: false };
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const body = doc.body;
    const match = findPlainTextRangeInRoot(body, target, 0);

    if (!match) {
        return { html, replaced: false };
    }

    const ok = wrapTextRangeWithLink(doc, match, url);

    return {
        html: body.innerHTML,
        replaced: ok,
    };
}
