import { normalizeLinkText } from './articleLinkScroll';
import { SEO_EDITOR_LINK_CLASS } from './articleEditorTransientMarkup';

/**
 * @typedef {{ node: Text, start: number, endNode: Text, endOffset: number }} PlainTextRange
 */

/**
 * @param {Node} node
 */
function isInsideLink(node) {
    return node.parentElement?.closest('a[href]') != null;
}

/**
 * @param {Node} root
 * @returns {Text[]}
 */
export function collectEligibleTextNodes(root) {
    if (!root) {
        return [];
    }

    const doc = root.ownerDocument ?? document;
    const nodes = [];
    const walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode(textNode) {
            if (!textNode.textContent?.length) {
                return NodeFilter.FILTER_REJECT;
            }
            if (isInsideLink(textNode)) {
                return NodeFilter.FILTER_REJECT;
            }
            return NodeFilter.FILTER_ACCEPT;
        },
    });

    let current = walker.nextNode();
    while (current) {
        nodes.push(/** @type {Text} */ (current));
        current = walker.nextNode();
    }

    return nodes;
}

/**
 * Tìm range text (có thể cắt qua nhiều node — ví dụ qua thẻ b/strong).
 *
 * @param {Node} root
 * @param {string} phrase
 * @param {number} matchIndex
 * @returns {PlainTextRange|null}
 */
export function findPlainTextRangeInRoot(root, phrase, matchIndex = 0) {
    const target = normalizeLinkText(phrase);
    if (!root || !target) {
        return null;
    }

    const textNodes = collectEligibleTextNodes(root);
    if (textNodes.length === 0) {
        return null;
    }

    const parts = textNodes.map((node) => node.textContent ?? '');
    const fullText = parts.join('');
    const lowerFull = fullText.toLowerCase();
    const lowerTarget = target.toLowerCase();

    let searchFrom = 0;
    let found = 0;
    let startIdx = -1;

    while (searchFrom <= lowerFull.length) {
        const idx = lowerFull.indexOf(lowerTarget, searchFrom);
        if (idx === -1) {
            break;
        }
        if (found === matchIndex) {
            startIdx = idx;
            break;
        }
        found += 1;
        searchFrom = idx + lowerTarget.length;
    }

    if (startIdx < 0) {
        return null;
    }

    const endIdx = startIdx + target.length;

    return mapOffsetsToTextRange(textNodes, parts, startIdx, endIdx);
}

/**
 * @param {Text[]} textNodes
 * @param {string[]} parts
 * @param {number} startIdx
 * @param {number} endIdx
 * @returns {PlainTextRange|null}
 */
function mapOffsetsToTextRange(textNodes, parts, startIdx, endIdx) {
    let pos = 0;
    /** @type {Text|null} */
    let startNode = null;
    let startOffset = 0;
    /** @type {Text|null} */
    let endNode = null;
    let endOffset = 0;

    for (let i = 0; i < textNodes.length; i += 1) {
        const node = textNodes[i];
        const part = parts[i];
        const partStart = pos;
        const partEnd = pos + part.length;

        if (startNode === null && startIdx >= partStart && startIdx < partEnd) {
            startNode = node;
            startOffset = startIdx - partStart;
        }

        if (endIdx > partStart && endIdx <= partEnd) {
            endNode = node;
            endOffset = endIdx - partStart;
            break;
        }

        pos = partEnd;
    }

    if (!startNode || !endNode) {
        return null;
    }

    return { node: startNode, start: startOffset, endNode, endOffset };
}

/**
 * @param {Document} doc
 * @param {PlainTextRange} match
 * @param {string} href
 */
export function wrapTextRangeWithLink(doc, match, href) {
    const url = String(href ?? '').trim();
    if (!url || !match?.node || !match.endNode) {
        return false;
    }

    const range = doc.createRange();
    range.setStart(match.node, match.start);
    range.setEnd(match.endNode, match.endOffset);

    const anchor = doc.createElement('a');
    anchor.href = url;
    anchor.className = SEO_EDITOR_LINK_CLASS;

    try {
        const fragment = range.extractContents();
        unwrapFormattingInFragment(fragment);
        anchor.appendChild(fragment);
        range.insertNode(anchor);

        return true;
    } catch {
        return false;
    }
}

/**
 * @param {DocumentFragment} fragment
 */
function unwrapFormattingInFragment(fragment) {
    const inlineTags = new Set(['B', 'STRONG', 'I', 'EM', 'U', 'S', 'STRIKE']);

    const walk = (parent) => {
        const children = [...parent.childNodes];
        for (const child of children) {
            if (child.nodeType === Node.ELEMENT_NODE) {
                const el = /** @type {Element} */ (child);
                if (inlineTags.has(el.tagName)) {
                    while (el.firstChild) {
                        parent.insertBefore(el.firstChild, el);
                    }
                    parent.removeChild(el);
                    walk(parent);
                } else {
                    walk(el);
                }
            }
        }
    };

    walk(fragment);
}
