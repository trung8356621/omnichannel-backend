import { csrfToken, seoArticleApiFetch } from './seoArticleApi.js';
import { clearDraft, hashContent, loadDraft } from './articleEditorStorage.js';

/**
 * Token conflict hiện tại (expected_updated_at / expected_content_hash) — bootstrap từ
 * meta server (article-editor.jsx), cập nhật lại sau mỗi lần save thành công.
 * @returns {{ expected_updated_at: string|null, expected_content_hash: string|null }}
 */
export function getEditorConflictTokens() {
    const tokens = window.__SEO_EDITOR_CONFLICT__;

    return tokens && typeof tokens === 'object'
        ? tokens
        : { expected_updated_at: null, expected_content_hash: null };
}

/**
 * @param {{ expected_updated_at?: string|null, expected_content_hash?: string|null }} tokens
 */
export function setEditorConflictTokens(tokens) {
    window.__SEO_EDITOR_CONFLICT__ = {
        expected_updated_at: tokens?.expected_updated_at ?? null,
        expected_content_hash: tokens?.expected_content_hash ?? null,
    };
}

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

    const conflictTokens = getEditorConflictTokens();

    return {
        html: String(editorBundle?.html ?? ''),
        seo_analysis: editorBundle?.seoAnalysis ?? null,
        article_meta: readArticleMetaFromWire(wire),
        publish_box: window.__seoPublishBoxSnapshot?.() ?? null,
        category_ids: window.__seoPublishCategoriesSnapshot?.() ?? null,
        featured_image: featured,
        product_album: productAlbum,
        faqs: Array.isArray(faqsFromEditor) ? faqsFromEditor : faqsFromBundle,
        expected_updated_at: conflictTokens.expected_updated_at,
        expected_content_hash: conflictTokens.expected_content_hash,
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

    if (response.status === 409) {
        const error = new Error(data?.message ?? 'Nội dung đã bị thay đổi ở nơi khác — không lưu.');
        error.conflict = true;
        error.data = data;

        throw error;
    }

    if (!response.ok || data.success === false) {
        throw new Error(data.message ?? 'Không lưu được bài viết.');
    }

    return data;
}

/**
 * @param {number} articleId
 * @param {{ focus_keyword?: string, meta_description?: string, slug?: string }} payload
 */
