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
import '../css/image-splitter.css';
import './utils/seoLocalMediaUpload';
import {
    readArticleMediaPickerCache,
    writeArticleMediaPickerCache,
    isArticleMediaPickerCacheableTab,
} from './utils/articleMediaPickerCache';
import { clearArticleLocalState } from './utils/articleLocalState';
import { loadFaqDraft } from './utils/articleEditorStorage';
import { registerFilamentHeaderActionsPersistence } from './utils/articleEditorHeaderActions';
import { normalizeArticleSlug } from './utils/articleSlugUtils';
import {
    loadFeaturedImage,
    persistFeaturedImageDraftToServer,
    saveFeaturedImage,
} from './utils/articleFeaturedImageStorage';
import {
    appendProductAlbumItems,
    loadProductAlbum,
    normalizeProductAlbumList,
    persistProductAlbumDraftToServer,
    removeProductAlbumItem,
    reorderProductAlbum,
    saveProductAlbum,
} from './utils/articleProductAlbumStorage';
import { installArticleAutosaveLock } from './utils/articleAutosaveLock';
import {
    applyFetchedWpCategories,
    loadWpCategoryIds,
    saveWpCategoryIds,
} from './utils/articleWpCategoriesStorage';
installArticleAutosaveLock();

window.normalizeArticleSlug = normalizeArticleSlug;
window.__seoClearArticleLocalState = clearArticleLocalState;
window.__seoWpCategoryStorage = {
    load: loadWpCategoryIds,
    save: saveWpCategoryIds,
    applyFetched: applyFetchedWpCategories,
};
window.__seoFeaturedImageStorage = {
    load: loadFeaturedImage,
    save: saveFeaturedImage,
};
window.__seoPersistFeaturedImageDraft = persistFeaturedImageDraftToServer;
window.dispatchEvent(new CustomEvent('seo-featured-image-storage-ready'));

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

window.__seoExecuteHeavyArticleAction = async function executeHeavyArticleAction(action, wire) {
    const normalizedAction = action === 'sync' ? 'sync' : 'save';

    if (!window.__seoArticleHeavyActionOverlay?.locked) {
        window.__seoBeginArticleHeavyActionClient?.(normalizedAction);
    }

    await window.__seoYieldForHeavyActionPaint?.();

    try {
        const collect = window.__seoCollectEditorHeavyBundle;
        if (typeof collect !== 'function') {
            throw new Error('Editor chưa sẵn sàng — tải lại trang rồi thử lại.');
        }

        const editorBundle = await collect();
        const html = String(editorBundle?.html ?? '').trim();
        if (!html) {
            throw new Error('Không thu thập được nội dung bài viết.');
        }

        const articleId = Number(editorBundle?.articleId ?? 0);
        const featured = window.__seoFeaturedImageStorage?.load?.(articleId) ?? null;
        const productAlbum = window.__seoProductAlbumStorage?.load?.(articleId) ?? null;
        const faqsFromEditor =
            typeof window.__seoCollectArticleFaqs === 'function' ? window.__seoCollectArticleFaqs() : null;
        const faqsFromBundle = Array.isArray(editorBundle?.faqs) ? editorBundle.faqs : null;
        const faqsFromDraft = articleId > 0 ? loadFaqDraft(articleId) : null;
        const faqsPayload = Array.isArray(faqsFromEditor)
            ? faqsFromEditor
            : Array.isArray(faqsFromBundle)
              ? faqsFromBundle
              : Array.isArray(faqsFromDraft)
                ? faqsFromDraft
                : [];

        await wire.executeHeavyArticleAction({
            action: normalizedAction,
            html,
            seo_analysis: editorBundle?.seoAnalysis ?? null,
            faqs: faqsPayload,
            publish_box: window.__seoPublishBoxSnapshot?.() ?? null,
            category_ids: window.__seoPublishCategoriesSnapshot?.() ?? null,
            featured_image: featured,
            product_album: productAlbum,
        });
    } catch (error) {
        window.__seoEndArticleHeavyActionClient?.();
        throw error;
    }
};

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

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) {
        return;
    }

    const data = event.data;
    if (!data || data.type !== 'seo-image-splitter-saved') {
        return;
    }

    const galleryItems = Array.isArray(data.product_gallery_items) ? data.product_gallery_items : [];
    const articleId = Number(data.article_id ?? 0);
    const storage = window.__seoProductAlbumStorage;

    if (galleryItems.length === 0 || !storage?.append || !Number.isFinite(articleId) || articleId <= 0) {
        return;
    }

    storage.append(articleId, galleryItems);
});

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
        Livewire.on('article-featured-snippet-generated', forward('article-featured-snippet-generated'));
        Livewire.on('article-ai-video-generated', forward('article-ai-video-generated'));
        Livewire.on('article-ai-media-failed', forward('article-ai-media-failed'));
        Livewire.on('article-post-images-synced', forward('article-post-images-synced'));
        Livewire.on('article-supplemental-images-synced', forward('article-supplemental-images-synced'));
        Livewire.on('virtual-reviews-updated', forward('virtual-reviews-updated'));
        Livewire.on('google-serp-preview-updated', forward('google-serp-preview-updated'));
        Livewire.on('pending-internal-link-ready', forward('pending-internal-link-ready'));
        Livewire.on('article-autosave-lock', forward('article-autosave-lock'));
    }
}

