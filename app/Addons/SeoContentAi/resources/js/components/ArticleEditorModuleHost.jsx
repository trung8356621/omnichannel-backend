import React, { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import ArticleEditorModuleErrorBoundary from './ArticleEditorModuleErrorBoundary';
import {
    dispatchActiveModule,
    isAbortError,
    isExternalHostedModule,
    MODULE_EVENT_ACTIVE,
    MODULE_EVENT_OPEN,
    MODULE_EVENT_SWITCH,
    normalizeHeavyModuleId,
} from '../utils/articleEditorModules';
import { logModuleLoadError, normalizeFaqPayload } from '../utils/articleEditorPayloadAdapters';
import { csrfToken, seoArticleApiFetch } from '../utils/seoArticleApi';
import { t } from '../utils/i18n';

const EMPTY_FAQ_PAYLOAD = normalizeFaqPayload(null);

const LinksModule = lazy(() => import('../modules/LinksModule'));
const FaqModule = lazy(() => import('../modules/FaqModule'));
const AiChatModule = lazy(() => import('../modules/AiChatModule'));

function ModuleLoading() {
    return (
        <div className="seo-module-loading p-3 text-sm text-gray-500 dark:text-gray-400">
            {t('editor_module_loading')}
        </div>
    );
}

/**
 * Phase 3/4: hosts Links / FAQ / CTA / AI Chat as one-at-a-time heavy modules.
 * CTA activates Links module + link-section=cta (single Links contract).
 * Do NOT manually clear portal DOM — React createPortal owns those nodes (removeChild crash).
 */
export default function ArticleEditorModuleHost({
    articleId = null,
    siteId = null,
    aiDebug = null,
    canGenerateImage = true,
    canGenerateVideo = false,
    showLinkWidgets = true,
}) {
    const [activeModule, setActiveModule] = useState(null);
    // Single state — tránh render ready với payload null giữa 2 setState.
    const [faqView, setFaqView] = useState(() => ({
        status: 'idle',
        payload: EMPTY_FAQ_PAYLOAD,
    }));
    const [moduleKey, setModuleKey] = useState(0);
    const abortRef = useRef(null);
    const activeModuleRef = useRef(null);
    activeModuleRef.current = activeModule;

    const abortPending = useCallback(() => {
        if (abortRef.current) {
            abortRef.current.abort();
            abortRef.current = null;
        }
    }, []);

    const openExternalModule = useCallback((moduleId) => {
        const next = normalizeHeavyModuleId(moduleId);
        if (!next || !isExternalHostedModule(next)) {
            return;
        }

        // CTA shares Links module (domain_cta_list from /editor/links).
        const hosted = next === 'cta' ? 'links' : next;
        abortPending();
        setActiveModule((prev) => (prev === hosted ? prev : hosted));
        dispatchActiveModule(hosted);
    }, [abortPending]);

    const closeExternalModules = useCallback(() => {
        abortPending();
        setActiveModule(null);
    }, [abortPending]);

    useEffect(() => {
        const onSwitch = (event) => {
            const rawPanel = event?.detail?.panel ?? event?.detail?.widgetId ?? event?.detail?.module;
            if (rawPanel == null || rawPanel === '' || event?.detail?.closed === true) {
                closeExternalModules();
                dispatchActiveModule(null);

                return;
            }

            const panel = normalizeHeavyModuleId(rawPanel);
            if (!panel) {
                // Alpine-only panels (featured / album / article) — unmount external heavy modules.
                closeExternalModules();
                dispatchActiveModule(null);

                return;
            }

            if (isExternalHostedModule(panel)) {
                openExternalModule(panel);
                return;
            }

            closeExternalModules();
            dispatchActiveModule(panel);
        };

        const onActive = (event) => {
            const moduleId = normalizeHeavyModuleId(event?.detail?.module);
            if (!moduleId) {
                closeExternalModules();
                return;
            }
            if (!isExternalHostedModule(moduleId) && activeModuleRef.current) {
                closeExternalModules();
            }
        };

        const onAiOpen = () => openExternalModule('ai-chat');
        const onAiClose = () => {
            if (activeModuleRef.current === 'ai-chat') {
                closeExternalModules();
                dispatchActiveModule(null);
            }
        };
        // Canonical: article-editor:module-open. Compat: seo-faq-panel-activate + switch-panel.
        const onModuleOpen = (event) => {
            const moduleId = normalizeHeavyModuleId(
                event?.detail?.module ?? event?.detail?.panel ?? event?.detail?.widgetId,
            );
            if (!moduleId) {
                return;
            }
            if (isExternalHostedModule(moduleId) || moduleId === 'cta') {
                openExternalModule(moduleId);
                return;
            }
            closeExternalModules();
            dispatchActiveModule(moduleId, {
                source: event?.detail?.source ?? 'module-open',
            });
        };
        const onFaqActivate = () => openExternalModule('faq');

        window.addEventListener(MODULE_EVENT_SWITCH, onSwitch);
        window.addEventListener(MODULE_EVENT_ACTIVE, onActive);
        window.addEventListener(MODULE_EVENT_OPEN, onModuleOpen);
        window.addEventListener('seo-article-ai-chat-open', onAiOpen);
        window.addEventListener('seo-article-ai-chat-close', onAiClose);
        window.addEventListener('seo-faq-panel-activate', onFaqActivate);

        return () => {
            window.removeEventListener(MODULE_EVENT_SWITCH, onSwitch);
            window.removeEventListener(MODULE_EVENT_ACTIVE, onActive);
            window.removeEventListener(MODULE_EVENT_OPEN, onModuleOpen);
            window.removeEventListener('seo-article-ai-chat-open', onAiOpen);
            window.removeEventListener('seo-article-ai-chat-close', onAiClose);
            window.removeEventListener('seo-faq-panel-activate', onFaqActivate);
            abortPending();
        };
    }, [abortPending, closeExternalModules, openExternalModule]);

    useEffect(() => {
        if (activeModule !== 'faq') {
            setFaqView({ status: 'idle', payload: EMPTY_FAQ_PAYLOAD });
        }
    }, [activeModule]);

    useEffect(() => {
        if (activeModule !== 'faq' || !articleId) {
            return undefined;
        }

        const controller = new AbortController();
        abortRef.current = controller;
        setFaqView({ status: 'loading', payload: EMPTY_FAQ_PAYLOAD });

        void (async () => {
            let settled = false;
            const url =
                window.__SEO_EDITOR_LAZY_ENDPOINTS__?.faqs
                || `/api/seo/articles/${articleId}/editor/faqs`;
            try {
                const { response, data } = await seoArticleApiFetch(url, {
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
                    },
                });
                if (controller.signal.aborted || activeModuleRef.current !== 'faq') {
                    return;
                }
                if (!response.ok || data?.success === false) {
                    settled = true;
                    setFaqView({ status: 'error', payload: EMPTY_FAQ_PAYLOAD });
                    logModuleLoadError({
                        moduleName: 'faq',
                        articleId,
                        endpoint: url,
                        error: data?.message || `HTTP ${response.status}`,
                    });
                    return;
                }
                const normalized = normalizeFaqPayload(data) ?? EMPTY_FAQ_PAYLOAD;
                settled = true;
                // Một setState atomic — không bao giờ ready + payload null.
                setFaqView({ status: 'ready', payload: normalized });
            } catch (error) {
                if (isAbortError(error) || controller.signal.aborted) {
                    return;
                }
                if (activeModuleRef.current === 'faq') {
                    settled = true;
                    setFaqView({ status: 'error', payload: EMPTY_FAQ_PAYLOAD });
                    logModuleLoadError({
                        moduleName: 'faq',
                        articleId,
                        endpoint: url,
                        error,
                    });
                }
            } finally {
                if (
                    !settled
                    && !controller.signal.aborted
                    && activeModuleRef.current === 'faq'
                ) {
                    setFaqView({ status: 'error', payload: EMPTY_FAQ_PAYLOAD });
                    logModuleLoadError({
                        moduleName: 'faq',
                        articleId,
                        endpoint: url,
                        error: 'UNSETTLED_FETCH',
                    });
                }
            }
        })();

        return () => {
            controller.abort();
            if (abortRef.current === controller) {
                abortRef.current = null;
            }
        };
    }, [activeModule, articleId, moduleKey]);

    const retryModule = useCallback(() => {
        setModuleKey((value) => value + 1);
        if (activeModule === 'faq') {
            setFaqView({ status: 'idle', payload: EMPTY_FAQ_PAYLOAD });
        }
    }, [activeModule]);

    const linksRoot = typeof document !== 'undefined'
        ? document.getElementById('seo-article-links-root')
        : null;
    const faqRoot = typeof document !== 'undefined'
        ? document.getElementById('seo-article-faq-root')
        : null;
    const chatRoot = typeof document !== 'undefined'
        ? document.getElementById('seo-article-ai-chat-root')
        : null;

    const linksPortal =
        showLinkWidgets && linksRoot && activeModule === 'links'
            ? createPortal(
                <ArticleEditorModuleErrorBoundary
                    key={`links-${moduleKey}`}
                    moduleName="links"
                    articleId={articleId}
                    endpoint={window.__SEO_EDITOR_LAZY_ENDPOINTS__?.links || `/api/seo/articles/${articleId}/editor/links`}
                    onRetry={retryModule}
                >
                    <Suspense fallback={<ModuleLoading />}>
                        <LinksModule
                            articleId={articleId}
                            siteId={siteId}
                            initialDomainLinkList={[]}
                            initialDomainLinkCatalog={[]}
                            initialDomainCtaList={[]}
                        />
                    </Suspense>
                </ArticleEditorModuleErrorBoundary>,
                linksRoot,
            )
            : null;

    const faqPortal =
        faqRoot && activeModule === 'faq'
            ? createPortal(
                <ArticleEditorModuleErrorBoundary
                    key={`faq-${moduleKey}`}
                    moduleName="faq"
                    articleId={articleId}
                    endpoint={window.__SEO_EDITOR_LAZY_ENDPOINTS__?.faqs || `/api/seo/articles/${articleId}/editor/faqs`}
                    autoRetryOnCachedError
                    onRetry={retryModule}
                >
                    {faqView.status === 'loading' || faqView.status === 'idle' ? (
                        <ModuleLoading />
                    ) : faqView.status === 'error' ? (
                        <div className="seo-module-error p-3 text-sm">
                            <p className="mb-2 opacity-80">{t('editor_module_error_title')}</p>
                            <button type="button" className="rounded bg-primary-600 px-3 py-1.5 text-white" onClick={retryModule}>
                                {t('editor_module_error_retry')}
                            </button>
                        </div>
                    ) : (
                        <Suspense fallback={<ModuleLoading />}>
                            <FaqModule
                                articleId={articleId}
                                initialFaqs={Array.isArray(faqView.payload?.items) ? faqView.payload.items : []}
                                initialExtractDebug={faqView.payload?.extractDebug ?? null}
                                canGenerateFaq={faqView.payload?.canGenerateFaq !== false}
                                canImportMarkdownFaq={Boolean(faqView.payload?.canImportMarkdownFaq)}
                            />
                        </Suspense>
                    )}
                </ArticleEditorModuleErrorBoundary>,
                faqRoot,
            )
            : null;

    const chatPortal =
        chatRoot && activeModule === 'ai-chat'
            ? createPortal(
                <ArticleEditorModuleErrorBoundary
                    key={`ai-${moduleKey}`}
                    moduleName="ai-chat"
                    articleId={articleId}
                    onRetry={retryModule}
                >
                    <Suspense fallback={<ModuleLoading />}>
                        <AiChatModule
                            articleId={articleId}
                            aiDebug={aiDebug}
                            canGenerateImage={canGenerateImage}
                            canGenerateVideo={canGenerateVideo}
                        />
                    </Suspense>
                </ArticleEditorModuleErrorBoundary>,
                chatRoot,
            )
            : null;

    return (
        <>
            {linksPortal}
            {faqPortal}
            {chatPortal}
        </>
    );
}
