import React from 'react';
import '../css/media-library.css';
import { createRoot } from 'react-dom/client';
import MediaLibraryTools from './components/MediaLibraryTools';

function readBootstrap() {
    const el = document.getElementById('seo-media-library-react-root');
    if (!el) {
        return { sites: [], siteId: null, activeTab: 'original' };
    }

    let sites = [];
    try {
        sites = JSON.parse(el.dataset.sites ?? '[]');
    } catch {
        sites = [];
    }

    return {
        sites,
        siteId: el.dataset.siteId ? Number(el.dataset.siteId) : null,
        activeTab: el.dataset.activeTab ?? 'original',
    };
}

function mount() {
    const el = document.getElementById('seo-media-library-react-root');
    if (!el) return;

    const props = readBootstrap();
    let root = el.__seoMediaLibraryRoot;

    if (!root) {
        root = createRoot(el);
        el.__seoMediaLibraryRoot = root;
    }

    root.render(
        <MediaLibraryTools sites={props.sites} siteId={props.siteId} />,
    );
}

mount();

document.addEventListener('livewire:navigated', mount);

if (typeof Livewire !== 'undefined') {
    Livewire.hook('morph.updated', () => {
        window.requestAnimationFrame(mount);
    });
}