document.addEventListener('livewire:init', registerArticleEditorLivewireBridge);
if (typeof Livewire !== 'undefined') {
    registerArticleEditorLivewireBridge();
}

function getOrCreateReactRoot(element) {
    if (!element.__seoArticleReactRoot) {
        element.__seoArticleReactRoot = createRoot(element);
    }

    return element.__seoArticleReactRoot;
}

function readArticleEditorBootstrap() {
    let initialHtml = '';
    let initialSeo = null;
    let editorSettings = { history_step: 20, autosave_interval_seconds: 60 };
    let initialPostImages = [];
    let initialSupplementalImages = [];
    let articleId = null;
    let siteId = null;
    let articleTitle = '';
    let articlePostType = '';
    let contentRevision = '';
    let supportsProductGallery = false;
    let productCategoryOptions = [];
    let initialProductGallery = [];
    let aiDebug = { enabled: false };
    let initialVirtualReviews = [];
    let mediaPickerUrl = '';
    let initialFaqs = [];
    let initialLoaiSanPham = '';
    let initialGalleryDescription = '';

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
            contentRevision = String(meta?.content_revision ?? '').trim();
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
            mediaPickerUrl = String(meta?.media_picker_url ?? '').trim();
            initialLoaiSanPham = String(meta?.loai_san_pham ?? '').trim();
            initialGalleryDescription = String(meta?.gallery_description ?? '').trim();
        }
    } catch (e) {
        console.warn('Invalid article meta JSON', e);
    }

    try {
        const faqsEl = document.getElementById('seo-article-initial-faqs');
        const rawFaqs = faqsEl?.textContent?.trim();
        if (rawFaqs) {
            initialFaqs = JSON.parse(rawFaqs);
        }
    } catch (e) {
        console.warn('Invalid article FAQs JSON for editor', e);
    }

    return {
        initialHtml,
        initialSeo,
        editorSettings,
        initialPostImages,
        initialSupplementalImages,
        articleId,
        siteId,
        articleTitle,
        articlePostType,
        contentRevision,
        supportsProductGallery,
        productCategoryOptions,
        initialProductGallery,
        aiDebug,
        initialVirtualReviews,
        mediaPickerUrl,
        initialFaqs,
        initialLoaiSanPham,
        initialGalleryDescription,
    };
}

