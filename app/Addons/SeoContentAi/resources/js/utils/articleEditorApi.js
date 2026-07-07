import { csrfToken, seoArticleApiFetch } from './seoArticleApi.js';

/**
 * @param {object|null|undefined} wire Livewire component ($wire)
 * @return {{ title: string, slug: string, seo_meta_description: string, focus_keyword: string }}
 */
export function readArticleMetaFromWire(wire) {
    if (!wire) {
        return {
            title: '',
            slug: '',
            seo_meta_description: '',
            focus_keyword: '',
        };
    }

    return {
        title: String(wire.articleTitle ?? '').trim(),
        slug: String(wire.articleSlug ?? '').trim(),
        seo_meta_description: String(wire.seoMetaDescription ?? '').trim(),
        focus_keyword: String(wire.focusKeyword ?? '').trim(),
    };
}

/**
 * @param {object} editorBundle from __seoCollectEditorHeavyBundle
 * @param {object|null|undefined} wire
 * @return {Record<string, unknown>}
 */
export function buildArticleEditorApiPayload(editorBundle, wire) {
    const articleId = Number(editorBundle?.articleId ?? 0);
    const featured = window.__seoFeaturedImageStorage?.load?.(articleId) ?? null;
    const productAlbum = window.__seoProductAlbumStorage?.load?.(articleId) ?? null;
    const faqsFromEditor =
        typeof window.__seoCollectArticleFaqs === 'function' ? window.__seoCollectArticleFaqs() : null;
    const faqsFromBundle = Array.isArray(editorBundle?.faqs) ? editorBundle.faqs : null;

    return {
        html: String(editorBundle?.html ?? ''),
        seo_analysis: editorBundle?.seoAnalysis ?? null,
        article_meta: readArticleMetaFromWire(wire),
        publish_box: window.__seoPublishBoxSnapshot?.() ?? null,
        category_ids: window.__seoPublishCategoriesSnapshot?.() ?? null,
        featured_image: featured,
        product_album: productAlbum,
        faqs: Array.isArray(faqsFromEditor) ? faqsFromEditor : faqsFromBundle,
    };
}

/**
 * @param {number} articleId
 * @param {Record<string, unknown>} payload
 */
export async function saveArticleViaApi(articleId, payload) {
    const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${articleId}/save`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok || data.success === false) {
        throw new Error(data.message ?? 'Không lưu được bài viết.');
    }

    return data;
}

/**
 * @param {number} articleId
 * @param {Record<string, unknown>} payload
 */
export async function syncArticleToWordPressViaApi(articleId, payload) {
    const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${articleId}/sync-wp`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok || data.success === false) {
        throw new Error(data.message ?? 'Đồng bộ WordPress thất bại.');
    }

    return data;
}

/**
 * @param {object|null|undefined} wire
 * @param {{ title?: string, body?: string, status?: string }} notification
 */
export function notifyEditorFromApi(wire, notification) {
    const payload = {
        title: notification.title ?? '',
        body: notification.body ?? '',
        status: notification.status ?? 'success',
    };

    if (wire?.handleEditorNotify) {
        wire.handleEditorNotify(payload);

        return;
    }

    window.dispatchEvent(new CustomEvent('article-editor-notify', { detail: payload }));
}

/**
 * @param {{ reload?: boolean, clear_local_state?: boolean, message?: string }} result
 * @param {number} articleId
 * @param {number} siteId
 * @param {'save'|'sync'} action
 */
export function finishArticleEditorApiAction(result, articleId, siteId, action = 'save') {
    if (result.reload) {
        window.__seoArticleHeavyActionOverlay?.show(action, { persistUntilUnload: true });
        if (result.clear_local_state) {
            window.__seoClearArticleLocalState?.(articleId, siteId);
        }
        window.location.reload();

        return;
    }

    window.__seoEndArticleHeavyActionClient?.();
}
