import React from 'react';
import { createRoot } from 'react-dom/client';
import SeoArticleEditor from './components/SeoArticleEditor';
import ArticleAiFloatingLauncher from './components/ArticleAiFloatingLauncher';
import ArticleEditorModuleHost from './components/ArticleEditorModuleHost';
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
import { loadFaqDraft, saveOutline, clearDraft } from './utils/articleEditorStorage';
import { registerFilamentHeaderActionsPersistence } from './utils/articleEditorHeaderActions';
import { installArticleEditorStickyHeaderBridge } from './utils/articleEditorStickyHeader';
import { normalizeArticleSlug } from './utils/articleSlugUtils';
import {
    buildArticleEditorApiPayload,
    finishArticleEditorApiAction,
    finishArticleSaveFromApi,
    handleArticleSaveConflict,
    patchPermalinkDisplay,
    syncArticleToWordPressViaApi,
} from './utils/articleEditorApi';
import { seoArticleApiFetch } from './utils/seoArticleApi';
import { saveArticleViaApiSingleFlight } from './utils/articleEditorSaveQueue';
import {
    loadFeaturedImage,
    persistFeaturedImageDraftToServer,
    saveFeaturedImage,
} from './utils/articleFeaturedImageStorage';
import {
    appendProductAlbumItems,
    loadProductAlbum,
    mergeProductAlbumBootstrap,
    normalizeProductAlbumList,
    persistProductAlbumDraftToServer,
    removeProductAlbumItem,
    reorderProductAlbum,
    saveProductAlbum,
    syncProductAlbumToServer,
} from './utils/articleProductAlbumStorage';
import { installArticleAutosaveLock } from './utils/articleAutosaveLock';
import { installArticleOperationTracker } from './utils/articleOperationTracker';
import { mountArticleTitlePromptHook } from './utils/articleTitlePromptHook';
import './utils/seoAssistantNavigator';
import {
    applyFetchedWpCategories,
    loadWpCategoryIds,
    saveWpCategoryIds,
} from './utils/articleWpCategoriesStorage';
installArticleAutosaveLock();
installArticleOperationTracker();
window.__ARTICLE_EDITOR_UI_REVISION__ = 'sticky-help-v1';
window.__ARTICLE_EDITOR_HELP__ = { revision: 'sticky-help-v1' };

function installArticleEditorPageBodyClass() {
    const page = document.querySelector('.seo-article-edit-page, .article-editor-page, [data-article-editor-page]');
    if (!page) {
        return () => {};
    }

    document.body.classList.add('article-editor-page');
    document.documentElement.classList.add('article-editor-page');

    return () => {
        if (!document.querySelector('.seo-article-edit-page, .article-editor-page, [data-article-editor-page]')) {
            document.body.classList.remove('article-editor-page');
            document.documentElement.classList.remove('article-editor-page');
        }
    };
}

queueMicrotask(() => {
    if (window.__SEO_EDITOR_EXITING__) {
        return;
    }

    const activeOp = window.__SEO_ACTIVE_ARTICLE_OPERATION__;
    const articleId = Number(activeOp?.article_id ?? 0);
    if (activeOp && typeof activeOp === 'object' && articleId > 0) {
        // WP sync queued/processing → tracker redirect Sync Queue (không Elapsed).
        window.__seoArticleOperationTracker?.apply?.(articleId, activeOp);

        return;
    }
    if (articleId > 0) {
        window.__seoArticleOperationTracker?.bootstrap?.(articleId);
    }
});

window.addEventListener('seo-editor-slug-updated', (event) => {
    const detail = event?.detail ?? {};
    patchPermalinkDisplay({
        permalink: detail.permalink,
        article_slug: detail.article_slug ?? detail.slug,
        slug: detail.slug,
        permalink_base: detail.permalink_base,
        permalink_suffix: detail.permalink_suffix,
    });
});

window.addEventListener('seo-article-pipeline-rerun-completed', (event) => {
    const articleId = Number(event?.detail?.articleId ?? 0);
    if (!Number.isFinite(articleId) || articleId <= 0) {
        return;
    }

    let siteId = 0;
    try {
        const meta = document.getElementById('seo-article-meta')?.textContent;
        siteId = Number(meta ? JSON.parse(meta)?.site_id : 0) || 0;
    } catch (_error) {
        siteId = 0;
    }

    clearArticleLocalState(articleId, siteId);
    window.setTimeout(() => {
        window.location.reload();
    }, 150);
});

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

