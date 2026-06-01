import { stripEditorTransientMarkup } from './articleEditorTransientMarkup';

const HEADING_TAG_RE = /^h([1-6])$/i;
const BLOCK_WRAPPER_TAGS = new Set(['p', 'div']);

export const FAQ_SHORTCODE_PLACEHOLDER = '[omi_faq]';

export const FAQ_SHORTCODE_HTML = `<p class="omi-faq-placeholder" data-omi-faq="1">${FAQ_SHORTCODE_PLACEHOLDER}</p>`;

export function isFaqPlaceholderHtml(html) {
    return /omi-faq-placeholder|\[omi_faq\]/i.test(html || '');
}

/**
 * Một block chỉ gồm đúng một thẻ heading (thường gặp khi tách block từ WP).
 *
 * @returns {number|null} level 1–6 hoặc null
 */
export function standaloneHeadingLevel(html) {
    const trimmed = (html || '').trim();
    if (!trimmed) return null;

    const doc = new DOMParser().parseFromString(trimmed, 'text/html');
    const nodes = Array.from(doc.body.childNodes).filter((node) => {
        if (node.nodeType === 3) return Boolean(node.textContent?.trim());
        return node.nodeType === 1;
    });

    if (nodes.length !== 1 || nodes[0].nodeType !== 1) return null;

    const tag = nodes[0].tagName.toLowerCase();
    const match = tag.match(HEADING_TAG_RE);
    return match ? Number(match[1]) : null;
}

function standaloneBodyNodes(html) {
    const doc = new DOMParser().parseFromString((html || '').trim(), 'text/html');

    return Array.from(doc.body.childNodes).filter((node) => {
        if (node.nodeType === 3) {
            return Boolean(node.textContent?.trim());
        }

        return node.nodeType === 1;
    });
}

function extractStandaloneInnerHtml(exportedHtml) {
    const trimmed = (exportedHtml || '').trim();
    if (!trimmed) {
        return '';
    }

    const nodes = standaloneBodyNodes(trimmed);
    if (nodes.length === 1 && nodes[0].nodeType === 1) {
        const element = nodes[0];
        const tag = element.tagName.toLowerCase();
        if (BLOCK_WRAPPER_TAGS.has(tag)) {
            return element.innerHTML.trim();
        }

        return element.outerHTML.trim();
    }

    const doc = new DOMParser().parseFromString(trimmed, 'text/html');

    return doc.body.innerHTML.trim();
}

function rebuildStandaloneHeadingHtml(originalHtml, innerHtml, level) {
    const doc = new DOMParser().parseFromString((originalHtml || '').trim(), 'text/html');
    const originalHeading = doc.body.querySelector('h1,h2,h3,h4,h5,h6');
    const className = originalHeading?.getAttribute('class');
    const classAttr = className ? ` class="${className}"` : '';

    return `<h${level}${classAttr}>${innerHtml}</h${level}>`;
}

/**
 * TipTap đôi khi đổi `<h2>…</h2>` thành `<p><strong>…</strong></p>` khi block chỉ có heading.
 * Giữ cấp heading và nội dung người dùng vừa sửa thay vì revert về HTML gốc.
 */
export function coalesceTiptapExportHtml(originalHtml, exportedHtml) {
    if (isFaqPlaceholderHtml(originalHtml)) {
        const exportText = exportedHtml || '';
        if (!/\[omi_faq\]/i.test(exportText)) {
            return originalHtml;
        }
        if (!/omi-faq-placeholder/i.test(exportText)) {
            return FAQ_SHORTCODE_HTML;
        }

        return stripEditorTransientMarkup(exportedHtml);
    }

    const originalLevel = standaloneHeadingLevel(originalHtml);
    if (originalLevel === null) {
        return stripEditorTransientMarkup(exportedHtml);
    }

    const exportedLevel = standaloneHeadingLevel(exportedHtml);
    if (exportedLevel !== null) {
        return stripEditorTransientMarkup(exportedHtml);
    }

    const trimmedExport = (exportedHtml || '').trim();
    if (!trimmedExport) {
        return originalHtml;
    }

    const innerHtml = extractStandaloneInnerHtml(trimmedExport);
    if (!innerHtml) {
        return originalHtml;
    }

    return stripEditorTransientMarkup(rebuildStandaloneHeadingHtml(originalHtml, innerHtml, originalLevel));
}

/**
 * Bóc các wrapper div/section (vd. `.term-description`) để mỗi đoạn thành một block riêng.
 */
export function flattenHtmlBodyNodes(parent) {
    const result = [];

    Array.from(parent.childNodes).forEach((node) => {
        if (node.nodeType === 3) {
            if (node.textContent?.trim()) {
                result.push(node);
            }
            return;
        }

        if (node.nodeType !== 1) {
            return;
        }

        const tag = node.tagName.toLowerCase();
        const unwrap =
            (tag === 'div' || tag === 'section' || tag === 'article') &&
            !node.classList?.contains('wp-block-image') &&
            !node.classList?.contains('wp-caption');

        if (unwrap) {
            result.push(...flattenHtmlBodyNodes(node));
            return;
        }

        result.push(node);
    });

    return result;
}
