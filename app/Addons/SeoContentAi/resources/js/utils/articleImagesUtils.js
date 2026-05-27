import {
    extractImagesFromHtml,
    isAiPlaceholderLoadingSrc,
    parseImageFromBlockContent,
    renderImageFigure,
} from './blockImageUtils';

export function slugFromUrl(src) {
    if (!src) return '';
    try {
        const path = new URL(src, window.location.origin).pathname;
        const base = path.split('/').pop() || '';
        const dot = base.lastIndexOf('.');
        return dot > 0 ? base.slice(0, dot) : base;
    } catch {
        const parts = String(src).split('/');
        const base = parts.pop() || '';
        const dot = base.lastIndexOf('.');
        return dot > 0 ? base.slice(0, dot) : base;
    }
}

export function replaceUrlSlug(src, newSlug) {
    if (!src || !newSlug) return src;
    try {
        const url = new URL(src, window.location.origin);
        const parts = url.pathname.split('/');
        const filename = parts.pop() || '';
        const dot = filename.lastIndexOf('.');
        const ext = dot > 0 ? filename.slice(dot) : '';
        parts.push(`${newSlug}${ext}`);
        url.pathname = parts.join('/');
        return url.href;
    } catch {
        const parts = String(src).split('/');
        const filename = parts.pop() || '';
        const dot = filename.lastIndexOf('.');
        const ext = dot > 0 ? filename.slice(dot) : '';
        parts.push(`${newSlug}${ext}`);
        return parts.join('/');
    }
}

export function parseWpAttachmentId(imgEl) {
    if (!imgEl) return null;
    const className = imgEl.getAttribute?.('class') || '';
    const m = className.match(/\bwp-image-(\d+)\b/);
    if (m) return Number(m[1]);
    const dataId = Number(imgEl.getAttribute?.('data-id'));
    return dataId > 0 ? dataId : null;
}

function normalizeSrcKey(src) {
    try {
        return new URL(src, window.location.origin).pathname.toLowerCase();
    } catch {
        return String(src).toLowerCase();
    }
}

function isLocalSeoMediaSrc(src) {
    const value = String(src || '').trim().toLowerCase();
    return value.includes('/storage/uploads/seo_media/');
}

function normalizePreferredWpUrl(meta, image) {
    const wpUrl = String(
        meta?.wp_url || meta?.wordpress_url || meta?.source_url || meta?.wpUrl || '',
    ).trim();

    if (wpUrl && !isLocalSeoMediaSrc(wpUrl)) {
        return wpUrl;
    }

    if (image?.src && !isLocalSeoMediaSrc(image.src)) {
        return String(image.src).trim();
    }

    return '';
}

function normalizeLocalSrc(meta, image) {
    const localSrc = String(meta?.local_src || meta?.localSrc || '').trim();
    if (localSrc) {
        return localSrc;
    }

    if (image?.src && isLocalSeoMediaSrc(image.src)) {
        return String(image.src).trim();
    }

    return '';
}

/**
 * Gắn wp_attachment_id / slug từ meta đồng bộ vào block ảnh.
 */
