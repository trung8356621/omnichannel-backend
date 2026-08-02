/**
 * Presentation status for assistant dock widgets.
 * Backend/analysis feeds status + reason codes; UI does not invent rules alone.
 */

import {
    computeQuickFixSlugSupplementalOutcome,
    isImageReadyForWpSlugFix,
    resolveImageRefIds,
} from './articleImagesUtils';
import { loadFeaturedImage } from './articleFeaturedImageStorage';
import { presentSeoReason } from './seoReasonMetrics';

export const MIN_VALID_HTTP_LINKS = 5;

/**
 * @typedef {{
 *   code: string,
 *   message: string,
 *   target_id?: string|null,
 *   target?: string|null,
 * }} AssistantHealthReason
 */

/**
 * @typedef {{
 *   key: string,
 *   item_count: number,
 *   issue_count: number,
 *   status: 'error'|'warning'|'success'|'neutral',
 *   reasons: AssistantHealthReason[],
 * }} AssistantWidgetHealth
 */

/**
 * @param {string} href
 * @returns {boolean}
 */
export function isValidHttpLinkHref(href) {
    const value = String(href ?? '').trim();
    if (value === '' || value.startsWith('#') || value.startsWith('javascript:')) {
        return false;
    }

    if (/^(tel|mailto|sms|fax|callto|geo|skype|whatsapp|viber|zalo|maps):/i.test(value)) {
        return false;
    }

    if (/^https?:\/\//i.test(value) || value.startsWith('//') || value.startsWith('/')) {
        return true;
    }

    return false;
}

/**
 * @param {{ internal?: Array<{href?: string}>, external?: Array<{href?: string}> }|null|undefined} extractedLinks
 * @returns {number}
 */
export function countValidHttpLinks(extractedLinks) {
    const buckets = [
        ...(Array.isArray(extractedLinks?.internal) ? extractedLinks.internal : []),
        ...(Array.isArray(extractedLinks?.external) ? extractedLinks.external : []),
    ];

    const seen = new Set();
    let count = 0;

    buckets.forEach((link) => {
        const href = String(link?.href ?? '').trim();
        if (!isValidHttpLinkHref(href)) {
            return;
        }
        const key = href.toLowerCase().replace(/\/+$/, '');
        if (seen.has(key)) {
            return;
        }
        seen.add(key);
        count += 1;
    });

    return count;
}

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
function rowHasMediaSignal(row) {
    if (!row) {
        return false;
    }
    const src = String(row.src ?? row.url ?? '').trim();
    const localSrc = String(row.localSrc ?? row.local_src ?? '').trim();
    const { wpAttachmentId, seoMediaId } = resolveImageRefIds(row);

    return src !== '' || localSrc !== '' || wpAttachmentId > 0 || seoMediaId > 0;
}

/**
 * @param {Array<Record<string, unknown>>} rows
 * @param {string} keyword
 * @returns {{ issueCount: number, reasons: AssistantHealthReason[], itemCount: number }}
 */
export function analyzeImageRowsHealth(rows, keyword = '') {
    const list = Array.isArray(rows) ? rows : [];
    const reasons = [];
    let slugIssues = 0;
    let invalidIssues = 0;
    let firstSlugTarget = null;
    let ordinal = 0;

    list.forEach((row) => {
        if (!row || row.excludeQuickFix) {
            return;
        }

        ordinal += 1;
        const blockId = String(row.blockId ?? row.block_id ?? '').trim();
        const targetId = blockId || (row.wpAttachmentId ? `image-${row.wpAttachmentId}` : null);

        if (!rowHasMediaSignal(row)) {
            invalidIssues += 1;
            return;
        }

        if (!isImageReadyForWpSlugFix(row) && blockId) {
            invalidIssues += 1;
            if (!firstSlugTarget) {
                firstSlugTarget = targetId;
            }
        }

        const enriched = {
            ...row,
            quickFixIndex: Number(row.quickFixIndex ?? 0) > 0 ? Number(row.quickFixIndex) : ordinal,
        };
        const outcome = computeQuickFixSlugSupplementalOutcome(enriched, keyword, { wpOnly: false });
        if (outcome.wpRename || outcome.localRename || outcome.patch?.slug) {
            slugIssues += 1;
            if (!firstSlugTarget) {
                firstSlugTarget = targetId;
            }
        }
    });

    if (slugIssues > 0) {
        reasons.push({
            code: 'image_slug_not_fixed',
            message: slugIssues === 1
                ? '1 ảnh chưa sửa slug'
                : `${slugIssues} ảnh chưa sửa slug`,
            target_id: firstSlugTarget,
            target: 'images',
        });
    }

    if (invalidIssues > 0) {
        reasons.push({
            code: 'image_reference_invalid',
            message: invalidIssues === 1
                ? '1 ảnh chưa hợp lệ hoặc chưa upload xong'
                : `${invalidIssues} ảnh chưa hợp lệ hoặc chưa upload xong`,
            target: 'images',
        });
    }

    return {
        itemCount: list.length,
        issueCount: slugIssues + invalidIssues,
        reasons,
        slugIssues,
        invalidIssues,
    };
}

