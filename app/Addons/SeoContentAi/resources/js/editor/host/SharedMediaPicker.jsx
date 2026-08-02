import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Plus, RefreshCw, X } from 'lucide-react';
import { EditorModuleErrorBoundary } from '../runtime/EditorModuleErrorBoundary';
import {
    closeMediaPicker,
    confirmMediaPicker,
    patchMediaPickerSelection,
    subscribeMediaPicker,
} from '../runtime/editorMediaPickerStore';
import { csrfToken, seoArticleApiFetch } from '../../utils/seoArticleApi';
import { t } from '../../utils/i18n';
import { canMutateEditor } from '../../utils/editorSessionState';
import { getEditorCommandHost } from '../../utils/editorCommands';
import {
    addCustomPickerTab,
    loadCustomPickerTabs,
    normalizeArticleDomain,
    removeCustomPickerTab,
} from '../../utils/articleMediaPickerCustomTabs';

const CACHE_TTL_MS = 45_000;

function imageKey(image) {
    const id = Number(image?.seo_media_id || image?.wp_attachment_id || 0);
    const url = String(image?.url || '').trim();
    return id > 0 ? `id:${id}` : `url:${url}`;
}

function normalizePickerItem(image) {
    return {
        url: String(image?.url || '').trim(),
        alt: String(image?.alt || '').trim(),
        slug: String(image?.slug || '').trim(),
        wp_attachment_id: Number(image?.wp_attachment_id || 0) || 0,
        seo_media_id: Number(image?.seo_media_id || 0) || 0,
        media_type: String(image?.media_type || 'image').toLowerCase() === 'video' ? 'video' : 'image',
        source: String(image?.source || '').trim(),
    };
}

function dedupePickerImages(list) {
    const seen = new Set();
    const out = [];
    for (const image of Array.isArray(list) ? list : []) {
        const key = imageKey(image);
        if (!key || key === 'url:' || seen.has(key)) continue;
        seen.add(key);
        out.push(image);
    }
    return out;
}

function cacheKey(articleId, tab, page, search) {
    return `${articleId}|${tab}|${page}|${String(search || '').trim().toLowerCase()}`;
}

function emptyTabState() {
    return {
        images: [],
        page: 1,
        totalPages: 1,
        search: '',
        loading: false,
        error: '',
        loadedAt: 0,
        requestId: 0,
    };
}

/**
 * Shared Media Picker — immediate tab switch + in-memory SWR cache.
 */
