/**
 * Phase 2C — thin insertion command facade.
 * Components must not resolve TipTap / restore selection themselves.
 */

import {
    clearFrozenEditorInsertionContext,
    getInsertionContextForCommand,
    preserveEditorContextBeforeSidebarAction,
    resolveEditorForInsertion,
} from './editorInsertionContext';
import {
    insertContactCtaAtBookmark,
    insertContactValueAtBookmark,
} from './editorSelectionUtils';
import { canMutateEditor } from './editorSessionState';

export function assertWritableInsertionContext(reason = 'editor_insertion_blocked') {
    if (!canMutateEditor()) {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: 'Read-only',
                    body: reason,
                    status: 'warning',
                },
            }),
        );
        return false;
    }
    return true;
}

export function restoreInsertionBookmark() {
    return getInsertionContextForCommand();
}

/**
 * @param {Map<string, import('@tiptap/core').Editor>|null|undefined} blockEditors
 * @param {import('@tiptap/core').Editor|null|undefined} globalEditor
 * @param {string|null|undefined} activeBlockId
 */
export function resolveWritableEditor(blockEditors, globalEditor = null, activeBlockId = null) {
    const ctx = getInsertionContextForCommand();
    return resolveEditorForInsertion({
        blockEditors,
        activeBlockId: activeBlockId ?? ctx?.activeBlockId ?? null,
        globalEditor,
    });
}

export function insertContactValueCommand(editor, label, href, type = '', bookmark = null) {
    if (!assertWritableInsertionContext('editor_insertion_context_missing')) {
        return false;
    }
    const ctx = getInsertionContextForCommand();
    const mark = bookmark ?? ctx?.selection ?? null;
    const ok = insertContactValueAtBookmark(editor, label, href, type, mark);
    if (ok) {
        clearFrozenEditorInsertionContext();
    }
    return ok;
}

export function insertContactCtaCommand(editor, opts = {}) {
    if (!assertWritableInsertionContext('editor_insertion_context_missing')) {
        return false;
    }
    const ctx = getInsertionContextForCommand();
    const bookmark = opts.bookmark ?? ctx?.selection ?? null;
    const ok = insertContactCtaAtBookmark(editor, { ...opts, bookmark });
    if (ok) {
        clearFrozenEditorInsertionContext();
    }
    return ok;
}

export {
    preserveEditorContextBeforeSidebarAction,
    getInsertionContextForCommand,
};

export default {
    assertWritableInsertionContext,
    restoreInsertionBookmark,
    resolveWritableEditor,
    insertContactValueCommand,
    insertContactCtaCommand,
    preserveEditorContextBeforeSidebarAction,
};
