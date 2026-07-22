import { saveArticleViaApi } from './articleEditorApi';

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
