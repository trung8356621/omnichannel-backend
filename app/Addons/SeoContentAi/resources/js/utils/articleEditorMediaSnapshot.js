/**
 * Canonical Article Editor media snapshot (Phase 2A).
 * Laravel SoT; React holds in-memory snapshot only. No Featured/Gallery localStorage SoT.
 */

export const ARTICLE_EDITOR_MEDIA_SNAPSHOT_EVENT = 'article-editor-media-snapshot-changed';

const LEGACY_FEATURED_KEY = (articleId) => `seo_featured_image_${articleId}`;
const LEGACY_ALBUM_KEY = (articleId) => `seo_product_album_list_${articleId}`;

/** @type {Record<number, object>} */
const snapshotsByArticle = Object.create(null);
/** @type {Set<(detail: { articleId: number, snapshot: object }) => void>} */
const snapshotListeners = new Set();

/**
 * Phase 6C.3 — React panels subscribe without Alpine mirror.
 * @param {(detail: { articleId: number, snapshot: object }) => void} listener
 * @returns {() => void}
 */
export function subscribeMediaSnapshot(listener) {
    if (typeof listener !== 'function') {
        return () => {};
    }
    snapshotListeners.add(listener);
    return () => snapshotListeners.delete(listener);
}

function emitSnapshotListeners(articleId, snapshot) {
    const detail = { articleId: Number(articleId) || 0, snapshot };
    snapshotListeners.forEach((listener) => {
        try {
            listener(detail);
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[media-snapshot] listener failed', error);
        }
    });
}

function emptySnapshot(articleId = 0) {
    return {
        version: 1,
        snapshot_version: 1,
        article_id: Number(articleId) || 0,
        document_version: 1,
        generated_at: null,
        featured: null,
        content_images: {
            occurrence_count: 0,
            valid_count: 0,
            invalid_count: 0,
            items: [],
        },
        gallery: {
            required: false,
            items: [],
        },
        capabilities: {
            can_edit_featured: false,
            can_edit_gallery: false,
            can_browse_wordpress_media: false,
            can_upload_local_media: false,
            can_rename_wordpress_media: false,
        },
    };
}

/**
 * Discard legacy Featured/Gallery localStorage shadow SoT. Never apply into React.
 */
export function discardLegacyMediaLocalStorage(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    try {
        window.localStorage.removeItem(LEGACY_FEATURED_KEY(id));
        window.localStorage.removeItem(LEGACY_ALBUM_KEY(id));
    } catch {
        // ignore
    }
}

export function getMediaSnapshot(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return emptySnapshot(0);
    }

    return snapshotsByArticle[id] ? { ...snapshotsByArticle[id] } : emptySnapshot(id);
}

/**
 * Apply server snapshot if version is not older than current.
 * @returns {object|null} applied snapshot or null if ignored as stale
 */
export function applyMediaSnapshot(articleId, snapshot, { force = false } = {}) {
    const id = Number(articleId ?? snapshot?.article_id ?? 0);
    if (!Number.isFinite(id) || id <= 0 || !snapshot || typeof snapshot !== 'object') {
        return null;
    }

    const incoming = Math.max(1, Number(snapshot.snapshot_version) || 1);
    const current = snapshotsByArticle[id];
    const currentVersion = Math.max(1, Number(current?.snapshot_version) || 0);

    if (!force && current && incoming < currentVersion) {
        return null;
    }

    const next = {
        ...emptySnapshot(id),
        ...snapshot,
        article_id: id,
        snapshot_version: incoming,
        featured: snapshot.featured ?? null,
        gallery: snapshot.gallery && typeof snapshot.gallery === 'object'
            ? {
                required: Boolean(snapshot.gallery.required),
                items: Array.isArray(snapshot.gallery.items) ? snapshot.gallery.items : [],
            }
            : emptySnapshot(id).gallery,
        content_images: snapshot.content_images && typeof snapshot.content_images === 'object'
            ? snapshot.content_images
            : emptySnapshot(id).content_images,
        capabilities: snapshot.capabilities && typeof snapshot.capabilities === 'object'
            ? snapshot.capabilities
            : emptySnapshot(id).capabilities,
    };

    snapshotsByArticle[id] = next;
    discardLegacyMediaLocalStorage(id);
    emitSnapshotListeners(id, next);

    const featuredPresent = Boolean(next.featured?.url);
    const galleryCount = Array.isArray(next.gallery?.items) ? next.gallery.items.length : 0;

    window.dispatchEvent(new CustomEvent(ARTICLE_EDITOR_MEDIA_SNAPSHOT_EVENT, {
        detail: {
            article_id: id,
            snapshot_version: next.snapshot_version,
            featured_present: featuredPresent,
            gallery_count: galleryCount,
            media_snapshot: next,
        },
    }));

    // Compat one-way notify for legacy Alpine/React listeners (no LS write).
    if (featuredPresent) {
        window.dispatchEvent(new CustomEvent('seo-featured-image-updated', {
            detail: {
                articleId: id,
                item: {
                    url: next.featured.url,
                    wp_attachment_id: next.featured.wp_attachment_id || 0,
                    seo_media_id: next.featured.media_id || 0,
                    alt: next.featured.alt || '',
                },
                from_snapshot: true,
            },
        }));
    } else {
        window.dispatchEvent(new CustomEvent('seo-featured-image-cleared', {
            detail: { articleId: id, from_snapshot: true },
        }));
    }

    window.dispatchEvent(new CustomEvent('seo-product-gallery-updated', {
        detail: {
            article_id: id,
            articleId: id,
            gallery: (next.gallery?.items || []).map((row) => ({
                id: Number(row.wp_attachment_id || row.media_id || 0) || 0,
                url: String(row.url || ''),
            })),
            from_snapshot: true,
        },
    }));

    return next;
}

