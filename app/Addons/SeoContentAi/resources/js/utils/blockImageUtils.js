import { detectWordPressImageSize } from './wordpressImageSize';
import { isLocalSeoMediaSrc, resolveFullWordPressImageUrl } from './wordpressImageUrl';

const ALIGN_CLASSES = {
    none: '',
    left: 'alignleft',
    center: 'aligncenter',
    right: 'alignright',
    full: 'alignfull',
};

export const IMAGE_ALIGN_OPTIONS = [
    { id: 'none', labelKey: 'image_align_default' },
    { id: 'left', labelKey: 'toolbar_align_left' },
    { id: 'center', labelKey: 'toolbar_align_center' },
    { id: 'right', labelKey: 'toolbar_align_right' },
    { id: 'full', labelKey: 'image_align_full_width' },
];

function alignFromElement(el) {
    if (!el) return 'none';
    const cls = typeof el.className === 'string' ? el.className : '';
    if (cls.includes('alignfull')) return 'full';
    if (cls.includes('alignright')) return 'right';
    if (cls.includes('aligncenter')) return 'center';
    if (cls.includes('alignleft')) return 'left';
    return 'none';
}

function figureClassForAlign(align) {
    return ALIGN_CLASSES[align] || '';
}

function parseWpAttachmentIdFromImg(img) {
    if (!img) return null;
    const cls = img.getAttribute('class') ?? '';
    const m = cls.match(/\bwp-image-(\d+)\b/);
    if (m) return Number(m[1]);
    const dataId = Number(img.getAttribute('data-id'));
    return dataId > 0 ? dataId : null;
}

function parseSeoMediaIdFromImg(img) {
    if (!img) return null;
    const id = Number(img.getAttribute('data-seo-media-id'));
    return id > 0 ? id : null;
}

export function isAiPlaceholderLoadingSrc(src) {
    if (!src) {
        return false;
    }

    return String(src).includes('placeholder-loading');
}

function slugFromSrc(src) {
    if (!src) return '';
    try {
        const path = new URL(src, window.location.origin).pathname;
        const base = path.split('/').pop() || '';
        const dot = base.lastIndexOf('.');
        return dot > 0 ? base.slice(0, dot) : base;
    } catch {
        return '';
    }
}

function parseImageFromFigure(fig, id) {
    const img = fig.querySelector('img');
    if (!img?.getAttribute('src')) return null;

    const widthAttr = img.getAttribute('width');
    const heightAttr = img.getAttribute('height');
    const src = img.getAttribute('src');

    const isLocal = isLocalSeoMediaSrc(src ?? '');

    return {
        id,
        src,
        wpSrc: isLocal ? '' : resolveFullWordPressImageUrl(src ?? ''),
        size: isLocal ? 'full' : detectWordPressImageSize(src ?? ''),
        slug: slugFromSrc(src),
        alt: img.getAttribute('alt') ?? '',
        title: img.getAttribute('title') ?? '',
        caption: fig.querySelector('figcaption')?.textContent?.trim() ?? '',
        align: alignFromElement(fig) || alignFromElement(img),
        width: widthAttr ? Number(widthAttr) : null,
        height: heightAttr ? Number(heightAttr) : null,
        wpImageClass: img.getAttribute('class') ?? '',
        wpAttachmentId: parseWpAttachmentIdFromImg(img),
        seoMediaId: parseSeoMediaIdFromImg(img),
        isProcessing: fig.hasAttribute('data-ai-processing'),
    };
}

function parseImageFromImg(img, id) {
    if (!img.getAttribute('src')) return null;

    const parent = img.closest('figure');
    const widthAttr = img.getAttribute('width');
    const heightAttr = img.getAttribute('height');

    const src = img.getAttribute('src');

    const isLocal = isLocalSeoMediaSrc(src ?? '');

    return {
        id,
        src,
        wpSrc: isLocal ? '' : resolveFullWordPressImageUrl(src ?? ''),
        size: isLocal ? 'full' : detectWordPressImageSize(src ?? ''),
        slug: slugFromSrc(src),
        alt: img.getAttribute('alt') ?? '',
        title: img.getAttribute('title') ?? '',
        caption: parent?.querySelector('figcaption')?.textContent?.trim() ?? '',
        align: parent ? alignFromElement(parent) : alignFromElement(img),
        width: widthAttr ? Number(widthAttr) : null,
        height: heightAttr ? Number(heightAttr) : null,
        wpImageClass: img.getAttribute('class') ?? '',
        wpAttachmentId: parseWpAttachmentIdFromImg(img),
        seoMediaId: parseSeoMediaIdFromImg(img),
        isProcessing:
            img.hasAttribute('data-ai-processing') ||
            Boolean(img.closest('[data-ai-processing]')),
    };
}

