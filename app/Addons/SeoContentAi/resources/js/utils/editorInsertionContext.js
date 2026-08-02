/**
 * Transient frontend insertion bookmark (CTA / link / media assistants).
 * Not persisted to DB.
 */

/** @typedef {{ from: number, to: number, docSize: number }} SelectionBookmark */

/**
 * @typedef {{
 *   activeSectionId: string|null,
 *   activeBlockId: string|null,
 *   activeEditorId: string|null,
 *   selection: SelectionBookmark|null,
 *   updatedAt: number,
 * }} EditorInsertionContext
 */

/** @type {EditorInsertionContext} */
let context = {
    activeSectionId: null,
    activeBlockId: null,
    activeEditorId: null,
    selection: null,
    updatedAt: 0,
};

/** Frozen at assistant pointerdown — survives dropdown focus steal. */
/** @type {EditorInsertionContext|null} */
let frozenContext = null;

/**
 * @returns {EditorInsertionContext}
 */
export function getEditorInsertionContext() {
    return { ...context, selection: context.selection ? { ...context.selection } : null };
}

/**
 * Snapshot used by CTA/link insert after sidebar steals focus.
 *
 * @returns {EditorInsertionContext|null}
 */
export function getFrozenEditorInsertionContext() {
    if (!frozenContext) {
        return null;
    }

    return {
        ...frozenContext,
        selection: frozenContext.selection ? { ...frozenContext.selection } : null,
    };
}

/**
 * Drop freeze after a successful insert (or cancel).
 */
export function clearFrozenEditorInsertionContext() {
    frozenContext = null;
}

/**
 * @param {EditorInsertionContext|null|undefined} snapshot
 * @param {{ force?: boolean }} [options]
 */
export function freezeEditorInsertionContext(snapshot = null, options = {}) {
    const force = options.force === true;
    const source = snapshot ?? getEditorInsertionContext();
    const nextSelection = source.selection ? { ...source.selection } : null;

    // Keep a good caret freeze when a later blur sync has no trustworthy selection.
    if (
        !force
        && frozenContext?.selection
        && (!nextSelection || (
            nextSelection.from === 0
            && nextSelection.to === 0
            && frozenContext.selection.from > 0
        ))
    ) {
        return;
    }

    frozenContext = {
        activeSectionId: source.activeSectionId,
        activeBlockId: source.activeBlockId,
        activeEditorId: source.activeEditorId,
        selection: nextSelection,
        updatedAt: Date.now(),
    };
    // Keep live context aligned with freeze so resolveEditorForInsertion stays consistent.
    context = { ...frozenContext };
}

/**
 * Prefer frozen bookmark for insert; fall back to live context.
 *
 * @returns {EditorInsertionContext}
 */
export function getInsertionContextForCommand() {
    return getFrozenEditorInsertionContext() ?? getEditorInsertionContext();
}

/**
 * @param {Partial<EditorInsertionContext>} patch
 */
export function patchEditorInsertionContext(patch = {}) {
    context = {
        ...context,
        ...patch,
        selection: patch.selection
            ? { ...patch.selection }
            : patch.selection === null
              ? null
              : context.selection
                ? { ...context.selection }
                : null,
        updatedAt: Date.now(),
    };
}

/**
 * @param {{
 *   sectionId?: string|null,
 *   blockId?: string|null,
 *   editor?: { state?: { selection?: { from: number, to: number }, doc?: { content?: { size: number } } }, isDestroyed?: boolean }|null,
 * }} params
 */
export function captureEditorInsertionContext({ sectionId = null, blockId = null, editor = null } = {}) {
    const next = {
        activeSectionId: sectionId != null && String(sectionId).trim() !== '' ? String(sectionId) : context.activeSectionId,
        activeBlockId: blockId != null && String(blockId).trim() !== '' ? String(blockId) : context.activeBlockId,
        activeEditorId: blockId != null && String(blockId).trim() !== '' ? String(blockId) : context.activeEditorId,
        selection: context.selection,
        updatedAt: Date.now(),
    };

    if (editor && !editor.isDestroyed && editor.state?.selection) {
        const { from, to } = editor.state.selection;
        const docSize = Number(editor.state.doc?.content?.size ?? 0);
        next.selection = {
            from: Number(from) || 0,
            to: Number(to) || 0,
            docSize,
        };
        if (blockId) {
            next.activeBlockId = String(blockId);
            next.activeEditorId = String(blockId);
        }
    }

    context = next;
}

/**
 * Clamp bookmark into current doc. Never invent "end of doc" here — caller decides fallback.
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {SelectionBookmark|null|undefined} bookmark
 * @returns {{ from: number, to: number }|null}
 */
export function resolveBookmarkSelection(editor, bookmark) {
    if (!editor?.state || editor.isDestroyed || !bookmark) {
        return null;
    }

    const docSize = Number(editor.state.doc?.content?.size ?? 0);
    if (docSize <= 0) {
        return null;
    }

    const from = Math.max(0, Math.min(Number(bookmark.from) || 0, docSize));
    const to = Math.max(from, Math.min(Number(bookmark.to) || from, docSize));

    return { from, to };
}

