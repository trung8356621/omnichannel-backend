import React, { useCallback, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CircleHelp, ExternalLink, Play, X } from 'lucide-react';
import {
    ARTICLE_EDITOR_HELP_OPEN_EVENT,
    ARTICLE_EDITOR_HELP_TOPICS,
    findArticleEditorHelpTopic,
} from '../help/articleEditorHelpTopics';
import {
    MODULE_EVENT_SWITCH,
    dispatchActiveModule,
    normalizeHeavyModuleId,
} from '../utils/articleEditorModules';

/**
 * @param {import('../help/articleEditorHelpTopics').ArticleEditorHelpTarget|null|undefined} target
 */
function navigateHelpTarget(target) {
    if (!target || typeof target !== 'object') {
        return;
    }

    const id = String(target.id ?? '').trim();
    if (id === '') {
        return;
    }

    if (target.type === 'module') {
        const moduleId = normalizeHeavyModuleId(id);
        if (!moduleId) {
            return;
        }

        if (moduleId === 'publishing') {
            window.dispatchEvent(new CustomEvent('seo-sidebar-open-publish-tab'));
            window.dispatchEvent(new CustomEvent('seo-assistant-open-publishing'));
            return;
        }

        window.dispatchEvent(
            new CustomEvent(MODULE_EVENT_SWITCH, {
                detail: { panel: moduleId, module: moduleId },
            }),
        );
        dispatchActiveModule(moduleId);

        window.setTimeout(() => {
            const panel = document.querySelector(
                `[data-seo-assistant-panel="${moduleId}"], [data-seo-module="${moduleId}"], .seo-assistant-dock`,
            );
            panel?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
            if (panel instanceof HTMLElement) {
                panel.classList.add('is-help-target-flash');
                window.setTimeout(() => panel.classList.remove('is-help-target-flash'), 1200);
            }
        }, 80);

        return;
    }

    if (target.type === 'widget' && id === 'outline') {
        window.dispatchEvent(new CustomEvent('seo-outline-rail-opened'));
        const rail = document.querySelector('.seo-article-editor-outline-rail, .seo-outline-panel');
        rail?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
        if (rail instanceof HTMLElement) {
            rail.classList.add('is-help-target-flash');
            window.setTimeout(() => rail.classList.remove('is-help-target-flash'), 1200);
        }
        return;
    }

    if (target.type === 'scroll' && id === 'google-preview') {
        const preview = document.querySelector(
            '.seo-article-editor-google-preview-rail, .seo-google-serp-preview, [data-seo-google-serp-preview]',
        );
        preview?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
        if (preview instanceof HTMLElement) {
            preview.classList.add('is-help-target-flash');
            window.setTimeout(() => preview.classList.remove('is-help-target-flash'), 1200);
        }
    }
}

/**
 * Lazy video: metadata only until Play; pause/destroy on close.
 *
 * @param {{ video: import('../help/articleEditorHelpTopics').ArticleEditorHelpVideo, active: boolean }} props
 */
