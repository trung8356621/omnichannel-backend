import { findPlainTextRangeInRoot } from './articlePlainTextRange';
import {
    SEO_EDITOR_LINK_MARK_CLASS,
    SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS,
} from './articleEditorTransientMarkup';

/**
 * Tìm block chứa offset trong HTML export (join \n\n).
 *
 * @param {Array<{ id: string, content?: string, prefix?: string, suffix?: string }>} blocks
 * @param {number} offset
 */
export function findBlockIdForExportOffset(blocks, offset) {
    if (typeof offset !== 'number' || offset < 0) {
        return null;
    }

    let pos = 0;

    for (let i = 0; i < blocks.length; i++) {
        const block = blocks[i];
        const part =
            block.prefix || block.suffix
                ? [block.prefix, block.content, block.suffix].filter(Boolean).join('\n')
                : (block.content ?? '');

        if (!part) {
            continue;
        }

        const start = pos;
        const end = pos + part.length;

        if (offset >= start && offset < end) {
            return block.id;
        }

        pos = end;
        if (i < blocks.length - 1) {
            pos += 2;
        }
    }

    return null;
}

export function normalizeLinkText(text) {
    return String(text ?? '')
        .replace(/\s+/g, ' ')
        .trim();
}

function normalizeHrefForCompare(href) {
    const value = String(href ?? '').trim();
    if (!value) {
        return '';
    }

    try {
        const url = new URL(value, window.location.origin);
        const pathname = url.pathname.replace(/\/+$/, '');
        return `${url.origin}${pathname}${url.search}`;
    } catch {
        return value.replace(/\/+$/, '');
    }
}

/**
 * @param {HTMLAnchorElement|Element} anchor
 * @param {string} targetText
 */
export function anchorTextMatches(anchor, targetText) {
    const keyword = normalizeLinkText(targetText);
    if (!keyword) {
        return true;
    }

    const anchorText = normalizeLinkText(anchor.textContent);
    return anchorText === keyword;
}

/**
 * @param {HTMLAnchorElement|Element} anchor
 * @param {string} href
 */
export function anchorHrefMatches(anchor, href) {
    const targetHref = normalizeHrefForCompare(href);
    if (!targetHref) {
        return true;
    }

    const rawHref = anchor.getAttribute?.('href') ?? '';
    const normalized = normalizeHrefForCompare(rawHref);
    if (normalized === targetHref) {
        return true;
    }

    // Fallback for relative href rendered in editor.
    try {
        const absolute = normalizeHrefForCompare(new URL(rawHref, window.location.origin).toString());
        return absolute === targetHref;
    } catch {
        return false;
    }
}

/**
 * Đếm thẻ &lt;a&gt; có đúng anchor text (từ khóa), không lọc theo href.
 *
 * @param {string} html
 * @param {string} text
 * @param {string} [href]
 */
