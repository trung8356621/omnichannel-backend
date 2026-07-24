import {
    extractImagesFromHtml,
    isAiPlaceholderLoadingSrc,
    parseImageFromBlockContent,
    renderImageFigure,
    withDefaultImageInsertAlign,
} from './blockImageUtils';
import {
    isLocalSeoMediaSrc,
    resolveArticleImageSrc,
    resolveFullWordPressImageUrl,
    toPreviewImageUrl,
} from './wordpressImageUrl';
import { loadProductAlbum, saveProductAlbum } from './articleProductAlbumStorage';

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

export function isWordPressOriginalPickerTab(pickerTab) {
    return String(pickerTab ?? '').trim() === 'original';
}

/** Ảnh chọn từ tab Gốc (WP) trong media picker → tự Except khỏi quick-fix hàng loạt. */
export function shouldAutoExcludeQuickFixFromWpPicker(pickerTab, wpAttachmentId = 0) {
    return isWordPressOriginalPickerTab(pickerTab) && Number(wpAttachmentId ?? 0) > 0;
}

export function withWpPickerExcludeQuickFix(image, pickerTab, wpAttachmentId = 0) {
    if (!shouldAutoExcludeQuickFixFromWpPicker(pickerTab, wpAttachmentId)) {
        return image;
    }

    return {
        ...image,
        excludeQuickFix: true,
    };
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

export function hasArticleImageBlockId(row) {
    return String(row?.blockId ?? row?.block_id ?? '').trim() !== '';
}

/**
 * Src không phải URL ảnh thật (placeholder / data-uri) — không dùng để match xóa.
 */
export function isNonPersistentArticleImageSrc(src) {
    const value = String(src ?? '').trim();
    if (!value) {
        return true;
    }

    if (value.startsWith('data:')) {
        return true;
    }

    if (value.includes('placeholder-loading')) {
        return true;
    }

    return false;
}

function articleImageIdentityOverlap(row, image) {
    const rowWp = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
    const imageWp = Number(image?.wpAttachmentId ?? image?.wp_attachment_id ?? 0);
    if (rowWp > 0 && imageWp > 0) {
        return rowWp === imageWp;
    }

    const rowSeo = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
    const imageSeo = Number(image?.seoMediaId ?? image?.seo_media_id ?? 0);
    if (rowSeo > 0 && imageSeo > 0) {
        return rowSeo === imageSeo;
    }

    const rowKeys = [
        normalizeSrcKey(String(row?.src ?? '').trim()),
        normalizeSrcKey(String(row?.wpSrc ?? row?.wp_url ?? '').trim()),
        normalizeSrcKey(String(row?.localSrc ?? row?.local_src ?? '').trim()),
    ].filter(Boolean);

    const imageKeys = [
        normalizeSrcKey(String(image?.src ?? '').trim()),
        normalizeSrcKey(String(image?.wpSrc ?? image?.wp_url ?? '').trim()),
        normalizeSrcKey(String(image?.localSrc ?? image?.local_src ?? '').trim()),
    ].filter(Boolean);

    return rowKeys.some((key) => imageKeys.includes(key));
}

/**
 * Chỉ cho xóa khi còn match được block editor hoặc supplemental thật.
 * Ảnh 404 stale (blockId lệch / src không còn) → null → disable nút Xóa.
 *
 * @returns {{ kind: 'block', blockId: string } | { kind: 'supplemental', src: string, origin: string } | null}
 */
export function resolveArticleImageRemoveTarget(row, blocks = [], supplementalRows = []) {
    if (!row) {
        return null;
    }

    const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();
    if (blockId) {
        const block = (Array.isArray(blocks) ? blocks : []).find(
            (item) => String(item?.id ?? '').trim() === blockId && item?.type === 'image',
        );
        if (!block) {
            return null;
        }

        const image = block.image ?? parseImageFromBlockContent(block.content);
        if (!image?.src || isNonPersistentArticleImageSrc(image.src)) {
            return null;
        }

        if (!articleImageIdentityOverlap(row, image)) {
            return null;
        }

        return { kind: 'block', blockId };
    }

    const src = String(row?.src ?? '').trim();
    if (isNonPersistentArticleImageSrc(src)) {
        return null;
    }

    const origin = String(row?.origin ?? '').trim();
    const rowWp = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
    const rowSeo = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
    const srcKey = normalizeSrcKey(src);

    const matched = (Array.isArray(supplementalRows) ? supplementalRows : []).find((item) => {
        if (String(item?.blockId ?? item?.block_id ?? '').trim() !== '') {
            return false;
        }

        const itemWp = Number(item?.wpAttachmentId ?? item?.wp_attachment_id ?? 0);
        if (rowWp > 0 && itemWp > 0 && rowWp === itemWp) {
            return true;
        }

        const itemSeo = Number(item?.seoMediaId ?? item?.seo_media_id ?? 0);
        if (rowSeo > 0 && itemSeo > 0 && rowSeo === itemSeo) {
            return true;
        }

        const itemSrcKey = normalizeSrcKey(String(item?.src ?? '').trim());
        if (srcKey && itemSrcKey && srcKey === itemSrcKey) {
            if (origin && String(item?.origin ?? '').trim() && String(item.origin).trim() !== origin) {
                return false;
            }

            return true;
        }

        return false;
    });

    if (!matched) {
        return null;
    }

    return {
        kind: 'supplemental',
        src: String(matched.src ?? src).trim(),
        origin: String(matched.origin ?? origin).trim(),
    };
}

export function articleImageIdentityKey(row) {
    const wpId = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
    const seoId = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
    const srcKey = normalizeSrcKey(String(row?.src ?? '').trim());

    if (wpId > 0) {
        return `wp:${wpId}`;
    }

    if (seoId > 0) {
        return `seo:${seoId}`;
    }

    if (srcKey) {
        return `src:${srcKey}`;
    }

    return '';
}

export function articleImageRowsShareIdentity(left, right) {
    if (!left || !right) {
        return false;
    }

    const leftKey = articleImageIdentityKey(left);
    const rightKey = articleImageIdentityKey(right);
    if (leftKey && rightKey && leftKey === rightKey) {
        return true;
    }

    return articleImageIdentityOverlap(left, right);
}

/** Ẩn ảnh đại diện/album trùng identity với ảnh đã có trong block (tránh 404 sau Fix slug all). */
export function filterSupplementalDuplicatesOfBlockRows(rows) {
    const blockKeys = new Set();

    rows.forEach((row) => {
        if (!hasArticleImageBlockId(row)) {
            return;
        }

        const key = articleImageIdentityKey(row);
        if (key) {
            blockKeys.add(key);
        }
    });

    return rows.filter((row) => {
        if (hasArticleImageBlockId(row)) {
            return true;
        }

        const key = articleImageIdentityKey(row);

        return key === '' || !blockKeys.has(key);
    });
}

/** Slug -1, -2… chỉ theo thứ tự ảnh trong bài (block), bỏ qua ảnh Except. */
export function assignInArticleQuickFixIndices(rows) {
    let ordinal = 0;

    return rows.map((row) => {
        if (!hasArticleImageBlockId(row)) {
            return { ...row, quickFixIndex: 0 };
        }

        if (row?.excludeQuickFix) {
            return { ...row, quickFixIndex: 0 };
        }

        ordinal += 1;

        return { ...row, quickFixIndex: ordinal };
    });
}

export function buildQuickFixIndexByBlockId(rows) {
    const indexByBlockId = {};

    rows.forEach((row) => {
        const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();
        const quickFixIndex = Number(row?.quickFixIndex ?? 0);

        if (blockId && quickFixIndex > 0) {
            indexByBlockId[blockId] = quickFixIndex;
        }
    });

    return indexByBlockId;
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
    const byLocalSrc = new Map();
    const bySeoId = new Map();
    postImages.forEach((row) => {
        const wpId = row.wp_attachment_id ?? row.wp_id;
        if (wpId) byWpId.set(Number(wpId), row);
        if (row.src) bySrc.set(normalizeSrcKey(row.src), row);
        const localSrc = String(row.local_src ?? row.localSrc ?? '').trim();
        if (localSrc) byLocalSrc.set(normalizeSrcKey(localSrc), row);
        const seoId = Number(row.seo_media_id ?? row.seoMediaId ?? 0);
        if (seoId > 0) bySeoId.set(seoId, row);
    });

    return blocks.map((block) => {
        if (block.type !== 'image') return block;

        let image = block.image ?? parseImageFromBlockContent(block.content);
        if (!image) return block;

        const wpId = image.wpAttachmentId;
        const seoId = Number(image.seoMediaId ?? 0);
        const srcKey = image.src ? normalizeSrcKey(image.src) : '';
        const localKey = image.localSrc ? normalizeSrcKey(image.localSrc) : '';
        const meta =
            (wpId && byWpId.get(Number(wpId)))
            || (seoId > 0 && bySeoId.get(seoId))
            || (srcKey && bySrc.get(srcKey))
            || (srcKey && byLocalSrc.get(srcKey))
            || (localKey && byLocalSrc.get(localKey))
            || null;

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
            seoMediaId:
                image.seoMediaId ??
                image.seo_media_id ??
                meta.seo_media_id ??
                meta.seoMediaId ??
                null,
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
            excludeQuickFix: Boolean(
                image.excludeQuickFix ??
                    meta.exclude_quick_fix ??
                    meta.excludeQuickFix,
            ),
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
            // src local mới thắng localSrc cũ (tránh 404 sau Fix slug khi localSrc stale).
            localSrc: isLocalSeoMediaSrc(image.src)
                ? image.src
                : (image.localSrc ?? ''),
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
 * WP attachment ID dùng khi cập nhật alt/title lên WordPress (không lọc bỏ như rename slug).
 */
export function resolveWpAttachmentIdForMetaUpdate(row) {
    if (!row) {
        return 0;
    }

    const rawWp = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
    const rawSeo = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
    const src = String(row?.src ?? '').trim();
    const wpSrc = String(row?.wpSrc ?? row?.wp_url ?? '').trim();
    const isLocalDisplay = isLocalSeoMediaSrc(src);

    if (!isLocalDisplay) {
        if (rawWp > 0) {
            return rawWp;
        }

        return resolveImageRefIds(row).wpAttachmentId;
    }

    if (rawWp > 0 && rawSeo > 0 && rawWp !== rawSeo) {
        return rawWp;
    }

    if (rawWp > 0 && wpSrc !== '' && !isLocalSeoMediaSrc(wpSrc)) {
        return rawWp;
    }

    return 0;
}

/**
 * @returns {{ seoMediaId: number, wpAttachmentId: number, patch: { alt: string, title: string } }}
 */
export function buildAltTitleMetaUpdatePayload(row, altTitle) {
    const phrase = String(altTitle ?? '').trim();
    const patch = phrase ? { alt: phrase, title: phrase } : { alt: '', title: '' };

    return {
        patch,
        seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0),
        wpAttachmentId: resolveWpAttachmentIdForMetaUpdate(row),
    };
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
        if (seoMediaId > 0 && wpAttachmentId > 0) {
            if (seoMediaId === wpAttachmentId) {
                wpAttachmentId = 0;
            }
        } else if (seoMediaId <= 0 && wpAttachmentId > 0 && !hasWpUrl) {
            // Local file + wp_attachment_id không URL WP: thường ID WP stale, không phải SeoMedia.
            // Không promote → tránh POST /media/{wpId}/rename (ModelNotFound).
            wpAttachmentId = 0;
        } else if (!hasWpUrl && !(seoMediaId > 0 && wpAttachmentId > 0)) {
            wpAttachmentId = 0;
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
 * URL WordPress tin cậy — không phải `/storage/uploads/seo_media/`.
 */
export function hasTrustedWordPressUrl(row) {
    const candidates = [
        String(row?.wpSrc || row?.wp_url || '').trim(),
        String(row?.src || '').trim(),
    ];

    for (const url of candidates) {
        if (url !== '' && !isLocalSeoMediaSrc(url)) {
            return true;
        }
    }

    return false;
}

/**
 * Ảnh sẵn sàng Fix slug trên WordPress: có attachment id + URL WP thật.
 */
export function isImageReadyForWpSlugFix(row) {
    if (!row || row?.excludeQuickFix) {
        return true;
    }

    const { wpAttachmentId } = resolveImageRefIds(row);

    return wpAttachmentId > 0 && hasTrustedWordPressUrl(row);
}

/**
 * True nếu có ít nhất 1 ảnh không phải thuần WordPress (local / Laravel / chưa gắn WP).
 * Dùng để quyết định: phải save article trước khi Fix slug all.
 *
 * @param {Array<Record<string, unknown>>|null|undefined} rows
 * @returns {boolean}
 */
export function hasNonWordPressArticleImages(rows) {
    const list = Array.isArray(rows) ? rows : [];

    return list.some((row) => {
        if (!row) {
            return false;
        }

        const src = String(row.src ?? '').trim();
        const localSrc = String(row.localSrc ?? row.local_src ?? '').trim();
        const { wpAttachmentId, seoMediaId, isLocal } = resolveImageRefIds(row);
        const hasImageSignal = src !== ''
            || localSrc !== ''
            || seoMediaId > 0
            || Number(row.seo_media_id ?? 0) > 0
            || Number(row.wpAttachmentId ?? row.wp_attachment_id ?? 0) > 0;

        if (!hasImageSignal) {
            return false;
        }

        const pureWordPress = wpAttachmentId > 0
            && hasTrustedWordPressUrl(row)
            && !isLocal
            && !isLocalSeoMediaSrc(src)
            && !isLocalSeoMediaSrc(localSrc);

        return !pureWordPress;
    });
}

/**
 * Còn ảnh trong bài (có blockId) chưa gắn URL WP thật → bắt Sync WP trước Fix slug.
 */
export function imagesNeedWpSyncBeforeFixSlug(rows) {
    const list = Array.isArray(rows) ? rows : [];

    return list.some((row) => {
        if (row?.excludeQuickFix) {
            return false;
        }

        const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();
        if (!blockId) {
            return false;
        }

        return !isImageReadyForWpSlugFix(row);
    });
}

/**
 * Đổi slug attachment WordPress khi đã liên kết wp_attachment_id (kể cả src đang hiển thị file Laravel).
 * Chỉ tin ID đã qua resolveImageRefIds — không fallback rawWp (tránh ID stale sau paste/reimport).
 */
export function shouldRenameSlugOnWordPress(row) {
    const { wpAttachmentId } = resolveImageRefIds(row);

    return wpAttachmentId > 0 && hasTrustedWordPressUrl(row);
}

function resolveWordPressAttachmentSlug(row) {
    const wpUrl = resolveWpRenameOldUrl(row);
    if (wpUrl && !isLocalSeoMediaSrc(wpUrl)) {
        const fromUrl = slugFromUrl(wpUrl);
        if (fromUrl) {
            return fromUrl;
        }
    }

    const wpSrc = String(row?.wpSrc || row?.wp_url || '').trim();
    if (wpSrc && !isLocalSeoMediaSrc(wpSrc)) {
        return slugFromUrl(wpSrc);
    }

    return '';
}

function resolveLocalMediaSlug(row) {
    const localSrc = resolveLocalRenameSrc(row);
    const src = String(row?.src || '').trim();
    const localUrl = localSrc || (isLocalSeoMediaSrc(src) ? src : '');
    if (localUrl) {
        const fromUrl = slugFromUrl(localUrl);
        if (fromUrl) {
            return fromUrl;
        }
    }

    return String(row?.slug ?? '').trim();
}

function enrichImageRowFromLookup(row, enrichmentByBlockId) {
    const blockId = String(row?.blockId ?? '').trim();
    const extra = blockId && enrichmentByBlockId ? enrichmentByBlockId[blockId] : null;
    if (!extra) {
        return row;
    }

    return {
        ...row,
        wpAttachmentId: row.wpAttachmentId ?? extra.wpAttachmentId ?? extra.wp_attachment_id ?? null,
        seoMediaId: row.seoMediaId ?? extra.seoMediaId ?? extra.seo_media_id ?? null,
        wpSrc: String(row.wpSrc || extra.wpSrc || extra.wp_url || '').trim(),
        localSrc: String(row.localSrc || extra.localSrc || extra.local_src || '').trim(),
        slug: row.slug || extra.slug || '',
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
export function computeQuickFixSlugSupplementalOutcome(row, keyword, { wpOnly = true } = {}) {
    const phrase = String(keyword ?? '').trim();
    const fromRow = Number(row?.quickFixIndex ?? 0);
    const slugIndex = fromRow > 0 ? fromRow : 0;
    const suggestedSlug = slugIndex > 0 ? imageSlugFromKeyword(phrase, slugIndex) : '';
    const { wpAttachmentId, seoMediaId, isLocal } = resolveImageRefIds(row);
    const effectiveWpId = wpAttachmentId > 0 ? wpAttachmentId : 0;
    const localFileSrc = resolveLocalRenameSrc(row);
    const rowSlug = String(row?.slug ?? '').trim();
    const wpOldSlug = resolveWordPressAttachmentSlug(row);
    const localOldSlug = resolveLocalMediaSlug(row);
    const oldUrlForWp = resolveWpRenameOldUrl(row);

    if (!suggestedSlug) {
        return { patch: {}, wpRename: null, localRename: null };
    }

    let wpRename = null;
    let localRename = null;

    if (shouldRenameSlugOnWordPress(row) && suggestedSlug !== wpOldSlug) {
        wpRename = {
            attachment_id: effectiveWpId,
            new_slug: suggestedSlug,
            old_url: oldUrlForWp,
            old_slug: wpOldSlug || rowSlug,
            block_id: String(row?.blockId ?? row?.block_id ?? '').trim(),
        };
    }

    if (!wpOnly) {
        if (seoMediaId > 0 && suggestedSlug !== localOldSlug) {
            localRename = {
                seo_media_id: seoMediaId,
                src: localFileSrc || row?.src,
                new_slug: suggestedSlug,
                old_slug: localOldSlug || rowSlug,
            };
        } else if (isLocal && localFileSrc && suggestedSlug !== localOldSlug) {
            localRename = {
                seo_media_id: null,
                src: localFileSrc,
                new_slug: suggestedSlug,
                old_slug: localOldSlug || rowSlug,
            };
        }
    }

    if (!wpRename && !localRename) {
        if (suggestedSlug === rowSlug || (wpOnly && suggestedSlug === wpOldSlug)) {
            return { patch: {}, wpRename: null, localRename: null };
        }

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
    const { patch, wpAttachmentId } = buildAltTitleMetaUpdatePayload(row, phrase);

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

    let ordinal = 0;
    for (const row of images) {
        if (row?.excludeQuickFix) {
            continue;
        }

        ordinal += 1;
        if (String(row?.blockId ?? '').trim() === targetId) {
            return ordinal;
        }
    }

    return 0;
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

function buildSlugRenameQueuesForRow(
    row,
    images,
    phrase,
    slugIndexByBlockId,
    renameQueue,
    localRenameQueue,
    localRenameSeen,
    { wpOnly = true, includeWordPressRenames = true } = {},
) {
    const mappedIndex =
        slugIndexByBlockId && slugIndexByBlockId[row.blockId] != null
            ? Number(slugIndexByBlockId[row.blockId])
            : 0;
    const slugIndex = mappedIndex > 0 ? mappedIndex : quickFixSlugIndexForBlock(images, row.blockId);
    if (slugIndex < 1) {
        return null;
    }

    const slug = imageSlugFromKeyword(phrase, slugIndex);
    const rowSlug = (row.slug || '').trim();
    const wpOldSlug = resolveWordPressAttachmentSlug(row);
    const localOldSlug = resolveLocalMediaSlug(row);
    const { wpAttachmentId, seoMediaId } = resolveImageRefIds(row);
    const effectiveWpId = wpAttachmentId > 0 ? wpAttachmentId : 0;
    const localFileSrc = resolveLocalRenameSrc(row);
    const oldUrlForWp = resolveWpRenameOldUrl(row);

    if (
        includeWordPressRenames
        && shouldRenameSlugOnWordPress(row)
        && slug !== ''
        && slug !== wpOldSlug
    ) {
        renameQueue.push({
            attachment_id: effectiveWpId,
            new_slug: slug,
            old_url: oldUrlForWp,
            old_slug: wpOldSlug || rowSlug,
            block_id: String(row?.blockId ?? '').trim(),
        });
    }

    if (
        !wpOnly
        && slug !== ''
        && slug !== localOldSlug
        && (seoMediaId > 0 || localFileSrc)
    ) {
        const localKey =
            seoMediaId > 0 ? `id:${seoMediaId}` : `src:${localFileSrc || String(row.src || '').trim()}`;
        if (!localRenameSeen.has(localKey)) {
            localRenameSeen.add(localKey);
            localRenameQueue.push({
                seo_media_id: seoMediaId > 0 ? seoMediaId : row.seoMediaId ?? null,
                src: localFileSrc || row.src,
                block_id: row.blockId,
                new_slug: slug,
                old_slug: localOldSlug || rowSlug,
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
export function applyQuickFixSlugToBlocks(
    blocks,
    keyword,
    slugIndexByBlockId = null,
    enrichmentByBlockId = null,
    { wpOnly = true, includeWordPressRenames = true } = {},
) {
    const phrase = String(keyword ?? '').trim();
    const base = keywordToImageSlugBase(phrase);
    if (!base || !phrase) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const images = collectImagesFromBlocks(blocks).map((row) =>
        enrichmentByBlockId ? enrichImageRowFromLookup(row, enrichmentByBlockId) : row,
    );
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
            { wpOnly, includeWordPressRenames },
        );
        if (!slug) {
            return;
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
        const wpAttachmentId = resolveWpAttachmentIdForMetaUpdate(row);
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
export function applyQuickFixSlugToBlock(
    blocks,
    keyword,
    blockId,
    enrichmentRow = null,
    { wpOnly = true, includeWordPressRenames = true } = {},
) {
    const phrase = String(keyword ?? '').trim();
    const base = keywordToImageSlugBase(phrase);
    const targetId = String(blockId ?? '').trim();

    if (!base || !phrase || !targetId) {
        return { blocks, applied: 0, renameQueue: [], localRenameQueue: [] };
    }

    const enrichmentByBlockId = enrichmentRow ? { [targetId]: enrichmentRow } : null;
    const images = collectImagesFromBlocks(blocks).map((row) =>
        enrichmentByBlockId ? enrichImageRowFromLookup(row, enrichmentByBlockId) : row,
    );
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
        { wpOnly, includeWordPressRenames },
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
    const wpAttachmentId = resolveWpAttachmentIdForMetaUpdate(row);
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
 * Chuẩn hóa kết quả rename WP + local thành một danh sách map theo ID.
 *
 * @param {Array} wpRenamed
 * @param {Array} localResults
 */
/**
 * Gắn block_id / old_url từ request rename vào kết quả WordPress (plugin không echo block_id).
 */
export function enrichWpRenamedWithRequestMeta(renamed = [], requestItems = []) {
    const byAttachmentId = new Map();
    const byOldUrl = new Map();

    (Array.isArray(requestItems) ? requestItems : []).forEach((item) => {
        const attachmentId = Number(item?.attachment_id ?? item?.wp_attachment_id ?? 0);
        const blockId = String(item?.block_id ?? item?.blockId ?? '').trim();
        const oldUrl = String(item?.old_url ?? item?.oldUrl ?? item?.src ?? '').trim();
        const oldUrlKey = oldUrl ? normalizeSrcKey(oldUrl) : '';
        const meta = { block_id: blockId, old_url: oldUrl };

        if (attachmentId > 0) {
            byAttachmentId.set(attachmentId, meta);
        }
        if (oldUrlKey !== '') {
            byOldUrl.set(oldUrlKey, meta);
        }
    });

    return (Array.isArray(renamed) ? renamed : []).map((row) => {
        const attachmentId = Number(row?.attachment_id ?? row?.attachmentId ?? 0);
        const responseOldUrl = String(row?.old_url ?? row?.oldUrl ?? '').trim();
        const responseOldUrlKey = responseOldUrl ? normalizeSrcKey(responseOldUrl) : '';
        const meta =
            (attachmentId > 0 && byAttachmentId.get(attachmentId)) ||
            (responseOldUrlKey !== '' && byOldUrl.get(responseOldUrlKey)) ||
            null;

        if (!meta) {
            return row;
        }

        return {
            ...row,
            block_id: String(row?.block_id ?? row?.blockId ?? meta.block_id ?? '').trim(),
            old_url: responseOldUrl || meta.old_url,
        };
    });
}

export function normalizeRenameEntries(wpRenamed = [], localResults = []) {
    const entries = [];

    (Array.isArray(wpRenamed) ? wpRenamed : []).forEach((row) => {
        const attachmentId = Number(row?.attachment_id ?? row?.attachmentId ?? 0);
        const newUrl = String(row?.new_url ?? row?.newUrl ?? '').trim();
        const success = row?.success;
        if (success === false) {
            return;
        }
        if (attachmentId <= 0 || newUrl === '') {
            return;
        }

        entries.push({
            attachment_id: attachmentId,
            seo_media_id: Number(row?.seo_media_id ?? row?.seoMediaId ?? 0) || null,
            block_id: String(row?.block_id ?? row?.blockId ?? '').trim(),
            old_url: String(row?.old_url ?? row?.oldUrl ?? '').trim(),
            new_url: newUrl,
            new_slug: String(row?.new_slug ?? row?.newSlug ?? slugFromUrl(newUrl)).trim(),
        });
    });

    (Array.isArray(localResults) ? localResults : []).forEach((row) => {
        const data = row?.data ?? {};
        const newUrl = String(data?.url ?? '').trim();
        if (newUrl === '') {
            return;
        }

        const seoId = Number(data?.id ?? row?.seo_media_id ?? 0);
        entries.push({
            attachment_id: null,
            seo_media_id: seoId > 0 ? seoId : null,
            block_id: String(row?.block_id ?? '').trim(),
            old_url: String(row?.src ?? row?.old_url ?? '').trim(),
            new_url: newUrl,
            new_slug: String(data?.slug ?? row?.new_slug ?? slugFromUrl(newUrl)).trim(),
        });
    });

    return entries;
}

export function buildRenameResultMaps(entries) {
    const byAttachmentId = new Map();
    const bySeoMediaId = new Map();
    const byBlockId = new Map();
    const byOldUrl = new Map();

    const pushMapList = (map, key, entry) => {
        if (key === '' || key === 0 || key == null) {
            return;
        }
        const list = map.get(key) ?? [];
        list.push(entry);
        map.set(key, list);
    };

    (Array.isArray(entries) ? entries : []).forEach((entry) => {
        const wpId = Number(entry?.attachment_id ?? 0);
        const seoId = Number(entry?.seo_media_id ?? 0);
        const blockId = String(entry?.block_id ?? '').trim();
        const oldUrl = normalizeSrcKey(String(entry?.old_url ?? ''));

        if (wpId > 0) {
            pushMapList(byAttachmentId, wpId, entry);
        }
        if (seoId > 0) {
            pushMapList(bySeoMediaId, seoId, entry);
        }
        if (blockId !== '') {
            pushMapList(byBlockId, blockId, entry);
        }
        if (oldUrl !== '') {
            pushMapList(byOldUrl, oldUrl, entry);
        }
    });

    return { byAttachmentId, bySeoMediaId, byBlockId, byOldUrl };
}

function pushRenameMapEntries(push, map, key) {
    if (key === '' || key === 0 || key == null || !map?.has(key)) {
        return;
    }

    const value = map.get(key);
    if (Array.isArray(value)) {
        value.forEach(push);

        return;
    }

    push(value);
}

function findAllRenameEntriesForImageRow(row, maps) {
    const resolved = resolveImageRefIds(row);
    const wpId = resolved.wpAttachmentId > 0
        ? resolved.wpAttachmentId
        : Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
    const seoId = resolved.seoMediaId > 0
        ? resolved.seoMediaId
        : Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
    const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();
    const srcCandidates = [
        normalizeSrcKey(String(row?.src ?? '')),
        normalizeSrcKey(String(row?.localSrc ?? row?.local_src ?? '')),
        normalizeSrcKey(String(row?.wpSrc ?? row?.wp_src ?? row?.wp_url ?? '')),
        normalizeSrcKey(String(resolved.localSrc ?? '')),
        normalizeSrcKey(String(resolved.src ?? '')),
    ].filter((key) => key !== '');

    const entries = [];
    const seen = new Set();
    const push = (entry) => {
        if (!entry || typeof entry !== 'object') {
            return;
        }
        const key = [
            Number(entry.attachment_id ?? 0),
            Number(entry.seo_media_id ?? 0),
            String(entry.new_url ?? '').trim(),
        ].join('|');
        if (seen.has(key)) {
            return;
        }
        seen.add(key);
        entries.push(entry);
    };

    pushRenameMapEntries(push, maps.byAttachmentId, wpId);
    pushRenameMapEntries(push, maps.bySeoMediaId, seoId);
    pushRenameMapEntries(push, maps.byBlockId, blockId);
    for (const srcKey of srcCandidates) {
        pushRenameMapEntries(push, maps.byOldUrl, srcKey);
    }

    return entries;
}

/**
 * Sau rename: chỉ giữ supplemental không trùng block; cập nhật URL theo ID (không theo số thứ tự).
 */
export function resetSupplementalImagesAfterSlugRename(supplementalRows, blocks, wpRenamed = [], localResults = []) {
    const maps = buildRenameResultMaps(normalizeRenameEntries(wpRenamed, localResults));
    const blockIdentityKeys = new Set();

    collectImagesFromBlocks(blocks).forEach((row) => {
        const key = articleImageIdentityKey(row);
        if (key) {
            blockIdentityKeys.add(key);
        }
    });

    const cleaned = (Array.isArray(supplementalRows) ? supplementalRows : [])
        .filter((row) => {
            if (hasArticleImageBlockId(row)) {
                return false;
            }

            const key = articleImageIdentityKey(row);

            return key === '' || !blockIdentityKeys.has(key);
        })
        .map((row) => {
            if (row?.excludeQuickFix) {
                return row;
            }

            const entries = findAllRenameEntriesForImageRow(row, maps);
            if (entries.length === 0) {
                return row;
            }

            let nextWpSrc = String(row?.wpSrc ?? row?.wp_url ?? '').trim();
            let nextLocalSrc = String(row?.localSrc ?? row?.local_src ?? '').trim();
            let nextSlug = String(row?.slug ?? '').trim();
            let localUpdated = false;
            let wpUpdated = false;

            entries.forEach((item) => {
                const newUrl = String(item.new_url ?? '').trim();
                if (!newUrl) {
                    return;
                }
                const entrySlug = String(item.new_slug ?? slugFromUrl(newUrl)).trim();
                if (entrySlug) {
                    nextSlug = entrySlug;
                }
                if (isLocalSeoMediaSrc(newUrl)) {
                    nextLocalSrc = newUrl;
                    localUpdated = true;
                } else {
                    nextWpSrc = newUrl;
                    wpUpdated = true;
                }
            });

            if (nextSlug && nextLocalSrc && !localUpdated) {
                const localSlug = slugFromUrl(nextLocalSrc);
                if (localSlug && localSlug !== nextSlug) {
                    nextLocalSrc = '';
                }
            }

            const originalSrc = String(row?.src ?? '').trim();
            const nextSrc = wpUpdated && nextWpSrc
                ? nextWpSrc
                : (localUpdated && nextLocalSrc)
                  ? nextLocalSrc
                  : (nextLocalSrc || nextWpSrc || originalSrc);

            if (!nextSrc) {
                return row;
            }

            const isLocal = isLocalSeoMediaSrc(nextSrc);

            return {
                ...row,
                src: nextSrc,
                slug: nextSlug || row.slug,
                wp_url: nextWpSrc || (isLocal ? String(row?.wp_url ?? '').trim() : nextSrc),
                wpSrc: nextWpSrc || (isLocal ? String(row?.wpSrc ?? '').trim() : nextSrc),
                localSrc: nextLocalSrc,
                local_src: nextLocalSrc,
            };
        });

    return filterSupplementalDuplicatesOfBlockRows(assignInArticleQuickFixIndices(cleaned));
}

/**
 * Sau khi WordPress/local đổi tên xong: map URL mới theo attachment_id / seo_media_id / block_id.
 */
/**
 * Map API fix-slug replacements → local rename results cho finalizeBlocksAfterWpRename.
 *
 * @param {Array<{media_id?: number, old_url?: string, new_url?: string, old_slug?: string, new_slug?: string}>|null|undefined} replacements
 * @param {Array<{seo_media_id?: number|null, src?: string, url?: string, new_slug?: string, old_slug?: string, block_id?: string}>} queue
 * @returns {Array<{seo_media_id: number|null, src: string, new_slug: string, old_slug: string, block_id: string, data: {id: number|null, slug: string, url: string}}>}
 */
export function mapArticleSlugFixReplacementsToLocalResults(replacements, queue = []) {
    const queueList = Array.isArray(queue) ? queue : [];
    const list = Array.isArray(replacements) ? replacements : [];

    return list.map((rep, index) => {
        const mediaId = Number(rep?.media_id ?? 0) || null;
        const oldUrl = String(rep?.old_url ?? '').trim();
        const newUrl = String(rep?.new_url ?? '').trim();
        const newSlug = String(rep?.new_slug ?? '').trim();
        const oldSlug = String(rep?.old_slug ?? '').trim();

        const matched = queueList.find((item) => {
            const itemId = Number(item?.seo_media_id ?? 0);
            if (mediaId > 0 && itemId > 0 && itemId === mediaId) {
                return true;
            }

            const itemSrc = normalizeSrcKey(String(item?.src ?? item?.url ?? '').trim());
            const oldKey = normalizeSrcKey(oldUrl);

            return itemSrc !== '' && oldKey !== '' && itemSrc === oldKey;
        }) ?? queueList[index] ?? null;

        return {
            seo_media_id: mediaId,
            src: oldUrl || String(matched?.src ?? matched?.url ?? '').trim(),
            new_slug: newSlug || String(matched?.new_slug ?? '').trim(),
            old_slug: oldSlug || String(matched?.old_slug ?? '').trim(),
            block_id: String(matched?.block_id ?? matched?.blockId ?? '').trim(),
            data: {
                id: mediaId,
                slug: newSlug || String(matched?.new_slug ?? '').trim(),
                url: newUrl || replaceUrlSlug(
                    oldUrl || String(matched?.src ?? '').trim(),
                    newSlug || String(matched?.new_slug ?? '').trim(),
                ),
            },
        };
    }).filter((row) => String(row?.data?.url ?? '').trim() !== '' || String(row?.new_slug ?? '').trim() !== '');
}

export function finalizeBlocksAfterWpRename(blocks, wpRenamed = [], localResults = [], _keyword = '') {
    const result = applyRenameResultsToBlocks(blocks, wpRenamed, localResults);

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

function localSlugRenameItemKey(item) {
    const id = Number(item?.seo_media_id ?? 0);
    if (id > 0) {
        return `id:${id}`;
    }

    const src = normalizeSrcKey(String(item?.src ?? '').trim());
    if (src) {
        return `src:${src}`;
    }

    const blockId = String(item?.block_id ?? '').trim();
    return blockId ? `block:${blockId}` : '';
}

/**
 * Rename hàng loạt slug ảnh local: pha 1 → slug tạm (tránh đè file), pha 2 → slug đích.
 *
 * @param {Array<{seo_media_id?: number|null, src: string, new_slug: string, old_slug?: string, block_id?: string}>} items
 * @param {{ renameById: Function, renameByUrl: Function }} adapters
 * @returns {Promise<Array<{seo_media_id: number|null, src: string, new_slug: string, old_slug: string, block_id: string, data: {id?: number, slug: string, url: string}}>>}
 */
/**
 * Đảm bảo mỗi item WP rename queue có entry finalize — recovery khi file WP đã đổi tên.
 */
export function ensureWpRenameResultsCoverQueue(requestItems, renamed = []) {
    const list = Array.isArray(renamed) ? [...renamed] : [];
    const covered = new Set(
        list
            .map((row) => Number(row?.attachment_id ?? row?.attachmentId ?? 0))
            .filter((id) => id > 0),
    );

    (Array.isArray(requestItems) ? requestItems : []).forEach((item) => {
        const attachmentId = Number(item?.attachment_id ?? item?.wp_attachment_id ?? 0);
        if (attachmentId <= 0 || covered.has(attachmentId)) {
            return;
        }

        const newSlug = String(item?.new_slug ?? '').trim();
        const oldUrl = String(item?.old_url ?? item?.oldUrl ?? item?.src ?? '').trim();
        if (!newSlug || !oldUrl) {
            return;
        }

        list.push({
            attachment_id: attachmentId,
            block_id: String(item?.block_id ?? item?.blockId ?? '').trim(),
            old_url: oldUrl,
            new_url: replaceUrlSlug(oldUrl, newSlug),
            new_slug: newSlug,
            success: true,
        });
        covered.add(attachmentId);
    });

    return list;
}

/**
 * Đảm bảo mỗi item trong queue local rename có result để finalize block.
 * Trường hợp file đã đổi tên trên disk (re-run sau Fix slug dở) — API fail nhưng editor vẫn cần URL mới.
 */
export function ensureLocalRenameResultsCoverQueue(queue, results = []) {
    const covered = new Set();
    const list = Array.isArray(results) ? [...results] : [];

    list.forEach((row) => {
        const id = Number(row?.data?.id ?? row?.seo_media_id ?? 0);
        if (id > 0) {
            covered.add(`id:${id}`);
        }
        const blockId = String(row?.block_id ?? '').trim();
        if (blockId) {
            covered.add(`block:${blockId}`);
        }
        const srcKey = normalizeSrcKey(String(row?.src ?? ''));
        if (srcKey) {
            covered.add(`src:${srcKey}`);
        }
    });

    (Array.isArray(queue) ? queue : []).forEach((item) => {
        const id = Number(item?.seo_media_id ?? 0);
        const blockId = String(item?.block_id ?? '').trim();
        const src = String(item?.src ?? '').trim();
        const newSlug = String(item?.new_slug ?? '').trim();
        const keys = [
            id > 0 ? `id:${id}` : '',
            blockId ? `block:${blockId}` : '',
            src ? `src:${normalizeSrcKey(src)}` : '',
        ].filter(Boolean);

        if (keys.some((key) => covered.has(key))) {
            return;
        }
        if (!newSlug || !src) {
            return;
        }

        const synthetic = {
            seo_media_id: id > 0 ? id : null,
            src,
            block_id: blockId,
            new_slug: newSlug,
            old_slug: String(item?.old_slug ?? '').trim(),
            data: {
                id: id > 0 ? id : null,
                slug: newSlug,
                url: replaceUrlSlug(src, newSlug),
            },
        };
        list.push(synthetic);
        keys.forEach((key) => covered.add(key));
    });

    return list;
}

/**
 * Key dùng loại ảnh local rename đã fail khỏi queue recovery.
 */
export function localSlugRenameFailureKeys(errors = []) {
    const keys = new Set();

    (Array.isArray(errors) ? errors : []).forEach((entry) => {
        const item = entry?.item ?? entry;
        if (!item) {
            return;
        }

        const id = Number(item?.seo_media_id ?? 0);
        if (id > 0) {
            keys.add(`id:${id}`);
        }

        const blockId = String(item?.block_id ?? '').trim();
        if (blockId) {
            keys.add(`block:${blockId}`);
        }

        const src = String(item?.src ?? '').trim();
        if (src) {
            keys.add(`src:${normalizeSrcKey(src)}`);
        }
    });

    return keys;
}

/**
 * Bỏ item fail khỏi queue — tránh ensureLocalRenameResultsCoverQueue bịa URL.
 */
export function omitFailedLocalSlugRenameQueueItems(queue, errors = []) {
    const failedKeys = localSlugRenameFailureKeys(errors);
    if (failedKeys.size === 0) {
        return Array.isArray(queue) ? [...queue] : [];
    }

    return (Array.isArray(queue) ? queue : []).filter((item) => {
        const id = Number(item?.seo_media_id ?? 0);
        const blockId = String(item?.block_id ?? '').trim();
        const src = String(item?.src ?? '').trim();
        const itemKeys = [
            id > 0 ? `id:${id}` : '',
            blockId ? `block:${blockId}` : '',
            src ? `src:${normalizeSrcKey(src)}` : '',
        ].filter(Boolean);

        return !itemKeys.some((key) => failedKeys.has(key));
    });
}

/**
 * Đổi slug local: ưu tiên rename-by-url khi có /storage/... (ID có thể là WP stale).
 */
async function renameLocalSlugViaAdapters(id, src, slug, { renameById, renameByUrl }) {
    const mediaId = Number(id ?? 0) || 0;
    const mediaSrc = String(src ?? '').trim();
    const useUrl =
        mediaSrc !== ''
        && (isLocalSeoMediaSrc(mediaSrc) || mediaSrc.includes('/storage/'));

    if (useUrl) {
        return renameByUrl(mediaSrc, slug, { seoMediaId: mediaId > 0 ? mediaId : null });
    }

    if (mediaId > 0) {
        return renameById(mediaId, slug);
    }

    throw new Error('Thiếu URL /storage hoặc seo_media_id để đổi slug ảnh nội bộ.');
}

/**
 * @returns {Promise<Array & { errors?: Array<{ item: object, phase: 1|2, message: string }> }>}
 */
export async function executeSeoMediaSlugRenamesTwoPhase(items, { renameById, renameByUrl }) {
    const queue = (Array.isArray(items) ? items : [])
        .map((item) => ({
            seo_media_id: Number(item?.seo_media_id ?? 0) > 0 ? Number(item.seo_media_id) : null,
            src: String(item?.src ?? '').trim(),
            new_slug: String(item?.new_slug ?? '').trim(),
            old_slug: String(item?.old_slug ?? '').trim(),
            block_id: String(item?.block_id ?? '').trim(),
        }))
        .filter((item) => item.new_slug !== '' && (item.src !== '' || Number(item.seo_media_id ?? 0) > 0));

    const results = [];
    results.errors = [];

    if (!queue.length) {
        return results;
    }

    const adapters = { renameById, renameByUrl };
    const tempToken = `seo-ren-${Date.now()}`;
    const interim = new Map();

    const pushError = (item, phase, error) => {
        results.errors.push({
            item,
            phase,
            message: String(error?.message ?? error ?? 'Unknown error'),
        });
    };

    for (let index = 0; index < queue.length; index += 1) {
        const item = queue[index];
        const tempSlug = `${tempToken}-${index + 1}`;
        const id = Number(item.seo_media_id ?? 0);

        try {
            const data = await renameLocalSlugViaAdapters(id, item.src, tempSlug, adapters);
            interim.set(localSlugRenameItemKey(item), {
                item,
                data,
                src: String(data?.url ?? item.src).trim(),
                id: Number(data?.id ?? id ?? 0) || 0,
            });
        } catch (error) {
            // Ảnh thiếu / ID stale: bỏ qua, tiếp tục ảnh khác.
            pushError(item, 1, error);
        }
    }

    for (const item of queue) {
        const state = interim.get(localSlugRenameItemKey(item));
        if (!state) {
            continue;
        }

        try {
            const data = await renameLocalSlugViaAdapters(
                state.id,
                state.src,
                item.new_slug,
                adapters,
            );

            results.push({
                ...item,
                data,
            });
        } catch (error) {
            pushError(item, 2, error);
        }
    }

    return results;
}

/**
 * Gắn URL/slug mới vào đúng block ảnh sau rename local (theo blockId).
 */
export function applyLocalSlugRenameResultToBlocks(blocks, blockId, result) {
    const targetId = String(blockId ?? '').trim();
    if (!targetId || !result?.data) {
        return blocks;
    }

    const { data } = result;
    const resolvedSeoId = Number(data.id ?? result.seo_media_id ?? 0);
    const nextUrl = String(data.url ?? '').trim();
    if (!nextUrl) {
        return blocks;
    }

    const nextSlug = String(data.slug ?? slugFromUrl(nextUrl)).trim();
    const isLocal = isLocalSeoMediaSrc(nextUrl);

    return blocks.map((block) => {
        if (block.type !== 'image' || String(block.id ?? '').trim() !== targetId) {
            return block;
        }

        const image = block.image ?? parseImageFromBlockContent(block.content);
        if (!image?.src) {
            return block;
        }

        const nextImage = {
            ...image,
            slug: nextSlug || image.slug,
            src: nextUrl,
            seoMediaId: resolvedSeoId > 0 ? resolvedSeoId : image.seoMediaId,
            localSrc: isLocal ? nextUrl : String(image.localSrc ?? '').trim(),
            originalSlug: result.old_slug ?? '',
        };

        return {
            ...block,
            image: nextImage,
            content: renderImageFigure(nextImage),
        };
    });
}

/**
 * Cập nhật src/slug ảnh sau rename — match theo ID, không theo số thứ tự slug.
 */
export function applyRenameResultsToBlocks(blocks, wpRenamed = [], localResults = []) {
    const maps = buildRenameResultMaps(normalizeRenameEntries(wpRenamed, localResults));
    if (
        maps.byAttachmentId.size === 0
        && maps.bySeoMediaId.size === 0
        && maps.byBlockId.size === 0
        && maps.byOldUrl.size === 0
    ) {
        return blocks;
    }

    return blocks.map((block) => {
        if (block.type !== 'image') {
            return block;
        }

        const image = block.image ?? parseImageFromBlockContent(block.content);
        if (!image?.src) {
            return block;
        }

        if (image.excludeQuickFix) {
            return block;
        }

        const row = {
            blockId: block.id,
            wpAttachmentId: image.wpAttachmentId,
            seoMediaId: image.seoMediaId,
            src: image.src,
            wpSrc: image.wpSrc,
            localSrc: image.localSrc,
        };
        const entries = findAllRenameEntriesForImageRow(row, maps);
        if (entries.length === 0) {
            return block;
        }

        const originalSrc = String(image.src ?? '').trim();
        const originalSrcIsLocal = isLocalSeoMediaSrc(originalSrc);
        let nextWpSrc = String(image.wpSrc ?? '').trim();
        let nextLocalSrc = String(image.localSrc ?? '').trim();
        if (!nextLocalSrc && originalSrcIsLocal) {
            nextLocalSrc = originalSrc;
        }
        let nextSlug = String(image.slug ?? '').trim();
        let resolvedSeoId = Number(image.seoMediaId ?? 0);
        let localUpdated = false;
        let wpUpdated = false;

        entries.forEach((entry) => {
            const newUrl = String(entry.new_url ?? '').trim();
            if (!newUrl) {
                return;
            }
            const entrySlug = String(entry.new_slug ?? slugFromUrl(newUrl)).trim();
            if (entrySlug) {
                nextSlug = entrySlug;
            }
            const entrySeoId = Number(entry?.seo_media_id ?? 0);
            if (entrySeoId > 0) {
                resolvedSeoId = entrySeoId;
            }
            if (isLocalSeoMediaSrc(newUrl)) {
                nextLocalSrc = newUrl;
                localUpdated = true;
            } else {
                nextWpSrc = resolveFullWordPressImageUrl(newUrl);
                wpUpdated = true;
            }
        });

        // localSrc cũ lệch slug sau WP rename → bỏ, tránh thắng src và 404.
        if (nextSlug && nextLocalSrc && !localUpdated) {
            const localSlug = slugFromUrl(nextLocalSrc);
            if (localSlug && localSlug !== nextSlug) {
                nextLocalSrc = '';
            }
        }

        let nextSrc = '';
        if (originalSrcIsLocal) {
            nextSrc = (localUpdated ? nextLocalSrc : '') || nextLocalSrc || nextWpSrc || originalSrc;
        } else {
            nextSrc = (wpUpdated ? nextWpSrc : '') || nextWpSrc || nextLocalSrc || originalSrc;
        }

        if (
            nextSlug
            && nextSrc
            && isLocalSeoMediaSrc(nextSrc)
            && slugFromUrl(nextSrc) !== nextSlug
            && nextWpSrc
        ) {
            nextSrc = nextWpSrc;
        }

        if (!nextSrc) {
            return block;
        }

        const nextImage = {
            ...image,
            src: nextSrc,
            slug: nextSlug || slugFromUrl(nextSrc),
            seoMediaId: resolvedSeoId > 0 ? resolvedSeoId : image.seoMediaId,
            wpSrc: nextWpSrc,
            localSrc: nextLocalSrc,
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
        if (patch.src !== undefined) {
            nextSrc = patch.src;
        } else if (patch.slug !== undefined && patch.slug !== image.slug) {
            const wpAttachmentId = Number(image.wpAttachmentId ?? 0);
            const hasWordPressSrc =
                wpAttachmentId > 0 ||
                (String(image.wpSrc ?? '').trim() !== '' && !isLocalSeoMediaSrc(image.src));
            if (!hasWordPressSrc && !shouldRenameSlugOnWordPress({ ...image, blockId: block.id })) {
                nextSrc = replaceUrlSlug(image.src, patch.slug);
            }
        }

        const nextImage = withDefaultImageInsertAlign({
            ...image,
            ...patch,
            src: nextSrc,
            slug: patch.slug !== undefined ? patch.slug : image.slug || slugFromUrl(nextSrc),
        });

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
        seo_media_id: row.seoMediaId ?? null,
        src: row.src,
        slug: row.slug,
        alt: row.alt,
        title: row.title,
        caption: row.caption,
        align: row.align,
        exclude_quick_fix: row.excludeQuickFix ? 1 : 0,
        local_src: row.localSrc ?? (isLocalSeoMediaSrc(row.src) ? row.src : ''),
    }));
}

/**
 * Cùng logic tab Hình ảnh (blocks + supplemental) → payload modal «Trong bài».
 *
 * @returns {Array<{picker_key:string,id:number,wp_attachment_id:number,seo_media_id:number,url:string,thumb_url:string,slug:string,alt:string,media_type:string}>}
 */
export function buildMergedEditorImagesForPicker(blocks, supplementalImages = []) {
    const normalizeSrc = (value) => {
        const raw = String(value || '').trim();
        if (!raw) return '';
        try {
            return new URL(raw, window.location.origin).pathname.toLowerCase();
        } catch {
            return raw.split('?')[0].toLowerCase();
        }
    };

    const mergeRow = mergeArticleImageRow;

    const normalizedRows = [
        ...(Array.isArray(supplementalImages)
            ? supplementalImages
                  .map((row, index) => {
                      const src = String(row?.src || '').trim();
                      if (!src) return null;

                      return {
                          key: row?.key || `extra-${index}-${src}`,
                          blockId: String(row?.blockId || row?.block_id || '').trim(),
                          wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0) || null,
                          seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || null,
                          src,
                          wpSrc: String(row?.wpSrc || row?.wp_url || '').trim(),
                          localSrc: String(row?.localSrc || row?.local_src || '').trim(),
                          slug: String(row?.slug || '').trim(),
                          alt: String(row?.alt || '').trim(),
                      };
                  })
                  .filter(Boolean)
            : []),
        ...collectImagesFromBlocks(blocks),
    ];

    const merged = [];
    normalizedRows.forEach((row) => {
        const srcKey = normalizeSrc(row?.src);
        const wpId = Number(row?.wpAttachmentId ?? 0);
        const seoId = Number(row?.seoMediaId ?? 0);

        const index = merged.findIndex((existing) => {
            const eWp = Number(existing?.wpAttachmentId ?? 0);
            const eSeo = Number(existing?.seoMediaId ?? 0);
            const eSrc = normalizeSrc(existing?.src);

            if (wpId > 0 && eWp > 0 && wpId === eWp) return true;
            if (seoId > 0 && eSeo > 0 && seoId === eSeo) return true;
            if (srcKey !== '' && eSrc !== '' && srcKey === eSrc) return true;

            return false;
        });

        if (index < 0) {
            merged.push(row);
            return;
        }

        merged[index] = mergeRow(merged[index], row);
    });

    return merged.map((row, index) => {
        const url = resolveArticleImageSrc(row);
        const wpId = Number(row?.wpAttachmentId ?? 0);
        const seoId = Number(row?.seoMediaId ?? 0);
        const slug = String(row?.slug || '').trim() || slugFromUrl(url);
        const alt = String(row?.alt || '').trim() || slug;
        const pickerKey = `article-${seoId > 0 ? 'seo-' + seoId : 'wp-' + wpId}-${index}-${url}`;

        return {
            picker_key: pickerKey,
            id: wpId > 0 ? wpId : seoId > 0 ? seoId : index + 1,
            wp_attachment_id: wpId,
            seo_media_id: seoId,
            url,
            thumb_url: toPreviewImageUrl(url),
            slug,
            alt,
            media_type: 'image',
        };
    });
}

export function reconcileSupplementalImagesWithBlocks(supplementalRows, blocks) {
    return filterSupplementalDuplicatesOfBlockRows(
        syncSupplementalRowsFromBlockImages(supplementalRows, blocks),
    );
}

/**
 * Gộp hai dòng ảnh trùng identity — ưu tiên URL/slug từ ảnh gắn block (editor).
 */
export function mergeArticleImageRow(current, next) {
    const blockRow = hasArticleImageBlockId(current)
        ? current
        : hasArticleImageBlockId(next)
          ? next
          : null;
    const urlSource = blockRow ?? next;
    const urlFallback = urlSource === current ? next : current;

    const pickUrlField = (field) =>
        String(urlSource?.[field] ?? '').trim() || String(urlFallback?.[field] ?? '').trim();

    return {
        ...current,
        ...next,
        blockId: String(next?.blockId ?? next?.block_id ?? current?.blockId ?? current?.block_id ?? '').trim(),
        wpAttachmentId:
            Number(next?.wpAttachmentId ?? next?.wp_attachment_id ?? 0) > 0
                ? Number(next.wpAttachmentId ?? next.wp_attachment_id)
                : Number(current?.wpAttachmentId ?? current?.wp_attachment_id ?? 0) || null,
        seoMediaId:
            Number(next?.seoMediaId ?? next?.seo_media_id ?? 0) > 0
                ? Number(next.seoMediaId ?? next.seo_media_id)
                : Number(current?.seoMediaId ?? current?.seo_media_id ?? 0) || null,
        src: pickUrlField('src'),
        wpSrc: pickUrlField('wpSrc'),
        localSrc: pickUrlField('localSrc'),
        slug: pickUrlField('slug'),
        alt: String(next?.alt || '').trim() || String(current?.alt || '').trim(),
        title: String(next?.title || '').trim() || String(current?.title || '').trim(),
        caption: String(next?.caption || '').trim() || String(current?.caption || '').trim(),
        originLabel:
            String(next?.originLabel || next?.origin_label || '').trim() ||
            String(current?.originLabel || current?.origin_label || '').trim(),
        excludeQuickFix: Boolean(
            next?.excludeQuickFix ?? next?.exclude_quick_fix ?? current?.excludeQuickFix ?? current?.exclude_quick_fix,
        ),
    };
}

export function syncSupplementalRowsFromBlockImages(supplementalRows, blocks) {
    const blockImages = collectImagesFromBlocks(blocks);
    const bySeoId = new Map();
    const byWpId = new Map();

    blockImages.forEach((img) => {
        const seoId = Number(img.seoMediaId ?? img.seo_media_id ?? 0);
        const wpId = Number(img.wpAttachmentId ?? img.wp_attachment_id ?? 0);
        if (seoId > 0) {
            bySeoId.set(seoId, img);
        }
        if (wpId > 0) {
            byWpId.set(wpId, img);
        }
    });

    return (Array.isArray(supplementalRows) ? supplementalRows : []).map((row) => {
        if (String(row?.blockId ?? row?.block_id ?? '').trim() !== '') {
            return row;
        }

        const seoId = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
        const wpId = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
        const match =
            (seoId > 0 ? bySeoId.get(seoId) : null) ?? (wpId > 0 ? byWpId.get(wpId) : null);

        if (!match) {
            return row;
        }

        const nextSrc = String(match.src ?? '').trim();
        if (!nextSrc) {
            return row;
        }

        const isLocal = isLocalSeoMediaSrc(nextSrc);

        return {
            ...row,
            src: nextSrc,
            slug: String(match.slug ?? row.slug ?? '').trim() || row.slug,
            seoMediaId: Number(match.seoMediaId ?? match.seo_media_id ?? seoId) || row.seoMediaId,
            wpAttachmentId: Number(match.wpAttachmentId ?? match.wp_attachment_id ?? wpId) || row.wpAttachmentId,
            wpSrc: isLocal ? String(row.wpSrc ?? row.wp_url ?? '').trim() : nextSrc,
            wp_url: isLocal ? String(row.wp_url ?? '').trim() : nextSrc,
            localSrc: isLocal ? nextSrc : String(row.localSrc ?? row.local_src ?? '').trim(),
            local_src: isLocal ? nextSrc : String(row.local_src ?? '').trim(),
        };
    });
}

/**
 * Cập nhật URL album sản phẩm theo id (seo_media / wp) sau rename — không phụ thuộc URL cũ.
 */
export function syncProductAlbumUrlsFromBlockImages(articleId, blocks) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    const album = loadProductAlbum(id);
    if (album.length === 0) {
        return [];
    }

    const blockImages = collectImagesFromBlocks(blocks);
    const bySeoId = new Map();
    const byWpId = new Map();

    blockImages.forEach((img) => {
        const seoId = Number(img.seoMediaId ?? img.seo_media_id ?? 0);
        const wpId = Number(img.wpAttachmentId ?? img.wp_attachment_id ?? 0);
        if (seoId > 0) {
            bySeoId.set(seoId, img);
        }
        if (wpId > 0) {
            byWpId.set(wpId, img);
        }
    });

    const updated = album.map((item) => {
        const itemId = Number(item.id ?? 0);
        if (itemId <= 0) {
            return item;
        }

        const match = bySeoId.get(itemId) ?? byWpId.get(itemId);
        if (!match) {
            return item;
        }

        const matchWp = String(match.wpSrc ?? match.wp_url ?? '').trim();
        const matchSrc = String(match.src ?? '').trim();
        const matchLocal = String(match.localSrc ?? match.local_src ?? '').trim();
        // Gallery WP ưu tiên URL WordPress mới; không giữ localSrc stale → 404.
        const nextUrl = (!isLocalSeoMediaSrc(matchWp) && matchWp)
            || (!isLocalSeoMediaSrc(matchSrc) && matchSrc)
            || matchSrc
            || matchLocal;
        if (!nextUrl || nextUrl === String(item.url ?? '').trim()) {
            return item;
        }

        return {
            ...item,
            id: itemId,
            url: nextUrl,
        };
    });

    return saveProductAlbum(id, updated);
}