function mountArticleEditorPage() {
    const rootElement = document.getElementById('seo-article-editor-root');
    if (!rootElement) {
        return;
    }

    const bootstrap = readArticleEditorBootstrap();
    const {
        initialHtml,
        initialSeo,
        editorSettings,
        initialPostImages,
        initialSupplementalImages,
        articleId,
        siteId,
        articleTitle,
        articlePostType,
        contentRevision,
        supportsProductGallery,
        productCategoryOptions,
        initialProductGallery,
        aiDebug,
        initialVirtualReviews,
        mediaPickerUrl,
        initialFaqs,
        initialLoaiSanPham,
        initialGalleryDescription,
    } = bootstrap;

    if (articleId && initialProductGallery.length > 0) {
        saveProductAlbum(articleId, initialProductGallery);
    }

    getOrCreateReactRoot(rootElement).render(
        <SeoArticleEditor
            articleId={articleId}
            siteId={siteId}
            initialHtml={initialHtml}
            initialSeo={initialSeo}
            initialPostImages={initialPostImages}
            initialSupplementalImages={initialSupplementalImages}
            initialPostType={articlePostType}
            contentRevision={contentRevision}
            supportsProductGallery={supportsProductGallery}
            productCategoryOptions={productCategoryOptions}
            initialProductGallery={initialProductGallery}
            initialFaqs={initialFaqs}
            initialVirtualReviews={initialVirtualReviews}
            articleTitle={articleTitle}
            editorSettings={editorSettings}
            mediaPickerUrl={mediaPickerUrl}
            initialLoaiSanPham={initialLoaiSanPham}
            initialGalleryDescription={initialGalleryDescription}
        />,
    );

    const showLinkWidgets = editorSettings?.show_link_widgets !== false;

    const linksRoot = document.getElementById('seo-article-links-root');
    if (showLinkWidgets && linksRoot) {
        getOrCreateReactRoot(linksRoot).render(<ArticleLinksSidebar />);
    }

    const domainWidgetsRoot = document.getElementById('seo-article-domain-widgets-root');
    if (showLinkWidgets && domainWidgetsRoot) {
        getOrCreateReactRoot(domainWidgetsRoot).render(
            <ArticleDomainWidgetsSidebar
                initialDomainLinkList={initialSeo?.domain_link_list ?? []}
                initialDomainLinkCatalog={initialSeo?.domain_link_list_catalog ?? []}
                initialDomainCtaList={initialSeo?.domain_cta_list ?? []}
            />,
        );
    }

    const launcherRoot = document.getElementById('seo-article-ai-launcher-root');
    if (launcherRoot) {
        getOrCreateReactRoot(launcherRoot).render(<ArticleAiFloatingLauncher />);
    }

    const chatRoot = document.getElementById('seo-article-ai-chat-root');
    if (chatRoot) {
        getOrCreateReactRoot(chatRoot).render(
            <ArticleAiChatPanel
                articleId={articleId}
                aiDebug={aiDebug}
                canGenerateImage={editorSettings?.can_generate_image !== false}
                canGenerateVideo={editorSettings?.can_generate_video === true}
            />,
        );
    }

    const faqRoot = document.getElementById('seo-article-faq-root');
    if (faqRoot) {
        let faqInitialFaqs = initialFaqs;
        let initialExtractDebug = null;
        let canGenerateFaq = false;
        let canImportMarkdownFaq = false;
        try {
            const configEl = document.getElementById('seo-article-faq-config');
            const rawConfig = configEl?.textContent?.trim();
            if (rawConfig) {
                const config = JSON.parse(rawConfig);
                canGenerateFaq = Boolean(config?.can_generate_faq);
                canImportMarkdownFaq = Boolean(config?.can_import_markdown_faq);
            }
        } catch (e) {
            console.warn('Invalid article FAQ config JSON', e);
        }
        try {
            const faqsEl = document.getElementById('seo-article-initial-faqs');
            const rawFaqs = faqsEl?.textContent?.trim();
            if (rawFaqs) {
                faqInitialFaqs = JSON.parse(rawFaqs);
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

        getOrCreateReactRoot(faqRoot).render(
            <ArticleFaqEditor
                articleId={articleId}
                initialFaqs={faqInitialFaqs}
                initialExtractDebug={initialExtractDebug}
                canGenerateFaq={canGenerateFaq}
                canImportMarkdownFaq={canImportMarkdownFaq}
            />,
        );
    }
}

mountArticleEditorPage();
registerFilamentHeaderActionsPersistence();
document.addEventListener('livewire:navigated', mountArticleEditorPage);