window.__seoExecuteHeavyArticleAction = async function executeHeavyArticleAction(
    action,
    wire,
    { renameImagesBeforeWpSync = false } = {},
) {
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

        const editorBundle = await collect({
            renameImagesBeforeWpSync:
                normalizedAction === 'sync' && renameImagesBeforeWpSync === true,
        });
        const html = String(editorBundle?.html ?? '').trim();
        if (!html) {
            throw new Error('Không thu thập được nội dung bài viết.');
        }

        const articleId = Number(editorBundle?.articleId ?? 0);
        if (!Number.isFinite(articleId) || articleId <= 0) {
            throw new Error('Không xác định được ID bài viết.');
        }

        const siteId = Number(
            document.getElementById('seo-article-meta')?.textContent
                ? JSON.parse(document.getElementById('seo-article-meta').textContent)?.site_id
                : 0,
        );

        if (normalizedAction === 'sync') {
            const syncMode = document.querySelector('[data-seo-sync-mode]')?.getAttribute('data-seo-sync-mode');
            window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
                syncMode === 'project_local_save'
                    ? 'Đang lưu workspace…'
                    : 'Đang đưa vào hàng đợi…',
            );
            const apiPayload = buildArticleEditorApiPayload(editorBundle, wire);
            const result = await syncArticleToWordPressViaApi(articleId, apiPayload);
            finishArticleEditorApiAction(result, articleId, siteId, 'sync');
            return;
        } else {
            window.__seoArticleHeavyActionOverlay?.setStatusMessage?.('Đang lưu bài viết…');
            try {
                const result = await saveArticleViaApiSingleFlight(articleId, async () => {
                    const freshCollect = window.__seoCollectEditorHeavyBundle;
                    let bundle = editorBundle;
                    if (typeof freshCollect === 'function') {
                        try {
                            bundle = await freshCollect({ renameImagesBeforeWpSync: false });
                        } catch {
                            bundle = editorBundle;
                        }
                    }

                    return buildArticleEditorApiPayload(bundle, wire);
                });
                finishArticleSaveFromApi(result, {
                    articleId,
                    siteId,
                    connectionHash: window.__SEO_EDITOR_CONNECTION_HASH__ ?? '',
                    savedHtml: String(window.__SEO_EDITOR_LAST_SAVE_HTML__ ?? editorBundle.html ?? ''),
                });
                window.__seoResetPublishTabPrimed?.();
            } catch (error) {
                if (error?.conflict) {
                    handleArticleSaveConflict(error);

                    return;
                }

                throw error;
            }
        }
    } catch (error) {
        window.__seoEndArticleHeavyActionClient?.();
        throw error;
    }
};

/**
 * Lưu / đồng bộ qua REST (dùng từ Alpine editor-html-collected).
 *
 * @param {'save'|'sync'} action
 * @param {object|null|undefined} wire
 * @param {{ html?: string, seoAnalysis?: object|null, articleId?: number }} [editorDetail]
 */