export function isWordPressImageElement(el) {
    if (!el || el.nodeType !== 1) return false;
    const tag = el.tagName.toLowerCase();

    if (tag === 'img') return true;
    if (tag === 'figure' && el.querySelector('img')) return true;
    if (el.classList.contains('wp-block-image')) return true;
    if (el.classList.contains('wp-caption') && el.querySelector('img')) return true;

    return false;
}

/**
 * Trích xuất danh sách ảnh từ HTML (figure.wp-caption, .wp-block-image, img…).
 */
export function extractImagesFromHtml(html) {
    if (!html?.trim()) return [];

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const images = [];
    const consumed = new Set();
    let index = 0;

    const registerFigure = (fig) => {
        if (!fig || consumed.has(fig)) return;
        const id = `img_${index++}`;
        const data = parseImageFromFigure(fig, id);
        if (!data) return;
        images.push(data);
        consumed.add(fig);
        fig.querySelectorAll('img').forEach((img) => consumed.add(img));
    };

    doc.body.querySelectorAll('.wp-block-image').forEach((wrap) => {
        const fig = wrap.querySelector('figure');
        if (fig) {
            registerFigure(fig);
            return;
        }
        const img = wrap.querySelector('img');
        if (img && !consumed.has(img)) {
            const id = `img_${index++}`;
            const data = parseImageFromImg(img, id);
            if (data) {
                images.push({ ...data, align: alignFromElement(wrap) || data.align });
                consumed.add(img);
            }
        }
    });

    doc.body.querySelectorAll('figure').forEach((fig) => {
        if (fig.querySelector('img')) registerFigure(fig);
    });

    doc.body.querySelectorAll('img').forEach((img) => {
        if (consumed.has(img)) return;
        const id = `img_${index++}`;
        const data = parseImageFromImg(img, id);
        if (data) images.push(data);
    });

    return images;
}

/**
 * HTML chỉ còn phần chữ sau khi tách ảnh.
 */
export function stripImagesFromHtml(html) {
    if (!html?.trim()) return '';

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    Array.from(doc.body.childNodes).forEach((node) => {
        if (node.nodeType === 1 && isWordPressImageElement(node)) {
            node.remove();
        }
    });

    doc.body.querySelectorAll('figure, img, .wp-block-image').forEach((el) => el.remove());

    return doc.body.innerHTML.trim();
}

/** @deprecated Ảnh đã tách thành block riêng — giữ cho tương thích. */
export function splitBlockHtml(html) {
    const images = extractImagesFromHtml(html);
    return {
        textHtml: stripImagesFromHtml(html),
        images,
    };
}

export function parseImageFromBlockContent(html) {
    const images = extractImagesFromHtml(html);
    if (images.length === 1) return images[0];

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const fig = doc.body.querySelector('figure');
    if (fig) {
        return parseImageFromFigure(fig, `img_${Date.now()}`);
    }
    const img = doc.body.querySelector('img');
    if (img) {
        return parseImageFromImg(img, `img_${Date.now()}`);
    }

    return null;
}

