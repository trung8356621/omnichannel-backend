/**
 * Di chuyển Filament header actions (Prompts, Assign, Restore, Delete)
 * vào toolbar SEO. Thứ tự: Debug → Prompts → … → Phím tắt → Xóa (ngoài cùng phải).
 */

function actionFingerprint(element) {
    if (!(element instanceof HTMLElement)) {
        return '';
    }

    if (element.hasAttribute('data-seo-debug-md-import')) {
        return 'debug-md-import';
    }

    if (element.hasAttribute('data-seo-shortcuts-wrap')) {
        return 'shortcuts';
    }

    const wireClick = element.getAttribute('wire:click') ?? '';
    const href = element.getAttribute('href') ?? '';
    const label =
        element.querySelector('.fi-btn-label')?.textContent?.trim()
        ?? element.getAttribute('title')
        ?? element.getAttribute('aria-label')
        ?? '';

    return `${wireClick}|${href}|${label}`;
}

function findDeleteButton(slot) {
    const custom = slot.querySelector('[data-seo-delete-article-btn]');
    if (custom instanceof HTMLElement) {
        return custom;
    }

    const wireDelete = slot.querySelector('[wire\\:click*="delete" i]');
    if (wireDelete instanceof HTMLElement) {
        return wireDelete;
    }

    const dangerIcon = slot.querySelector('.fi-icon-btn.fi-color-danger');
    if (dangerIcon instanceof HTMLElement) {
        return dangerIcon;
    }

    return [...slot.querySelectorAll('.fi-icon-btn')].find((button) => {
        const hint = `${button.getAttribute('title') ?? ''} ${button.getAttribute('aria-label') ?? ''}`.toLowerCase();

        return hint.includes('delete') || hint.includes('xóa');
    }) ?? null;
}

function dedupeSlotChildren(slot) {
    const seen = new Set();

    [...slot.children].forEach((child) => {
        const fingerprint = actionFingerprint(child);
        if (fingerprint === '') {
            return;
        }

        if (seen.has(fingerprint)) {
            child.remove();
            return;
        }

        seen.add(fingerprint);
    });
}

function dedupeShortcutButtons() {
    const slot = document.querySelector('[data-seo-page-actions-slot]');
    const wraps = [...document.querySelectorAll('[data-seo-shortcuts-wrap]')];
    if (wraps.length <= 1) {
        return;
    }

    const keep = slot?.querySelector('[data-seo-shortcuts-wrap]') ?? wraps[0] ?? null;
    wraps.forEach((wrap) => {
        if (wrap !== keep) {
            wrap.remove();
        }
    });
}

function clearMovedHeaderActionsFromSlot() {
    const slot = document.querySelector('[data-seo-page-actions-slot]');
    if (!slot) {
        return;
    }

    [...slot.children].forEach((child) => {
        if (
            child.hasAttribute('data-seo-shortcuts-wrap')
            || child.hasAttribute('data-seo-debug-md-import')
        ) {
            return;
        }

        child.remove();
    });
}

function normalizeToolbarLayout() {
    const slot = document.querySelector('[data-seo-page-actions-slot]');
    if (!slot) {
        return;
    }

    dedupeShortcutButtons();

    const strayShortcuts = document.querySelectorAll(
        '.wp-seo-fields-toolbar-end > [data-seo-shortcuts-wrap], .wp-seo-fields-toolbar-end > .relative',
    );
    strayShortcuts.forEach((wrap) => {
        if (wrap.hasAttribute('data-seo-shortcuts-wrap') || wrap.querySelector('.article-editor-shortcuts-trigger')) {
            wrap.remove();
        }
    });

    dedupeSlotChildren(slot);

    const debugButton = slot.querySelector('[data-seo-debug-md-import]');
    const restoreButton = slot.querySelector('[data-seo-restore-wp-btn]');
    const deleteButton = findDeleteButton(slot);
    const shortcuts = slot.querySelector('[data-seo-shortcuts-wrap]');

    const middleButtons = [...slot.children].filter(
        (child) => child !== debugButton
            && child !== restoreButton
            && child !== deleteButton
            && child !== shortcuts,
    );

    [debugButton, restoreButton, ...middleButtons, shortcuts, deleteButton]
        .filter(Boolean)
        .forEach((child) => slot.appendChild(child));
}

export function mountFilamentHeaderActionsToToolbar() {
    const page = document.querySelector('.seo-article-edit-page');
    const slot = document.querySelector('[data-seo-page-actions-slot]');
    const headerActions = page?.querySelector('.fi-header > div:last-child');

    if (!page || !slot || !headerActions) {
        return false;
    }

    if (headerActions.childElementCount === 0) {
        normalizeToolbarLayout();

        return slot.querySelector('[data-seo-shortcuts-wrap]') !== null
            || slot.childElementCount > 0;
    }

    clearMovedHeaderActionsFromSlot();

    while (headerActions.firstChild) {
        const child = headerActions.firstChild;
        headerActions.removeChild(child);

        const fingerprint = actionFingerprint(child);
        const isDuplicate =
            fingerprint !== ''
            && [...slot.children].some((existing) => actionFingerprint(existing) === fingerprint);

        if (isDuplicate) {
            continue;
        }

        slot.appendChild(child);
    }

    normalizeToolbarLayout();

    if (slot.childElementCount === 0) {
        return false;
    }

    window.dispatchEvent(new CustomEvent('seo-article-editor-header-actions-mounted'));

    return true;
}

let mountTimer = null;

export function scheduleMountFilamentHeaderActionsToToolbar() {
    if (mountTimer !== null) {
        window.clearTimeout(mountTimer);
    }

    mountTimer = window.setTimeout(() => {
        mountTimer = null;

        const attempt = (retriesLeft) => {
            if (mountFilamentHeaderActionsToToolbar() || retriesLeft <= 0) {
                return;
            }

            window.setTimeout(() => attempt(retriesLeft - 1), 80);
        };

        window.requestAnimationFrame(() => attempt(20));
    }, 40);
}

let persistenceRegistered = false;
let morphHookAttached = false;

/** Giữ nút sau khi Livewire morph / re-render partial. */
export function registerFilamentHeaderActionsPersistence() {
    if (persistenceRegistered) {
        scheduleMountFilamentHeaderActionsToToolbar();
        return;
    }

    persistenceRegistered = true;

    const attachMorphHook = () => {
        if (morphHookAttached || !window.Livewire?.hook) {
            return;
        }

        morphHookAttached = true;
        window.Livewire.hook('morph.updated', () => {
            scheduleMountFilamentHeaderActionsToToolbar();
        });
    };

    const onPageRefresh = () => {
        clearMovedHeaderActionsFromSlot();
        scheduleMountFilamentHeaderActionsToToolbar();
    };

    document.addEventListener('livewire:navigated', onPageRefresh);
    document.addEventListener('seo-article-editor-toolbar-refresh', scheduleMountFilamentHeaderActionsToToolbar);
    document.addEventListener('livewire:init', attachMorphHook);
    if (window.Livewire) {
        attachMorphHook();
    }

    const page = document.querySelector('.seo-article-edit-page');
    const header = page?.querySelector('.fi-header');
    if (header) {
        const observer = new MutationObserver(() => {
            const headerActionCount = header.querySelector(':scope > div:last-child')?.childElementCount ?? 0;
            if (headerActionCount > 0) {
                scheduleMountFilamentHeaderActionsToToolbar();
            }
        });
        observer.observe(header, { childList: true, subtree: true });
    }

    scheduleMountFilamentHeaderActionsToToolbar();
}