async function runArticleEditorApiAction(action, wire, editorDetail = {}) {
    const normalizedAction = action === 'sync' ? 'sync' : 'save';

    if (!window.__seoArticleHeavyActionOverlay?.locked) {
        window.__seoBeginArticleHeavyActionClient?.(normalizedAction);
    }

    await window.__seoYieldForHeavyActionPaint?.();

    const html = String(editorDetail.html ?? '').trim();
    if (!html) {
        window.__seoEndArticleHeavyActionClient?.();
        throw new Error('Không thu thập được nội dung bài viết.');
    }

    const articleId = Number(editorDetail.articleId ?? 0);
    if (!Number.isFinite(articleId) || articleId <= 0) {
        window.__seoEndArticleHeavyActionClient?.();
        throw new Error('Không xác định được ID bài viết.');
    }

    let siteId = 0;
    try {
        const metaEl = document.getElementById('seo-article-meta');
        const meta = metaEl?.textContent?.trim() ? JSON.parse(metaEl.textContent) : {};
        siteId = Number(meta?.site_id ?? 0);
    } catch {
        siteId = 0;
    }

    const editorBundle = {
        articleId,
        html,
        seoAnalysis: editorDetail.seoAnalysis ?? null,
    };

    const apiPayload = buildArticleEditorApiPayload(editorBundle, wire);

    try {
        if (normalizedAction === 'sync') {
            window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
                'Đang đưa bài vào hàng đợi đồng bộ WordPress…',
            );
            const result = await syncArticleToWordPressViaApi(articleId, apiPayload);
            finishArticleEditorApiAction(result, articleId, siteId, 'sync');
            return;
        } else {
            window.__seoArticleHeavyActionOverlay?.setStatusMessage?.('Đang lưu bài viết…');
            try {
                const result = await saveArticleViaApiSingleFlight(articleId, async () => {
                    const freshHtml = String(
                        typeof window.__seoCollectEditorHeavyBundle === 'function'
                            ? (await window.__seoCollectEditorHeavyBundle({ renameImagesBeforeWpSync: false }))?.html
                                ?? apiPayload.html
                            : apiPayload.html,
                    );

                    return buildArticleEditorApiPayload(
                        {
                            articleId,
                            html: freshHtml,
                            seoAnalysis: editorDetail.seoAnalysis ?? null,
                        },
                        wire,
                    );
                });
                finishArticleSaveFromApi(result, {
                    articleId,
                    siteId,
                    connectionHash: window.__SEO_EDITOR_CONNECTION_HASH__ ?? '',
                    savedHtml: String(window.__SEO_EDITOR_LAST_SAVE_HTML__ ?? apiPayload.html ?? ''),
                });
            } catch (error) {
                if (error?.conflict) {
                    handleArticleSaveConflict(error);

                    return;
                }

                throw error;
            }
        }
    } catch (error) {
        window.__seoEndArticleHeavyActionClient?.();
        throw error;
    }
}

window.__seoRunArticleEditorApiAction = runArticleEditorApiAction;

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
    syncProductAlbumToServer(articleId);
});

/** Livewire 3 có thể gửi params dạng object, mảng, hoặc nhiều argument — chuẩn hóa cho listener window. */
function normalizeLivewireEventDetail(payload) {
    if (payload == null) {
        return {};
    }
    if (Array.isArray(payload)) {
        if (payload.length === 1 && payload[0] != null && typeof payload[0] === 'object') {
            return payload[0];
        }

        const merged = {};
        for (const item of payload) {
            if (item != null && typeof item === 'object' && !Array.isArray(item)) {
                Object.assign(merged, item);
            }
        }

        return Object.keys(merged).length > 0 ? merged : { params: payload };
    }

    if (typeof payload === 'object') {
        // Livewire Event wrapper: { detail: {...} } hoặc { params: [...] }
        if (payload.detail != null && typeof payload.detail === 'object' && !Array.isArray(payload.detail)) {
            return payload.detail;
        }
        if (Array.isArray(payload.params)) {
            return normalizeLivewireEventDetail(payload.params);
        }

        return payload;
    }

    return {};
}

function mergeLivewireForwardArgs(args) {
    if (!Array.isArray(args) || args.length === 0) {
        return {};
    }

    if (args.length === 1) {
        return normalizeLivewireEventDetail(args[0]);
    }

    const merged = {};
    for (const arg of args) {
        Object.assign(merged, normalizeLivewireEventDetail(arg));
    }

    return merged;
}

