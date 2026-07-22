import { cleanBlockHtmlForEditorDisplay, stripEmptyParagraphsFromHtml } from './editorHtmlUtils';
import { pruneEmptyTextBlocks } from './blockImageUtils';

/** Schema version for the local draft (content-only, HTML canonical — matches backend `body`). */
export const ARTICLE_EDITOR_DRAFT_VERSION = 2;

const DRAFT_KEY_PREFIX = 'seo-editor:draft:';
const LEGACY_DRAFT_PREFIX = 'seo_article_draft_';
const legacyDraftKey = (articleId) => `${LEGACY_DRAFT_PREFIX}${articleId}`;
const historyKey = (articleId) => `seo_article_history_${articleId}`;
const outlineKey = (articleId) => `seo_article_outline_${articleId}`;
const chatKey = (articleId) => `seo_article_chat_${articleId}`;
const faqKey = (articleId) => `seo_article_faq_${articleId}`;
const STORAGE_PREFIX = 'seo_article_';

function draftKey(articleId, connectionHash) {
    const hash = String(connectionHash ?? '').trim() || 'default';
    return `${DRAFT_KEY_PREFIX}${hash}:${articleId}`;
}

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
        if (key?.startsWith(STORAGE_PREFIX) || key?.startsWith(DRAFT_KEY_PREFIX)) {
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
        if (typeof parsed?.saved_at === 'string') {
            const ts = Date.parse(parsed.saved_at);
            if (Number.isFinite(ts)) return ts;
        }
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

function sanitizeEditorBlock(block) {
    if (!block || typeof block !== 'object') {
        return block;
    }

    if (block.type === 'image' || block.isWp || typeof block.content !== 'string') {
        return block;
    }

    return {
        ...block,
        content: cleanBlockHtmlForEditorDisplay(block.content),
    };
}

export function sanitizeBlocksForEditor(blocks) {
    if (!Array.isArray(blocks)) {
        return blocks;
    }

    return pruneEmptyTextBlocks(blocks.map(sanitizeEditorBlock));
}

function sanitizeHistorySnapshot(snapshot) {
    if (!snapshot || typeof snapshot !== 'object' || !Array.isArray(snapshot.blocks)) {
        return snapshot;
    }

    return {
        ...snapshot,
        blocks: sanitizeBlocksForEditor(snapshot.blocks),
    };
}

/* --------------------------------------------------------------------- */
/* Sync SHA-256 (pure JS) — matches PHP hash('sha256', trim($body)).     */
/* Kept sync (no await) so it can run inline on the debounced save path. */
/* --------------------------------------------------------------------- */

const SHA256_K = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
];

function sha256Rotr(x, n) {
    return (x >>> n) | (x << (32 - n));
}

/** @returns {string} lowercase hex digest */
function sha256Hex(input) {
    const bytes = new TextEncoder().encode(input);
    const bitLen = bytes.length * 8;
    const paddedLen = Math.ceil((bytes.length + 9) / 64) * 64;
    const buffer = new Uint8Array(paddedLen);
    buffer.set(bytes);
    buffer[bytes.length] = 0x80;

    const view = new DataView(buffer.buffer);
    view.setUint32(paddedLen - 4, bitLen >>> 0, false);
    view.setUint32(paddedLen - 8, Math.floor(bitLen / 0x100000000), false);

    let [h0, h1, h2, h3, h4, h5, h6, h7] = [
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
        0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
    ];

    const w = new Uint32Array(64);
    for (let offset = 0; offset < paddedLen; offset += 64) {
        for (let i = 0; i < 16; i += 1) {
            w[i] = view.getUint32(offset + i * 4, false);
        }
        for (let i = 16; i < 64; i += 1) {
            const s0 = sha256Rotr(w[i - 15], 7) ^ sha256Rotr(w[i - 15], 18) ^ (w[i - 15] >>> 3);
            const s1 = sha256Rotr(w[i - 2], 17) ^ sha256Rotr(w[i - 2], 19) ^ (w[i - 2] >>> 10);
            w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
        }

        let [a, b, c, d, e, f, g, h] = [h0, h1, h2, h3, h4, h5, h6, h7];
        for (let i = 0; i < 64; i += 1) {
            const S1 = sha256Rotr(e, 6) ^ sha256Rotr(e, 11) ^ sha256Rotr(e, 25);
            const ch = (e & f) ^ (~e & g);
            const temp1 = (h + S1 + ch + SHA256_K[i] + w[i]) | 0;
            const S0 = sha256Rotr(a, 2) ^ sha256Rotr(a, 13) ^ sha256Rotr(a, 22);
            const maj = (a & b) ^ (a & c) ^ (b & c);
            const temp2 = (S0 + maj) | 0;

            h = g; g = f; f = e; e = (d + temp1) | 0;
            d = c; c = b; b = a; a = (temp1 + temp2) | 0;
        }

        h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
        h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
    }

    return [h0, h1, h2, h3, h4, h5, h6, h7]
        .map((n) => (n >>> 0).toString(16).padStart(8, '0'))
        .join('');
}