/**
 * Prefer active-block TipTap instance. Never silently pick "first" map entry.
 *
 * @param {{
 *   blockEditors: Map<string, import('@tiptap/core').Editor>,
 *   activeBlockId?: string|null,
 *   globalEditor?: import('@tiptap/core').Editor|null,
 * }} params
 * @returns {import('@tiptap/core').Editor|null}
 */
export function resolveEditorForInsertion({ blockEditors, activeBlockId = null, globalEditor = null }) {
    const ctx = getInsertionContextForCommand();
    const preferredId = String(ctx.activeBlockId || activeBlockId || '').trim();

    if (preferredId && blockEditors instanceof Map) {
        const preferred = blockEditors.get(preferredId);
        if (preferred && !preferred.isDestroyed) {
            return preferred;
        }
    }

    if (globalEditor && !globalEditor.isDestroyed && !preferredId) {
        return globalEditor;
    }

    return null;
}

/**
 * Capture from a live TipTap editor map using current context / active ids.
 *
 * @param {{
 *   blockEditors: Map<string, import('@tiptap/core').Editor>,
 *   activeBlockId?: string|null,
 *   sectionByBlockId?: Map<string, string>|null,
 * }} params
 */
export function syncInsertionContextFromLiveEditors({
    blockEditors,
    activeBlockId = null,
    sectionByBlockId = null,
} = {}) {
    if (!(blockEditors instanceof Map)) {
        return;
    }

    const ctx = getEditorInsertionContext();

    // Prefer the TipTap instance that still has focus (pointerdown before steal).
    let focusedBlockId = '';
    let focusedEditor = null;
    for (const [id, editor] of blockEditors.entries()) {
        if (editor && !editor.isDestroyed && editor.isFocused) {
            focusedBlockId = String(id);
            focusedEditor = editor;
            break;
        }
    }

    const blockId = String(focusedBlockId || ctx.activeBlockId || activeBlockId || '').trim();
    if (!blockId) {
        return;
    }

    const editor = focusedEditor && String(focusedBlockId) === blockId
        ? focusedEditor
        : blockEditors.get(blockId);
    if (!editor || editor.isDestroyed) {
        return;
    }

    const sectionId = sectionByBlockId?.get?.(blockId) ?? ctx.activeSectionId;
    captureEditorInsertionContext({ sectionId, blockId, editor });
}

/**
 * Sync live editors then freeze for assistant actions (CTA dropdown, etc.).
 *
 * @param {{
 *   blockEditors: Map<string, import('@tiptap/core').Editor>,
 *   activeBlockId?: string|null,
 *   sectionByBlockId?: Map<string, string>|null,
 * }} params
 */
export function syncAndFreezeInsertionContext(params = {}) {
    const prior = getFrozenEditorInsertionContext();
    const blockEditors = params.blockEditors;
    let anyFocused = false;
    if (blockEditors instanceof Map) {
        for (const editor of blockEditors.values()) {
            if (editor && !editor.isDestroyed && editor.isFocused) {
                anyFocused = true;
                break;
            }
        }
    }

    // Dropdown item pointerdown often fires AFTER editor blurred. Do not overwrite
    // the caret captured on the CTA trigger with a stale/end selection.
    if (prior?.selection && !anyFocused) {
        return;
    }

    syncInsertionContextFromLiveEditors(params);
    freezeEditorInsertionContext(getEditorInsertionContext(), { force: anyFocused });
}

/**
 * Ensure element is in view without forcing scroll when already visible.
 *
 * @param {Element|null|undefined} el
 * @param {ScrollIntoViewOptions} [options]
 */
export function scrollElementIntoViewIfNeeded(el, options = { behavior: 'smooth', block: 'nearest' }) {
    if (!el || typeof el.getBoundingClientRect !== 'function') {
        return;
    }

    const rect = el.getBoundingClientRect();
    const margin = 48;
    const fullyVisible =
        rect.top >= margin &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) - margin;

    if (fullyVisible) {
        return;
    }

    el.scrollIntoView(options);
}

/**
 * Pointer targets that steal focus from the editor (sidebar / assistant).
 *
 * @param {EventTarget|null} target
 * @returns {boolean}
 */
export function isAssistantFocusStealTarget(target) {
    if (!(target instanceof Element)) {
        return false;
    }

    return Boolean(
        target.closest(
            [
                '.wp-article-edit-sidebar',
                '.seo-assistant-dock',
                '.seo-assistant-dock-shell',
                '.seo-assistant-widget-layer',
                '.seo-assistant-panel-slot',
                '.seo-assistant-panel',
                '.seo-assistant-tab',
                '.seo-assistant-widget',
                '.wp-article-links-box',
                '.seo-link-assistant',
                '.wp-article-links-keyword-row',
                '.wp-article-links-insert-btn',
                '.wp-article-links-cta-actions',
                '.wp-article-links-cta-template-menu',
                '.wp-article-cta-settings',
                '.seo-featured-health',
                '.seo-publishing-box',
                '.seo-gallery-box',
                '[data-seo-assistant]',
                '[data-assistant-widget]',
            ].join(','),
        ),
    );
}
