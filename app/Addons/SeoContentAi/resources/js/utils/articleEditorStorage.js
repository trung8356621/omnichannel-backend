const draftKey = (articleId) => `seo_article_draft_${articleId}`;
const historyKey = (articleId) => `seo_article_history_${articleId}`;
const outlineKey = (articleId) => `seo_article_outline_${articleId}`;
const chatKey = (articleId) => `seo_article_chat_${articleId}`;

export function loadDraft(articleId) {
    if (!articleId) return null;
    try {
        const raw = localStorage.getItem(draftKey(articleId));
        if (!raw) return null;
        const data = JSON.parse(raw);
        return data && typeof data === 'object' ? data : null;
    } catch {
        return null;
    }
}

export function saveDraft(articleId, payload) {
    if (!articleId) return;
    try {
        localStorage.setItem(
            draftKey(articleId),
            JSON.stringify({
                ...payload,
                updatedAt: Date.now(),
            }),
        );
    } catch (e) {
        console.warn('Không lưu được nháp localStorage', e);
    }
}

export function loadHistory(articleId) {
    if (!articleId) return { past: [], future: [] };
    try {
        const raw = localStorage.getItem(historyKey(articleId));
        if (!raw) return { past: [], future: [] };
        const data = JSON.parse(raw);
        return {
            past: Array.isArray(data?.past) ? data.past : [],
            future: Array.isArray(data?.future) ? data.future : [],
        };
    } catch {
        return { past: [], future: [] };
    }
}

export function saveHistory(articleId, past, future) {
    if (!articleId) return;
    try {
        localStorage.setItem(
            historyKey(articleId),
            JSON.stringify({
                past: past ?? [],
                future: future ?? [],
                updatedAt: Date.now(),
            }),
        );
    } catch (e) {
        console.warn('Không lưu được lịch sử localStorage', e);
    }
}

export function loadOutline(articleId) {
    if (!articleId) return null;
    try {
        const raw = localStorage.getItem(outlineKey(articleId));
        if (raw === null) return null;
        const data = JSON.parse(raw);
        return typeof data?.markdown === 'string' ? data.markdown : null;
    } catch {
        return null;
    }
}

export function saveOutline(articleId, markdown) {
    if (!articleId) return;
    try {
        localStorage.setItem(
            outlineKey(articleId),
            JSON.stringify({
                markdown: markdown ?? '',
                updatedAt: Date.now(),
            }),
        );
    } catch (e) {
        console.warn('Không lưu được dàn ý localStorage', e);
    }
}

/**
 * @returns {Array<{id: number, role: 'user'|'assistant', content: string, quote?: string, ts: number}>}
 */
export function loadChat(articleId) {
    if (!articleId) return [];
    try {
        const raw = localStorage.getItem(chatKey(articleId));
        if (!raw) return [];
        const data = JSON.parse(raw);
        return Array.isArray(data?.messages) ? data.messages : [];
    } catch {
        return [];
    }
}

export function saveChat(articleId, messages) {
    if (!articleId) return;
    try {
        localStorage.setItem(
            chatKey(articleId),
            JSON.stringify({
                messages: messages ?? [],
                updatedAt: Date.now(),
            }),
        );
    } catch (e) {
        console.warn('Không lưu được chat localStorage', e);
    }
}