/**
 * Sync SHA-256 hex of trimmed HTML — mirrors PHP `hash('sha256', trim($body))`.
 * @param {string} html
 * @returns {string}
 */
export function hashContent(html) {
    try {
        return sha256Hex(String(html ?? '').trim());
    } catch {
        return '';
    }
}

/* --------------------------------------------------------------------- */
/* Draft (schema_version 2) — HTML canonical, no blocks stored.          */
/* --------------------------------------------------------------------- */

function migrateLegacyDraft(articleId) {
    try {
        const raw = localStorage.getItem(legacyDraftKey(articleId));
        if (!raw) return null;
        const legacy = JSON.parse(raw);
        const html = typeof legacy?.html === 'string' ? legacy.html.trim() : '';
        if (!html) {
            // Không thể tái tạo HTML từ blocks trong module storage (tránh phụ thuộc render logic).
            return null;
        }

        return {
            content: html,
            savedAtMs: Number(legacy?.updatedAt ?? Date.now()) || Date.now(),
        };
    } catch {
        return null;
    }
}

/**
 * @param {number|string} articleId
 * @param {string} [connectionHash]
 * @returns {{
 *   schema_version: 2,
 *   article_id: number,
 *   connection_hash: string,
 *   base_updated_at: string|null,
 *   base_content_hash: string|null,
 *   saved_at: string,
 *   title?: string,
 *   slug?: string,
 *   content: string,
 *   content_hash: string,
 *   dirty_fields: string[],
 * }|null}
 */
export function loadDraft(articleId, connectionHash) {
    if (!articleId) return null;

    const key = draftKey(articleId, connectionHash);
    try {
        const raw = localStorage.getItem(key);
        if (raw) {
            const data = JSON.parse(raw);
            if (data && typeof data === 'object' && Number(data.schema_version) === ARTICLE_EDITOR_DRAFT_VERSION) {
                return data;
            }
        }
    } catch {
        // fallthrough to legacy migration below
    }

    const migrated = migrateLegacyDraft(articleId);
    if (!migrated) {
        return null;
    }

    const draft = {
        schema_version: ARTICLE_EDITOR_DRAFT_VERSION,
        article_id: Number(articleId),
        connection_hash: String(connectionHash ?? '').trim(),
        base_updated_at: null,
        base_content_hash: null,
        saved_at: new Date(migrated.savedAtMs).toISOString(),
        content: migrated.content,
        content_hash: hashContent(migrated.content),
        dirty_fields: ['content'],
    };

    try {
        setItemWithPrune(key, JSON.stringify(draft), 'nháp');
        localStorage.removeItem(legacyDraftKey(articleId));
    } catch {
        // ignore quota / private mode — migrated draft still returned in-memory
    }

    return draft;
}

export function clearDraft(articleId, connectionHash) {
    if (!articleId) return;
    try {
        localStorage.removeItem(draftKey(articleId, connectionHash));
    } catch {
        // ignore quota / private mode
    }
}

/**
 * @param {number|string} articleId
 * @param {string} connectionHash
 * @param {{
 *   content: string,
 *   title?: string,
 *   slug?: string,
 *   base_updated_at?: string|null,
 *   base_content_hash?: string|null,
 *   dirty_fields?: string[],
 * }} payload
 */
