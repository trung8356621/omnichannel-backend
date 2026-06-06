import React from 'react';
import { createRoot } from 'react-dom/client';
import SeoArticleEditor from './components/SeoArticleEditor';
import ArticleAiChatPanel from './components/ArticleAiChatPanel';
import ArticleFaqEditor from './components/ArticleFaqEditor';
import ArticleLinksSidebar from './components/ArticleLinksSidebar';
import ArticleDomainWidgetsSidebar from './components/ArticleDomainWidgetsSidebar';
import ArticleAiFloatingLauncher from './components/ArticleAiFloatingLauncher';
import '../css/article-editor.css';
import '../css/seo-select.css';
import './utils/seoLocalMediaUpload';
import {
    readArticleMediaPickerCache,
    writeArticleMediaPickerCache,
    isArticleMediaPickerCacheableTab,
} from './utils/articleMediaPickerCache';
import { normalizeArticleSlug } from './utils/articleSlugUtils';
import {
    appendProductAlbumItems,
    loadProductAlbum,
    normalizeProductAlbumList,
    persistProductAlbumDraftToServer,
    removeProductAlbumItem,
    reorderProductAlbum,
    saveProductAlbum,
} from './utils/articleProductAlbumStorage';

window.normalizeArticleSlug = normalizeArticleSlug;

window.__seoArticleMediaPickerCache = {
    read: readArticleMediaPickerCache,
    write: writeArticleMediaPickerCache,
    isCacheableTab: isArticleMediaPickerCacheableTab,
};

window.__seoProductAlbumStorage = {
    load: loadProductAlbum,
    save: saveProductAlbum,
    append: appendProductAlbumItems,
    remove: removeProductAlbumItem,
    reorder: reorderProductAlbum,
};

window.__seoPersistProductAlbumDraft = persistProductAlbumDraftToServer;

window.seoProductAlbumBoxData = function seoProductAlbumBoxData(articleId) {
    const id = Number(articleId ?? 0);

    return {
        articleId: id,
        albumItems: [],
        dragUrl: null,
        init() {
            this.syncFromStorage();
            this._onGalleryUpdated = (event) => {
                const detail = event?.detail ?? {};
                const aid = Number(detail.article_id ?? detail.articleId ?? 0);
                if (aid === this.articleId) {
                    this.syncFromStorage();
                }
            };
            window.addEventListener('seo-product-gallery-updated', this._onGalleryUpdated);
        },
        destroy() {
            if (this._onGalleryUpdated) {
                window.removeEventListener('seo-product-gallery-updated', this._onGalleryUpdated);
            }
        },
        syncFromStorage() {
            const storage = window.__seoProductAlbumStorage;
            this.albumItems = storage?.load ? storage.load(this.articleId) : [];
        },
        removeItem(url) {
            const storage = window.__seoProductAlbumStorage;
            if (storage?.remove) {
                storage.remove(this.articleId, url);
            }
            this.syncFromStorage();
        },
        startDrag(event) {
            this.dragUrl = event.currentTarget.dataset.galleryUrl || null;
        },
        allowDrop(event) {
            event.preventDefault();
        },
        finishDrag() {
            this.dragUrl = null;
        },
        onDrop(event) {
            event.preventDefault();
            const dragUrl = this.dragUrl;
            if (!dragUrl) {
                return;
            }

            const targetEl = event.currentTarget.closest('[data-gallery-url]');
            const targetUrl = targetEl?.dataset?.galleryUrl || null;
            if (!targetUrl || targetUrl === dragUrl) {
                return;
            }

            const urls = this.albumItems.map((item) => String(item?.url ?? '').trim());
            const from = urls.indexOf(dragUrl);
            const to = urls.indexOf(targetUrl);
            if (from < 0 || to < 0 || from === to) {
                return;
            }

            urls.splice(from, 1);
            urls.splice(to, 0, dragUrl);

            const storage = window.__seoProductAlbumStorage;
            if (storage?.reorder) {
                storage.reorder(this.articleId, urls);
            }

            this.syncFromStorage();
            this.dragUrl = null;
        },
        albumCountLabel() {
            const count = this.albumItems.length;
            if (count === 0) {
                return 'Chưa có ảnh trong album';
            }

            return `${count} ảnh · Ảnh đầu là đại diện · Kéo thả để đổi vị trí`;
        },
    };
};

/** Livewire 3 có thể gửi params dạng object hoặc mảng — chuẩn hóa cho listener window. */
function normalizeLivewireEventDetail(payload) {
    if (payload == null) {
        return {};
    }
    if (Array.isArray(payload)) {
        if (payload.length === 1 && payload[0] != null && typeof payload[0] === 'object') {
            return payload[0];
        }

        return { params: payload };
    }

    return typeof payload === 'object' ? payload : {};
}

