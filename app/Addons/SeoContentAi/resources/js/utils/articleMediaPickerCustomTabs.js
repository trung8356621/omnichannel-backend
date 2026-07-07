const TABS_PREFIX = 'seo-article-media-picker-custom-tabs:v1';
const STAGED_PREFIX = 'seo-article-media-picker-custom-staged:v1';
const FETCH_PREFIX = 'seo-article-media-picker-custom-fetch:v1';
const MAX_FETCH_AGE_MS = 7 * 24 * 60 * 60 * 1000;

function tabsKey(articleId) {
    return `${TABS_PREFIX}:${Number(articleId)}`;
}

function stagedKey(articleId, tabId) {
    return `${STAGED_PREFIX}:${Number(articleId)}:${String(tabId)}`;
}

function fetchKey(articleId, tabId, page, keyword) {
    const normalized = String(keyword || '').trim().toLowerCase();

    return `${FETCH_PREFIX}:${Number(articleId)}:${String(tabId)}:${Number(page)}:${normalized}`;
}

function readJson(key) {
    try {
        const raw = localStorage.getItem(key);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function writeJson(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));

        return true;
    } catch {
        return false;
    }
}

function normalizeImage(image) {
    if (!image || typeof image !== 'object') {
        return null;
    }

    const url = String(image.url || '').trim();
    if (url === '') {
        return null;
    }

    const wpId = Number(image.wp_attachment_id || 0);
    const seoId = Number(image.seo_media_id || 0);
    const pickerKey = String(
        image.picker_key
            || `staged-wp-${wpId}-seo-${seoId}-${url}`,
    );

    return {
        picker_key: pickerKey,
        id: Number(image.id || (wpId > 0 ? wpId : seoId)),
        wp_attachment_id: wpId,
        seo_media_id: seoId,
        url,
        thumb_url: String(image.thumb_url || url),
        slug: String(image.slug || '').trim(),
        alt: String(image.alt || '').trim(),
        media_type: String(image.media_type || 'image').toLowerCase() === 'video' ? 'video' : 'image',
        staged: true,
    };
}

function truncateLabel(keyword, max = 18) {
    const text = String(keyword || '').trim();
    if (text.length <= max) {
        return text;
    }

    return `${text.slice(0, max - 1)}…`;
}

export function isCustomPickerTab(tab) {
    return String(tab || '').startsWith('custom:');
}

export function customTabIdFromPickerTab(tab) {
    return String(tab || '').replace(/^custom:/, '');
}

export function pickerTabFromCustomId(tabId) {
    return `custom:${String(tabId)}`;
}

/**
 * @returns {Array<{id: string, keyword: string, label: string, createdAt: number}>}
 */
export function loadCustomPickerTabs(articleId) {
    const parsed = readJson(tabsKey(articleId));
    if (!parsed || !Array.isArray(parsed.tabs)) {
        return [];
    }

    return parsed.tabs
        .map((row) => ({
            id: String(row?.id || '').trim(),
            keyword: String(row?.keyword || '').trim(),
            label: String(row?.label || '').trim(),
            createdAt: Number(row?.createdAt || 0),
        }))
        .filter((row) => row.id !== '' && row.keyword !== '');
}

function saveCustomPickerTabs(articleId, tabs) {
    writeJson(tabsKey(articleId), {
        tabs: Array.isArray(tabs) ? tabs : [],
        updatedAt: Date.now(),
    });
}

export function addCustomPickerTab(articleId, keyword) {
    const normalized = String(keyword || '').trim();
    if (normalized === '') {
        return null;
    }

    const tabs = loadCustomPickerTabs(articleId);
    const tab = {
        id: `c${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`,
        keyword: normalized,
        label: truncateLabel(normalized),
        createdAt: Date.now(),
    };
    tabs.push(tab);
    saveCustomPickerTabs(articleId, tabs);

    return tab;
}

export function removeCustomPickerTab(articleId, tabId) {
    const id = String(tabId || '').trim();
    if (id === '') {
        return;
    }

    const tabs = loadCustomPickerTabs(articleId).filter((row) => row.id !== id);
    saveCustomPickerTabs(articleId, tabs);
    clearCustomTabCaches(articleId, id);
}

export function loadStagedPickerImages(articleId, tabId) {
    const parsed = readJson(stagedKey(articleId, tabId));
    if (!parsed || !Array.isArray(parsed.images)) {
        return [];
    }

    return parsed.images
        .map((row) => normalizeImage(row))
        .filter(Boolean);
}

export function stagePickerImageToTab(articleId, tabId, image) {
    const normalized = normalizeImage(image);
    if (!normalized) {
        return false;
    }

    const existing = loadStagedPickerImages(articleId, tabId);
    if (existing.some((row) => row.picker_key === normalized.picker_key)) {
        return false;
    }

    existing.unshift(normalized);

    return writeJson(stagedKey(articleId, tabId), {
        images: existing,
        updatedAt: Date.now(),
    });
}

export function countStagedPickerImages(articleId, tabId) {
    return loadStagedPickerImages(articleId, tabId).length;
}

export function readCustomTabFetchCache(articleId, tabId, page, keyword) {
    const parsed = readJson(fetchKey(articleId, tabId, page, keyword));
    if (!parsed || typeof parsed !== 'object') {
        return null;
    }

    const cachedAt = Number(parsed.cachedAt || 0);
    if (cachedAt > 0 && Date.now() - cachedAt > MAX_FETCH_AGE_MS) {
        localStorage.removeItem(fetchKey(articleId, tabId, page, keyword));

        return null;
    }

    if (!Array.isArray(parsed.images)) {
        return null;
    }

    return {
        tab: pickerTabFromCustomId(tabId),
        page: Math.max(1, Number(parsed.page || page)),
        totalPages: Math.max(1, Number(parsed.totalPages || 1)),
        error: parsed.error ? String(parsed.error) : null,
        images: parsed.images,
        catalog: null,
        cachedAt,
    };
}

export function writeCustomTabFetchCache(articleId, tabId, page, keyword, detail) {
    if (!detail || typeof detail !== 'object' || !Array.isArray(detail.images)) {
        return false;
    }

    return writeJson(fetchKey(articleId, tabId, page, keyword), {
        tab: pickerTabFromCustomId(tabId),
        page: Math.max(1, Number(detail.page || page)),
        totalPages: Math.max(1, Number(detail.totalPages || 1)),
        error: detail.error ? String(detail.error) : null,
        images: detail.images,
        cachedAt: Date.now(),
    });
}

export function clearCustomTabCaches(articleId, tabId) {
    const id = Number(articleId);
    const tab = String(tabId || '').trim();
    if (!Number.isFinite(id) || id <= 0 || tab === '') {
        return;
    }

    localStorage.removeItem(stagedKey(id, tab));

    const stagedPrefix = `${STAGED_PREFIX}:${id}:${tab}`;
    const fetchPrefix = `${FETCH_PREFIX}:${id}:${tab}:`;
    const keys = [];

    for (let index = 0; index < localStorage.length; index += 1) {
        const key = localStorage.key(index);
        if (!key) {
            continue;
        }

        if (key.startsWith(fetchPrefix) || key === stagedPrefix) {
            keys.push(key);
        }
    }

    keys.forEach((key) => localStorage.removeItem(key));
}