/**
 * @param {object} params
 * @returns {AssistantWidgetHealth}
 */
export function buildImagesWidgetHealth({
    rows = [],
    keyword = '',
    imageRatioMetrics = null,
    locale = 'vi',
    messages = {},
} = {}) {
    const analyzed = analyzeImageRowsHealth(rows, keyword);
    const reasons = [...analyzed.reasons];

    if (
        imageRatioMetrics
        && Number(imageRatioMetrics.missing_image_count) > 0
        && Number(imageRatioMetrics.recommended_image_count) > Number(imageRatioMetrics.current_image_count)
    ) {
        const presented = presentSeoReason('image_ratio_low', {
            messages,
            metrics: imageRatioMetrics,
            locale,
        });
        const codeExists = reasons.some((r) => r.code === 'image_ratio_low');
        if (!codeExists) {
            reasons.push({
                code: 'image_ratio_low',
                message: presented.summary,
                target: 'images',
            });
        }
    }

    // Chip: valid content images. ⚠ = slug/invalid (+ at most 1 for SEO ratio). Never "6/11".
    const fixableIssues = analyzed.slugIssues + analyzed.invalidIssues;
    const recommended = Math.max(0, Number(imageRatioMetrics?.recommended_image_count) || 0);
    const missingRecommended = Math.max(0, Number(imageRatioMetrics?.missing_image_count) || 0);
    const metricsValid = imageRatioMetrics?.valid_image_count ?? imageRatioMetrics?.current_image_count;
    const validCount = metricsValid != null && Number.isFinite(Number(metricsValid))
        ? Math.max(0, Number(metricsValid))
        : Math.max(0, analyzed.itemCount - analyzed.invalidIssues);

    if (recommended > 0) {
        reasons.push({
            code: 'image_recommendation',
            message: locale === 'en'
                ? `About ${recommended} images recommended for this article (${validCount} valid content images).`
                : `Đề xuất khoảng ${recommended} ảnh cho bài viết này (${validCount} ảnh nội dung hợp lệ).`,
            target: 'images',
            severity: 'info',
        });
    }

    const issueCount = fixableIssues + (missingRecommended > 0 ? 1 : 0);

    let status = 'neutral';
    if (issueCount > 0) {
        status = 'error';
    } else if (validCount > 0) {
        status = 'success';
    }

    return {
        key: 'images',
        item_count: validCount,
        issue_count: issueCount,
        recommended_count: recommended,
        missing_recommended_count: missingRecommended,
        status,
        reasons: reasons.filter((reason, index, list) => (
            list.findIndex((entry) => entry.code === reason.code) === index
        )),
    };
}

/**
 * @param {object} params
 * @returns {AssistantWidgetHealth}
 */
