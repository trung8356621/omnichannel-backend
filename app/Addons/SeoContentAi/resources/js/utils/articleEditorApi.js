import { csrfToken, seoArticleApiFetch } from './seoArticleApi.js';

/**
 * @param {object|null|undefined} wire Livewire snapshot (read-only properties, không gọi method)
 * @return {{ title: string, slug: string, seo_meta_description: string, focus_keyword: string }}
 */
export function readArticleMetaFromWire(wire) {
    if (!wire) {
        return readArticleMetaFromDom();
    }

    return {
        title: String(wire.articleTitle ?? '').trim(),
        slug: String(wire.articleSlug ?? '').trim(),
        seo_meta_description: String(wire.seoMetaDescription ?? '').trim(),
        focus_keyword: String(wire.focusKeyword ?? '').trim(),
    };
}

/**
 * Đọc meta từ DOM/Livewire snapshot mà không gọi $wire method.
 */
export function readArticleMetaFromDom() {
    const titleInput = document.querySelector('.seo-article-edit-page input[wire\\:model\\.blur="articleTitle"]');
    const slugInput = document.querySelector('.seo-article-edit-page input[data-seo-article-slug-input]');

    let focusKeyword = '';
    let seoMetaDescription = '';

    try {
        const pageRoot = document.querySelector('.seo-article-edit-page[wire\\:id]');
        const wireId = pageRoot?.getAttribute('wire:id');
        const component = typeof Livewire !== 'undefined' && wireId ? Livewire.find(wireId) : null;
        if (component) {
            focusKeyword = String(component.get?.('focusKeyword') ?? component.focusKeyword ?? '').trim();
            seoMetaDescription = String(component.get?.('seoMetaDescription') ?? component.seoMetaDescription ?? '').trim();
        }
    } catch {
        /* ignore */
    }

    return {
        title: String(titleInput?.value ?? '').trim(),
        slug: String(slugInput?.value ?? '').trim(),
        seo_meta_description: seoMetaDescription,
        focus_keyword: focusKeyword,
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

const TOAST_DEDUPE_MS = 2200;
/** @type {{ key: string, at: number } | null} */
let lastEditorToastFingerprint = null;

/**
 * Toast Filament thuần JS — không qua Livewire.
 * Bỏ toast rỗng (trắng) + dedupe ngắn để tránh lặp.
 *
 * @param {{ title?: string, body?: string, status?: string }|null|undefined} notification
 */
export function showArticleEditorFilamentToast(notification) {
    if (!notification || typeof notification !== 'object') {
        return;
    }

    if (typeof window.FilamentNotification === 'undefined') {
        return;
    }

    const title = String(notification.title ?? '').trim();
    const body = String(notification.body ?? '').trim();
    if (title === '' && body === '') {
        return;
    }

    const status = String(notification.status ?? 'success').trim() || 'success';
    const key = `${status}|${title}|${body}`;
    const now = Date.now();
    if (
        lastEditorToastFingerprint
        && lastEditorToastFingerprint.key === key
        && now - lastEditorToastFingerprint.at < TOAST_DEDUPE_MS
    ) {
        return;
    }
    lastEditorToastFingerprint = { key, at: now };

    const toast = new window.FilamentNotification();
    if (title !== '') {
        toast.title(title);
    }
    if (body !== '') {
        toast.body(body);
    }

    if (status === 'danger' || status === 'error') {
        toast.danger();
    } else if (status === 'warning') {
        toast.warning();
    } else if (status === 'info') {
        toast.info();
    } else {
        toast.success();
    }

    toast.send();
}

if (typeof window !== 'undefined') {
    window.__seoShowArticleEditorToast = showArticleEditorFilamentToast;
}

/**
 * @param {Record<string, unknown>} patch
 */
export function applyArticleEditorSavePatch(patch) {
    if (!patch || typeof patch !== 'object') {
        return;
    }

    window.dispatchEvent(new CustomEvent('article-editor-save-patched', { detail: patch }));

    const article = patch.article ?? {};
    if (article.updated_at_label) {
        document.querySelectorAll('[data-seo-article-updated-at]').forEach((el) => {
            el.textContent = String(article.updated_at_label);
        });
    }

    if (article.seo_score != null) {
        document.querySelectorAll('[data-seo-article-score]').forEach((el) => {
            el.textContent = String(article.seo_score);
        });
        window.dispatchEvent(
            new CustomEvent('seo-article-score-patched', {
                detail: { score: Number(article.seo_score) },
            }),
        );
    }

    if (patch.flags && typeof patch.flags === 'object') {
        window.dispatchEvent(
            new CustomEvent('article-editor-flags-patched', {
                detail: patch.flags,
            }),
        );
    }

    if (patch.seo_analysis && typeof patch.seo_analysis === 'object') {
        window.dispatchEvent(
            new CustomEvent('seo-editor-analyze-result', {
                detail: { result: patch.seo_analysis },
            }),
        );
    }

    if (patch.revision_count != null) {
        window.dispatchEvent(
            new CustomEvent('article-revisions-changed', {
                detail: { count: Number(patch.revision_count) },
            }),
        );

        const revisionCountEl = document.querySelector('[data-seo-revision-count]');
        if (revisionCountEl) {
            revisionCountEl.textContent = String(Number(patch.revision_count));
        }
    }
}

function resetEditArticleHeavyActionBusyOnWire() {
    if (typeof Livewire === 'undefined') {
        return;
    }

    const wireId =
        String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '').trim()
        || document.querySelector('.seo-article-edit-page[wire\\:id]')?.getAttribute('wire:id')
        || document.querySelector('.seo-article-edit-page [wire\\:id]')?.getAttribute('wire:id')
        || '';

    if (wireId === '') {
        return;
    }

    const component = Livewire.find(wireId);
    if (!component?.call) {
        return;
    }

    const busy = Boolean(component.get?.('articleHeavyActionBusy') ?? component.articleHeavyActionBusy);
    if (!busy) {
        return;
    }

    void component.call('cancelHeavyArticleAction');
}

/**
 * Hoàn tất Save — không reload, không Livewire.
 *
 * @param {{ patch?: Record<string, unknown>, notification?: Record<string, string> }} result
 */
export function finishArticleSaveFromApi(result) {
    if (result.patch) {
        applyArticleEditorSavePatch(result.patch);
    }

    if (result.notification) {
        showArticleEditorFilamentToast(result.notification);
    }

    window.__seoEndArticleHeavyActionClient?.();
    resetEditArticleHeavyActionBusyOnWire();
    window.dispatchEvent(new CustomEvent('article-editor-save-finished'));
}

/**
 * Hoàn tất Sync WP — vẫn reload trang sau khi đồng bộ.
 *
 * @param {{ reload?: boolean, clear_local_state?: boolean, notification?: Record<string, string> }} result
 * @param {number} articleId
 * @param {number} siteId
 */
export function finishArticleSyncFromApi(result, articleId, siteId) {
    if (result.notification) {
        showArticleEditorFilamentToast(result.notification);
    }

    if (result.queued) {
        window.__seoEndArticleHeavyActionClient?.();
        window.dispatchEvent(new CustomEvent('article-wordpress-sync-queued', { detail: result }));

        return;
    }

    if (result.reload) {
        window.__seoArticleHeavyActionOverlay?.show('sync', { persistUntilUnload: true });
        if (result.clear_local_state) {
            window.__seoClearArticleLocalState?.(articleId, siteId);
        }
        window.location.reload();

        return;
    }

    window.__seoEndArticleHeavyActionClient?.();
}

/** @deprecated Sync-only — Save dùng finishArticleSaveFromApi */
export function finishArticleEditorApiAction(result, articleId, siteId, action = 'save') {
    if (action === 'sync') {
        finishArticleSyncFromApi(result, articleId, siteId);

        return;
    }

    finishArticleSaveFromApi(result);
}

/** @deprecated Save không gọi Livewire notify */
export function notifyEditorFromApi(_wire, notification) {
    showArticleEditorFilamentToast(notification);
}
