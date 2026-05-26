import { stripEditorTransientMarkup } from './articleEditorTransientMarkup';

const HEADING_TAG_RE = /^h([1-6])$/i;

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

/**
 * TipTap đôi khi đổi `<h2>…</h2>` thành `<p><strong>…</strong></p>` khi block chỉ có heading.
 * Giữ HTML gốc nếu export làm mất cấp heading.
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
        return exportedHtml;
    }

    const exportedLevel = standaloneHeadingLevel(exportedHtml);
    if (exportedLevel !== null) {
        return stripEditorTransientMarkup(exportedHtml);
    }

    const trimmedExport = (exportedHtml || '').trim();
    if (!trimmedExport) {
        return originalHtml;
    }

    return stripEditorTransientMarkup(originalHtml);
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