export function buildSeoWidgetHealth({
    focusKeyword = '',
    violations = [],
    failedItems = [],
    locale = 'vi',
} = {}) {
    const keyword = String(focusKeyword ?? '').trim();
    const reasons = [];
    const failed = Array.isArray(failedItems) ? failedItems : [];

    if (keyword === '' || /^(từ khóa|keyword|focus keyword|nhập|enter)/i.test(keyword)) {
        reasons.push({
            code: 'focus_keyword_missing',
            message: locale === 'en' ? 'Focus keyword is missing' : 'Thiếu từ khóa chính',
            target_id: 'focus-keyword',
            target: 'seo',
        });
    }

    failed.forEach((item) => {
        const code = String(item?.key ?? item?.code ?? '').trim();
        if (!code || code === 'missing_focus_keyword') {
            return;
        }
        if (reasons.some((r) => r.code === code)) {
            return;
        }
        reasons.push({
            code,
            message: String(item?.summary ?? item?.label ?? code),
            target: 'seo',
            target_id: code,
        });
    });

    // violations that are errors but maybe not in failedItems yet
    if (Array.isArray(violations) && violations.includes('missing_focus_keyword')) {
        if (!reasons.some((r) => r.code === 'focus_keyword_missing')) {
            reasons.unshift({
                code: 'focus_keyword_missing',
                message: locale === 'en' ? 'Focus keyword is missing' : 'Thiếu từ khóa chính',
                target_id: 'focus-keyword',
                target: 'seo',
            });
        }
    }

    const issueCount = reasons.length;
    let status = 'success';
    if (issueCount > 0) {
        const onlySoft = reasons.every((r) =>
            String(r.code).includes('suboptimal')
            || String(r.code).includes('below_excellent'),
        );
        status = onlySoft ? 'warning' : 'error';
    } else if (keyword === '') {
        status = 'error';
    }

    return {
        key: 'seo',
        item_count: failed.length,
        issue_count: issueCount,
        status: issueCount > 0 ? status : (keyword ? 'success' : 'neutral'),
        reasons,
    };
}

/**
 * @param {object} params
 * @returns {AssistantWidgetHealth}
 */
export function buildLinksWidgetHealth({ extractedLinks = null, locale = 'vi' } = {}) {
    const validCount = countValidHttpLinks(extractedLinks);
    const reasons = [];

    if (validCount < MIN_VALID_HTTP_LINKS) {
        reasons.push({
            code: 'links_below_minimum',
            message: locale === 'en'
                ? `Need at least ${MIN_VALID_HTTP_LINKS} valid links (${validCount}/${MIN_VALID_HTTP_LINKS}).`
                : `Cần tối thiểu ${MIN_VALID_HTTP_LINKS} link hợp lệ (${validCount}/${MIN_VALID_HTTP_LINKS}).`,
            target: 'links',
        });
    }

    return {
        key: 'links',
        item_count: validCount,
        issue_count: reasons.length,
        status: reasons.length > 0 ? 'error' : (validCount > 0 ? 'success' : 'neutral'),
        reasons,
    };
}

/**
 * @param {object} params
 * @returns {AssistantWidgetHealth}
 */
export function buildFeaturedWidgetHealth({
    articleId = 0,
    featuredImage = null,
    keyword = '',
    altMandatory = false,
    locale = 'vi',
} = {}) {
    const stored = loadFeaturedImage(articleId);
    const item = featuredImage && String(featuredImage.url ?? featuredImage.src ?? '').trim()
        ? featuredImage
        : stored;
    const reasons = [];
    const url = String(item?.url ?? item?.src ?? '').trim();

    if (!item || url === '') {
        reasons.push({
            code: 'featured_missing',
            message: locale === 'en' ? 'Featured image is missing' : 'Chưa có ảnh đại diện',
            target: 'featured',
        });
    } else {
        // Presence wins — never keep featured_missing once a renderable URL exists.
        const row = {
            src: url,
            slug: item.slug,
            wpAttachmentId: item.wp_attachment_id ?? item.wpAttachmentId,
            seoMediaId: item.seo_media_id ?? item.seoMediaId,
            quickFixIndex: 1,
        };
        const wpId = Number(row.wpAttachmentId ?? 0);
        const seoId = Number(row.seoMediaId ?? 0);

        if (/placeholder/i.test(url) || url.startsWith('blob:')) {
            reasons.push({
                code: 'featured_upload_incomplete',
                message: locale === 'en' ? 'Featured image upload incomplete' : 'Ảnh đại diện chưa upload xong',
                target: 'featured',
            });
        } else if (wpId <= 0 && seoId <= 0 && !/^https?:\/\//i.test(url)) {
            reasons.push({
                code: 'featured_upload_incomplete',
                message: locale === 'en' ? 'Featured image upload incomplete' : 'Ảnh đại diện chưa upload xong',
                target: 'featured',
            });
        }

        // Slug warning only when filename is clearly placeholder / empty — not every SEO rename suggestion.
        const slug = String(item.slug ?? '').trim();
        const basename = url.split('/').pop()?.split('?')[0] ?? '';
        const looksPlaceholderSlug = slug === ''
            && (/^(image|img|photo|untitled|download)[-_]?\d*\./i.test(basename) || /placeholder/i.test(basename));
        if (looksPlaceholderSlug && keyword) {
            const outcome = computeQuickFixSlugSupplementalOutcome(
                { ...row, quickFixIndex: 1 },
                keyword,
                { wpOnly: false },
            );
            if (outcome.wpRename || outcome.localRename || outcome.patch?.slug) {
                reasons.push({
                    code: 'featured_slug_not_fixed',
                    message: locale === 'en' ? 'Featured image slug not fixed' : 'Ảnh đại diện chưa sửa slug',
                    target: 'featured',
                });
            }
        }

        const alt = String(item.alt ?? '').trim();
        if (alt === '') {
            reasons.push({
                code: 'featured_alt_missing',
                message: locale === 'en' ? 'Featured image ALT is missing' : 'Ảnh đại diện thiếu ALT',
                target: 'featured',
            });
        }
    }

    const hardErrors = reasons.filter((r) => r.code !== 'featured_alt_missing' || altMandatory);
    const onlyAltWarning = reasons.length === 1 && reasons[0].code === 'featured_alt_missing' && !altMandatory;

    let status = 'success';
    if (reasons.some((r) => r.code === 'featured_missing')) {
        status = 'error';
    } else if (onlyAltWarning) {
        status = 'warning';
    } else if (hardErrors.length > 0) {
        status = 'error';
    } else if (url) {
        status = 'success';
    }

    return {
        key: 'featured',
        item_count: url ? 1 : 0,
        issue_count: reasons.filter((r) => r.code !== 'featured_alt_missing' || altMandatory).length,
        status,
        reasons,
    };
}

