import React from 'react';
import '../css/magic-eraser.css';
import { createRoot } from 'react-dom/client';
import MagicEraserApp from './components/MagicEraserApp';

function readBootstrap() {
    const el = document.getElementById('seo-media-image-editor-root');
    if (!el) {
        return {
            imageUrl: '',
            imageId: null,
            wpAttachmentId: 0,
            pendingWpSync: false,
            libraryUrl: '/seo/media-library',
        };
    }

    return {
        imageUrl: el.dataset.imageUrl ?? '',
        imageId: el.dataset.imageId ? Number(el.dataset.imageId) : null,
        wpAttachmentId: Number(el.dataset.wpAttachmentId ?? 0),
        pendingWpSync: el.dataset.pendingWpSync === '1',
        libraryUrl: el.dataset.libraryUrl ?? '/seo/media-library',
    };
}

function notifyOpener(payload) {
    if (!window.opener || window.opener.closed) {
        return;
    }

    window.opener.postMessage(
        {
            type: 'seo-magic-eraser-saved',
            ...payload,
        },
        window.location.origin,
    );
}

function mount() {
    const el = document.getElementById('seo-media-image-editor-root');
    if (!el) {
        return;
    }

    const props = readBootstrap();

    let root = el.__seoMediaImageEditorRoot;
    if (!root) {
        root = createRoot(el);
        el.__seoMediaImageEditorRoot = root;
    }

    root.render(
        <MagicEraserApp
            standalone
            imageUrl={props.imageUrl}
            imageId={props.imageId}
            onSave={(url) => {
                const isWpStaging = props.wpAttachmentId > 0;
                notifyOpener({
                    url,
                    imageId: props.imageId,
                    pendingWpSync: isWpStaging,
                });
                window.close();
            }}
            onClose={() => {
                window.close();
            }}
        />,
    );
}

mount();
