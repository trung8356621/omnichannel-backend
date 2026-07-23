import { ARTICLE_EDITOR_SAVE_STATUS_EVENT } from '../help/articleEditorHelpTopics';

/**
 * Sync sticky header save status from editor client state.
 * @returns {() => void}
 */
export function installArticleEditorStickyHeaderBridge() {
    const header = document.querySelector('[data-seo-sticky-editor-header]');
    if (!(header instanceof HTMLElement)) {
        return () => {};
    }

    const statusEl = header.querySelector('[data-seo-sticky-save-status]');

    const onSaveStatus = (event) => {
        if (!(statusEl instanceof HTMLElement)) {
            return;
        }
        const status = String(event?.detail?.status ?? 'saved');
        const label = String(event?.detail?.label ?? '').trim();
        statusEl.dataset.status = status;
        statusEl.textContent = label;
    };

    const onConflict = () => {
        onSaveStatus({ detail: { status: 'conflict', label: 'Conflict' } });
    };

    const onSaveFinished = (event) => {
        if (event?.detail?.conflict) {
            onConflict();
            return;
        }
        if (event?.detail?.failed) {
            onSaveStatus({ detail: { status: 'failed', label: 'Save failed' } });
        }
    };

    window.addEventListener(ARTICLE_EDITOR_SAVE_STATUS_EVENT, onSaveStatus);
    window.addEventListener('seo-article-save-conflict', onConflict);
    window.addEventListener('article-editor-save-finished', onSaveFinished);

    return () => {
        window.removeEventListener(ARTICLE_EDITOR_SAVE_STATUS_EVENT, onSaveStatus);
        window.removeEventListener('seo-article-save-conflict', onConflict);
        window.removeEventListener('article-editor-save-finished', onSaveFinished);
    };
}
