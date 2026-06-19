export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * @return {{ siteId: number|null, connectionHash: string }}
 */
export function readSeoArticleApiContext() {
    let siteId = null;
    let connectionHash =
        typeof window.__SEO_CONNECTION_HASH__ === 'string'
            ? window.__SEO_CONNECTION_HASH__.trim()
            : '';

    try {
        const metaEl = document.getElementById('seo-article-meta');
        const rawMeta = metaEl?.textContent?.trim();
        if (!rawMeta) {
            return { siteId, connectionHash };
        }

        const meta = JSON.parse(rawMeta);
        const parsedSiteId = Number.parseInt(String(meta?.site_id ?? ''), 10);
        if (Number.isFinite(parsedSiteId) && parsedSiteId > 0) {
            siteId = parsedSiteId;
        }

        const metaHash = String(meta?.seo_connection_hash ?? '').trim();
        if (metaHash !== '') {
            connectionHash = metaHash;
        }
    } catch {
        /* ignore invalid meta */
    }

    return { siteId, connectionHash };
}

/**
 * @param {Record<string, string>} extraHeaders
 * @return {Record<string, string>}
 */
export function seoArticleApiHeaders(extraHeaders = {}) {
    const { siteId, connectionHash } = readSeoArticleApiContext();
    const headers = { ...extraHeaders };

    if (connectionHash !== '') {
        headers['X-SEO-Connection'] = connectionHash;
    }

    if (siteId !== null && siteId > 0) {
        headers['X-Site-ID'] = String(siteId);
    }

    return headers;
}

/**
 * @param {string} url
 * @param {RequestInit & { headers?: Record<string, string> }} [options]
 */
export async function seoArticleApiFetch(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            ...seoArticleApiHeaders(),
            ...(options.headers ?? {}),
        },
    });

    const data = await response.json().catch(() => ({}));

    return { response, data };
}