function registerArticleEditorLivewireBridge() {
    if (window.__seoArticleLivewireBridgeRegistered) {
        return;
    }
    window.__seoArticleLivewireBridgeRegistered = true;

    /** Livewire 3 listens to window events with the same name — prevent echo loops. */
    const forwardingLivewireEvents = new Set();

    const forward = (name) => (payload) => {
        if (forwardingLivewireEvents.has(name)) {
            return;
        }

        forwardingLivewireEvents.add(name);
        try {
            window.dispatchEvent(
                new CustomEvent(name, {
                    detail: normalizeLivewireEventDetail(payload),
                }),
            );
        } finally {
            queueMicrotask(() => {
                forwardingLivewireEvents.delete(name);
            });
        }
    };

    if (typeof Livewire !== 'undefined') {
        Livewire.on('collect-editor-html', forward('collect-editor-html'));
        Livewire.on('article-faqs-extracted', forward('article-faqs-extracted'));
        Livewire.on('seo-analyze-result', forward('seo-analyze-result'));
        Livewire.on('flush-article-faqs', forward('flush-article-faqs'));
        Livewire.on('article-faq-extract-debug', forward('article-faq-extract-debug'));
        Livewire.on('article-faq-extract-debug-cleared', forward('article-faq-extract-debug-cleared'));
        Livewire.on('editor-block-image-selected', forward('editor-block-image-selected'));
        Livewire.on('article-media-selected', forward('article-media-selected'));
        Livewire.on('article-media-removed', forward('article-media-removed'));
        Livewire.on('article-faqs-save-finished', forward('article-faqs-save-finished'));
        Livewire.on('seo-product-gallery-updated', (payload) => {
            const name = 'seo-product-gallery-updated';
            if (forwardingLivewireEvents.has(name)) {
                return;
            }

            const detail = normalizeLivewireEventDetail(payload);
            const gallery = normalizeProductAlbumList(detail.gallery);
            const syncedArticleId = detail.article_id ?? detail.articleId;
            if (syncedArticleId && gallery.length > 0) {
                saveProductAlbum(syncedArticleId, gallery, { dispatch: false });
            }

            forwardingLivewireEvents.add(name);
            try {
                window.dispatchEvent(new CustomEvent(name, { detail: { ...detail, gallery } }));
            } finally {
                queueMicrotask(() => {
                    forwardingLivewireEvents.delete(name);
                });
            }
        });
        Livewire.on('article-ai-image-generated', forward('article-ai-image-generated'));
        Livewire.on('article-ai-video-generated', forward('article-ai-video-generated'));
        Livewire.on('article-ai-media-failed', forward('article-ai-media-failed'));
        Livewire.on('article-post-images-synced', forward('article-post-images-synced'));
        Livewire.on('article-supplemental-images-synced', forward('article-supplemental-images-synced'));
        Livewire.on('virtual-reviews-updated', forward('virtual-reviews-updated'));
    }
}

document.addEventListener('livewire:init', registerArticleEditorLivewireBridge);
if (typeof Livewire !== 'undefined') {
    registerArticleEditorLivewireBridge();
}

const rootElement = document.getElementById('seo-article-editor-root');

