import {
    buildArticleEditorApiPayload,
    finishArticleSaveFromApi,
    saveArticleViaApi,
} from './articleEditorApi';

/**
 * Single-flight save — at most one active request; further calls queue one final round
 * with the latest payloadFactory result.
 */
let activeSavePromise = null;
let pendingRoundPromise = null;
/** @type {null | (() => Record<string, unknown> | Promise<Record<string, unknown>>)} */
let latestPayloadFactory = null;
let latestArticleId = null;

async function runSingleFlightSave() {
    const articleId = latestArticleId;
    const factory = latestPayloadFactory;
    const payload = typeof factory === 'function' ? await factory() : {};

    if (typeof window !== 'undefined' && payload?.html) {
        window.__SEO_EDITOR_LAST_SAVE_HTML__ = String(payload.html);
    }

    activeSavePromise = saveArticleViaApi(articleId, payload).finally(() => {
        activeSavePromise = null;
    });

    return activeSavePromise;
}

/**
 * @param {number} articleId
 * @param {() => Record<string, unknown> | Promise<Record<string, unknown>>} payloadFactory
 * @returns {Promise<Record<string, unknown>>}
 */
export function saveArticleViaApiSingleFlight(articleId, payloadFactory) {
    latestArticleId = articleId;
    latestPayloadFactory = payloadFactory;

    if (activeSavePromise) {
        if (!pendingRoundPromise) {
            pendingRoundPromise = activeSavePromise
                .catch(() => null)
                .then(() => {
                    pendingRoundPromise = null;

                    return runSingleFlightSave();
                });
        }

        return pendingRoundPromise;
    }

    return runSingleFlightSave();
}

/** True nếu có request save đang chạy (round hiện tại hoặc round kế đã xếp hàng). */
export function isArticleSaveInFlight() {
    return activeSavePromise !== null || pendingRoundPromise !== null;
}

/**
 * Save toàn bộ editor hiện tại (await) — dùng trước Fix slug all / action cần DB mới nhất.
 * Không bật/tắt heavy overlay (caller tự quản lý UI busy).
 *
 * @param {{ wire?: object|null, reason?: string, siteId?: number, keepOverlay?: boolean, silentNotification?: boolean }} [options]
 * @returns {Promise<Record<string, unknown>>}
 */
export async function saveCurrentArticleFromEditor(options = {}) {
    const collect = window.__seoCollectEditorHeavyBundle;
    if (typeof collect !== 'function') {
        throw new Error('Editor chưa sẵn sàng — tải lại trang rồi thử lại.');
    }

    const editorBundle = await collect({ renameImagesBeforeWpSync: false });
    const html = String(editorBundle?.html ?? '').trim();
    if (!html) {
        throw new Error('Không thu thập được nội dung bài viết.');
    }

    const articleId = Number(editorBundle?.articleId ?? 0);
    if (!Number.isFinite(articleId) || articleId <= 0) {
        throw new Error('Không xác định được ID bài viết.');
    }

    let siteId = Number(options.siteId ?? window.__SEO_ARTICLE_SITE_ID__ ?? 0) || 0;
    if (siteId <= 0) {
        try {
            const metaEl = document.getElementById('seo-article-meta');
            const meta = metaEl?.textContent?.trim() ? JSON.parse(metaEl.textContent) : {};
            siteId = Number(meta?.site_id ?? 0) || 0;
        } catch {
            siteId = 0;
        }
    }

    const result = await saveArticleViaApiSingleFlight(articleId, async () => {
        let bundle = editorBundle;
        try {
            const fresh = await collect({ renameImagesBeforeWpSync: false });
            if (fresh && String(fresh.html ?? '').trim() !== '') {
                bundle = fresh;
            }
        } catch {
            bundle = editorBundle;
        }

        return buildArticleEditorApiPayload(bundle, options.wire ?? null);
    });

    finishArticleSaveFromApi(result, {
        articleId,
        siteId,
        connectionHash: window.__SEO_EDITOR_CONNECTION_HASH__ ?? '',
        savedHtml: String(window.__SEO_EDITOR_LAST_SAVE_HTML__ ?? editorBundle.html ?? ''),
        reason: options.reason ?? 'editor_action',
        keepOverlay: options.keepOverlay === true,
        silentNotification: options.silentNotification === true,
    });

    return result;
}