export function enrichBlocksWithPostImages(blocks, postImages) {
    if (!postImages?.length) return blocks;

    const byWpId = new Map();
    const bySrc = new Map();
    postImages.forEach((row) => {
        const wpId = row.wp_attachment_id ?? row.wp_id;
        if (wpId) byWpId.set(Number(wpId), row);
        if (row.src) bySrc.set(normalizeSrcKey(row.src), row);
    });

    return blocks.map((block) => {
        if (block.type !== 'image') return block;

        let image = block.image ?? parseImageFromBlockContent(block.content);
        if (!image) return block;

        const wpId = image.wpAttachmentId;
        const srcKey = image.src ? normalizeSrcKey(image.src) : '';
        const meta = (wpId && byWpId.get(Number(wpId))) || (srcKey && bySrc.get(srcKey));

        if (!meta) {
            return {
                ...block,
                image: {
                    ...image,
                    slug: image.slug || slugFromUrl(image.src),
                },
            };
        }

        const merged = {
            ...image,
            wpAttachmentId: image.wpAttachmentId ?? meta.wp_attachment_id ?? null,
            wpSrc: normalizePreferredWpUrl(meta, image),
            localSrc: normalizeLocalSrc(meta, image),
            src:
                (image.wpAttachmentId ?? meta.wp_attachment_id ?? null) &&
                normalizePreferredWpUrl(meta, image)
                    ? normalizePreferredWpUrl(meta, image)
                    : image.src,
            slug: image.slug || meta.slug || slugFromUrl(image.src),
            alt: image.alt || meta.alt || '',
            title: image.title || meta.title || '',
            caption: image.caption || meta.caption || '',
        };

        return {
            ...block,
            image: merged,
            content: renderImageFigure(merged),
        };
    });
}

/**
 * Danh sách ảnh dùng cho tab / meta (một dòng mỗi block ảnh).
 */
export function collectImagesFromBlocks(blocks) {
    const list = [];

    blocks.forEach((block) => {
        if (block.type !== 'image') return;

        const image = block.image ?? parseImageFromBlockContent(block.content);
        if (!image?.src) return;
        if (image.isProcessing || isAiPlaceholderLoadingSrc(image.src)) {
            return;
        }

        list.push({
            key: image.id || block.id,
            blockId: block.id,
            wpAttachmentId: image.wpAttachmentId ?? null,
            seoMediaId: image.seoMediaId ?? null,
            src: image.src,
            wpSrc: image.wpSrc ?? (!isLocalSeoMediaSrc(image.src) ? image.src : ''),
            localSrc: image.localSrc ?? (isLocalSeoMediaSrc(image.src) ? image.src : ''),
            slug: image.slug || slugFromUrl(image.src),
            alt: image.alt ?? '',
            title: image.title ?? '',
            caption: image.caption ?? '',
            align: image.align ?? 'none',
            excludeQuickFix: Boolean(image.excludeQuickFix),
        });
    });

    return list;
}

/**
 * @param {Array} blocks
 * @param {string} blockId
 * @param {object} patch - slug, alt, title, caption, src
 */
/**
 * Chuyển từ khóa thành slug file ảnh (kebab-case, không dấu).
 */