export function countMatchingAnchorsInHtml(html, text, href = '') {
    const targetText = normalizeLinkText(text);
    if (!html) {
        return 0;
    }

    if (!targetText && !normalizeHrefForCompare(href)) {
        return 0;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');

    return [...doc.querySelectorAll('a[href]')].filter(
        (anchor) => anchorTextMatches(anchor, targetText) && anchorHrefMatches(anchor, href),
    ).length;
}

/**
 * Tìm anchor trong block theo từ khóa (thứ tự xuất hiện trong DOM).
 *
 * @param {HTMLElement} root
 * @param {string} text
 * @param {number} matchIndex
 * @param {string} [href]
 */
export function findAnchorElementInRoot(root, text, matchIndex = 0, href = '') {
    if (!root) {
        return null;
    }

    const targetText = normalizeLinkText(text);
    if (!targetText && !normalizeHrefForCompare(href)) {
        return null;
    }

    const anchors = [...root.querySelectorAll('a[href]')];
    let seen = 0;

    for (const anchor of anchors) {
        if (!anchorTextMatches(anchor, targetText) || !anchorHrefMatches(anchor, href)) {
            continue;
        }
        if (seen === matchIndex) {
            return anchor;
        }
        seen += 1;
    }

    return null;
}

/**
 * @param {HTMLElement} el
 */
export function highlightAnchorElement(el) {
    if (!el) {
        return;
    }

    el.classList.add('seo-link-scroll-highlight');
    window.setTimeout(() => {
        el.classList.remove('seo-link-scroll-highlight');
    }, 2400);
}

/**
 * Cuộn tới anchor theo từ khóa — thử lại khi TipTap đang mount.
 *
 * @param {string} blockId
 * @param {string} text
 * @param {number} matchIndex
 * @param {string} [href]
 * @param {{ onDone?: () => void }} [options]
 */
export function scrollToKeywordAnchor(blockId, text, matchIndex = 0, href = '', options = {}) {
    const maxAttempts = 12;

    const attempt = (tryNo) => {
        const slot = document.querySelector(`[data-seo-block-id="${blockId}"]`);
        if (!slot) {
            if (tryNo < maxAttempts) {
                window.requestAnimationFrame(() => attempt(tryNo + 1));
            }
            return;
        }

        const anchorEl = findAnchorElementInRoot(slot, text, matchIndex, href);

        if (anchorEl) {
            anchorEl.scrollIntoView({ behavior: tryNo === 0 ? 'smooth' : 'auto', block: 'center' });
            highlightAnchorElement(anchorEl);
            options.onDone?.();
            return;
        }

        if (tryNo < maxAttempts) {
            window.requestAnimationFrame(() => attempt(tryNo + 1));
            return;
        }

        if (options.onMiss?.() === true) {
            options.onDone?.(true);
            return;
        }

        slot.scrollIntoView({ behavior: 'auto', block: 'center' });
        options.onDone?.(false);
    };

    attempt(0);
}

/**
 * @param {HTMLElement} itemEl
 * @param {string} keyword
 */
export function faqItemMatchesKeyword(itemEl, keyword) {
    const target = normalizeLinkText(keyword);
    if (!target || !itemEl) {
        return false;
    }

    const question = normalizeLinkText(
        itemEl.querySelector('.seo-faq-question-input')?.value ?? '',
    );

    return question === target;
}

/**
 * @param {number} faqIndex — thứ tự trong `.seo-faq-item` (khớp sidebar FAQ).
 * @returns {boolean}
 */
export function scrollToFaqByIndex(faqIndex) {
    const item =
        document.querySelector(`[data-seo-faq-index="${faqIndex}"]`) ??
        [...document.querySelectorAll('.seo-faq-item')][faqIndex];

    if (!item) {
        return false;
    }

    window.dispatchEvent(new CustomEvent('seo-editor-faq-navigate'));

    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
    item.classList.add('seo-faq-scroll-highlight');

    window.setTimeout(() => {
        item.classList.remove('seo-faq-scroll-highlight');
    }, 2400);

    return true;
}

/**
 * @param {string} text
 * @param {number} matchIndex
 * @returns {boolean}
 */
function countOccurrencesCaseInsensitive(haystack, needle) {
    const h = haystack.toLowerCase();
    const n = needle.toLowerCase();
    if (!n) {
        return 0;
    }

    let count = 0;
    let pos = 0;

    while ((pos = h.indexOf(n, pos)) !== -1) {
        count += 1;
        pos += n.length;
    }

    return count;
}

/**
 * @param {string} html
 * @param {string} text
 */
export function countPlainTextInHtml(html, text) {
    const targetText = normalizeLinkText(text);
    if (!targetText || !html) {
        return 0;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const bodyText = doc.body?.textContent ?? '';

    return countOccurrencesCaseInsensitive(bodyText, targetText);
}

/**
 * @param {HTMLElement} root
 * @param {string} text
 * @param {number} matchIndex
 */
export function findPlainTextMatchInRoot(root, text, matchIndex = 0) {
    return findPlainTextRangeInRoot(root, text, matchIndex);
}

/**
 * @param {string} blockId
 * @param {string} text
 * @param {number} matchIndex
 * @param {{ onDone?: () => void, onMiss?: () => boolean|void }} [options]
 */
export function scrollToPlainTextInBlock(blockId, text, matchIndex = 0, options = {}) {
    const maxAttempts = 12;

    const attempt = (tryNo) => {
        const slot = document.querySelector(`[data-seo-block-id="${blockId}"]`);
        if (!slot) {
            if (tryNo < maxAttempts) {
                window.requestAnimationFrame(() => attempt(tryNo + 1));
            }
            return;
        }

        const match = findPlainTextMatchInRoot(slot, text, matchIndex);

        if (match) {
            const range = document.createRange();
            range.setStart(match.node, match.start);
            range.setEnd(match.endNode, match.endOffset);

            const mark = document.createElement('mark');
            mark.className = `${SEO_EDITOR_LINK_MARK_CLASS} ${SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS}`;

            try {
                range.surroundContents(mark);
                mark.scrollIntoView({ behavior: tryNo === 0 ? 'smooth' : 'auto', block: 'center' });
                window.setTimeout(() => {
                    const parent = mark.parentNode;
                    if (parent) {
                        while (mark.firstChild) {
                            parent.insertBefore(mark.firstChild, mark);
                        }
                        parent.removeChild(mark);
                    }
                }, 2400);
                options.onDone?.();
                return;
            } catch {
                range.startContainer.parentElement?.scrollIntoView({
                    behavior: tryNo === 0 ? 'smooth' : 'auto',
                    block: 'center',
                });
                options.onDone?.();
                return;
            }
        }

        if (tryNo < maxAttempts) {
            window.requestAnimationFrame(() => attempt(tryNo + 1));
            return;
        }

        if (options.onMiss?.() === true) {
            options.onDone?.(true);
            return;
        }

        slot.scrollIntoView({ behavior: 'auto', block: 'center' });
        options.onDone?.(false);
    };

    attempt(0);
}

export function scrollToFaqKeyword(text, matchIndex = 0) {
    const keyword = normalizeLinkText(text);
    if (!keyword) {
        return false;
    }

    const items = [...document.querySelectorAll('.seo-faq-item')];
    let seen = 0;

    for (const item of items) {
        if (!faqItemMatchesKeyword(item, keyword)) {
            continue;
        }

        if (seen === matchIndex) {
            window.dispatchEvent(new CustomEvent('seo-editor-faq-navigate'));
            item.scrollIntoView({ behavior: 'smooth', block: 'center' });
            item.classList.add('seo-faq-scroll-highlight');

            window.setTimeout(() => {
                item.classList.remove('seo-faq-scroll-highlight');
            }, 2400);

            return true;
        }

        seen += 1;
    }

    return false;
}
