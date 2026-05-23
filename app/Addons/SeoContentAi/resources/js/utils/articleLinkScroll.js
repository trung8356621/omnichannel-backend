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

/**
 * @param {HTMLAnchorElement|Element} anchor
 * @param {string} targetText
 */
export function anchorTextMatches(anchor, targetText) {
    const keyword = normalizeLinkText(targetText);
    if (!keyword) {
        return false;
    }

    const anchorText = normalizeLinkText(anchor.textContent);
    return anchorText === keyword;
}

/**
 * Đếm thẻ &lt;a&gt; có đúng anchor text (từ khóa), không lọc theo href.
 *
 * @param {string} html
 * @param {string} text
 */
export function countMatchingAnchorsInHtml(html, text) {
    const targetText = normalizeLinkText(text);
    if (!targetText || !html) {
        return 0;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');

    return [...doc.querySelectorAll('a[href]')].filter((anchor) =>
        anchorTextMatches(anchor, targetText),
    ).length;
}

/**
 * Tìm anchor trong block theo từ khóa (thứ tự xuất hiện trong DOM).
 *
 * @param {HTMLElement} root
 * @param {string} text
 * @param {number} matchIndex
 */
export function findAnchorElementInRoot(root, text, matchIndex = 0) {
    if (!root) {
        return null;
    }

    const targetText = normalizeLinkText(text);
    if (!targetText) {
        return null;
    }

    const anchors = [...root.querySelectorAll('a[href]')];
    let seen = 0;

    for (const anchor of anchors) {
        if (!anchorTextMatches(anchor, targetText)) {
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
 * @param {{ onDone?: () => void }} [options]
 */
export function scrollToKeywordAnchor(blockId, text, matchIndex = 0, options = {}) {
    const maxAttempts = 12;

    const attempt = (tryNo) => {
        const slot = document.querySelector(`[data-seo-block-id="${blockId}"]`);
        if (!slot) {
            if (tryNo < maxAttempts) {
                window.requestAnimationFrame(() => attempt(tryNo + 1));
            }
            return;
        }

        const anchorEl = findAnchorElementInRoot(slot, text, matchIndex);

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