function HelpTopicVideo({ video, active }) {
    const [playing, setPlaying] = useState(false);
    const iframeRef = useRef(/** @type {HTMLIFrameElement|null} */ (null));

    useEffect(() => {
        if (!active) {
            setPlaying(false);
        }
    }, [active]);

    useEffect(() => {
        if (playing) {
            return undefined;
        }

        const iframe = iframeRef.current;
        if (iframe) {
            iframe.src = 'about:blank';
        }

        return undefined;
    }, [playing]);

    if (!video?.url) {
        return null;
    }

    if (!playing) {
        return (
            <div className="article-editor-help-video">
                <button
                    type="button"
                    className="article-editor-help-video__play"
                    onClick={() => setPlaying(true)}
                >
                    {video.thumbnail ? (
                        <img src={video.thumbnail} alt="" className="article-editor-help-video__thumb" />
                    ) : (
                        <span className="article-editor-help-video__thumb article-editor-help-video__thumb--empty" />
                    )}
                    <span className="article-editor-help-video__play-icon" aria-hidden="true">
                        <Play size={22} />
                    </span>
                    <span className="article-editor-help-video__meta">
                        {video.title || 'Video'}
                        {video.duration ? ` · ${video.duration}` : ''}
                    </span>
                </button>
                {video.longUrl ? (
                    <a
                        href={video.longUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="article-editor-help-video__long"
                    >
                        Xem video dài
                        <ExternalLink size={14} aria-hidden="true" />
                    </a>
                ) : null}
            </div>
        );
    }

    return (
        <div className="article-editor-help-video is-playing">
            <iframe
                ref={iframeRef}
                className="article-editor-help-video__iframe"
                title={video.title || 'Help video'}
                src={video.url}
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowFullScreen
            />
            {video.longUrl ? (
                <a
                    href={video.longUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="article-editor-help-video__long"
                >
                    Xem video dài
                    <ExternalLink size={14} aria-hidden="true" />
                </a>
            ) : null}
        </div>
    );
}

export default function ArticleEditorHelpModal() {
    const titleId = useId();
    const [open, setOpen] = useState(false);
    const [expandedKey, setExpandedKey] = useState(/** @type {string|null} */ (null));
    const openTriggerRef = useRef(/** @type {Element|null} */ (null));
    const dialogRef = useRef(/** @type {HTMLDivElement|null} */ (null));
    const closeBtnRef = useRef(/** @type {HTMLButtonElement|null} */ (null));

    const close = useCallback(() => {
        setOpen(false);
        setExpandedKey(null);
        document.body.classList.remove('article-editor-help-modal-open');
        const trigger = openTriggerRef.current;
        openTriggerRef.current = null;
        if (trigger instanceof HTMLElement) {
            window.requestAnimationFrame(() => trigger.focus?.());
        }
    }, []);

    const openModal = useCallback((topicKey = null, trigger = null) => {
        openTriggerRef.current = trigger;
        setOpen(true);
        document.body.classList.add('article-editor-help-modal-open');

        const topic = findArticleEditorHelpTopic(topicKey);
        setExpandedKey(topic?.key ?? null);

        window.requestAnimationFrame(() => {
            closeBtnRef.current?.focus?.();
            if (topic?.key) {
                const el = dialogRef.current?.querySelector(`[data-help-topic="${topic.key}"]`);
                el?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }, []);

    useEffect(() => {
        const onOpen = (event) => {
            const topic = event?.detail?.topic ?? null;
            const trigger = event?.detail?.trigger
                ?? (document.activeElement instanceof Element ? document.activeElement : null);
            openModal(topic, trigger);
        };

        window.addEventListener(ARTICLE_EDITOR_HELP_OPEN_EVENT, onOpen);

        return () => {
            window.removeEventListener(ARTICLE_EDITOR_HELP_OPEN_EVENT, onOpen);
            document.body.classList.remove('article-editor-help-modal-open');
        };
    }, [openModal]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                return;
            }

            if (event.key !== 'Tab' || !dialogRef.current) {
                return;
            }

            const focusables = dialogRef.current.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
            );
            const list = [...focusables].filter(
                (node) => node instanceof HTMLElement && !node.hasAttribute('disabled'),
            );
            if (list.length === 0) {
                return;
            }

            const first = list[0];
            const last = list[list.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [open, close]);

    const goToTarget = useCallback((target) => {
        close();
        window.setTimeout(() => navigateHelpTarget(target), 40);
    }, [close]);

    if (!open || typeof document === 'undefined') {
        return (
            <div
                hidden
                data-article-editor-help-modal
                data-article-editor-help-modal-host
                aria-hidden="true"
            />
        );
    }

    return createPortal(
        <div
            className="article-editor-help-modal"
            data-article-editor-help-modal
            data-article-editor-help-modal-open
        >
            <button
                type="button"
                className="article-editor-help-modal__backdrop"
                aria-label="Đóng hướng dẫn"
                onClick={close}
            />
            <div
                ref={dialogRef}
                className="article-editor-help-modal__dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
            >
                <header className="article-editor-help-modal__header">
                    <div className="article-editor-help-modal__heading">
                        <CircleHelp size={18} aria-hidden="true" />
                        <h2 id={titleId}>Hướng dẫn Article Editor</h2>
                    </div>
                    <button
                        ref={closeBtnRef}
                        type="button"
                        className="article-editor-help-modal__close"
                        aria-label="Đóng"
                        onClick={close}
                    >
                        <X size={18} aria-hidden="true" />
                    </button>
                </header>

                <div className="article-editor-help-modal__body">
                    <div className="article-editor-help-accordion">
                        {ARTICLE_EDITOR_HELP_TOPICS.map((topic) => {
                            const expanded = expandedKey === topic.key;
                            const panelId = `article-editor-help-panel-${topic.key.replace(/\./g, '-')}`;
                            const buttonId = `article-editor-help-btn-${topic.key.replace(/\./g, '-')}`;

                            return (
                                <section
                                    key={topic.key}
                                    className={`article-editor-help-item${expanded ? ' is-open' : ''}`}
                                    data-help-topic={topic.key}
                                >
                                    <h3 className="article-editor-help-item__title">
                                        <button
                                            id={buttonId}
                                            type="button"
                                            className="article-editor-help-item__trigger"
                                            aria-expanded={expanded}
                                            aria-controls={panelId}
                                            onClick={() => setExpandedKey(expanded ? null : topic.key)}
                                        >
                                            <span>{topic.title}</span>
                                            <span className="article-editor-help-item__chevron" aria-hidden="true" />
                                        </button>
                                    </h3>
                                    <div
                                        id={panelId}
                                        role="region"
                                        aria-labelledby={buttonId}
                                        hidden={!expanded}
                                        className="article-editor-help-item__panel"
                                    >
                                        {topic.summary ? (
                                            <p className="article-editor-help-item__summary">{topic.summary}</p>
                                        ) : null}
                                        {Array.isArray(topic.steps) && topic.steps.length > 0 ? (
                                            <ol className="article-editor-help-item__steps">
                                                {topic.steps.map((step) => (
                                                    <li key={step}>{step}</li>
                                                ))}
                                            </ol>
                                        ) : null}
                                        <HelpTopicVideo video={topic.video} active={expanded} />
                                        {topic.target ? (
                                            <button
                                                type="button"
                                                className="article-editor-help-item__goto"
                                                onClick={() => goToTarget(topic.target)}
                                            >
                                                Đi tới chức năng
                                            </button>
                                        ) : null}
                                    </div>
                                </section>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
