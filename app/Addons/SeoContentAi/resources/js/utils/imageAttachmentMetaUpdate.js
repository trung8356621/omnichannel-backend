export function dispatchWordPressAttachmentMetaUpdate(items) {
    if (!items?.length) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('seo-update-attachment-meta', {
            detail: { items },
        }),
    );
}
