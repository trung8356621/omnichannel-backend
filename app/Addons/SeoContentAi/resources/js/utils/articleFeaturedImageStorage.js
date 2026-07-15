const featuredImageKey = (articleId) => `seo_featured_image_${articleId}`;

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
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(featuredImageKey(id));
        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw);

        return normalizeFeaturedImage(parsed?.item ?? parsed);
    } catch {
        return null;
    }
}

export function saveFeaturedImage(articleId, item) {
    const id = Number(articleId ?? 0);
    const normalized = normalizeFeaturedImage(item);
    if (!Number.isFinite(id) || id <= 0 || !normalized) {
        return null;
    }

    try {
        window.localStorage.setItem(
            featuredImageKey(id),
            JSON.stringify({
                item: normalized,
                updatedAt: Date.now(),
            }),
        );
    } catch (error) {
        console.warn('Không lưu được ảnh đại diện vào localStorage', error);
    }

    // Sidebar Alpine chỉ sync lúc load / cleared — bắn event để cập nhật featuredImageDraft ngay.
    window.dispatchEvent(
        new CustomEvent('seo-featured-image-updated', {
            detail: {
                articleId: id,
                item: normalized,
            },
        }),
    );

    return normalized;
}

export function persistFeaturedImageDraftToServer(articleId, wire) {
    const item = loadFeaturedImage(articleId);
    if (!item || !wire?.persistFeaturedImageFromClient) {
        return Promise.resolve(item);
    }

    return wire.persistFeaturedImageFromClient(item);
}

export function clearFeaturedImageStorage(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    window.localStorage.removeItem(featuredImageKey(id));
}
