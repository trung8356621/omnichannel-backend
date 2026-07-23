/**
 * Phase 3 — sidebar heavy-module ids + helpers.
 * Core editor keeps only a lightweight activeModule scalar; payloads live in modules.
 */

export const HEAVY_SIDEBAR_MODULES = Object.freeze([
    'seo',
    'images',
    'reviews',
    'links',
    'faq',
    'cta',
    'publishing',
    'ai-chat',
]);

/** Modules hosted outside SeoArticleEditor (portal targets). */
export const EXTERNAL_HOSTED_MODULES = Object.freeze(['links', 'faq', 'cta', 'ai-chat']);

/** Modules portal-mounted from SeoArticleEditor. */
export const EDITOR_HOSTED_MODULES = Object.freeze(['seo', 'images', 'reviews']);

export const MODULE_EVENT_ACTIVE = 'seo-editor-active-module';
export const MODULE_EVENT_SWITCH = 'seo-assistant-switch-panel';
/** Canonical open contract — FAQ shortcode / Help goto / widgets. */
export const MODULE_EVENT_OPEN = 'article-editor:module-open';
/** Links sidebar mount → ask editor to republish client existing-link scan. */
export const LINKS_RESCAN_REQUEST_EVENT = 'seo-editor-links-rescan-request';

/**
 * @param {unknown} raw
 * @returns {string|null}
 */
export function normalizeHeavyModuleId(raw) {
    const panel = String(raw ?? '').trim().toLowerCase();
    if (!panel) {
        return null;
    }

    if (panel === 'image') {
        return 'images';
    }
    if (panel === 'ai' || panel === 'ai-chat' || panel === 'aichat') {
        return 'ai-chat';
    }
    if (panel === 'publish' || panel === 'publishing') {
        return 'publishing';
    }

    return HEAVY_SIDEBAR_MODULES.includes(panel) ? panel : null;
}

/**
 * @param {string|null} moduleId
 * @returns {boolean}
 */
export function isExternalHostedModule(moduleId) {
    return EXTERNAL_HOSTED_MODULES.includes(String(moduleId ?? ''));
}

/**
 * @param {string|null} moduleId
 * @returns {boolean}
 */
export function isEditorHostedModule(moduleId) {
    return EDITOR_HOSTED_MODULES.includes(String(moduleId ?? ''));
}

/**
 * Broadcast active heavy module (one at a time). Null = none.
 * @param {string|null} moduleId
 * @param {Record<string, unknown>} [extra]
 */
export function dispatchActiveModule(moduleId, extra = {}) {
    window.dispatchEvent(
        new CustomEvent(MODULE_EVENT_ACTIVE, {
            detail: { module: moduleId, ...extra },
        }),
    );
}

/**
 * @param {unknown} error
 * @returns {boolean}
 */
export function isAbortError(error) {
    if (!error || typeof error !== 'object') {
        return false;
    }
    const name = String(error.name ?? '');
    return name === 'AbortError' || name === 'CanceledError';
}