export function saveDraft(articleId, connectionHash, payload) {
    if (!articleId) return;

    const key = draftKey(articleId, connectionHash);
    const existing = (() => {
        try {
            const raw = localStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    })();

    const content = typeof payload?.content === 'string'
        ? stripEmptyParagraphsFromHtml(payload.content)
        : String(existing?.content ?? '');
    const contentHash = hashContent(content);

    const draft = {
        schema_version: ARTICLE_EDITOR_DRAFT_VERSION,
        article_id: Number(articleId),
        connection_hash: String(connectionHash ?? '').trim(),
        base_updated_at: payload?.base_updated_at ?? existing?.base_updated_at ?? null,
        base_content_hash: payload?.base_content_hash ?? existing?.base_content_hash ?? null,
        saved_at: new Date().toISOString(),
        title: payload?.title ?? existing?.title,
        slug: payload?.slug ?? existing?.slug,
        content,
        content_hash: contentHash,
        dirty_fields: Array.isArray(payload?.dirty_fields) ? payload.dirty_fields : ['content'],
    };

    try {
        setItemWithPrune(key, JSON.stringify(draft), 'nháp', JSON.stringify({
            schema_version: ARTICLE_EDITOR_DRAFT_VERSION,
            article_id: Number(articleId),
            connection_hash: draft.connection_hash,
            saved_at: draft.saved_at,
            content,
            content_hash: contentHash,
            dirty_fields: draft.dirty_fields,
        }));
    } catch (e) {
        console.warn('Không lưu được nháp localStorage', e);
    }
}

/**
 * True nếu nên hỏi người dùng khôi phục nháp (nội dung khác server hiện tại
 * hoặc khác baseline mà nháp được tạo ra từ đó).
 *
 * @param {ReturnType<typeof loadDraft>} draft
 * @param {{ content_hash?: string, expected_content_hash?: string }} server
 */
export function draftNeedsRestore(draft, server) {
    if (!draft || typeof draft !== 'object') {
        return false;
    }

    const draftContentHash = String(draft.content_hash ?? '').trim();
    if (draftContentHash === '') {
        return false;
    }

    const serverContentHash = String(server?.content_hash ?? '').trim();
    if (serverContentHash !== '' && draftContentHash === serverContentHash) {
        return false;
    }

    const draftBaseHash = String(draft.base_content_hash ?? '').trim();
    const serverExpectedHash = String(server?.expected_content_hash ?? '').trim();
    if (serverExpectedHash !== '' && draftBaseHash !== '' && draftBaseHash !== serverExpectedHash) {
        return true;
    }

    return serverContentHash !== '' && draftContentHash !== serverContentHash;
}

export function loadHistory(articleId) {
    if (!articleId) return { past: [], future: [] };
    try {
        const raw = localStorage.getItem(historyKey(articleId));
        if (!raw) return { past: [], future: [] };
        const data = JSON.parse(raw);
        const past = Array.isArray(data?.past) ? data.past.map(sanitizeHistorySnapshot) : [];
        const future = Array.isArray(data?.future) ? data.future.map(sanitizeHistorySnapshot) : [];

        return { past, future };
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

export function loadFaqDraft(articleId) {
    if (!articleId) return null;
    try {
        const raw = localStorage.getItem(faqKey(articleId));
        if (!raw) return null;
        const data = JSON.parse(raw);

        return Array.isArray(data?.faqs) ? data.faqs : null;
    } catch {
        return null;
    }
}

export function saveFaqDraft(articleId, faqs) {
    if (!articleId) return;
    try {
        setItemWithPrune(
            faqKey(articleId),
            JSON.stringify({
                faqs: Array.isArray(faqs) ? faqs : [],
                updatedAt: Date.now(),
            }),
            'FAQ',
        );
    } catch (error) {
        console.warn('Không lưu được FAQ vào localStorage', error);
    }
}

export function clearArticleEditorStorage(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    [legacyDraftKey(id), historyKey(id), outlineKey(id), chatKey(id), faqKey(id)].forEach((key) => {
        localStorage.removeItem(key);
    });

    // connection_hash không xác định tại call site này — xóa mọi draft theo articleId, bất kể hash.
    const suffix = `:${id}`;
    getSeoStorageKeys()
        .filter((key) => key.startsWith(DRAFT_KEY_PREFIX) && key.endsWith(suffix))
        .forEach((key) => localStorage.removeItem(key));
}