export function featuredFromSnapshot(articleId) {
    const featured = getMediaSnapshot(articleId).featured;
    if (!featured?.url) {
        return null;
    }

    return {
        url: String(featured.url),
        wp_attachment_id: Number(featured.wp_attachment_id) || 0,
        seo_media_id: Number(featured.media_id) || 0,
        alt: String(featured.alt || ''),
        slug: String(featured.filename || ''),
    };
}

export function galleryFromSnapshot(articleId) {
    const items = getMediaSnapshot(articleId).gallery?.items;
    if (!Array.isArray(items)) {
        return [];
    }

    return items
        .map((row) => ({
            id: Number(row.wp_attachment_id || row.media_id || 0) || 0,
            url: String(row.url || '').trim(),
            stable_id: String(row.id || ''),
        }))
        .filter((row) => row.url !== '');
}

function sessionHeaders() {
    const client = window.__seoEditorSessionClient;
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrf) {
        headers['X-CSRF-TOKEN'] = csrf;
    }
    if (client?.sessionId) {
        headers['X-Editor-Session-Id'] = client.sessionId;
    }

    return headers;
}

function sessionBodyExtras(articleId) {
    const client = window.__seoEditorSessionClient;
    const snap = getMediaSnapshot(articleId);

    return {
        editor_session_id: client?.sessionId || null,
        expected_snapshot_version: snap.snapshot_version || null,
    };
}

async function parseSnapshotResponse(response, articleId) {
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.success === false) {
        const error = new Error(data?.message || `HTTP ${response.status}`);
        error.code = data?.error || 'media_mutation_failed';
        error.data = data;
        throw error;
    }

    const snapshot = data.media_snapshot || data.data?.media_snapshot;
    if (snapshot) {
        applyMediaSnapshot(articleId, snapshot);
    }

    return snapshot || getMediaSnapshot(articleId);
}

export async function fetchMediaSnapshot(articleId, endpoint) {
    const id = Number(articleId);
    const url = endpoint || `/api/seo/articles/${id}/editor/media-snapshot`;
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: sessionHeaders(),
    });

    return parseSnapshotResponse(response, id);
}

export async function setFeaturedViaApi(articleId, item, endpoint) {
    const id = Number(articleId);
    const url = endpoint || `/api/seo/articles/${id}/editor/media/featured`;
    const previous = getMediaSnapshot(id);
    const response = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: sessionHeaders(),
        body: JSON.stringify({
            ...sessionBodyExtras(id),
            item,
            url: item?.url,
            wp_attachment_id: item?.wp_attachment_id ?? item?.wpAttachmentId,
            seo_media_id: item?.seo_media_id ?? item?.seoMediaId,
        }),
    });

    try {
        return await parseSnapshotResponse(response, id);
    } catch (error) {
        applyMediaSnapshot(id, previous, { force: true });
        throw error;
    }
}

export async function clearFeaturedViaApi(articleId, endpoint) {
    const id = Number(articleId);
    const url = endpoint || `/api/seo/articles/${id}/editor/media/featured`;
    const previous = getMediaSnapshot(id);
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: sessionHeaders(),
        body: JSON.stringify(sessionBodyExtras(id)),
    });

    try {
        return await parseSnapshotResponse(response, id);
    } catch (error) {
        applyMediaSnapshot(id, previous, { force: true });
        throw error;
    }
}

export async function replaceGalleryViaApi(articleId, items, endpoint) {
    const id = Number(articleId);
    const url = endpoint || `/api/seo/articles/${id}/editor/media/gallery`;
    const previous = getMediaSnapshot(id);
    const response = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: sessionHeaders(),
        body: JSON.stringify({
            ...sessionBodyExtras(id),
            items,
        }),
    });

    try {
        return await parseSnapshotResponse(response, id);
    } catch (error) {
        applyMediaSnapshot(id, previous, { force: true });
        throw error;
    }
}

export async function reorderGalleryViaApi(articleId, orderedIds, endpoint) {
    const id = Number(articleId);
    const url = endpoint || `/api/seo/articles/${id}/editor/media/gallery/reorder`;
    const previous = getMediaSnapshot(id);
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: sessionHeaders(),
        body: JSON.stringify({
            ...sessionBodyExtras(id),
            ordered_ids: orderedIds,
        }),
    });

    try {
        return await parseSnapshotResponse(response, id);
    } catch (error) {
        applyMediaSnapshot(id, previous, { force: true });
        throw error;
    }
}

export default {
    ARTICLE_EDITOR_MEDIA_SNAPSHOT_EVENT,
    discardLegacyMediaLocalStorage,
    getMediaSnapshot,
    applyMediaSnapshot,
    featuredFromSnapshot,
    galleryFromSnapshot,
    fetchMediaSnapshot,
    setFeaturedViaApi,
    clearFeaturedViaApi,
    replaceGalleryViaApi,
    reorderGalleryViaApi,
};