if (rootElement) {
    let initialHtml = '';
    let initialOutline = '';
    let initialSeo = null;
    let editorSettings = { history_step: 20, autosave_interval_seconds: 60 };
    let initialPostImages = [];
    let initialSupplementalImages = [];
    let articleId = null;
    let siteId = null;
    let articleTitle = '';
    let articlePostType = '';
    let supportsProductGallery = false;
    let productCategoryOptions = [];
    let initialProductGallery = [];
    let aiDebug = { enabled: false };
    let initialVirtualReviews = [];

    try {
        const htmlEl = document.getElementById('seo-article-initial-html');
        const raw = htmlEl?.textContent?.trim();
        if (raw) {
            initialHtml = JSON.parse(raw);
        }
    } catch (e) {
        console.warn('Invalid article HTML JSON', e);
    }

    try {
        const seoEl = document.getElementById('seo-article-initial-seo');
        const rawSeo = seoEl?.textContent?.trim();
        if (rawSeo) {
            initialSeo = JSON.parse(rawSeo);
        }
    } catch (e) {
        console.warn('Invalid article SEO JSON', e);
    }

    try {
        const settingsEl = document.getElementById('seo-article-editor-settings');
        const rawSettings = settingsEl?.textContent?.trim();
        if (rawSettings) {
            editorSettings = JSON.parse(rawSettings);
        }
    } catch (e) {
        console.warn('Invalid editor settings JSON', e);
    }

    try {
        const outlineEl = document.getElementById('seo-article-initial-outline');
        const rawOutline = outlineEl?.textContent?.trim();
        if (rawOutline) {
            initialOutline = JSON.parse(rawOutline);
        }
    } catch (e) {
        console.warn('Invalid article outline JSON', e);
    }

    try {
        const imagesEl = document.getElementById('seo-article-initial-images');
        const rawImages = imagesEl?.textContent?.trim();
        if (rawImages) {
            initialPostImages = JSON.parse(rawImages);
        }
    } catch (e) {
        console.warn('Invalid article images JSON', e);
    }

    try {
        const metaEl = document.getElementById('seo-article-meta');
        const rawMeta = metaEl?.textContent?.trim();
        if (rawMeta) {
            const meta = JSON.parse(rawMeta);
            articleId = meta?.id ?? null;
            siteId = meta?.site_id ?? meta?.siteId ?? null;
            articleTitle = meta?.title ?? '';
            articlePostType = String(meta?.post_type ?? '').trim();
            supportsProductGallery = Boolean(meta?.supports_product_gallery);
            productCategoryOptions = Array.isArray(meta?.product_category_options)
                ? meta.product_category_options
                : [];
            initialProductGallery = Array.isArray(meta?.product_gallery) ? meta.product_gallery : [];
            aiDebug = meta?.ai_debug ?? { enabled: false };
            initialSupplementalImages = Array.isArray(meta?.supplemental_images)
                ? meta.supplemental_images
                : [];
            initialVirtualReviews = Array.isArray(meta?.virtual_reviews)
                ? meta.virtual_reviews
                : [];
        }
    } catch (e) {
        console.warn('Invalid article meta JSON', e);
    }

    if (articleId && initialProductGallery.length > 0 && loadProductAlbum(articleId).length === 0) {
        saveProductAlbum(articleId, initialProductGallery);
    }

    let initialFaqs = [];

    try {
        const faqsEl = document.getElementById('seo-article-initial-faqs');
        const rawFaqs = faqsEl?.textContent?.trim();
        if (rawFaqs) {
            initialFaqs = JSON.parse(rawFaqs);
        }
    } catch (e) {
        console.warn('Invalid article FAQs JSON for editor', e);
    }

    const root = createRoot(rootElement);
    root.render(
        <SeoArticleEditor
            articleId={articleId}
            siteId={siteId}
            initialHtml={initialHtml}
            initialOutline={initialOutline}
            initialSeo={initialSeo}
            initialPostImages={initialPostImages}
            initialSupplementalImages={initialSupplementalImages}
            initialPostType={articlePostType}
            supportsProductGallery={supportsProductGallery}
            productCategoryOptions={productCategoryOptions}
            initialProductGallery={initialProductGallery}
            initialFaqs={initialFaqs}
            initialVirtualReviews={initialVirtualReviews}
            articleTitle={articleTitle}
            editorSettings={editorSettings}
        />,
    );

    const linksRoot = document.getElementById('seo-article-links-root');
    if (linksRoot) {
        createRoot(linksRoot).render(<ArticleLinksSidebar />);
    }

    const domainWidgetsRoot = document.getElementById('seo-article-domain-widgets-root');
    if (domainWidgetsRoot) {
        createRoot(domainWidgetsRoot).render(
            <ArticleDomainWidgetsSidebar
                initialDomainLinkList={initialSeo?.domain_link_list ?? []}
                initialDomainLinkCatalog={initialSeo?.domain_link_list_catalog ?? []}
                initialDomainCtaList={initialSeo?.domain_cta_list ?? []}
            />,
        );
    }

    const launcherRoot = document.getElementById('seo-article-ai-launcher-root');
    if (launcherRoot) {
        createRoot(launcherRoot).render(<ArticleAiFloatingLauncher />);
    }

    const chatRoot = document.getElementById('seo-article-ai-chat-root');
    if (chatRoot) {
        createRoot(chatRoot).render(<ArticleAiChatPanel articleId={articleId} aiDebug={aiDebug} />);
    }

    const faqRoot = document.getElementById('seo-article-faq-root');
    if (faqRoot) {
        let initialFaqs = [];
        let initialExtractDebug = null;
        let canGenerateFaq = false;
        try {
            const configEl = document.getElementById('seo-article-faq-config');
            const rawConfig = configEl?.textContent?.trim();
            if (rawConfig) {
                const config = JSON.parse(rawConfig);
                canGenerateFaq = Boolean(config?.can_generate_faq);
            }
        } catch (e) {
            console.warn('Invalid article FAQ config JSON', e);
        }
        try {
            const faqsEl = document.getElementById('seo-article-initial-faqs');
            const rawFaqs = faqsEl?.textContent?.trim();
            if (rawFaqs) {
                initialFaqs = JSON.parse(rawFaqs);
            }
        } catch (e) {
            console.warn('Invalid article FAQs JSON', e);
        }
        try {
            const debugEl = document.getElementById('seo-article-faq-extract-debug');
            const rawDebug = debugEl?.textContent?.trim();
            if (rawDebug && rawDebug !== 'null') {
                initialExtractDebug = JSON.parse(rawDebug);
            }
        } catch (e) {
            console.warn('Invalid FAQ extract debug JSON', e);
        }

        createRoot(faqRoot).render(
            <ArticleFaqEditor
                articleId={articleId}
                initialFaqs={initialFaqs}
                initialExtractDebug={initialExtractDebug}
                canGenerateFaq={canGenerateFaq}
            />,
        );
    }
}
