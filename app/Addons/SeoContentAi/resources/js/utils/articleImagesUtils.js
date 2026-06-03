import {
    extractImagesFromHtml,
    isAiPlaceholderLoadingSrc,
    parseImageFromBlockContent,
    renderImageFigure,
} from './blockImageUtils';
import { resolveArticleImageSrc, resolveFullWordPressImageUrl, isLocalSeoMediaSrc } from './wordpressImageUrl';

export function slugFromUrl(src) {
    if (!src) return '';

    const full = resolveFullWordPressImageUrl(String(src));
    try {
        const path = new URL(full, window.location.origin).pathname;
        const base = path.split('/').pop() || '';
        const dot = base.lastIndexOf('.');

        return dot > 0 ? base.slice(0, dot) : base;
    } catch {
        const parts = full.split('/');
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

function normalizePreferredWpUrl(meta, image) {
    const wpUrl = String(
        meta?.wp_url || meta?.wordpress_url || meta?.source_url || meta?.wpUrl || '',
    ).trim();

    if (wpUrl && !isLocalSeoMediaSrc(wpUrl)) {
        return resolveFullWordPressImageUrl(wpUrl);
    }

    if (image?.src && !isLocalSeoMediaSrc(image.src)) {
        return resolveFullWordPressImageUrl(String(image.src).trim());
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
            const src = isLocalSeoMediaSrc(image.src)
                ? image.src
                : resolveFullWordPressImageUrl(image.src);

            return {
                ...block,
                image: {
                    ...image,
                    src,
                    slug: image.slug || slugFromUrl(src),
                },
                content: renderImageFigure({
                    ...image,
                    src,
                    slug: image.slug || slugFromUrl(src),
                }),
            };
        }

        const preferredWp = normalizePreferredWpUrl(meta, image);
        const parsedSrc = String(image.src ?? '').trim();
        const blockHasWordPressSrc = parsedSrc !== '' && !isLocalSeoMediaSrc(parsedSrc);
        const merged = {
            ...image,
            wpAttachmentId: image.wpAttachmentId ?? meta.wp_attachment_id ?? null,
            wpSrc: blockHasWordPressSrc ? resolveFullWordPressImageUrl(parsedSrc) : preferredWp,
            localSrc: blockHasWordPressSrc
                ? String(meta.local_src ?? meta.localSrc ?? image.localSrc ?? '')
                : normalizeLocalSrc(meta, image),
            src: blockHasWordPressSrc
                ? resolveFullWordPressImageUrl(parsedSrc)
                : resolveArticleImageSrc({
                      ...meta,
                      src: preferredWp || image.src,
                  }),
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
            src: isLocalSeoMediaSrc(image.src) ? image.src : resolveFullWordPressImageUrl(image.src),
            wpSrc: image.wpSrc
                ? resolveFullWordPressImageUrl(image.wpSrc)
                : (!isLocalSeoMediaSrc(image.src) ? resolveFullWordPressImageUrl(image.src) : ''),
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
 * URL gốc WordPress dùng khi đổi slug attachment (không dùng URL staging Laravel).
 */
export function resolveWpRenameOldUrl(row) {
    const wpSrc = String(row?.wpSrc || row?.wp_url || '').trim();
    if (wpSrc && !isLocalSeoMediaSrc(wpSrc)) {
        return resolveFullWordPressImageUrl(wpSrc);
    }

    const src = String(row?.src || '').trim();
    if (src && !isLocalSeoMediaSrc(src)) {
        return resolveFullWordPressImageUrl(src);
    }

    return wpSrc || src;
}

function resolveLocalRenameSrc(row) {
    const localSrc = String(row?.localSrc || row?.local_src || '').trim();
    if (localSrc && isLocalSeoMediaSrc(localSrc)) {
        return localSrc;
    }

    const src = String(row?.src || '').trim();
    if (src && isLocalSeoMediaSrc(src)) {
        return src;
    }

    return localSrc || '';
}

/**
 * Phân biệt ID WordPress vs seo_media (album local hay gán nhầm wp_attachment_id).
 */
export function resolveImageRefIds(row) {
    const localSrc = resolveLocalRenameSrc(row);
    const wpSrcRaw = String(row?.wpSrc || row?.wp_url || '').trim();
    const src = String(row?.src || '').trim();
    const displaySrc = (isLocalSeoMediaSrc(src) ? src : '') || localSrc || src || wpSrcRaw;
    const isLocal = isLocalSeoMediaSrc(displaySrc) || localSrc !== '';
    const hasWpUrl = wpSrcRaw !== '' && !isLocalSeoMediaSrc(wpSrcRaw);

    let wpAttachmentId = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
    let seoMediaId = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);

    if (isLocal) {
        if (seoMediaId <= 0 && wpAttachmentId > 0 && !hasWpUrl) {
            seoMediaId = wpAttachmentId;
            wpAttachmentId = 0;
        } else if (seoMediaId > 0 && wpAttachmentId > 0 && seoMediaId === wpAttachmentId) {
            seoMediaId = 0;
        }
    } else if (seoMediaId > 0 && wpAttachmentId <= 0) {
        wpAttachmentId = 0;
    }

    return {
        wpAttachmentId: wpAttachmentId > 0 ? wpAttachmentId : 0,
        seoMediaId: seoMediaId > 0 ? seoMediaId : 0,
        isLocal,
        src: displaySrc,
        localSrc,
        wpSrc: hasWpUrl ? resolveFullWordPressImageUrl(wpSrcRaw) : '',
    };
}

/**
 * @returns {{ patch: { alt: string, title: string }, wpRename: object|null, localRename: object|null }}
 */
export function computeQuickFixSupplementalOutcome(row, keyword) {
    const slugOutcome = computeQuickFixSlugSupplementalOutcome(row, keyword);
    const altTitleOutcome = computeQuickFixAltTitleSupplementalOutcome(row, keyword);

    return {
        patch: { ...slugOutcome.patch, ...altTitleOutcome.patch },
        wpRename: slugOutcome.wpRename,
        localRename: slugOutcome.localRename,
        wpMeta: altTitleOutcome.wpMeta,
    };
}

/**
 * @returns {{ patch: { slug?: string }, wpRename: object|null, localRename: object|null }}
 */
export function computeQuickFixSlugSupplementalOutcome(row, keyword) {
    const phrase = String(keyword ?? '').trim();
    const fromRow = Number(row?.quickFixIndex ?? 0);
    const slugIndex = fromRow > 0 ? fromRow : 0;
    const suggestedSlug = slugIndex > 0 ? imageSlugFromKeyword(phrase, slugIndex) : '';
    const { wpAttachmentId, seoMediaId, isLocal } = resolveImageRefIds(row);
    const localFileSrc = resolveLocalRenameSrc(row);
    const oldSlug = String(row?.slug ?? '').trim();
    const oldUrlForWp = resolveWpRenameOldUrl(row);

    if (!suggestedSlug || suggestedSlug === oldSlug) {
        return { patch: {}, wpRename: null, localRename: null };
    }

    let wpRename = null;
    let localRename = null;

    if (wpAttachmentId > 0) {
        wpRename = {
            attachment_id: wpAttachmentId,
            new_slug: suggestedSlug,
            old_url: oldUrlForWp,
            old_slug: oldSlug,
        };
    }

    if (seoMediaId > 0) {
        localRename = {
            seo_media_id: seoMediaId,
            src: localFileSrc || row?.src,
            new_slug: suggestedSlug,
            old_slug: oldSlug,
        };
    } else if (isLocal && localFileSrc) {
        localRename = {
            seo_media_id: null,
            src: localFileSrc,
            new_slug: suggestedSlug,
            old_slug: oldSlug,
        };
    }

    if (!wpRename && !localRename) {
        return {
            patch: { slug: suggestedSlug },
            wpRename: null,
            localRename: null,
        };
    }

    return { patch: {}, wpRename, localRename };
}

/**
 * @returns {{ patch: { alt: string, title: string }, wpMeta: object|null }}
 */
export function computeQuickFixAltTitleSupplementalOutcome(row, keyword) {
    const phrase = String(keyword ?? '').trim();
    const patch = phrase ? { alt: phrase, title: phrase } : {};
    const { wpAttachmentId } = resolveImageRefIds(row);

    if (!phrase || wpAttachmentId <= 0) {
        return { patch, wpMeta: null };
    }

    return {
        patch,
        wpMeta: {
            attachment_id: wpAttachmentId,
            alt_text: phrase,
            title: phrase,
        },
    };
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

function buildSlugRenameQueuesForRow(row, images, phrase, slugIndexByBlockId, renameQueue, localRenameQueue, localRenameSeen) {
    const mappedIndex =
        slugIndexByBlockId && slugIndexByBlockId[row.blockId] != null
            ? Number(slugIndexByBlockId[row.blockId])
            : 0;
    const slugIndex = mappedIndex > 0 ? mappedIndex : quickFixSlugIndexForBlock(images, row.blockId);
    if (slugIndex < 1) {
        return null;
    }

    const slug = imageSlugFromKeyword(phrase, slugIndex);
    const oldSlug = (row.slug || '').trim();
    const { wpAttachmentId, seoMediaId } = resolveImageRefIds(row);
    const localFileSrc = resolveLocalRenameSrc(row);
    const oldUrlForWp = resolveWpRenameOldUrl(row);

    if (wpAttachmentId > 0 && slug !== oldSlug) {
        renameQueue.push({
            attachment_id: wpAttachmentId,
            new_slug: slug,
            old_url: oldUrlForWp,
            old_slug: oldSlug,
        });
    }

    if (slug !== oldSlug && (seoMediaId > 0 || localFileSrc)) {
        const localKey =
            seoMediaId > 0 ? `id:${seoMediaId}` : `src:${localFileSrc || String(row.src || '').trim()}`;
        if (!localRenameSeen.has(localKey)) {
            localRenameSeen.add(localKey);
            localRenameQueue.push({
                seo_media_id: seoMediaId > 0 ? seoMediaId : row.seoMediaId ?? null,
                src: localFileSrc || row.src,
                block_id: row.blockId,
                new_slug: slug,
                old_slug: oldSlug,
            });
        }
    }

    return slug;
}

/**
 * Chỉ đổi slug; slug WP đợi sau khi rename xong (tránh ảnh lỗi 404).
 *
 * @returns {{ blocks: Array, applied: number, renameQueue: Array, localRenameQueue: Array }}
 */
export function applyQuickFixSlugToBlocks(blocks, keyword, slugIndexByBlockId = null) {
    const phrase = String(keyword ?? '').trim();
    const base = keywordToImageSlugBase(phrase);
    if (!base || !phrase) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const images = collectImagesFromBlocks(blocks);
    const eligible = images.filter((row) => !row.excludeQuickFix);
    if (!eligible.length) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    let result = blocks;
    const renameQueue = [];
    const localRenameQueue = [];
    const localRenameSeen = new Set();

    eligible.forEach((row) => {
        const slug = buildSlugRenameQueuesForRow(
            row,
            images,
            phrase,
            slugIndexByBlockId,
            renameQueue,
            localRenameQueue,
            localRenameSeen,
        );
        if (!slug) {
            return;
        }

        const oldSlug = (row.slug || '').trim();
        const { wpAttachmentId } = resolveImageRefIds(row);
        if (slug !== oldSlug && wpAttachmentId <= 0 && !localRenameQueue.some((item) => item.block_id === row.blockId)) {
            result = applyImagePatchToBlocks(result, row.blockId, { slug });
        }
    });

    return { blocks: result, applied: eligible.length, renameQueue, localRenameQueue };
}

/**
 * Alt/title ngay; đẩy meta lên WordPress attachment nếu có wpAttachmentId.
 *
 * @returns {{ blocks: Array, applied: number, wpMetaQueue: Array }}
 */
export function applyQuickFixAltTitleToBlocks(blocks, keyword) {
    const phrase = String(keyword ?? '').trim();
    if (!phrase) {
        return { blocks, applied: 0, wpMetaQueue: [] };
    }

    const images = collectImagesFromBlocks(blocks);
    const eligible = images.filter((row) => !row.excludeQuickFix);
    if (!eligible.length) {
        return { blocks, applied: 0, wpMetaQueue: [] };
    }

    let result = blocks;
    const wpMetaQueue = [];
    const wpMetaSeen = new Set();
    const patch = { alt: phrase, title: phrase };

    eligible.forEach((row) => {
        result = applyImagePatchToBlocks(result, row.blockId, patch);
        const { wpAttachmentId } = resolveImageRefIds(row);
        if (wpAttachmentId > 0 && !wpMetaSeen.has(wpAttachmentId)) {
            wpMetaSeen.add(wpAttachmentId);
            wpMetaQueue.push({
                attachment_id: wpAttachmentId,
                alt_text: phrase,
                title: phrase,
            });
        }
    });

    return { blocks: result, applied: eligible.length, wpMetaQueue };
}

/**
 * Alt/title ngay; slug WP đợi sau khi rename xong (tránh ảnh lỗi 404).
 *
 * @returns {{ blocks: Array, applied: number, renameQueue: Array, localRenameQueue: Array, wpMetaQueue: Array }}
 */
export function applyQuickFixMetaToBlocks(blocks, keyword, slugIndexByBlockId = null) {
    const slugResult = applyQuickFixSlugToBlocks(blocks, keyword, slugIndexByBlockId);
    const altTitleResult = applyQuickFixAltTitleToBlocks(slugResult.blocks, keyword);

    return {
        blocks: altTitleResult.blocks,
        applied: Math.max(slugResult.applied, altTitleResult.applied),
        renameQueue: slugResult.renameQueue,
        localRenameQueue: slugResult.localRenameQueue,
        wpMetaQueue: altTitleResult.wpMetaQueue,
    };
}

/**
 * Fix slug một ảnh theo blockId (giữ thứ tự slug -N như fix tất cả).
 *
 * @returns {{ blocks: Array, applied: number, renameQueue: Array, localRenameQueue: Array }}
 */
export function applyQuickFixSlugToBlock(blocks, keyword, blockId) {
    const phrase = String(keyword ?? '').trim();
    const base = keywordToImageSlugBase(phrase);
    const targetId = String(blockId ?? '').trim();

    if (!base || !phrase || !targetId) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const images = collectImagesFromBlocks(blocks);
    const row = images.find((entry) => entry.blockId === targetId);
    if (!row || row.excludeQuickFix) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const renameQueue = [];
    const localRenameQueue = [];
    const slug = buildSlugRenameQueuesForRow(
        row,
        images,
        phrase,
        null,
        renameQueue,
        localRenameQueue,
        new Set(),
    );
    if (!slug) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const oldSlug = (row.slug || '').trim();
    const { wpAttachmentId } = resolveImageRefIds(row);
    let nextBlocks = blocks;
    if (slug !== oldSlug && wpAttachmentId <= 0 && localRenameQueue.length === 0) {
        nextBlocks = applyImagePatchToBlocks(blocks, row.blockId, { slug });
    }

    return {
        blocks: nextBlocks,
        applied: 1,
        renameQueue,
        localRenameQueue,
    };
}

/**
 * Fix alt/title một ảnh theo blockId.
 *
 * @returns {{ blocks: Array, applied: number, wpMetaQueue: Array }}
 */
export function applyQuickFixAltTitleToBlock(blocks, keyword, blockId) {
    const phrase = String(keyword ?? '').trim();
    const targetId = String(blockId ?? '').trim();

    if (!phrase || !targetId) {
        return { blocks, applied: 0, wpMetaQueue: [] };
    }

    const images = collectImagesFromBlocks(blocks);
    const row = images.find((entry) => entry.blockId === targetId);
    if (!row || row.excludeQuickFix) {
        return { blocks, applied: 0, wpMetaQueue: [] };
    }

    const patch = { alt: phrase, title: phrase };
    const nextBlocks = applyImagePatchToBlocks(blocks, row.blockId, patch);
    const { wpAttachmentId } = resolveImageRefIds(row);
    const wpMetaQueue =
        wpAttachmentId > 0
            ? [{ attachment_id: wpAttachmentId, alt_text: phrase, title: phrase }]
            : [];

    return {
        blocks: nextBlocks,
        applied: 1,
        wpMetaQueue,
    };
}

/**
 * Fix nhanh một ảnh theo blockId (giữ thứ tự slug -N như fix tất cả).
 *
 * @returns {{ blocks: Array, applied: number, renameQueue: Array, localRenameQueue: Array, wpMetaQueue: Array }}
 */
export function applyQuickFixMetaToBlock(blocks, keyword, blockId) {
    const slugResult = applyQuickFixSlugToBlock(blocks, keyword, blockId);
    const altTitleResult = applyQuickFixAltTitleToBlock(slugResult.blocks, keyword, blockId);

    return {
        blocks: altTitleResult.blocks,
        applied: Math.max(slugResult.applied, altTitleResult.applied),
        renameQueue: slugResult.renameQueue,
        localRenameQueue: slugResult.localRenameQueue,
        wpMetaQueue: altTitleResult.wpMetaQueue,
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
