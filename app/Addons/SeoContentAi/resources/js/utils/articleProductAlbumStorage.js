import { callEditArticleLivewire } from './articleEditorLivewire';

const albumKey = (articleId) => `seo_product_album_list_${articleId}`;

export function normalizeProductAlbumItem(item) {
    if (!item || typeof item !== 'object') {
        return null;
    }

    const url = String(item.url ?? item.src ?? '').trim();
    if (url === '') {
        return null;
    }

    const wpAttachmentId = Number(item.wp_attachment_id ?? item.wpAttachmentId ?? 0);
    const seoMediaId = Number(item.seo_media_id ?? item.seoMediaId ?? item.id ?? 0);
    const id = wpAttachmentId > 0 ? wpAttachmentId : seoMediaId;

    return {
        id: id > 0 ? id : 0,
        url,
    };
}

export function normalizeProductAlbumList(items) {
    if (!Array.isArray(items)) {
        return [];
    }

    const seen = new Set();
    const out = [];

    items.forEach((row) => {
        const normalized = normalizeProductAlbumItem(row);
        if (!normalized) {
            return;
        }

        const key = `${normalized.id}:${normalized.url}`;
        if (seen.has(key)) {
            return;
        }

        seen.add(key);
        out.push(normalized);
    });

    return out;
}

export function loadProductAlbum(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(albumKey(id));
        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);
        return normalizeProductAlbumList(Array.isArray(parsed) ? parsed : parsed?.items);
    } catch {
        return [];
    }
}

export function saveProductAlbum(articleId, items, { dispatch = true } = {}) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    const normalized = normalizeProductAlbumList(items);

    try {
        window.localStorage.setItem(
            albumKey(id),
            JSON.stringify({
                items: normalized,
                updatedAt: Date.now(),
            }),
        );
    } catch (error) {
        console.warn('Không lưu được album sản phẩm vào localStorage', error);
    }

    if (dispatch) {
        dispatchProductGalleryUpdated(id, normalized);
    }

    return normalized;
}

export function dispatchProductGalleryUpdated(articleId, gallery) {
    const items = normalizeProductAlbumList(gallery);

    window.dispatchEvent(
        new CustomEvent('seo-product-gallery-updated', {
            detail: {
                gallery: items,
                article_id: Number(articleId ?? 0),
                articleId: Number(articleId ?? 0),
            },
        }),
    );
}

export function appendProductAlbumItems(articleId, incomingItems) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    const current = loadProductAlbum(id);
    const merged = normalizeProductAlbumList([...current, ...incomingItems]);

    return saveProductAlbum(id, merged);
}

export function removeProductAlbumItem(articleId, url) {
    const id = Number(articleId ?? 0);
    const target = String(url ?? '').trim();
    if (!Number.isFinite(id) || id <= 0 || target === '') {
        return loadProductAlbum(id);
    }

    const next = loadProductAlbum(id).filter((row) => String(row?.url ?? '').trim() !== target);

    return saveProductAlbum(id, next);
}

export function reorderProductAlbum(articleId, orderedUrls) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    const current = loadProductAlbum(id);
    if (current.length === 0) {
        return [];
    }

    const byUrl = new Map(current.map((row) => [String(row.url ?? '').trim(), row]));
    const ordered = [];

    (Array.isArray(orderedUrls) ? orderedUrls : []).forEach((url) => {
        const key = String(url ?? '').trim();
        if (key !== '' && byUrl.has(key)) {
            ordered.push(byUrl.get(key));
            byUrl.delete(key);
        }
    });

    byUrl.forEach((row) => ordered.push(row));

    return saveProductAlbum(id, ordered);
}

export function persistProductAlbumDraftToServer(articleId, wire) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0 || !wire?.persistProductAlbumFromClient) {
        return Promise.resolve([]);
    }

    const items = loadProductAlbum(id);

    return wire.persistProductAlbumFromClient(items);
}

export function syncProductAlbumToServer(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return Promise.resolve([]);
    }

    const items = loadProductAlbum(id);
    if (items.length === 0) {
        return Promise.resolve([]);
    }

    return callEditArticleLivewire('persistProductAlbumFromClient', items).catch((error) => {
        console.warn('Không đồng bộ album sản phẩm lên server', error);

        return [];
    });
}

export function clearProductAlbumStorage(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    window.localStorage.removeItem(albumKey(id));
}