export function renderImageFigure(image) {
    const alignClass = figureClassForAlign(image.align);
    const isProcessing = Boolean(
        image.isProcessing || isAiPlaceholderLoadingSrc(image.src),
    );
    const captionText = String(image.caption ?? '').trim();
    const hasCaption = captionText !== '';
    const figureClasses = ['wp-caption', alignClass, isProcessing ? 'seo-ai-media-loading' : '']
        .filter(Boolean)
        .join(' ');
    const style =
        image.width && !Number.isNaN(image.width)
            ? ` style="width: ${Math.round(image.width)}px"`
            : '';

    let imgClass = image.wpImageClass ?? '';
    if (!hasCaption && alignClass && !new RegExp(`\\b${alignClass}\\b`).test(imgClass)) {
        imgClass = imgClass ? `${imgClass} ${alignClass}` : alignClass;
    }
    if (image.wpAttachmentId && !/\bwp-image-\d+\b/.test(imgClass)) {
        const wpClass = `wp-image-${image.wpAttachmentId}`;
        imgClass = imgClass ? `${imgClass} ${wpClass}` : wpClass;
    }

    const imgAttrs = [
        `src="${escapeAttr(image.src)}"`,
        `alt="${escapeAttr(image.alt)}"`,
        image.title ? `title="${escapeAttr(image.title)}"` : '',
        image.width ? `width="${Math.round(image.width)}"` : '',
        image.height ? `height="${Math.round(image.height)}"` : '',
        imgClass ? `class="${escapeAttr(imgClass)}"` : '',
        image.wpAttachmentId ? `data-id="${Math.round(image.wpAttachmentId)}"` : '',
        image.seoMediaId ? `data-seo-media-id="${Math.round(image.seoMediaId)}"` : '',
        isProcessing ? 'data-ai-processing="1"' : '',
        'draggable="false"',
    ]
        .filter(Boolean)
        .join(' ');

    const caption = hasCaption
        ? `<figcaption class="wp-caption-text">${escapeHtml(captionText)}</figcaption>`
        : '';
    const processingLabel = isProcessing
        ? '<p class="seo-ai-media-loading__label">AI đang tạo ảnh…</p>'
        : '';

    if (!hasCaption && !isProcessing) {
        return `<img ${imgAttrs} />`;
    }

    return `<figure class="${figureClasses}" data-node="article-image"${
        isProcessing ? ' data-ai-processing="1"' : ''
    }${style}><img ${imgAttrs} />${processingLabel}${caption}</figure>`;
}

function escapeAttr(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;');
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Ghép lại HTML block text (chỉ marker — ảnh đã là block riêng).
 */
export function mergeBlockHtml(textHtml, images) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(textHtml || '<p></p>', 'text/html');
    const imageMap = Object.fromEntries((images ?? []).map((img) => [img.id, img]));

    doc.body.querySelectorAll('[data-seo-image-marker]').forEach((marker) => {
        marker.remove();
        const id = marker.getAttribute('data-seo-image-marker');
        if (imageMap[id]) {
            const wrap = doc.createElement('div');
            wrap.innerHTML = renderImageFigure(imageMap[id]);
            marker.replaceWith(wrap.firstElementChild);
        }
    });

    const parts = Array.from(doc.body.childNodes)
        .map((node) => {
            if (node.nodeType === 3 && !node.textContent?.trim()) return '';
            const temp = doc.createElement('div');
            temp.appendChild(node.cloneNode(true));
            return temp.innerHTML.trim();
        })
        .filter(Boolean);

    return parts.join('\n\n');
}

export function htmlToPlainText(html) {
    if (!html?.trim()) return '';
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
}

const newBlockId = (prefix) => `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`;

/**
 * Tách ảnh nhúng trong block text thành block ảnh riêng (WordPress-style).
 */
export function normalizeBlocks(blocks) {
    const result = [];

    blocks.forEach((block) => {
        if (block.type === 'image' || block.isWp) {
            if (block.type === 'image' && !block.image) {
                const image = parseImageFromBlockContent(block.content);
                result.push({
                    ...block,
                    type: 'image',
                    image: image ?? undefined,
                    content: image ? renderImageFigure(image) : block.content,
                });
                return;
            }
            result.push(block);
            return;
        }

        const images = extractImagesFromHtml(block.content);
        const textHtml = stripImagesFromHtml(block.content);

        if (textHtml) {
            result.push({
                ...block,
                type: 'text',
                content: textHtml,
            });
        }

        images.forEach((image) => {
            result.push({
                id: newBlockId('image'),
                type: 'image',
                isWp: false,
                prefix: '',
                suffix: '',
                content: renderImageFigure(image),
                image,
            });
        });

        if (!textHtml && images.length === 0) {
            result.push({ ...block, type: 'text' });
        }
    });

    return result.length ? result : blocks;
}
