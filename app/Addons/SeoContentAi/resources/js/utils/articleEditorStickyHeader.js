import { ARTICLE_EDITOR_SAVE_STATUS_EVENT } from '../help/helpEvents';

export const ARTICLE_EDITOR_DRAFT_ALERT_EVENT = 'article-editor:draft-alert';
export const ARTICLE_EDITOR_OPEN_DRAFT_CHOICE_EVENT = 'article-editor:open-draft-choice';

/**
 * Sync sticky header save status + draft-alert (!) from editor client state.
 * @returns {() => void}
 */
export function installArticleEditorStickyHeaderBridge() {
    const header = document.querySelector('[data-seo-sticky-editor-header]');
    if (!(header instanceof HTMLElement)) {
        return () => {};
    }

    const statusEl = header.querySelector('[data-seo-sticky-save-status]');
    const draftAlertBtn = header.querySelector('[data-seo-sticky-draft-alert]');

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

    const onDraftAlert = (event) => {
        if (!(draftAlertBtn instanceof HTMLElement)) {
            return;
        }

        const visible = Boolean(event?.detail?.visible);
        draftAlertBtn.hidden = !visible;
        const title = String(event?.detail?.title ?? '').trim();
        if (title !== '') {
            draftAlertBtn.title = title;
            draftAlertBtn.setAttribute('aria-label', title);
        }
    };

    const onDraftAlertClick = () => {
        window.dispatchEvent(new CustomEvent(ARTICLE_EDITOR_OPEN_DRAFT_CHOICE_EVENT));
    };

    window.addEventListener(ARTICLE_EDITOR_SAVE_STATUS_EVENT, onSaveStatus);
    window.addEventListener('seo-article-save-conflict', onConflict);
    window.addEventListener('article-editor-save-finished', onSaveFinished);
    window.addEventListener(ARTICLE_EDITOR_DRAFT_ALERT_EVENT, onDraftAlert);
    if (draftAlertBtn instanceof HTMLElement) {
        draftAlertBtn.addEventListener('click', onDraftAlertClick);
    }

    return () => {
        window.removeEventListener(ARTICLE_EDITOR_SAVE_STATUS_EVENT, onSaveStatus);
        window.removeEventListener('seo-article-save-conflict', onConflict);
        window.removeEventListener('article-editor-save-finished', onSaveFinished);
        window.removeEventListener(ARTICLE_EDITOR_DRAFT_ALERT_EVENT, onDraftAlert);
        if (draftAlertBtn instanceof HTMLElement) {
            draftAlertBtn.removeEventListener('click', onDraftAlertClick);
        }
    };
}
