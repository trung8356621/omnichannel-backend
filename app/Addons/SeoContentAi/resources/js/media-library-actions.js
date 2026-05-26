function registerSeoMediaLibraryActions() {
    Alpine.data('seoMediaLibraryActions', () => ({
        absoluteUrl(url) {
            if (!url) {
                return '';
            }
            if (url.startsWith('http://') || url.startsWith('https://')) {
                return url;
            }
            if (url.startsWith('/')) {
                return `${window.location.origin}${url}`;
            }

            return url;
        },
        async downloadUrl(url, filename) {
            const abs = this.absoluteUrl(url);
            if (!abs) {
                return;
            }

            try {
                const response = await fetch(abs, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('fetch failed');
                }

                const blob = await response.blob();
                const objectUrl = URL.createObjectURL(blob);
                const anchor = document.createElement('a');
                anchor.href = objectUrl;
                anchor.download = filename || 'image';
                document.body.appendChild(anchor);
                anchor.click();
                anchor.remove();
                URL.revokeObjectURL(objectUrl);
            } catch {
                const anchor = document.createElement('a');
                anchor.href = abs;
                anchor.target = '_blank';
                anchor.rel = 'noopener';
                document.body.appendChild(anchor);
                anchor.click();
                anchor.remove();
            }
        },
        downloadCard(card) {
            if (!card) {
                return;
            }

            const url = card.dataset.imageUrl;
            const name = card.dataset.downloadName || card.dataset.imageSlug || 'image';
            this.downloadUrl(url, name);
        },
        downloadSelected() {
            const cards = document.querySelectorAll('.seo-media-library-card.is-selected');
            if (!cards.length) {
                return;
            }

            cards.forEach((card, index) => {
                setTimeout(() => {
                    this.downloadCard(card);
                }, index * 350);
            });
        },
    }));
}

if (window.Alpine) {
    registerSeoMediaLibraryActions();
} else {
    document.addEventListener('alpine:init', registerSeoMediaLibraryActions);
}

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) {
        return;
    }

    const data = event.data;
    if (data && data.type === 'seo-image-splitter-saved') {
        if (typeof Livewire !== 'undefined') {
            Livewire.dispatch('seo-media-library-refresh');
        }

        return;
    }

    if (!data || data.type !== 'seo-magic-eraser-saved') {
        return;
    }

    if (typeof Livewire !== 'undefined') {
        Livewire.dispatch('seo-magic-eraser-saved', {
            url: data.url,
            imageId: data.imageId ?? null,
            pendingWpSync: !!data.pendingWpSync,
        });
        Livewire.dispatch('seo-media-library-refresh');
    }
});
