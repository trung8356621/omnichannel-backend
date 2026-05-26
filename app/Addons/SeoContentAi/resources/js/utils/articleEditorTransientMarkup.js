/**
 * Markup chỉ dùng trong editor (đánh dấu link khi cuộn sidebar) — không đẩy lên WordPress.
 */

/** Đánh dấu tạm khi bấm từ khóa ở sidebar (thẻ mark). */
export const SEO_EDITOR_LINK_MARK_CLASS = 'seo-editor-link-mark';

/** Legacy — vẫn lọc khi export. */
export const SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS = 'seo-link-scroll-highlight';

/** Class trên thẻ &lt;a&gt; thật trong editor. */
export const SEO_EDITOR_LINK_CLASS = 'seo-editor-link';

const TRANSIENT_CLASSES = [SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS];

function stripTransientClassesFromElement(el) {
    if (!el?.classList) {
        return;
    }

    for (const cls of TRANSIENT_CLASSES) {
        el.classList.remove(cls);
    }

    if (el.classList.length === 0) {
        el.removeAttribute('class');
    }
}

function unwrapElement(el) {
    const parent = el.parentNode;
    if (!parent) {
        return;
    }

    while (el.firstChild) {
        parent.insertBefore(el.firstChild, el);
    }

    parent.removeChild(el);
}

/**
 * Gỡ đánh dấu link / highlight cuộn trước khi lưu hoặc đồng bộ WordPress.
 *
 * @param {string} html
 * @returns {string}
 */
export function stripEditorTransientMarkup(html) {
    const raw = String(html ?? '');
    if (!raw.trim()) {
        return raw;
    }

    const doc = new DOMParser().parseFromString(raw, 'text/html');
    const body = doc.body;

    body.querySelectorAll('mark').forEach((mark) => {
        const classes = [...mark.classList];
        const isTransient = classes.some((c) => TRANSIENT_CLASSES.includes(c));
        if (isTransient) {
            unwrapElement(mark);
        }
    });

    body.querySelectorAll('*').forEach((el) => {
        const isAnchor = el.tagName === 'A';
        const hasTransient = TRANSIENT_CLASSES.some((c) => el.classList.contains(c));

        if (!hasTransient) {
            return;
        }

        if (isAnchor) {
            stripTransientClassesFromElement(el);
            return;
        }

        if (el.tagName === 'MARK' || el.tagName === 'SPAN') {
            unwrapElement(el);
            return;
        }

        stripTransientClassesFromElement(el);
    });

    return body.innerHTML;
}