/**
 * @param {object} params
 * @returns {AssistantWidgetHealth}
 */
export function buildGalleryWidgetHealth({
    required = false,
    items = [],
    keyword = '',
    locale = 'vi',
} = {}) {
    if (!required) {
        return {
            key: 'gallery',
            item_count: Array.isArray(items) ? items.length : 0,
            issue_count: 0,
            status: 'neutral',
            reasons: [],
        };
    }

    const list = Array.isArray(items) ? items : [];
    const reasons = [];

    if (list.length === 0) {
        reasons.push({
            code: 'gallery_missing',
            message: locale === 'en' ? 'Gallery images are missing' : 'Chưa có ảnh gallery',
            target: 'gallery',
        });
    } else {
        const analyzed = analyzeImageRowsHealth(
            list.map((item, index) => ({
                ...item,
                src: item.url ?? item.src,
                quickFixIndex: index + 1,
            })),
            keyword,
        );
        reasons.push(...analyzed.reasons.map((r) => ({
            ...r,
            target: 'gallery',
            code: r.code === 'image_slug_not_fixed' ? 'gallery_slug_not_fixed' : r.code,
        })));
    }

    return {
        key: 'gallery',
        item_count: list.length,
        issue_count: reasons.length,
        status: reasons.length > 0 ? 'error' : 'success',
        reasons,
    };
}

/**
 * @param {Record<string, AssistantWidgetHealth>} healthByKey
 * @returns {Record<string, AssistantWidgetHealth>}
 */
export function publishableWidgetHealth(healthByKey) {
    return healthByKey && typeof healthByKey === 'object' ? healthByKey : {};
}

/**
 * Dispatch health update without forcing layout reflow of editor.
 *
 * @param {Record<string, AssistantWidgetHealth>} healthByKey
 */
export function dispatchAssistantWidgetHealth(healthByKey) {
    if (typeof window === 'undefined') {
        return;
    }

    const detail = publishableWidgetHealth(healthByKey);
    window.dispatchEvent(new CustomEvent('seo-assistant-widget-health', { detail }));

    // Keep legacy badge counts in sync (item_count for display; issue via health).
    const badges = {};
    Object.values(detail).forEach((widget) => {
        if (!widget?.key) {
            return;
        }
        if (widget.key === 'seo') {
            badges.seo = widget.issue_count > 0 ? widget.issue_count : null;
        } else if (widget.key === 'links') {
            badges.links = widget.item_count > 0 ? widget.item_count : null;
        } else if (widget.key === 'images') {
            badges.images = widget.item_count > 0 ? widget.item_count : null;
        } else if (widget.key === 'featured') {
            badges.featured = widget.item_count > 0 ? widget.item_count : null;
        } else if (widget.key === 'gallery') {
            badges.gallery = widget.item_count > 0 ? widget.item_count : null;
        }
    });

    window.dispatchEvent(new CustomEvent('seo-assistant-navigator-badges', { detail: badges }));
}