export function keywordToImageSlugBase(keyword) {
    if (!keyword?.trim()) {
        return '';
    }

    let text = keyword.trim().toLowerCase();

    try {
        text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    } catch {
        // ignore
    }

    return text
        .replace(/đ/g, 'd')
        .replace(/[^a-z0-9\s-]/g, ' ')
        .trim()
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

/** Slug file ảnh: {kebab-tu-khoa}-{1..n} */
export function imageSlugFromKeyword(keyword, index) {
    const base = keywordToImageSlugBase(keyword);
    if (!base || index < 1) {
        return base;
    }

    return `${base}-${index}`;
}

/**
 * Số thứ tự slug (1..n) theo vị trí ảnh trong bài (thứ tự block), không đếm lại khi có Except.
 */
export function quickFixSlugIndexForBlock(images, blockId) {
    const targetId = String(blockId ?? '').trim();
    if (!targetId) {
        return 0;
    }

    const rowIndex = images.findIndex((row) => row.blockId === targetId);

    return rowIndex >= 0 ? rowIndex + 1 : 0;
}

export function appendCacheBustToSrc(src, cacheKey = Date.now()) {
    if (!src) {
        return src;
    }

    try {
        const url = new URL(src, window.location.origin);
        url.searchParams.set('seo_reload', String(cacheKey));

        return url.href;
    } catch {
        const sep = src.includes('?') ? '&' : '?';

        return `${src}${sep}seo_reload=${cacheKey}`;
    }
}

/**
 * Alt/title ngay; slug WP đợi sau khi rename xong (tránh ảnh lỗi 404).
 *
 * @returns {{ blocks: Array, applied: number, renameQueue: Array }}
 */
export function applyQuickFixMetaToBlocks(blocks, keyword) {
    const phrase = String(keyword ?? '').trim();
    const base = keywordToImageSlugBase(phrase);
    if (!base || !phrase) {
        return { blocks, applied: 0, renameQueue: [] };
    }

    const images = collectImagesFromBlocks(blocks);
    const eligible = images.filter((row) => !row.excludeQuickFix);
    if (!eligible.length) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    let result = blocks;
    const renameQueue = [];
    const localRenameQueue = [];

    eligible.forEach((row) => {
        const slugIndex = quickFixSlugIndexForBlock(images, row.blockId);
        if (slugIndex < 1) {
            return;
        }

        const slug = imageSlugFromKeyword(phrase, slugIndex);
        const patch = { alt: phrase, title: phrase };
        const oldSlug = (row.slug || '').trim();

        if (row.wpAttachmentId) {
            if (slug !== (row.slug || '').trim()) {
                renameQueue.push({
                    attachment_id: row.wpAttachmentId,
                    new_slug: slug,
                    old_url: row.src,
                    old_slug: oldSlug,
                });
            }
        } else {
            // Laravel media: chỉ đổi slug sau khi server rename xong (không đổi src trước).
            if (slug !== oldSlug) {
                localRenameQueue.push({
                    seo_media_id: row.seoMediaId ?? null,
                    src: row.src,
                    block_id: row.blockId,
                    new_slug: slug,
                    old_slug: oldSlug,
                });
            }
        }

        result = applyImagePatchToBlocks(result, row.blockId, patch);
    });

    return { blocks: result, applied: eligible.length, renameQueue, localRenameQueue };
}

/**
 * Fix nhanh một ảnh theo blockId (giữ thứ tự slug -N như fix tất cả).
 *
 * @returns {{ blocks: Array, applied: number, renameQueue: Array, localRenameQueue: Array }}
 */
export function applyQuickFixMetaToBlock(blocks, keyword, blockId) {
    const phrase = String(keyword ?? '').trim();
    const base = keywordToImageSlugBase(phrase);
    const targetId = String(blockId ?? '').trim();

    if (!base || !phrase || !targetId) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const images = collectImagesFromBlocks(blocks);
    const rowIndex = images.findIndex((row) => row.blockId === targetId);
    if (rowIndex < 0) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const row = images[rowIndex];
    if (row.excludeQuickFix) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const slugIndex = quickFixSlugIndexForBlock(images, row.blockId);
    if (slugIndex < 1) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const slug = imageSlugFromKeyword(phrase, slugIndex);
    const patch = { alt: phrase, title: phrase };
    const oldSlug = (row.slug || '').trim();
    const renameQueue = [];
    const localRenameQueue = [];

    if (row.wpAttachmentId) {
        if (slug !== oldSlug) {
            renameQueue.push({
                attachment_id: row.wpAttachmentId,
                new_slug: slug,
                old_url: row.src,
                old_slug: oldSlug,
            });
        }
    } else if (slug !== oldSlug) {
        localRenameQueue.push({
            seo_media_id: row.seoMediaId ?? null,
            src: row.src,
            block_id: row.blockId,
            new_slug: slug,
            old_slug: oldSlug,
        });
    }

    const nextBlocks = applyImagePatchToBlocks(blocks, row.blockId, patch);

    return {
        blocks: nextBlocks,
        applied: 1,
        renameQueue,
        localRenameQueue,
    };
}

/** @deprecated dùng applyQuickFixMetaToBlocks + finalizeBlocksAfterWpRename */
export function applyQuickFixImagesToBlocks(blocks, keyword) {
    const { blocks: next, renameQueue } = applyQuickFixMetaToBlocks(blocks, keyword);

    return { blocks: next, applied: collectImagesFromBlocks(blocks).length, renameQueue };
}

/**
 * Sau khi WordPress đổi tên xong: cập nhật URL + slug + cache-bust thumbnail.
 */
export function finalizeBlocksAfterWpRename(blocks, renamedItems, keyword = '') {
    let result = applyRenameResultsToBlocks(blocks, renamedItems);

    const phrase = String(keyword ?? '').trim();
    const base = phrase ? keywordToImageSlugBase(phrase) : '';

    if (base) {
        const images = collectImagesFromBlocks(result);
        images.forEach((row, index) => {
            if (row.wpAttachmentId) {
                return;
            }

            const slug = imageSlugFromKeyword(phrase, index + 1);
            result = applyImagePatchToBlocks(result, row.blockId, { slug });
        });
    }

    return bustAllImageBlockSrc(result);
}

export function bustAllImageBlockSrc(blocks, cacheKey = Date.now()) {
    return blocks.map((block) => {
        if (block.type !== 'image') {
            return block;
        }

        const image = block.image ?? parseImageFromBlockContent(block.content);
        if (!image?.src) {
            return block;
        }

        const nextImage = {
            ...image,
            src: appendCacheBustToSrc(image.src, cacheKey),
        };

        return {
            ...block,
            image: nextImage,
            content: renderImageFigure(nextImage),
        };
    });
}

/**
 * Cập nhật src ảnh sau khi WordPress trả URL mới (nếu khác dự đoán).
 */
export function applyRenameResultsToBlocks(blocks, renamedItems) {
    if (!renamedItems?.length) {
        return blocks;
    }

    const byAttachment = new Map();
    renamedItems.forEach((row) => {
        const id = row.attachment_id ?? row.attachmentId;
        const newUrl = row.new_url ?? row.newUrl;
        if (id && newUrl) {
            byAttachment.set(Number(id), newUrl);
        }
    });

    if (byAttachment.size === 0) {
        return blocks;
    }

    return blocks.map((block) => {
        if (block.type !== 'image') {
            return block;
        }

        const image = block.image ?? parseImageFromBlockContent(block.content);
        if (!image?.wpAttachmentId) {
            return block;
        }

        const newUrl = byAttachment.get(Number(image.wpAttachmentId));
        if (!newUrl || newUrl === image.src) {
            return block;
        }

        const nextImage = {
            ...image,
            src: newUrl,
            slug: slugFromUrl(newUrl),
        };

        return {
            ...block,
            image: nextImage,
            content: renderImageFigure(nextImage),
        };
    });
}

export function applyImagePatchToBlocks(blocks, blockId, patch) {
    return blocks.map((block) => {
        if (block.id !== blockId || block.type !== 'image') return block;

        const image = block.image ?? parseImageFromBlockContent(block.content);
        if (!image) return block;

        let nextSrc = image.src;
        if (patch.slug !== undefined && patch.slug !== image.slug) {
            nextSrc = replaceUrlSlug(image.src, patch.slug);
        } else if (patch.src !== undefined) {
            nextSrc = patch.src;
        }

        const nextImage = {
            ...image,
            ...patch,
            src: nextSrc,
            slug: patch.slug !== undefined ? patch.slug : image.slug || slugFromUrl(nextSrc),
        };

        return {
            ...block,
            image: nextImage,
            content: renderImageFigure(nextImage),
        };
    });
}

/**
 * JSON index gửi lên server (wp_post_images).
 */
export function buildPostImagesIndex(blocks) {
    return collectImagesFromBlocks(blocks).map((row) => ({
        key: row.key,
        block_id: row.blockId,
        wp_attachment_id: row.wpAttachmentId,
        src: row.src,
        slug: row.slug,
        alt: row.alt,
        title: row.title,
        caption: row.caption,
        align: row.align,
    }));
}

export { extractImagesFromHtml };
