import { seoArticleApiFetch } from './seoArticleApi.js';

/**
 * @returns {Promise<{ reasons: Array<{id:number,name:string,default_severity:string,description?:string|null}>, can_override_severity: boolean }>}
 */
export async function fetchKeywordReviewReasons() {
    const { response, data } = await seoArticleApiFetch('/api/seo/keywords/review-reasons', {
        method: 'GET',
    });

    if (!response.ok || data?.success !== true) {
        throw new Error(data?.message ?? 'Unable to load keyword review reasons.');
    }

    return {
        reasons: Array.isArray(data.reasons) ? data.reasons : [],
        can_override_severity: Boolean(data.can_override_severity),
    };
}

/**
 * @param {number} keywordId
 * @param {{
 *   reason_id?: number|null,
 *   custom_reason_text?: string|null,
 *   severity: string,
 *   note?: string|null,
 *   article_id?: number|null,
 *   source?: string
 * }} payload
 */
export async function submitKeywordReview(keywordId, payload) {
    const body = {
        severity: payload.severity,
        article_id: payload.article_id ?? null,
        source: payload.source ?? 'article_suggestion',
    };

    if (payload.reason_id != null && Number(payload.reason_id) > 0) {
        body.reason_id = Number(payload.reason_id);
    } else if (payload.custom_reason_text != null && String(payload.custom_reason_text).trim() !== '') {
        body.custom_reason_text = String(payload.custom_reason_text).trim();
    }

    if (payload.note != null && String(payload.note).trim() !== '') {
        body.note = String(payload.note).trim();
    }

    const { response, data } = await seoArticleApiFetch(`/api/seo/keywords/${keywordId}/review`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });

    if (!response.ok || data?.success !== true) {
        throw new Error(data?.message ?? 'Unable to submit keyword review.');
    }

    return data.keyword ?? null;
}

/**
 * @param {number} keywordId
 * @param {{ note?: string|null, source?: string }} [payload]
 */
export async function restoreKeywordReview(keywordId, payload = {}) {
    const { response, data } = await seoArticleApiFetch(`/api/seo/keywords/${keywordId}/restore`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });

    if (!response.ok || data?.success !== true) {
        throw new Error(data?.message ?? 'Unable to restore keyword.');
    }

    return data.keyword ?? null;
}
