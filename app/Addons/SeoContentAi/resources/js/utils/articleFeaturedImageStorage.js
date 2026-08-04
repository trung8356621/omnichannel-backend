import {
    clearFeaturedViaApi,
    featuredFromSnapshot,
    getMediaSnapshot,
    setFeaturedViaApi,
} from './articleEditorMediaSnapshot';

/**
 * Featured helpers — Phase 2A: in-memory snapshot only (no localStorage SoT).
 */

export function normalizeFeaturedImage(item) {
    if (!item || typeof item !== 'object') {
        return null;
    }

    const url = String(item.url ?? item.src ?? '').trim();
    if (url === '') {
        return null;
    }

    const wpAttachmentId = Number(item.wp_attachment_id ?? item.wpAttachmentId ?? 0);
    const seoMediaId = Number(item.seo_media_id ?? item.seoMediaId ?? 0);

    return {
        url,
        wp_attachment_id: wpAttachmentId > 0 ? wpAttachmentId : 0,
        seo_media_id: seoMediaId > 0 ? seoMediaId : 0,
        alt: String(item.alt ?? '').trim(),
        slug: String(item.slug ?? '').trim(),
    };
}

export function loadFeaturedImage(articleId) {
    return featuredFromSnapshot(articleId);
}

/**
 * Optimistic UI only — canonical persist via setFeaturedViaApi / Livewire bridge.
 * Does NOT write localStorage.
 */
export function saveFeaturedImage(articleId, item) {
    const id = Number(articleId ?? 0);
    const normalized = normalizeFeaturedImage(item);
    if (!Number.isFinite(id) || id <= 0 || !normalized) {
        return null;
    }

    // Fire-and-forget API; callers that need ACK should await setFeaturedViaApi.
    void setFeaturedViaApi(id, normalized).catch((error) => {
        console.warn('Featured persist failed', error);
    });

    return normalized;
}

export function persistFeaturedImageDraftToServer(articleId, wire) {
    const item = loadFeaturedImage(articleId);
    if (!item) {
        return Promise.resolve(null);
    }

    return setFeaturedViaApi(articleId, item).catch(() => {
        if (wire?.persistFeaturedImageFromClient) {
            return wire.persistFeaturedImageFromClient(item);
        }

        return item;
    });
}

export function clearFeaturedImageStorage(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    void clearFeaturedViaApi(id).catch((error) => {
        console.warn('Featured clear failed', error);
    });
}

export function featuredPresent(articleId) {
    return Boolean(getMediaSnapshot(articleId)?.featured?.url);
}