export function SharedMediaPicker({
    articleId = null,
    rootEl = null,
    wordpressAvailable = true,
    articleDomain = '',
}) {
    const [picker, setPicker] = useState(null);
    const [sessionId, setSessionId] = useState(0);
    const [tab, setTab] = useState('article');
    const [tabStates, setTabStates] = useState(() => ({}));
    const [selectedKeys, setSelectedKeys] = useState([]);
    const [selectedItems, setSelectedItems] = useState({});
    const [confirming, setConfirming] = useState(false);
    const [customTabs, setCustomTabs] = useState([]);

    const wasOpenRef = useRef(false);
    const cacheRef = useRef(new Map());
    const inFlightRef = useRef(new Map());
    const requestSeqRef = useRef(0);
    const customTabsRef = useRef([]);
    const tabRef = useRef(tab);

    const id = Number(articleId ?? getEditorCommandHost()?.articleId ?? 0) || 0;
    const domain = normalizeArticleDomain(articleDomain || window.__SEO_ARTICLE_DOMAIN__ || '');

    useEffect(() => {
        tabRef.current = tab;
    }, [tab]);

    useEffect(() => {
        customTabsRef.current = customTabs;
    }, [customTabs]);

    const patchTabState = useCallback((tabId, patch) => {
        setTabStates((prev) => {
            const current = prev[tabId] || emptyTabState();
            return { ...prev, [tabId]: { ...current, ...patch } };
        });
    }, []);

    const applyCachedToTab = useCallback((tabId, entry) => {
        if (!entry) return;
        patchTabState(tabId, {
            images: entry.images,
            page: entry.page,
            totalPages: entry.totalPages,
            search: entry.search,
            error: '',
            loadedAt: entry.loadedAt,
            loading: false,
        });
    }, [patchTabState]);

    const fetchRemote = useCallback(async (apiTab, tabId, nextPage, nextSearch, { skipCache = false } = {}) => {
        if (!id) return;
        const key = cacheKey(id, tabId, nextPage, nextSearch);
        const cached = cacheRef.current.get(key);
        const fresh = cached && (Date.now() - cached.loadedAt) < CACHE_TTL_MS;

        if (!skipCache && cached) {
            applyCachedToTab(tabId, cached);
            if (fresh) return;
        }

        if (inFlightRef.current.has(key) && !skipCache) {
            patchTabState(tabId, { loading: !cached });
            try {
                await inFlightRef.current.get(key);
            } catch {
                // ignore
            }
            return;
        }

        const requestId = ++requestSeqRef.current;
        patchTabState(tabId, {
            loading: !cached,
            error: '',
            requestId,
            page: nextPage,
            search: nextSearch,
        });

        const cacheBust = skipCache ? `&_=${Date.now()}` : '';
        const url = `/seo/articles/${id}/media-picker?tab=${encodeURIComponent(apiTab)}&page=${nextPage}&search=${encodeURIComponent(nextSearch || '')}${cacheBust}`;

        const promise = (async () => {
            const { response, data } = await seoArticleApiFetch(url, {
                headers: {
                    Accept: 'application/json',
                    ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
                },
            });
            if (!response.ok) {
                throw new Error(String(data?.error || data?.wordpress_media_unavailable_reason || `HTTP ${response.status}`));
            }
            return {
                images: dedupePickerImages(data?.images),
                page: Math.max(1, Number(data?.page) || nextPage),
                totalPages: Math.max(1, Number(data?.totalPages) || 1),
                search: nextSearch,
                loadedAt: Date.now(),
            };
        })();

        inFlightRef.current.set(key, promise);
        try {
            const entry = await promise;
            cacheRef.current.set(key, entry);
            setTabStates((prev) => {
                const current = prev[tabId] || emptyTabState();
                if (current.requestId !== requestId && current.requestId > requestId) {
                    return prev;
                }
                return {
                    ...prev,
                    [tabId]: {
                        ...current,
                        images: entry.images,
                        page: entry.page,
                        totalPages: entry.totalPages,
                        search: entry.search,
                        loadedAt: entry.loadedAt,
                        loading: false,
                        error: '',
                        requestId,
                    },
                };
            });
        } catch (err) {
            setTabStates((prev) => {
                const current = prev[tabId] || emptyTabState();
                if (current.requestId !== requestId && current.images.length > 0) {
                    return {
                        ...prev,
                        [tabId]: { ...current, loading: false },
                    };
                }
                return {
                    ...prev,
                    [tabId]: {
                        ...current,
                        loading: false,
                        error: String(err?.message || 'picker_load_failed'),
                        images: current.images,
                    },
                };
            });
        } finally {
            inFlightRef.current.delete(key);
        }
    }, [applyCachedToTab, id, patchTabState]);

    const fetchArticle = useCallback((tabId, { skipCache = false } = {}) => {
        const key = cacheKey(id, 'article', 1, '');
        const cached = cacheRef.current.get(key);
        const fresh = cached && (Date.now() - cached.loadedAt) < CACHE_TTL_MS;
        if (!skipCache && cached) {
            applyCachedToTab(tabId, cached);
            if (fresh) return;
        }

        const requestId = ++requestSeqRef.current;
        patchTabState(tabId, { loading: !cached, error: '', requestId, page: 1, search: '' });

        const onCatalog = (event) => {
            const list = dedupePickerImages(event?.detail?.images);
            const entry = {
                images: list,
                page: 1,
                totalPages: 1,
                search: '',
                loadedAt: Date.now(),
            };
            cacheRef.current.set(key, entry);
            setTabStates((prev) => {
                const current = prev[tabId] || emptyTabState();
                if (current.requestId !== requestId && current.requestId > requestId) {
                    return prev;
                }
                return {
                    ...prev,
                    [tabId]: {
                        ...current,
                        ...entry,
                        loading: false,
                        error: '',
                        requestId,
                    },
                };
            });
        };

        window.addEventListener('seo-editor-images-catalog', onCatalog, { once: true });
        window.dispatchEvent(new CustomEvent('seo-request-editor-images-catalog'));
        window.setTimeout(() => {
            window.removeEventListener('seo-editor-images-catalog', onCatalog);
            patchTabState(tabId, { loading: false });
        }, 2500);
    }, [applyCachedToTab, id, patchTabState]);

    const loadTab = useCallback((nextTab, nextPage, nextSearch, options = {}) => {
        if (nextTab === 'article') {
            fetchArticle(nextTab, options);
            return;
        }
        if (String(nextTab).startsWith('custom:')) {
            const customId = String(nextTab).slice('custom:'.length);
            const row = customTabsRef.current.find((item) => item.id === customId);
            const keyword = String(row?.keyword || nextSearch || '').trim();
            void fetchRemote('original', nextTab, nextPage, keyword || nextSearch, options);
            return;
        }
        void fetchRemote(nextTab, nextTab, nextPage, nextSearch, options);
    }, [fetchArticle, fetchRemote]);

    const switchTab = useCallback((nextTab) => {
        if (!nextTab || nextTab === tabRef.current) return;
        setTab(nextTab);
        const state = emptyTabState();
        const custom = String(nextTab).startsWith('custom:')
            ? customTabsRef.current.find((row) => `custom:${row.id}` === nextTab)
            : null;
        const search = custom ? String(custom.keyword || '') : (tabStates[nextTab]?.search || '');
        const page = tabStates[nextTab]?.page || 1;
        const key = cacheKey(id, nextTab, page, search);
        const cached = cacheRef.current.get(key);
        if (cached) {
            applyCachedToTab(nextTab, cached);
        } else {
            setTabStates((prev) => ({
                ...prev,
                [nextTab]: {
                    ...(prev[nextTab] || state),
                    search,
                    page,
                    loading: true,
                },
            }));
        }
        loadTab(nextTab, page, search, { skipCache: false });
    }, [applyCachedToTab, id, loadTab, tabStates]);

    useEffect(() => subscribeMediaPicker((next) => {
        const nowOpen = Boolean(next?.open);
        const becameOpen = nowOpen && !wasOpenRef.current;
        wasOpenRef.current = nowOpen;
        setPicker(next);

        if (!becameOpen) {
            return;
        }

        setSessionId((value) => value + 1);
        setSelectedKeys([]);
        setSelectedItems({});
        setTab('article');
        setTabStates({});
        cacheRef.current.clear();
        inFlightRef.current.clear();
        const tabs = loadCustomPickerTabs(
            normalizeArticleDomain(articleDomain || window.__SEO_ARTICLE_DOMAIN__ || ''),
            Number(articleId ?? 0) || 0,
        );
        setCustomTabs(tabs);
        customTabsRef.current = tabs;
    }), [articleDomain, articleId]);

    // Load/prefetch only on new picker session (not selection patches).
    useEffect(() => {
        if (!picker?.open || !sessionId) return undefined;
        loadTab('article', 1, '', { skipCache: false });
        const timer = window.setTimeout(() => {
            if (wordpressAvailable) {
                void fetchRemote('original', 'original', 1, '', { skipCache: false });
            }
            void fetchRemote('local', 'local', 1, '', { skipCache: false });
        }, 120);
        return () => window.clearTimeout(timer);
    }, [sessionId, picker?.open, loadTab, fetchRemote, wordpressAvailable]);

    const active = tabStates[tab] || emptyTabState();
    const multi = picker?.selection === 'multiple';
    const readOnly = !canMutateEditor() || Boolean(getEditorCommandHost()?.isArchived?.());
    const isCustomTab = String(tab || '').startsWith('custom:');
    const activeCustom = isCustomTab
        ? customTabs.find((row) => `custom:${row.id}` === tab)
        : null;

    const setActiveSearch = (value) => {
        patchTabState(tab, { search: value });
    };

    const runSearch = () => {
        const search = String(active.search || '');
        loadTab(tab, 1, search, { skipCache: true });
    };

    const refreshActiveTab = () => {
        const keyPrefix = `${id}|${tab}|`;
        for (const key of [...cacheRef.current.keys()]) {
            if (key.startsWith(keyPrefix)) {
                cacheRef.current.delete(key);
            }
        }
        loadTab(tab, 1, active.search || '', { skipCache: true });
    };

    const toggleSelect = (image) => {
        if (readOnly) return;
        const key = imageKey(image);
        const item = normalizePickerItem(image);
        if (!item.url) return;

        if (!multi) {
            setSelectedKeys([key]);
            setSelectedItems({ [key]: item });
            patchMediaPickerSelection([key], { [key]: item });
            return;
        }

        setSelectedKeys((prev) => {
            const exists = prev.includes(key);
            const nextKeys = exists ? prev.filter((k) => k !== key) : [...prev, key];
            setSelectedItems((prevItems) => {
                const nextItems = { ...prevItems };
                if (exists) delete nextItems[key];
                else nextItems[key] = item;
                patchMediaPickerSelection(nextKeys, nextItems);
                return nextItems;
            });
            return nextKeys;
        });
    };

    const onConfirm = async () => {
        if (readOnly || selectedKeys.length === 0 || confirming) return;
        setConfirming(true);
        try {
            patchMediaPickerSelection(selectedKeys, selectedItems);
            await confirmMediaPicker();
        } finally {
            setConfirming(false);
        }
    };

    const onAddCustomTab = () => {
        const keyword = window.prompt(t('media_picker_custom_tab_prompt'));
        if (keyword == null) return;
        const created = addCustomPickerTab(domain, keyword, { articleId: id });
        if (!created) return;
        const nextTabs = loadCustomPickerTabs(domain, id);
        setCustomTabs(nextTabs);
        customTabsRef.current = nextTabs;
        switchTab(`custom:${created.id}`);
    };

    const onRemoveCustomTab = (customId, event) => {
        event.stopPropagation();
        removeCustomPickerTab(domain, customId, id);
        const nextTabs = loadCustomPickerTabs(domain, id);
        setCustomTabs(nextTabs);
        customTabsRef.current = nextTabs;
        if (tab === `custom:${customId}`) {
            switchTab('original');
        }
    };

    const title = useMemo(() => {
        if (picker?.mode === 'featured') return t('media_picker_featured_title');
        if (picker?.mode === 'gallery') return t('media_picker_gallery_title');
        return t('media_picker_content_title');
    }, [picker?.mode]);

    if (!picker?.open || !rootEl) {
        return null;
    }

    const body = (
        <EditorModuleErrorBoundary moduleId="article-editor.media-picker" slotName="media.picker">
            <div className="seo-shared-media-picker-overlay" role="dialog" aria-modal="true" aria-label={title}>
                <div className="seo-shared-media-picker" data-active-tab={tab} data-picker-session={sessionId}>
                    <header className="seo-shared-media-picker__header">
                        <h3 className="seo-shared-media-picker__title">{title}</h3>
                        <button type="button" className="seo-shared-media-picker__close" onClick={() => closeMediaPicker()} aria-label="Close">
                            <X size={18} />
                        </button>
                    </header>
                    <div className="seo-shared-media-picker__tabs">
                        <button
                            type="button"
                            className={`seo-shared-media-picker__tab${tab === 'article' ? ' is-active' : ''}`}
                            onClick={() => switchTab('article')}
                            data-media-picker-tab="article"
                        >
                            {t('media_picker_tab_article')}
                        </button>
                        <button
                            type="button"
                            className={`seo-shared-media-picker__tab${tab === 'original' ? ' is-active' : ''}`}
                            onClick={() => switchTab('original')}
                            data-media-picker-tab="original"
                            title={wordpressAvailable ? t('media_picker_tab_wp') : (t('wp_media_unavailable') || 'WP library may be unavailable')}
                        >
                            {t('media_picker_tab_wp')}
                        </button>
                        {customTabs.map((row) => {
                            const tabId = `custom:${row.id}`;
                            return (
                                <button
                                    key={tabId}
                                    type="button"
                                    className={`seo-shared-media-picker__tab seo-shared-media-picker__tab--custom${tab === tabId ? ' is-active' : ''}`}
                                    onClick={() => switchTab(tabId)}
                                    data-media-picker-tab={tabId}
                                    title={row.keyword || row.label}
                                >
                                    <span>{row.label || row.keyword || tabId}</span>
                                    <span
                                        className="seo-shared-media-picker__tab-remove"
                                        role="button"
                                        tabIndex={0}
                                        onClick={(event) => onRemoveCustomTab(row.id, event)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                onRemoveCustomTab(row.id, event);
                                            }
                                        }}
                                        aria-label="Remove tab"
                                    >
                                        ×
                                    </span>
                                </button>
                            );
                        })}
                        <button
                            type="button"
                            className={`seo-shared-media-picker__tab${tab === 'local' ? ' is-active' : ''}`}
                            onClick={() => switchTab('local')}
                            data-media-picker-tab="local"
                        >
                            {t('media_picker_tab_local')}
                        </button>
                        {domain ? (
                            <button
                                type="button"
                                className="seo-shared-media-picker__tab seo-shared-media-picker__tab--add"
                                onClick={onAddCustomTab}
                                title={t('media_picker_add_custom_tab')}
                                data-media-picker-tab="add-custom"
                            >
                                <Plus size={14} aria-hidden />
                            </button>
                        ) : null}
                    </div>
                    <div className="seo-shared-media-picker__toolbar">
                        <input
                            type="search"
                            className="seo-shared-media-picker__search"
                            placeholder={t('media_picker_search')}
                            value={active.search || ''}
                            disabled={tab === 'article'}
                            onChange={(event) => setActiveSearch(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    runSearch();
                                }
                            }}
                        />
                        <button
                            type="button"
                            className="seo-shared-media-picker__btn"
                            disabled={tab === 'article'}
                            onClick={runSearch}
                        >
                            {t('media_picker_search_btn')}
                        </button>
                        <button
                            type="button"
                            className="seo-shared-media-picker__btn seo-shared-media-picker__refresh"
                            onClick={refreshActiveTab}
                            title={t('media_picker_refresh')}
                            aria-label={t('media_picker_refresh')}
                            data-media-picker-refresh="1"
                            disabled={active.loading}
                        >
                            <RefreshCw size={14} aria-hidden />
                        </button>
                    </div>
                    {activeCustom?.keyword ? (
                        <p className="seo-shared-media-picker__hint">{activeCustom.keyword}</p>
                    ) : null}
                    {active.error ? <p className="seo-shared-media-picker__error">{active.error}</p> : null}
                    {readOnly ? <p className="seo-shared-media-picker__hint">{t('media_picker_readonly_hint')}</p> : null}
                    <div className="seo-shared-media-picker__grid">
                        {active.loading && active.images.length === 0 ? (
                            <p className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</p>
                        ) : active.images.length === 0 ? (
                            <p className="seo-shared-media-picker__empty">{t('media_picker_empty')}</p>
                        ) : (
                            <>
                                {active.loading ? (
                                    <p className="seo-shared-media-picker__hint seo-shared-media-picker__hint--overlay">
                                        {t('editor_module_loading')}
                                    </p>
                                ) : null}
                                {active.images.map((image) => {
                                    const key = imageKey(image);
                                    const selected = selectedKeys.includes(key);
                                    return (
                                        <button
                                            key={key}
                                            type="button"
                                            className={`seo-shared-media-picker__item${selected ? ' is-selected' : ''}`}
                                            onClick={() => toggleSelect(image)}
                                        >
                                            <img src={String(image.url || '')} alt={String(image.alt || '')} loading="lazy" />
                                        </button>
                                    );
                                })}
                            </>
                        )}
                    </div>
                    <footer className="seo-shared-media-picker__footer">
                        <div className="seo-shared-media-picker__pager">
                            <button
                                type="button"
                                disabled={tab === 'article' || active.page <= 1 || active.loading}
                                onClick={() => loadTab(tab, active.page - 1, active.search || '')}
                            >
                                {t('media_picker_prev')}
                            </button>
                            <span>{active.page} / {active.totalPages}</span>
                            <button
                                type="button"
                                disabled={tab === 'article' || active.page >= active.totalPages || active.loading}
                                onClick={() => loadTab(tab, active.page + 1, active.search || '')}
                            >
                                {t('media_picker_next')}
                            </button>
                        </div>
                        <div className="seo-shared-media-picker__actions">
                            <button type="button" onClick={() => closeMediaPicker()}>{t('media_picker_cancel')}</button>
                            <button
                                type="button"
                                className="is-primary"
                                disabled={readOnly || selectedKeys.length === 0 || confirming}
                                onClick={() => void onConfirm()}
                            >
                                {confirming ? '…' : t('media_picker_confirm', { count: selectedKeys.length })}
                            </button>
                        </div>
                    </footer>
                </div>
            </div>
        </EditorModuleErrorBoundary>
    );

    return createPortal(body, rootEl);
}