function registerArticleEditorLivewireBridge() {
    if (window.__seoArticleLivewireBridgeRegistered) {
        return;
    }
    window.__seoArticleLivewireBridgeRegistered = true;

    /** Livewire 3 listens to window events with the same name — prevent echo loops. */
    const forwardingLivewireEvents = new Set();

    const forward = (name) => (...args) => {
        if (forwardingLivewireEvents.has(name)) {
            return;
        }

        forwardingLivewireEvents.add(name);
        try {
            window.dispatchEvent(
                new CustomEvent(name, {
                    detail: mergeLivewireForwardArgs(args),
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
        Livewire.on('seo-attachment-slugs-rename-finished', forward('seo-attachment-slugs-rename-finished'));
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
    let editorSettings = { history_step: 20, autosave_interval_seconds: 2 };
    let initialPostImages = [];
    let initialSupplementalImages = [];
    let articleId = null;
    let siteId = null;
    let articleTitle = '';
    let articlePostType = '';
    let contentRevision = '';
    let connectionHash = '';
    let expectedUpdatedAt = '';
    let expectedContentHash = '';
    let supportsProductGallery = false;
    let isCanaryProduct = false;
    let productCategoryOptions = [];
    let initialProductGallery = [];
    let aiDebug = { enabled: false };
    let initialVirtualReviews = [];
    let mediaPickerUrl = '';
    let initialFaqs = [];
    let initialLoaiSanPham = '';
    let initialGalleryDescription = '';
    let lazyEndpoints = {};
    let aiHistoryPendingApply = null;

    // Phase 2 primary: single core bootstrap.
    try {
        const coreEl = document.getElementById('seo-article-core-bootstrap');
        const rawCore = coreEl?.textContent?.trim();
        if (rawCore) {
            const core = JSON.parse(rawCore);
            articleId = core?.articleId ?? core?.id ?? null;
            siteId = core?.siteId ?? core?.site_id ?? null;
            articleTitle = String(core?.title ?? '');
            articlePostType = String(core?.postType ?? core?.post_type ?? '').trim();
            contentRevision = String(core?.contentRevision ?? core?.content_revision ?? '').trim();
            connectionHash = String(core?.connectionHash ?? core?.seo_connection_hash ?? '').trim();
            expectedUpdatedAt = String(core?.expectedUpdatedAt ?? core?.expected_updated_at ?? '').trim();
            expectedContentHash = String(core?.expectedContentHash ?? core?.expected_content_hash ?? '').trim();
            supportsProductGallery = Boolean(core?.supportsProductGallery ?? core?.supports_product_gallery);
            isCanaryProduct = Boolean(core?.isCanaryProduct ?? core?.is_canary_product);
            initialHtml = typeof core?.content === 'string' ? core.content : '';
            if (core?.aiHistoryPendingApply && typeof core.aiHistoryPendingApply === 'object') {
                aiHistoryPendingApply = core.aiHistoryPendingApply;
            }
            if (core?.settings && typeof core.settings === 'object') {
                editorSettings = { ...editorSettings, ...core.settings };
            }
            if (core?.endpoints && typeof core.endpoints === 'object') {
                lazyEndpoints = core.endpoints;
            }
            if (typeof core?.featuredImageUrl === 'string' && core.featuredImageUrl.trim() !== '') {
                // Featured URL available; images catalog still lazy.
            }
            // Light SERP / SEO identity from core — never wait for seo-summary to paint preview.
            if (!initialSeo) {
                const title = String(core?.title ?? '').trim();
                const slug = String(core?.slug ?? '').trim();
                const metaDescription = String(core?.metaDescription ?? core?.meta_description ?? '').trim();
                const permalinkBase = String(core?.permalinkBase ?? core?.permalink_base ?? '').trim();
                const permalinkSuffix = String(core?.permalinkSuffix ?? core?.permalink_suffix ?? '').trim();
                const siteDomain = String(core?.siteDomain ?? core?.site_domain ?? '').trim();
                const path = [slug, permalinkSuffix.replace(/^\//, '')].filter(Boolean).join('/');
                const url = permalinkBase !== ''
                    ? `${permalinkBase.replace(/\/$/, '')}/${path}`
                    : (siteDomain !== '' ? `https://${siteDomain.replace(/^https?:\/\//i, '')}/${path}` : '#');
                let displayHost = siteDomain;
                try {
                    if (permalinkBase) {
                        displayHost = new URL(
                            permalinkBase.includes('://') ? permalinkBase : `https://${permalinkBase}`,
                        ).hostname;
                    }
                } catch {
                    displayHost = siteDomain || permalinkBase;
                }
                initialSeo = {
                    google_serp_preview: {
                        title,
                        description: metaDescription,
                        url,
                        display_url: displayHost
                            ? (path ? `${displayHost} › ${path.replace(/\//g, ' › ')}` : displayHost)
                            : '#',
                    },
                    article_slug: slug,
                    site_domain: siteDomain,
                    permalink_base: permalinkBase,
                    permalink_suffix: permalinkSuffix,
                    focus_keyword: core?.focusKeyword ?? core?.focus_keyword ?? null,
                    meta_description: metaDescription,
                    skip_seo_score: false,
                };
            }
        }
    } catch (e) {
        console.warn('Invalid seo-article-core-bootstrap JSON', e);
    }

    // Legacy fallbacks (older cached HTML) — only fill gaps, do not prefer over core.
    try {
        if (!initialHtml) {
            const htmlEl = document.getElementById('seo-article-initial-html');
            const raw = htmlEl?.textContent?.trim();
            if (raw) {
                initialHtml = JSON.parse(raw);
            }
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
            editorSettings = { ...editorSettings, ...JSON.parse(rawSettings) };
        }
    } catch (e) {
        console.warn('Invalid editor settings JSON', e);
    }

    try {
        const metaEl = document.getElementById('seo-article-meta');
        const rawMeta = metaEl?.textContent?.trim();
        if (rawMeta) {
            const meta = JSON.parse(rawMeta);
            articleId = articleId ?? meta?.id ?? null;
            siteId = siteId ?? meta?.site_id ?? meta?.siteId ?? null;
            if (!articleTitle) articleTitle = meta?.title ?? '';
            if (!articlePostType) articlePostType = String(meta?.post_type ?? '').trim();
            if (!contentRevision) contentRevision = String(meta?.content_revision ?? '').trim();
            if (!connectionHash) connectionHash = String(meta?.seo_connection_hash ?? '').trim();
            if (!expectedUpdatedAt) expectedUpdatedAt = String(meta?.expected_updated_at ?? '').trim();
            if (!expectedContentHash) expectedContentHash = String(meta?.expected_content_hash ?? '').trim();
            supportsProductGallery = supportsProductGallery || Boolean(meta?.supports_product_gallery);
            isCanaryProduct = isCanaryProduct || Boolean(meta?.is_canary_product);
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

    window.__SEO_EDITOR_LAZY_ENDPOINTS__ = lazyEndpoints;
    if (connectionHash) {
        window.__SEO_CONNECTION_HASH__ = connectionHash;
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
        connectionHash,
        expectedUpdatedAt,
        expectedContentHash,
        supportsProductGallery,
        isCanaryProduct,
        productCategoryOptions,
        initialProductGallery,
        aiDebug,
        initialVirtualReviews,
        mediaPickerUrl,
        initialFaqs,
        initialLoaiSanPham,
        initialGalleryDescription,
        lazyEndpoints,
        aiHistoryPendingApply,
    };
}

function mountArticleEditorPage() {
    const rootElement = document.getElementById('seo-article-editor-root');
    if (!rootElement) {
        return;
    }

    const livewireId = String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '');
    // One React root per DOM node — skip identical remount for same Livewire page id.
    if (
        rootElement.__seoArticleReactRoot
        && rootElement.__seoMountedLivewireId === livewireId
        && livewireId !== ''
    ) {
        return;
    }

    // Phase 3: cleanup idle timers/fetches from previous navigate before remount.
    const previousCleanups = window.__seoArticleEditorPageCleanups;
    if (Array.isArray(previousCleanups)) {
        while (previousCleanups.length > 0) {
            const fn = previousCleanups.pop();
            try {
                fn?.();
            } catch {
                // ignore cleanup errors
            }
        }
    }
    const pageCleanups = [];
    window.__seoArticleEditorPageCleanups = pageCleanups;
    rootElement.__seoMountedLivewireId = livewireId;

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
        connectionHash,
        expectedUpdatedAt,
        expectedContentHash,
        supportsProductGallery,
        isCanaryProduct,
        productCategoryOptions,
        initialProductGallery,
        aiDebug,
        initialVirtualReviews,
        mediaPickerUrl,
        initialFaqs,
        initialLoaiSanPham,
        initialGalleryDescription,
        lazyEndpoints,
        aiHistoryPendingApply,
    } = bootstrap;

    // Manual AI History apply: nạp outline/content vào draft session, không gọi AI.
    if (articleId && aiHistoryPendingApply && typeof aiHistoryPendingApply === 'object') {
        const pendingTarget = String(aiHistoryPendingApply.target ?? '').trim();
        const pendingPayload = String(aiHistoryPendingApply.payload ?? '');
        if (pendingTarget === 'outline' && pendingPayload !== '') {
            saveOutline(articleId, pendingPayload);
        }
        if (pendingTarget === 'content' && pendingPayload !== '') {
            clearDraft(articleId, connectionHash, { siteId: Number(siteId ?? 0) || 0 });
        }
    }

    window.__SEO_EDITOR_CONFLICT__ = {
        expected_updated_at: expectedUpdatedAt || null,
        expected_content_hash: expectedContentHash || null,
    };
    window.__SEO_EDITOR_CONNECTION_HASH__ = connectionHash || '';
    window.__SEO_ARTICLE_SITE_ID__ = Number(siteId ?? 0) || 0;
    window.__SEO_EDITOR_LAZY_ENDPOINTS__ = lazyEndpoints || window.__SEO_EDITOR_LAZY_ENDPOINTS__ || {};

    const perfDebugEnabled = Boolean(window.__SEO_ARTICLE_EDITOR_PERF_DEBUG__ || editorSettings?.perf_debug);
    if (perfDebugEnabled && typeof performance !== 'undefined' && typeof performance.mark === 'function') {
        performance.mark('seo-article-editor-mount-start');
    }

    if (articleId && initialProductGallery.length > 0) {
        saveProductAlbum(articleId, mergeProductAlbumBootstrap(initialProductGallery, articleId));
    }

    const scoringRules = Array.isArray(editorSettings?.seo_scoring_rules) && editorSettings.seo_scoring_rules.length > 0
        ? editorSettings.seo_scoring_rules
        : (Array.isArray(initialSeo?.seo_scoring_rules) ? initialSeo.seo_scoring_rules : []);
    if (scoringRules.length > 0) {
        window.__SEO_SCORING_RULES__ = scoringRules;
    }
    const scoringMessages = editorSettings?.seo_rule_messages && typeof editorSettings.seo_rule_messages === 'object'
        ? editorSettings.seo_rule_messages
        : (initialSeo?.seo_rule_messages && typeof initialSeo.seo_rule_messages === 'object'
            ? initialSeo.seo_rule_messages
            : {});
    if (Object.keys(scoringMessages).length > 0) {
        window.__SEO_RULE_MESSAGES__ = scoringMessages;
    }

    getOrCreateReactRoot(rootElement).render(
        <>
            <SeoArticleEditor
                articleId={articleId}
                siteId={siteId}
                initialHtml={initialHtml}
                initialSeo={initialSeo}
                initialPostImages={initialPostImages}
                initialSupplementalImages={initialSupplementalImages}
                initialPostType={articlePostType}
                contentRevision={contentRevision}
                connectionHash={connectionHash}
                expectedUpdatedAt={expectedUpdatedAt}
                expectedContentHash={expectedContentHash}
                supportsProductGallery={supportsProductGallery}
                isCanaryProduct={isCanaryProduct}
                productCategoryOptions={productCategoryOptions}
                initialProductGallery={initialProductGallery}
                initialFaqs={[]}
                initialVirtualReviews={[]}
                articleTitle={articleTitle}
                editorSettings={editorSettings}
                mediaPickerUrl={mediaPickerUrl}
                initialLoaiSanPham={initialLoaiSanPham}
                initialGalleryDescription={initialGalleryDescription}
                perfDebug={perfDebugEnabled}
            />
            <ArticleEditorModuleHost
                articleId={articleId}
                siteId={siteId}
                aiDebug={aiDebug}
                canGenerateImage={editorSettings?.can_generate_image !== false}
                canGenerateVideo={editorSettings?.can_generate_video === true}
                showLinkWidgets={editorSettings?.show_link_widgets !== false}
            />
        </>,
    );

    pageCleanups.push(installArticleEditorStickyHeaderBridge());
    pageCleanups.push(installArticleEditorPageBodyClass());

    const launcherRoot = document.getElementById('seo-article-ai-launcher-root');
    if (launcherRoot) {
        getOrCreateReactRoot(launcherRoot).render(<ArticleAiFloatingLauncher />);
    }

    if (perfDebugEnabled && typeof performance !== 'undefined' && typeof performance.mark === 'function') {
        performance.mark('seo-article-editor-mount-end');
        try {
            performance.measure(
                'seo-article-editor-mount',
                'seo-article-editor-mount-start',
                'seo-article-editor-mount-end',
            );
        } catch {
            // measure API có thể ném lỗi nếu mark thiếu — bỏ qua, không chặn mount.
        }
    }

    // Phase 2/3: light SEO summary + settings idle — abortable; no heavy module fetch.
    if (articleId) {
        const seoSummaryUrl =
            bootstrap.lazyEndpoints?.seoSummary
            || `/api/seo/articles/${articleId}/editor/seo-summary`;
        const settingsUrl =
            bootstrap.lazyEndpoints?.settings
            || `/api/seo/articles/${articleId}/editor/settings`;

        const idleController = new AbortController();
        pageCleanups.push(() => idleController.abort());

        const schedule = typeof requestIdleCallback === 'function'
            ? (cb) => {
                const id = requestIdleCallback(cb, { timeout: 2500 });
                pageCleanups.push(() => {
                    if (typeof cancelIdleCallback === 'function') {
                        cancelIdleCallback(id);
                    }
                });
            }
            : (cb) => {
                const id = setTimeout(cb, 400);
                pageCleanups.push(() => clearTimeout(id));
            };

        schedule(() => {
            if (idleController.signal.aborted) {
                return;
            }
            void (async () => {
                try {
                    const [seoRes, settingsRes] = await Promise.all([
                        seoArticleApiFetch(seoSummaryUrl, { signal: idleController.signal }),
                        seoArticleApiFetch(settingsUrl, { signal: idleController.signal }),
                    ]);
                    if (idleController.signal.aborted) {
                        return;
                    }
                    if (settingsRes.response.ok && settingsRes.data?.success !== false) {
                        const settingsData = settingsRes.data?.data ?? {};
                        if (Array.isArray(settingsData.seo_scoring_rules)) {
                            window.__SEO_SCORING_RULES__ = settingsData.seo_scoring_rules;
                        }
                        if (settingsData.seo_rule_messages && typeof settingsData.seo_rule_messages === 'object') {
                            window.__SEO_RULE_MESSAGES__ = settingsData.seo_rule_messages;
                        }
                        window.dispatchEvent(
                            new CustomEvent('seo-editor-settings-loaded', { detail: settingsData }),
                        );
                    }
                    if (seoRes.response.ok && seoRes.data?.success !== false) {
                        window.dispatchEvent(
                            new CustomEvent('seo-editor-seo-summary-loaded', {
                                detail: seoRes.data?.data ?? {},
                            }),
                        );
                    }
                } catch (e) {
                    if (e?.name === 'AbortError') {
                        return;
                    }
                    console.warn('Failed to load SEO summary/settings', e);
                }
            })();
        });
    }
}

mountArticleEditorPage();
mountArticleTitlePromptHook();
registerFilamentHeaderActionsPersistence();

if (!window.__seoArticleEditorNavigatedBound) {
    window.__seoArticleEditorNavigatedBound = true;
    document.addEventListener('livewire:navigated', () => {
        // Force remount on navigate — clear same-id guard so new DOM gets a fresh root.
        const rootElement = document.getElementById('seo-article-editor-root');
        if (rootElement) {
            rootElement.__seoMountedLivewireId = null;
        }
        if (!document.querySelector('.seo-article-edit-page, [data-article-editor-page]')) {
            document.body.classList.remove('article-editor-page');
            document.documentElement.classList.remove('article-editor-page');
        }
        mountArticleEditorPage();
        mountArticleTitlePromptHook();
    });
}

if (typeof window !== 'undefined') {
    if (!window.__seoArticleEditorMorphBound) {
        window.__seoArticleEditorMorphBound = true;
        const bindMorph = () => {
            if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
                return;
            }
            Livewire.hook('morph.updated', () => {
                mountArticleTitlePromptHook();
            });
        };
        document.addEventListener('livewire:init', bindMorph);
        bindMorph();
    }
}
