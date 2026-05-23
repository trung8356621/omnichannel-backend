const draftKey = (articleId) => `seo_article_draft_${articleId}`;
const historyKey = (articleId) => `seo_article_history_${articleId}`;
const outlineKey = (articleId) => `seo_article_outline_${articleId}`;
const chatKey = (articleId) => `seo_article_chat_${articleId}`;
const STORAGE_PREFIX = 'seo_article_';

function isQuotaExceededError(error) {
    if (!error) return false;
    return (
        error?.name === 'QuotaExceededError' ||
        error?.name === 'NS_ERROR_DOM_QUOTA_REACHED' ||
        error?.code === 22 ||
        error?.code === 1014
    );
}

function getSeoStorageKeys() {
    const keys = [];
    for (let i = 0; i < localStorage.length; i += 1) {
        const key = localStorage.key(i);
        if (key?.startsWith(STORAGE_PREFIX)) {
            keys.push(key);
        }
    }
    return keys;
}

function storageUpdatedAt(key) {
    try {
        const raw = localStorage.getItem(key);
        if (!raw) return 0;
        const parsed = JSON.parse(raw);
        return Number(parsed?.updatedAt ?? 0);
    } catch {
        return 0;
    }
}

function pruneSeoStorage(currentKey, pass = 0) {
    const keys = getSeoStorageKeys().filter((key) => key !== currentKey);
    if (!keys.length) return;

    if (pass === 0) {
        keys.filter((key) => key.includes('_history_') || key.includes('_chat_')).forEach((key) => localStorage.removeItem(key));
        return;
    }

    const sorted = keys.sort((a, b) => storageUpdatedAt(a) - storageUpdatedAt(b));

    if (pass === 1) {
        sorted.slice(0, Math.ceil(sorted.length / 2)).forEach((key) => localStorage.removeItem(key));
        return;
    }

    sorted.forEach((key) => localStorage.removeItem(key));
}

function setItemWithPrune(key, value, label, fallbackValue = null) {
    for (let pass = 0; pass < 3; pass += 1) {
        try {
            localStorage.setItem(key, value);
            return true;
        } catch (error) {
            if (!isQuotaExceededError(error)) {
                console.warn(`Không lưu được ${label} localStorage`, error);
                return false;
            }
            pruneSeoStorage(key, pass);
        }
    }

    if (fallbackValue !== null) {
        for (let pass = 0; pass < 2; pass += 1) {
            try {
                localStorage.setItem(key, fallbackValue);
                return true;
            } catch (error) {
                if (!isQuotaExceededError(error)) {
                    console.warn(`Không lưu được ${label} localStorage`, error);
                    return false;
                }
                pruneSeoStorage(key, pass + 1);
            }
        }
    }

    console.warn(`Bỏ qua lưu ${label} localStorage vì vượt quota`);
    return false;
}

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
    const updatedAt = Date.now();
    const fullPayload = {
        ...payload,
        updatedAt,
    };
    const fallbackPayload = {
        html: typeof payload?.html === 'string' ? payload.html : '',
        updatedAt,
    };

    try {
        setItemWithPrune(
            draftKey(articleId),
            JSON.stringify(fullPayload),
            'nháp',
            JSON.stringify(fallbackPayload),
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
        setItemWithPrune(
            historyKey(articleId),
            JSON.stringify({
                past: past ?? [],
                future: future ?? [],
                updatedAt: Date.now(),
            }),
            'lịch sử',
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
        setItemWithPrune(
            outlineKey(articleId),
            JSON.stringify({
                markdown: markdown ?? '',
                updatedAt: Date.now(),
            }),
            'dàn ý',
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
        setItemWithPrune(
            chatKey(articleId),
            JSON.stringify({
                messages: messages ?? [],
                updatedAt: Date.now(),
            }),
            'chat',
        );
    } catch (e) {
        console.warn('Không lưu được chat localStorage', e);
    }
}