export async function saveSeoMetaViaApi(articleId, payload) {
    const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${articleId}/seo-meta`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok || data.success === false) {
        throw new Error(data.message ?? 'Không lưu được trường SEO.');
    }

    return data;
}

/**
 * Chạy Prompt Hook (không lưu article / SEO / WP).
 *
 * @param {string} hookKey vd. article.title_suggestion
 * @param {number} articleId
 * @param {Record<string, unknown>} [input] runtime overrides
 * @returns {Promise<{ success: true, data: { hook: string, output: { format: string, raw: string, value: string } } }>}
 */
export async function executePromptHookViaApi(hookKey, articleId, input = {}) {
    const encodedKey = encodeURIComponent(String(hookKey ?? '').trim());
    const { response, data } = await seoArticleApiFetch(`/api/seo/prompt-hooks/${encodedKey}/execute`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            article_id: Number(articleId),
            input: input && typeof input === 'object' ? input : {},
        }),
    });

    if (!response.ok || data.success === false) {
        const err = new Error(data.message ?? 'Prompt Hook thất bại.');
        err.code = data.error ?? 'HOOK_EXECUTION_FAILED';
        err.status = response.status;
        throw err;
    }

    return data;
}

function resolveEditArticleLivewireComponent() {
    if (typeof Livewire === 'undefined') {
        return null;
    }

    const wireId =
        String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '').trim()
        || document.querySelector('.seo-article-edit-page[wire\\:id]')?.getAttribute('wire:id')
        || document.querySelector('.seo-article-edit-page [wire\\:id]')?.getAttribute('wire:id')
        || '';

    if (wireId === '') {
        return null;
    }

    const component = Livewire.find(wireId);

    return component?.set || component?.call ? component : null;
}

/**
 * Đồng bộ meta SEO lên Livewire snapshot (không gọi method server).
 *
 * @param {{ focus_keyword?: string, meta_description?: string, article_slug?: string }} patch
 */
export function patchLivewireSeoMeta(patch) {
    if (!patch || typeof patch !== 'object') {
        return;
    }

    const component = resolveEditArticleLivewireComponent();
    if (!component) {
        return;
    }

    if (patch.focus_keyword != null) {
        component.set('focusKeyword', String(patch.focus_keyword).trim());
    }

    if (patch.meta_description != null) {
        component.set('seoMetaDescription', String(patch.meta_description).trim());
    }

    if (patch.article_slug != null) {
        component.set('articleSlug', String(patch.article_slug).trim());
    }
}

/**
 * Ghép URL hiển thị từ base + slug + suffix (vd. `.html`).
 *
 * @param {string} base
 * @param {string} slug
 * @param {string} suffix
 */
export function buildPermalinkDisplayUrl(base, slug, suffix = '') {
    const host = String(base ?? '').trim().replace(/\/+$/, '');
    const normalizedSlug = String(slug ?? '').trim().replace(/^\/+|\/+$/g, '');
    const suf = String(suffix ?? '').trim();

    if (host === '' || normalizedSlug === '') {
        return '';
    }

    if (suf !== '' && suf.startsWith('.')) {
        return `${host}/${normalizedSlug}${suf}`;
    }

    if (suf !== '' && suf !== '/') {
        const pathSuffix = suf.startsWith('/') ? suf : `/${suf}`;

        return `${host}/${normalizedSlug}${pathSuffix}`;
    }

    return `${host}/${normalizedSlug}/`;
}

/**
 * Cập nhật dòng «Đường dẫn» dưới tiêu đề (`.wp-permalink`) + slug input nếu có.
 *
 * @param {{
 *   permalink?: string,
 *   article_slug?: string,
 *   slug?: string,
 *   permalink_base?: string,
 *   permalink_suffix?: string,
 * }} patch
 */
export function patchPermalinkDisplay(patch) {
    if (!patch || typeof patch !== 'object') {
        return;
    }

    const slug = String(patch.article_slug ?? patch.slug ?? '').trim();
    const root = document.querySelector('[data-seo-permalink-root], .wp-permalink');
    const baseFromDom = String(root?.getAttribute('data-permalink-base') ?? '').trim();
    const suffixFromDom = String(root?.getAttribute('data-permalink-suffix') ?? '').trim();

    const base = String(patch.permalink_base ?? baseFromDom).trim().replace(/\/+$/, '');
    const suffix = String(patch.permalink_suffix ?? suffixFromDom).trim();

    let permalink = String(patch.permalink ?? '').trim();
    if (permalink === '' && slug !== '') {
        permalink = buildPermalinkDisplayUrl(base, slug, suffix);
    }

    if (slug !== '') {
        const slugInput = document.querySelector(
            '.seo-article-edit-page input[data-seo-article-slug-input]',
        );
        if (slugInput instanceof HTMLInputElement) {
            slugInput.value = slug;
        }

        if (root) {
            root.setAttribute('data-article-slug', slug);
        }
    }

    if (base !== '' && root) {
        root.setAttribute('data-permalink-base', base);
    }

    if (root && patch.permalink_suffix != null) {
        root.setAttribute('data-permalink-suffix', suffix);
    }

    if (permalink === '') {
        return;
    }

    const target = root?.querySelector('[data-seo-permalink-url]')
        ?? root?.querySelector('a')
        ?? root?.querySelector('span.break-all');

    if (!target) {
        return;
    }

    target.textContent = permalink;
    if (target instanceof HTMLAnchorElement) {
        target.href = permalink;
    }
}

/**
 * @param {Record<string, unknown>} result
 */
export function applyArticleSeoMetaSaveResult(result) {
    if (!result || typeof result !== 'object') {
        return;
    }

    const preview = result.google_serp_preview ?? null;
    if (preview && typeof preview === 'object') {
        window.dispatchEvent(
            new CustomEvent('google-serp-preview-updated', {
                detail: { preview },
            }),
        );
    }

    if (result.focus_keyword != null) {
        window.dispatchEvent(
            new CustomEvent('seo-focus-keyword-updated', {
                detail: { focus_keyword: result.focus_keyword },
            }),
        );
    }

    const slug = String(result.article_slug ?? '').trim();
    const permalink = String(
        result.permalink
            ?? preview?.url
            ?? '',
    ).trim();

    patchPermalinkDisplay({
        permalink,
        article_slug: slug,
        permalink_base: result.permalink_base,
        permalink_suffix: result.permalink_suffix,
    });

    if (slug !== '') {
        window.dispatchEvent(
            new CustomEvent('seo-editor-slug-updated', {
                detail: {
                    slug,
                    article_slug: slug,
                    permalink,
                    permalink_base: result.permalink_base,
                    permalink_suffix: result.permalink_suffix,
                },
            }),
        );
    }

    patchLivewireSeoMeta({
        focus_keyword: result.focus_keyword,
        meta_description: result.meta_description ?? preview?.description ?? undefined,
        article_slug: result.article_slug,
    });

    if (result.seo_analysis_pending) {
        window.dispatchEvent(
            new CustomEvent('article-editor-save-patched', {
                detail: { seo_analysis_pending: true },
            }),
        );
    }
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

    const payloadData = data && typeof data === 'object' ? data : {};

    // Gate blocked / fail: server đã gắn notification — phải toast trước khi throw.
    if (payloadData.notification) {
        showArticleEditorFilamentToast(payloadData.notification);
        payloadData.notificationShown = true;
    }

    if (!response.ok || payloadData.success === false) {
        const message = String(
            payloadData.message
                ?? payloadData.notification?.body
                ?? 'Đồng bộ WordPress thất bại.',
        );
        const error = new Error(message);
        error.automationStatus = String(payloadData.status ?? 'blocked');
        error.notificationShown = Boolean(payloadData.notification);
        error.payload = payloadData;
        throw error;
    }

    return payloadData;
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
 * Perf Phase 1 (F+G): sau khi save thành công, cập nhật lại token conflict
 * (expected_updated_at/expected_content_hash) theo nội dung VỪA lưu, và chỉ xóa nháp
 * local nếu nháp hiện tại (localStorage) trùng đúng nội dung vừa lưu — tránh mất nháp
 * của 1 lượt gõ mới hơn xảy ra trong lúc request save đang chạy.
 *
 * @param {{ patch?: Record<string, unknown>, notification?: Record<string, string> }} result
 * @param {{ articleId?: number, connectionHash?: string, savedHtml?: string }} [context]
 */
export function finishArticleSaveFromApi(result, context = {}) {
    if (result.patch) {
        applyArticleEditorSavePatch(result.patch);
    }

    if (result.notification) {
        showArticleEditorFilamentToast(result.notification);
    }

    const { articleId, connectionHash, savedHtml } = context;
    if (typeof savedHtml === 'string') {
        const savedContentHash = hashContent(savedHtml);
        setEditorConflictTokens({
            expected_updated_at: result.patch?.article?.updated_at ?? getEditorConflictTokens().expected_updated_at,
            expected_content_hash: savedContentHash,
        });

        if (articleId) {
            const draft = loadDraft(articleId, connectionHash);
            if (draft && draft.content_hash === savedContentHash) {
                clearDraft(articleId, connectionHash);
            }
        }
    }

    window.__seoEndArticleHeavyActionClient?.();
    resetEditArticleHeavyActionBusyOnWire();
    window.dispatchEvent(new CustomEvent('article-editor-save-finished'));
}

/**
 * Xử lý khi save trả 409 (conflict) — KHÔNG reload, KHÔNG clearDraft. Dispatch event để
 * UI (SeoArticleEditor) hiển thị modal/alert cho người dùng quyết định.
 *
 * @param {Error & { conflict?: boolean, data?: Record<string, unknown> }} error
 */
export function handleArticleSaveConflict(error) {
    window.__seoEndArticleHeavyActionClient?.();
    resetEditArticleHeavyActionBusyOnWire();

    const notification = error?.data?.notification ?? {
        title: 'Xung đột khi lưu',
        body: error?.message ?? 'Nội dung đã bị thay đổi ở nơi khác.',
        status: 'warning',
    };
    showArticleEditorFilamentToast(notification);

    window.dispatchEvent(
        new CustomEvent('seo-article-save-conflict', {
            detail: { conflict: error?.data?.conflict ?? null, message: error?.message ?? '' },
        }),
    );
    window.dispatchEvent(new CustomEvent('article-editor-save-finished', { detail: { conflict: true } }));
}

/**
 * Optional helper — navigate to Sync Queue list (no forced close).
 * Kept for manual "View queue" actions; enqueue success stays on editor + poll.
 *
 * @returns {string}
 */
export function resolveSyncQueueListUrl() {
    const configured = typeof window.__SEO_ARTICLES_SYNC_QUEUE_URL__ === 'string'
        ? window.__SEO_ARTICLES_SYNC_QUEUE_URL__.trim()
        : '';
    if (configured !== '') {
        return configured;
    }

    const indexUrl = typeof window.__SEO_ARTICLES_LIST_URL__ === 'string'
        ? window.__SEO_ARTICLES_LIST_URL__.trim()
        : '';
    if (indexUrl !== '') {
        const joiner = indexUrl.includes('?') ? '&' : '?';

        return `${indexUrl}${joiner}tab=queue`;
    }

    return '/seo/articles?tab=queue';
}

/**
 * Hoàn tất Sync WP — toast, overlay, poll tới terminal. Không đóng tab / không redirect.
 *
 * @param {{ reload?: boolean, clear_local_state?: boolean, queued?: boolean, notification?: Record<string, string>, operation?: object, notificationShown?: boolean }} result
 * @param {number} articleId
 * @param {number} siteId
 */
export function finishArticleSyncFromApi(result, articleId, siteId) {
    if (result.notification && result.notificationShown !== true) {
        showArticleEditorFilamentToast(result.notification);
    }

    if (result.queued) {
        window.__seoArticleHeavyActionOverlay?.show('sync', {
            persistUntilUnload: true,
            title: 'Đang chờ đồng bộ WordPress',
            message: 'Yêu cầu đã được đưa vào hàng đợi. Vui lòng không chỉnh sửa bài viết trong lúc đồng bộ.',
        });
        window.__seoArticleAutosaveLock?.set('article-operation', true);
        window.dispatchEvent(new CustomEvent('article-wordpress-sync-queued', { detail: result }));
        window.__seoArticleOperationTracker?.apply?.(articleId, result.operation ?? {
            type: 'wordpress_sync',
            status: 'queued',
            stage: 'queued',
        });
        window.__seoArticleOperationTracker?.poll?.(articleId);

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

/**
 * Load WordPress product reviews for Edit Article (source of truth).
 * @param {number} articleId
 */
export async function fetchWordPressProductReviews(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, message: 'Invalid article id' };
    }

    const { response, data } = await seoArticleApiFetch(
        `/api/seo/articles/${id}/wordpress-product-reviews`,
        {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
            },
        },
    );

    const payloadData = data && typeof data === 'object' ? data : {};
    if (!response.ok || payloadData.success === false) {
        return {
            success: false,
            message: String(payloadData.message ?? 'Không thể tải đánh giá từ WordPress.'),
            data: payloadData.data,
        };
    }

    return {
        success: true,
        data: payloadData.data && typeof payloadData.data === 'object' ? payloadData.data : payloadData,
    };
}

/**
 * Shared backend policy status for product reviews.
 * @param {number} articleId
 */
export async function fetchProductReviewStatus(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, message: 'Invalid article id' };
    }

    const { response, data } = await seoArticleApiFetch(
        `/api/seo/articles/${id}/product-review-status`,
        {
            method: 'GET',
            headers: { 'X-CSRF-TOKEN': csrfToken() },
        },
    );

    const payloadData = data && typeof data === 'object' ? data : {};
    if (!response.ok || payloadData.success === false) {
        return {
            success: false,
            message: String(payloadData.message ?? 'Không thể tải trạng thái đánh giá.'),
            data: payloadData.data,
        };
    }

    return {
        success: true,
        data: payloadData.data && typeof payloadData.data === 'object' ? payloadData.data : payloadData,
    };
}

/**
 * @param {number} articleId
 * @param {Record<string, unknown>} [body]
 */
export async function createProductReviewsForArticle(articleId, body = {}) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, message: 'Invalid article id' };
    }

    const { response, data } = await seoArticleApiFetch(
        `/api/seo/articles/${id}/product-reviews/create`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(body ?? {}),
        },
    );

    const payloadData = data && typeof data === 'object' ? data : {};
    return {
        success: response.ok && payloadData.success !== false,
        message: String(payloadData.message ?? ''),
        data: payloadData.data,
        status: payloadData.status,
    };
}

/**
 * @param {number} articleId
 */
export async function syncProductReviewsForArticle(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, message: 'Invalid article id' };
    }

    const { response, data } = await seoArticleApiFetch(
        `/api/seo/articles/${id}/product-reviews/sync`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: '{}',
        },
    );

    const payloadData = data && typeof data === 'object' ? data : {};
    return {
        success: response.ok && payloadData.success !== false,
        message: String(payloadData.message ?? ''),
        data: payloadData.data,
        status: payloadData.status,
    };
}

/**
 * @deprecated Prefer fetchWordPressProductReviews / fetchProductReviewStatus
 * @param {number} articleId
 */
export async function reconcileProductReviewsForArticle(articleId) {
    return fetchWordPressProductReviews(articleId);
}

