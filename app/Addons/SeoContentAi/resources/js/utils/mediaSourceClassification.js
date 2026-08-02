/**
 * Canonical media source classification for Article Editor / Media Library.
 * Keep free of imports from articleImagesUtils to avoid circular deps.
 */

function resolveWpAttachmentId(row) {
    return Math.max(
        0,
        Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? row?.attachment_id ?? 0) || 0,
    );
}

function resolveSeoMediaId(row) {
    return Math.max(0, Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || 0);
}

function isLocalSeoMediaSrc(src) {
    const value = String(src ?? '').trim();
    if (value === '') {
        return false;
    }

    return /\/storage\/seo\//i.test(value)
        || /\/seo-media\//i.test(value)
        || value.startsWith('blob:')
        || /^\/?storage\//i.test(value);
}

function hasTrustedWordPressUrl(row) {
    const wpSrc = String(row?.wpSrc ?? row?.wp_url ?? '').trim();
    const src = String(row?.src ?? row?.url ?? '').trim();
    const candidate = wpSrc || src;
    if (candidate === '' || isLocalSeoMediaSrc(candidate)) {
        return false;
    }

    return /\/wp-content\/uploads\//i.test(candidate) || /^https?:\/\//i.test(candidate);
}

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {'wordpress'|'local'|'generated'|'uploaded'|'unknown'}
 */
export function classifyMediaSource(row) {
    if (!row || typeof row !== 'object') {
        return 'unknown';
    }

    const sourceType = String(row.source_type ?? row.sourceType ?? row.kind ?? '').trim().toLowerCase();
    if (sourceType === 'wordpress' || sourceType === 'wp') {
        return 'wordpress';
    }
    if (sourceType === 'generated' || sourceType === 'ai') {
        return 'generated';
    }
    if (sourceType === 'uploaded' || sourceType === 'upload') {
        return 'uploaded';
    }
    if (sourceType === 'local' || sourceType === 'laravel' || sourceType === 'internal') {
        return 'local';
    }

    const wpAttachmentId = resolveWpAttachmentId(row);
    if (wpAttachmentId > 0 || hasTrustedWordPressUrl(row)) {
        return 'wordpress';
    }

    const src = String(row.src ?? row.url ?? row.localSrc ?? row.local_src ?? '').trim();
    const seoMediaId = resolveSeoMediaId(row);
    if (isLocalSeoMediaSrc(src) || seoMediaId > 0) {
        if (String(row.generation_status ?? row.ai_job_id ?? '').trim() !== '') {
            return 'generated';
        }

        return seoMediaId > 0 ? 'local' : 'uploaded';
    }

    if (/\/wp-content\/uploads\//i.test(src)) {
        return 'wordpress';
    }

    return 'unknown';
}

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
export function isWordPressProtectedMedia(row) {
    return classifyMediaSource(row) === 'wordpress';
}

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
export function isBulkSlugRenameSafeMedia(row) {
    if (!row || isWordPressProtectedMedia(row)) {
        return false;
    }

    const source = classifyMediaSource(row);
    return source === 'local' || source === 'generated' || source === 'uploaded';
}
