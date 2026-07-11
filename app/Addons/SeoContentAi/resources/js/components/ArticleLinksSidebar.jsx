import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, ChevronRight, Copy, Link2, Phone, RotateCcw, Trash2 } from 'lucide-react';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { t } from '../utils/i18n';
import ArticleAssistantWidget from './ArticleAssistantWidget';
import {
    ctaDisplayLabel,
    formatCtaHref,
    isCtaItemInsertable,
    isCtaPlainTextType,
} from '../utils/ctaLinkFormat';
import {
    buildVisibleInternalSuggestions,
    filterDomainLinksInArticleContent,
    filterSuggestedInternalLinks,
    isSuggestionExcluded,
    mergeSuggestionCatalog,
    normalizeHrefForCompare,
    normalizeLinkLabel,
} from '../utils/articleLinkSuggestionFilter';
import {
    clearExcludedLinkSuggestions,
    loadExcludedLinkSuggestions,
    saveExcludedLinkSuggestions,
} from '../utils/articleExcludedLinkSuggestionsStorage';

/**
 * @typedef {{ href?: string, text: string, offset?: number, is_nofollow?: boolean, is_suggestion?: boolean, target_url?: string|null, can_insert?: boolean, keyword_id?: number, occurrence_count?: number }} ExtractedLink
 * @typedef {{ text: string, index: number }} FaqLinkItem
 */

function LinkAssistantSection({ title, count, collapsed, onToggle, children, sectionKey = '' }) {
    return (
        <div className="seo-link-assistant__section" data-assistant-link-section={sectionKey || undefined}>
            <button
                type="button"
                className="seo-link-assistant__section-toggle"
                aria-expanded={!collapsed}
                onClick={onToggle}
            >
                {collapsed ? <ChevronRight size={15} aria-hidden /> : <ChevronDown size={15} aria-hidden />}
                <span className="seo-link-assistant__section-title">{title}</span>
                <span className="seo-assistant-widget__badge">{count}</span>
            </button>
            {!collapsed ? <div className="seo-link-assistant__section-body">{children}</div> : null}
        </div>
    );
}

function DomainInsertableList({
    items,
    variant,
    activeKey,
    hiddenRowKeys,
    onKeywordClick,
    onInsert,
    emptyText,
}) {
    if (!items.length) {
        return <p className="wp-article-links-empty">{emptyText}</p>;
    }

    return (
        <ul className="wp-article-links-keywords">
            {items.map((item, index) => {
                const label =
                    variant === 'cta'
                        ? ctaDisplayLabel(item)
                        : String(item?.text ?? '').trim();
                const href =
                    variant === 'cta'
                        ? String(item?.href ?? formatCtaHref(item?.type, item?.value)).trim()
                        : String(item?.href ?? item?.target_url ?? '').trim();
                const count = Number(item?.article_count ?? 0);
                const countSuffix =
                    variant === 'domain-link' && Number.isFinite(count) && count > 0
                        ? ` (${count})`
                        : '';
                const itemKey = `${variant}-${label}-${index}`;
                const isActive = activeKey === itemKey;
                const insertable =
                    variant === 'cta'
                        ? isCtaItemInsertable(item)
                        : item?.can_insert !== false && label !== '' && href !== '';
                const isCtaBlank = variant === 'cta' && item?.is_blank === true;
                const isRowHiding = hiddenRowKeys?.has(itemKey) === true;

                return (
                    <li
                        key={itemKey}
                        className={`wp-article-links-keyword-row${isCtaBlank ? ' is-cta-blank' : ''}${isRowHiding ? ' is-row-hiding' : ''}`}
                        aria-hidden={isRowHiding}
                    >
                        <button
                            type="button"
                            className={`wp-article-links-keyword${isActive ? ' is-active' : ''} is-suggestion`}
                            title={
                                variant === 'cta'
                                    ? t('cta_widget_find', { label, type: item?.type ?? '' })
                                    : t('domain_link_widget_find', { label, count })
                            }
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => onKeywordClick(item, index, itemKey)}
                        >
                            {variant === 'cta' ? (
                                <span className="wp-article-domain-cta-label">
                                    <span className="wp-article-domain-cta-type">{item?.type ?? 'cta'}</span>
                                    {label}
                                </span>
                            ) : (
                                `${label}${countSuffix}`
                            )}
                        </button>
                        {onInsert ? (
                            <button
                                type="button"
                                className="wp-article-links-insert-btn"
                                aria-label={
                                    variant === 'cta'
                                        ? t('cta_widget_insert_for', { label })
                                        : t('domain_link_widget_insert_for', { label })
                                }
                                title={
                                    variant === 'cta'
                                        ? t('cta_widget_insert_for', { label })
                                        : t('domain_link_widget_insert_for', { label })
                                }
                                disabled={!insertable}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    if (insertable) {
                                        onInsert(item, itemKey);
                                    }
                                }}
                            >
                                {variant === 'cta' ? (
                                    <Phone size={14} aria-hidden />
                                ) : (
                                    <Link2 size={14} aria-hidden />
                                )}
                            </button>
                        ) : null}
                    </li>
                );
            })}
        </ul>
    );
}

const applyDomainLinkFilters = (allLinks, articlePlainText, internalLinks) => {
    const inArticle = filterDomainLinksInArticleContent(allLinks, articlePlainText);

    return filterSuggestedInternalLinks(inArticle, internalLinks).map((item) => ({
        ...item,
        can_insert: item.can_insert !== false,
    }));
};

function keywordLabel(item) {
    const text = String(item?.text ?? '').trim();
    if (text !== '') {
        return text;
    }
    if (item?.href) {
        try {
            const url = new URL(item.href, window.location.origin);
            const path = url.pathname || '/';
            return `Link: ${path}`;
        } catch {
            return `Link: ${item.href}`;
        }
    }
    return '—';
}

function fullTitle(item, hint) {
    const label = keywordLabel(item);
    const parts = [hint, label];
    if (item?.href) {
        parts.push(item.href);
    }
    if (item?.target_url && item.target_url !== item.href) {
        parts.push(t('links_target_url_hint', { url: item.target_url }));
    }
    if (item?.keyword_type) {
        parts.push(t('links_source_hint', { type: item.keyword_type }));
    }
    return parts.filter(Boolean).join('\n');
}

function canInsertSuggestion(item) {
    if (item?.can_insert === false) {
        return false;
    }
    const href = String(item?.href ?? item?.target_url ?? '').trim();
    return href !== '';
}

function hasAnchorText(item) {
    return String(item?.text ?? '').trim() !== '';
}

function occurrenceCount(item) {
    const value = Number(item?.occurrence_count ?? 1);
    return Number.isFinite(value) && value > 1 ? Math.floor(value) : 1;
}

/**
 * @param {{ items: Array<ExtractedLink|FaqLinkItem>, title: string, activeKey: string, target: 'editor'|'faq', variant?: 'default'|'suggestion', hideTitle?: boolean, interactive?: boolean, hiddenRowKeys?: Set<string>, onKeywordClick: Function, onInsertSuggestion?: Function, onCopyKeyword?: Function, onRemoveInternalLink?: Function, onExcludeSuggestion?: Function }} props
 */
function KeywordList({
    items,
    title,
    activeKey,
    target,
    variant = 'default',
    hideTitle = false,
    interactive = true,
    hiddenRowKeys,
    onKeywordClick,
    onInsertSuggestion,
    onCopyKeyword,
    onRemoveInternalLink,
    onExcludeSuggestion,
}) {
    if (!items.length) {
        return (
            <div className="wp-article-links-group">
                {!hideTitle ? <h3 className="wp-article-links-group__title">{title}</h3> : null}
                <p className="wp-article-links-empty">{t('links_none')}</p>
            </div>
        );
    }

    return (
        <div className={`wp-article-links-group${hideTitle ? ' wp-article-links-group--nested' : ''}`}>
            {!hideTitle ? <h3 className="wp-article-links-group__title">{title}</h3> : null}
            <ul className="wp-article-links-keywords">
                {items.map((item, index) => {
                    const itemKey = `${variant}-${target}-${item.text}-${index}`;
                    const isActive = activeKey === itemKey;
                    const label = keywordLabel(item);
                    const count = occurrenceCount(item);
                    const labelWithCount = count > 1 ? `${label} (${count})` : label;
                    const insertable = variant === 'suggestion' && canInsertSuggestion(item);
                    const anchorTextPresent = hasAnchorText(item);
                    const hint =
                        variant === 'suggestion'
                            ? insertable
                                ? t('links_suggestion_insert_ready', { label })
                                : t('links_suggestion_insert_missing', { label })
                            : target === 'faq'
                              ? t('links_find_in_faq', { label })
                              : anchorTextPresent
                                ? t('links_find_keyword', { label })
                                : t('links_find_link', { label });

                    const isRowHiding = hiddenRowKeys?.has(itemKey) === true;

                    return (
                        <li
                            key={itemKey}
                            className={`wp-article-links-keyword-row${isRowHiding ? ' is-row-hiding' : ''}`}
                            aria-hidden={isRowHiding}
                        >
                            {interactive ? (
                                <button
                                    type="button"
                                    className={`wp-article-links-keyword${isActive ? ' is-active' : ''}${target === 'faq' ? ' is-faq' : ''}${variant === 'suggestion' ? ' is-suggestion' : ''}`}
                                    title={fullTitle(item, hint)}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={() => onKeywordClick(item, index, itemKey, target)}
                                >
                                    {labelWithCount}
                                </button>
                            ) : (
                                <span
                                    className={`wp-article-links-keyword is-readonly${target === 'faq' ? ' is-faq' : ''}`}
                                    title={label}
                                >
                                    {labelWithCount}
                                </span>
                            )}
                            {variant === 'suggestion' && onInsertSuggestion ? (
                                <button
                                    type="button"
                                    className="wp-article-links-insert-btn"
                                    aria-label={
                                        insertable
                                            ? t('links_insert_internal_for', { label })
                                            : t('links_missing_target_url')
                                    }
                                    title={
                                        insertable
                                            ? t('links_insert_internal_for_label', { label })
                                            : t('links_missing_target_mapping')
                                    }
                                    disabled={!insertable}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        if (insertable) {
                                            onInsertSuggestion(item, index, itemKey);
                                        }
                                    }}
                                >
                                    <Link2 size={14} aria-hidden />
                                </button>
                            ) : null}
                            {onCopyKeyword ? (
                                <button
                                    type="button"
                                    className={`wp-article-links-copy-btn${target === 'faq' ? ' is-faq' : ''}${variant === 'suggestion' ? ' is-suggestion' : ''}`}
                                    aria-label={t('links_copy_keyword', { label })}
                                    title={t('links_copy_title', { label })}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onCopyKeyword(label);
                                    }}
                                >
                                    <Copy size={14} aria-hidden />
                                </button>
                            ) : null}
                            {variant === 'suggestion' && onExcludeSuggestion ? (
                                <button
                                    type="button"
                                    className="wp-article-links-delete-btn is-suggestion"
                                    aria-label={t('links_exclude_suggestion', { label })}
                                    title={t('links_exclude_suggestion_title', { label })}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onExcludeSuggestion(item, index, itemKey);
                                    }}
                                >
                                    <Trash2 size={14} aria-hidden />
                                </button>
                            ) : null}
                            {variant === 'default' && target === 'editor' && onRemoveInternalLink ? (
                                <button
                                    type="button"
                                    className="wp-article-links-delete-btn"
                                    aria-label={t('links_remove_keyword', { label })}
                                    title={t('links_remove_title', { label })}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onRemoveInternalLink(item, index, itemKey);
                                    }}
                                >
                                    <Trash2 size={14} aria-hidden />
                                </button>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function InternalLinksSection({
    internal,
    suggestedInternal,
    activeKey,
    hiddenRowKeys,
    excludedCount = 0,
    onClearExcluded,
    onKeywordClick,
    onSuggestionClick,
    onInsertSuggestion,
    onCopyKeyword,
    onRemoveInternalLink,
    onExcludeSuggestion,
}) {
    const showSuggestions = internal.length < 10 && suggestedInternal.length > 0;
    const showExcludedClear = excludedCount > 0;

    if (internal.length === 0 && !showSuggestions && !showExcludedClear) {
        return (
            <KeywordList
                items={[]}
                title={t('links_internal_title_zero')}
                activeKey={activeKey}
                target="editor"
                onKeywordClick={onKeywordClick}
                onCopyKeyword={onCopyKeyword}
            />
        );
    }

    return (
        <div className="wp-article-links-group">
            <h3 className="wp-article-links-group__title">{t('links_internal_title', { count: internal.length })}</h3>
            {internal.length > 0 ? (
                <KeywordList
                    items={internal}
                    title=""
                    activeKey={activeKey}
                    target="editor"
                    hideTitle
                    onKeywordClick={onKeywordClick}
                    onCopyKeyword={onCopyKeyword}
                    onRemoveInternalLink={onRemoveInternalLink}
                />
            ) : (
                <p className="wp-article-links-empty">{t('links_internal_empty')}</p>
            )}
            {showSuggestions || showExcludedClear ? (
                <div className="wp-article-links-suggestions-head">
                    {showSuggestions ? (
                        <KeywordList
                            items={suggestedInternal}
                            title={t('links_suggestion_title', { count: suggestedInternal.length })}
                            activeKey={activeKey}
                            target="editor"
                            variant="suggestion"
                            hideTitle
                            hiddenRowKeys={hiddenRowKeys}
                            onKeywordClick={onSuggestionClick}
                            onInsertSuggestion={onInsertSuggestion}
                            onCopyKeyword={onCopyKeyword}
                            onExcludeSuggestion={onExcludeSuggestion}
                        />
                    ) : (
                        <p className="wp-article-links-empty">{t('links_suggestions_all_excluded')}</p>
                    )}
                    {showExcludedClear ? (
                        <button
                            type="button"
                            className="wp-article-links-clear-excluded-btn"
                            title={t('links_clear_excluded_title')}
                            onClick={onClearExcluded}
                        >
                            <RotateCcw size={13} aria-hidden />
                            {t('links_clear_excluded', { count: excludedCount })}
                        </button>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

function readEditorSeoBootstrap() {
    try {
        const el = document.getElementById('seo-article-initial-seo');
        const raw = el?.textContent?.trim();
        if (!raw) {
            return null;
        }

        const data = JSON.parse(raw);

        return data && typeof data === 'object' ? data : null;
    } catch {
        return null;
    }
}

function readArticleMetaIds() {
    try {
        const el = document.getElementById('seo-article-meta');
        const raw = el?.textContent?.trim();
        if (!raw) {
            return { articleId: 0, siteId: 0 };
        }

        const meta = JSON.parse(raw);

        return {
            articleId: Number(meta?.id ?? 0),
            siteId: Number(meta?.site_id ?? meta?.siteId ?? 0),
        };
    } catch {
        return { articleId: 0, siteId: 0 };
    }
}

function readSuggestionCatalogBootstrap() {
    const data = readEditorSeoBootstrap();

    return Array.isArray(data?.domain_link_list_catalog) ? data.domain_link_list_catalog : [];
}

export default function ArticleLinksSidebar({
    initialDomainLinkList = [],
    initialDomainLinkCatalog = [],
    initialDomainCtaList = [],
}) {
    const articleMetaRef = useRef(readArticleMetaIds());
    const editorSeoBootstrap = useRef(readEditorSeoBootstrap());
    const keywordCatalogRef = useRef(
        mergeSuggestionCatalog(
            editorSeoBootstrap.current?.suggested_internal_links_catalog ?? [],
            editorSeoBootstrap.current?.suggested_internal_links ?? [],
        ),
    );
    const domainCatalogRef = useRef(readSuggestionCatalogBootstrap());
    const [catalogVersion, setCatalogVersion] = useState(0);
    const stableSuggestionsRef = useRef([]);
    const stableSuggestionsKeyRef = useRef('');
    const [links, setLinks] = useState(() => ({
        internal: editorSeoBootstrap.current?.extracted_links?.internal ?? [],
        external: editorSeoBootstrap.current?.extracted_links?.external ?? [],
        faq: [],
    }));
    const [articlePlainText, setArticlePlainText] = useState('');
    const [excludedSuggestionLabels, setExcludedSuggestionLabels] = useState(() => {
        const { articleId, siteId } = articleMetaRef.current;

        return new Set(loadExcludedLinkSuggestions(articleId, siteId));
    });
    const excludedPersistRef = useRef(excludedSuggestionLabels);
    const [activeKey, setActiveKey] = useState('');
    const [cycleByKey, setCycleByKey] = useState({});
    const [internalCollapsed, setInternalCollapsed] = useState(true);
    const [externalCollapsed, setExternalCollapsed] = useState(true);
    const [faqCollapsed, setFaqCollapsed] = useState(true);
    const [domainLinksCollapsed, setDomainLinksCollapsed] = useState(true);
    const [ctaCollapsed, setCtaCollapsed] = useState(true);
    const [linkSectionFilter, setLinkSectionFilter] = useState('all');

    useEffect(() => {
        const onLinkSection = (event) => {
            const section = String(event?.detail?.section ?? 'all');
            setLinkSectionFilter(section);

            if (section === 'links') {
                setInternalCollapsed(false);
                setExternalCollapsed(false);
                setDomainLinksCollapsed(false);
                return;
            }

            if (section === 'faq') {
                setFaqCollapsed(false);
                return;
            }

            if (section === 'cta') {
                setCtaCollapsed(false);
            }
        };

        window.addEventListener('seo-assistant-link-section', onLinkSection);

        return () => window.removeEventListener('seo-assistant-link-section', onLinkSection);
    }, []);
    const [hiddenRowKeys, setHiddenRowKeys] = useState(() => new Set());
    const allDomainLinksRef = useRef(
        initialDomainLinkCatalog.length > 0 ? initialDomainLinkCatalog : initialDomainLinkList,
    );
    const [domainLinkCatalogCount, setDomainLinkCatalogCount] = useState(
        initialDomainLinkCatalog.length > 0
            ? initialDomainLinkCatalog.length
            : initialDomainLinkList.length,
    );
    const [domainLinks, setDomainLinks] = useState(initialDomainLinkList);
    const [domainCtas, setDomainCtas] = useState(initialDomainCtaList);
    const [domainLinkActiveKey, setDomainLinkActiveKey] = useState('');
    const [ctaActiveKey, setCtaActiveKey] = useState('');
    const [domainHiddenRowKeys, setDomainHiddenRowKeys] = useState(() => new Set());

    const { debounced: debouncedPersistExcluded } = useDebouncedCallback(() => {
        const { articleId, siteId } = articleMetaRef.current;
        saveExcludedLinkSuggestions(articleId, siteId, [...excludedPersistRef.current]);
    }, 400);

    const hideSuggestionRow = (itemKey) => {
        if (!itemKey) {
            return;
        }
        setHiddenRowKeys((prev) => {
            if (prev.has(itemKey)) {
                return prev;
            }
            const next = new Set(prev);
            next.add(itemKey);
            return next;
        });
    };

    const excludeSuggestion = (item) => {
        const label = normalizeLinkLabel(item?.text);
        if (!label) {
            return;
        }

        setExcludedSuggestionLabels((prev) => {
            if (prev.has(label)) {
                return prev;
            }

            const next = new Set(prev);
            next.add(label);
            excludedPersistRef.current = next;
            debouncedPersistExcluded();
            return next;
        });

        const displayLabel = String(item?.text ?? '').trim();
        if (displayLabel !== '') {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('links_excluded_suggestion_title'),
                        body: t('links_excluded_suggestion_body', { label: displayLabel }),
                        status: 'success',
                    },
                }),
            );
        }
    };

    const clearExcludedSuggestions = () => {
        const { articleId, siteId } = articleMetaRef.current;
        clearExcludedLinkSuggestions(articleId, siteId);
        excludedPersistRef.current = new Set();
        setExcludedSuggestionLabels(new Set());

        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('links_clear_excluded_done_title'),
                    body: t('links_clear_excluded_done_body'),
                    status: 'success',
                },
            }),
        );
    };

    useEffect(() => {
        const onLinksUpdate = (event) => {
            const payload = event.detail?.links ?? event.detail?.extracted_links;
            const articlePlain = String(event.detail?.article_plain_text ?? '');
            if (payload && typeof payload === 'object') {
                setLinks((prev) => ({
                    ...prev,
                    internal: Array.isArray(payload.internal) ? payload.internal : [],
                    external: Array.isArray(payload.external) ? payload.external : [],
                }));
                setCycleByKey({});
                setHiddenRowKeys(new Set());
            }
            setArticlePlainText(articlePlain);

            const internal = Array.isArray(payload?.internal)
                ? payload.internal
                : Array.isArray(event.detail?.extracted_links?.internal)
                  ? event.detail.extracted_links.internal
                  : [];
            setDomainLinks(applyDomainLinkFilters(allDomainLinksRef.current, articlePlain, internal));

            const incomingSuggested = Array.isArray(event.detail?.suggested_internal)
                ? event.detail.suggested_internal
                : [];
            const incomingKeywordCatalog = Array.isArray(event.detail?.suggested_internal_links_catalog)
                ? event.detail.suggested_internal_links_catalog
                : [];
            const incomingCatalog = Array.isArray(event.detail?.domain_link_list_catalog)
                ? event.detail.domain_link_list_catalog
                : [];

            if (incomingKeywordCatalog.length > 0) {
                keywordCatalogRef.current = mergeSuggestionCatalog(
                    keywordCatalogRef.current,
                    incomingKeywordCatalog,
                    incomingSuggested,
                );
            } else if (incomingSuggested.length > 0) {
                keywordCatalogRef.current = mergeSuggestionCatalog(
                    keywordCatalogRef.current,
                    incomingSuggested,
                );
            }

            if (incomingCatalog.length > 0) {
                domainCatalogRef.current = mergeSuggestionCatalog(domainCatalogRef.current, incomingCatalog);
            }
            setCatalogVersion((version) => version + 1);
        };

        const onSeoPayload = (event) => {
            const detail = event.detail ?? {};
            if (Array.isArray(detail.domain_link_list_catalog)) {
                allDomainLinksRef.current = detail.domain_link_list_catalog;
                setDomainLinkCatalogCount(detail.domain_link_list_catalog.length);
            } else if (Array.isArray(detail.domain_link_list)) {
                allDomainLinksRef.current = detail.domain_link_list;
                setDomainLinkCatalogCount(detail.domain_link_list.length);
            }
            if (
                Array.isArray(detail.domain_link_list_catalog) ||
                Array.isArray(detail.domain_link_list)
            ) {
                const internal = Array.isArray(detail.extracted_links?.internal)
                    ? detail.extracted_links.internal
                    : [];
                const articlePlain = String(detail.article_plain_text ?? '');
                setDomainLinks(
                    applyDomainLinkFilters(allDomainLinksRef.current, articlePlain, internal),
                );
            }
            if (Array.isArray(detail.domain_cta_list)) {
                setDomainCtas(
                    detail.domain_cta_list.map((item) => ({
                        ...item,
                        can_insert: true,
                    })),
                );
            }
        };

        const onDomainInserted = (event) => {
            const text = normalizeLinkLabel(event.detail?.text);
            const hrefKey = normalizeHrefForCompare(event.detail?.href);
            if (!text && !hrefKey) {
                return;
            }

            setDomainLinks((prev) =>
                prev.filter((item) => {
                    if (text && normalizeLinkLabel(item.text) === text) {
                        return false;
                    }
                    if (hrefKey && normalizeHrefForCompare(item.href ?? item.target_url) === hrefKey) {
                        return false;
                    }
                    return true;
                }),
            );
            setDomainHiddenRowKeys(new Set());
        };

        const onFaqUpdate = (event) => {
            const faq = event.detail?.faq;
            if (!Array.isArray(faq)) {
                return;
            }
            setLinks((prev) => ({
                ...prev,
                faq,
            }));
        };

        const onInserted = () => {
            setHiddenRowKeys(new Set());
            setDomainHiddenRowKeys(new Set());
        };

        window.addEventListener('seo-editor-links-updated', onLinksUpdate);
        window.addEventListener('seo-editor-seo-payload-updated', onSeoPayload);
        window.addEventListener('seo-editor-faqs-updated', onFaqUpdate);
        window.addEventListener('seo-editor-suggested-link-inserted', onInserted);
        window.addEventListener('seo-editor-suggested-link-inserted', onDomainInserted);

        return () => {
            window.removeEventListener('seo-editor-links-updated', onLinksUpdate);
            window.removeEventListener('seo-editor-seo-payload-updated', onSeoPayload);
            window.removeEventListener('seo-editor-faqs-updated', onFaqUpdate);
            window.removeEventListener('seo-editor-suggested-link-inserted', onInserted);
            window.removeEventListener('seo-editor-suggested-link-inserted', onDomainInserted);
        };
    }, []);

    const internal = links.internal ?? [];
    const external = links.external ?? [];
    const faq = links.faq ?? [];

    const suggestedInternal = useMemo(() => {
        const plain = articlePlainText.trim();
        const domainPool =
            plain !== ''
                ? filterDomainLinksInArticleContent(domainCatalogRef.current, plain)
                : domainCatalogRef.current;
        const pool = mergeSuggestionCatalog(keywordCatalogRef.current, domainPool);
        const internalSignature = (internal ?? [])
            .map((item) => {
                const label = normalizeLinkLabel(item?.text);
                const href = normalizeHrefForCompare(item?.href);

                return `${label}|${href}`;
            })
            .join(';');
        const poolKey = `${catalogVersion}:${internalSignature}:${plain}`;

        if (stableSuggestionsKeyRef.current !== poolKey) {
            stableSuggestionsKeyRef.current = poolKey;
            stableSuggestionsRef.current = buildVisibleInternalSuggestions({
                catalog: pool,
                internal,
                excludedLabels: [],
                skipContentFilter: true,
            });
        }

        return stableSuggestionsRef.current.filter(
            (item) => !isSuggestionExcluded(String(item?.text ?? ''), excludedSuggestionLabels),
        );
    }, [internal, excludedSuggestionLabels, articlePlainText, catalogVersion]);

    const copyKeyword = async (value) => {
        const text = String(value ?? '').trim();
        if (!text) {
            return;
        }

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-1000px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('links_copied_title'),
                        body: `«${text}»`,
                        status: 'success',
                    },
                }),
            );
        } catch {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('links_copy_failed_title'),
                        body: t('links_copy_failed_body'),
                        status: 'warning',
                    },
                }),
            );
        }
    };

    const scrollToKeyword = (item, type, listIndex, itemKey, options = {}) => {
        setActiveKey(itemKey);
        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? '').trim();
        const count = occurrenceCount(item);
        const currentCycle = Number(cycleByKey[itemKey] ?? 0);
        const nextIndex = count > 1 ? currentCycle % count : 0;

        setCycleByKey((prev) => ({
            ...prev,
            [itemKey]: currentCycle + 1,
        }));

        window.dispatchEvent(
            new CustomEvent('seo-editor-scroll-to-link', {
                detail: {
                    href,
                    text,
                    offset: item.offset,
                    type,
                    index: type === 'faq'
                        ? (typeof item.index === 'number' ? item.index : nextIndex)
                        : nextIndex,
                    faqIndex: type === 'faq'
                        ? (typeof item.index === 'number' ? item.index : nextIndex)
                        : undefined,
                    searchPlainText: options.searchPlainText === true,
                    preferHrefMatch: !text && !!href,
                },
            }),
        );
    };

    const removeInternalLink = (item) => {
        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? '').trim();
        if (!text && !href) {
            return;
        }

        window.dispatchEvent(
            new CustomEvent('seo-editor-remove-internal-link', {
                detail: { text, href },
            }),
        );
    };

    const insertSuggestedLink = (item, _index, itemKey) => {
        hideSuggestionRow(itemKey);

        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? item?.target_url ?? '').trim();
        const count = occurrenceCount(item);
        const cycle = Number(cycleByKey[itemKey] ?? 0);
        const occurrenceIndex = cycle > 0 && count > 1 ? (cycle - 1) % count : 0;
        if (!text || !href) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('links_insert_failed_title'),
                        body: t('links_insert_failed_body'),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        window.dispatchEvent(
            new CustomEvent('seo-editor-insert-suggested-link', {
                detail: {
                    text,
                    href,
                    keyword_id: item.keyword_id ?? null,
                    occurrence_index: occurrenceIndex,
                },
            }),
        );
    };

    const hideDomainRow = (itemKey) => {
        if (!itemKey) {
            return;
        }
        setDomainHiddenRowKeys((prev) => {
            if (prev.has(itemKey)) {
                return prev;
            }
            const next = new Set(prev);
            next.add(itemKey);
            return next;
        });
    };

    const scrollToDomainItem = (item, itemKey, variant) => {
        if (variant === 'cta') {
            setCtaActiveKey(itemKey);
        } else {
            setDomainLinkActiveKey(itemKey);
        }
        const text = variant === 'cta' ? ctaDisplayLabel(item) : String(item?.text ?? '').trim();

        window.dispatchEvent(
            new CustomEvent('seo-editor-scroll-to-link', {
                detail: {
                    href:
                        variant === 'cta'
                            ? String(item?.href ?? formatCtaHref(item?.type, item?.value)).trim()
                            : String(item?.href ?? item?.target_url ?? '').trim(),
                    text,
                    type: 'internal',
                    index: 0,
                    searchPlainText: true,
                },
            }),
        );
    };

    const insertDomainLink = (item, itemKey) => {
        hideDomainRow(itemKey);

        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? item?.target_url ?? '').trim();
        if (!text || !href) {
            return;
        }

        window.dispatchEvent(
            new CustomEvent('seo-editor-insert-suggested-link', {
                detail: {
                    text,
                    href,
                    keyword_id: item.keyword_id ?? null,
                },
            }),
        );
    };

    const insertCta = (item) => {
        const type = String(item?.type ?? '').toLowerCase();
        if (!type) {
            return;
        }

        if (item?.is_blank === true) {
            const token = `[${type}]`;
            window.dispatchEvent(
                new CustomEvent('seo-editor-insert-cta-link', {
                    detail: {
                        text: token,
                        href: '',
                        type,
                        is_placeholder: true,
                        html: `<span class="seo-cta-blank-placeholder" data-cta-type="${type}">${token}</span>`,
                    },
                }),
            );

            return;
        }

        const text = ctaDisplayLabel(item);
        const plainText = isCtaPlainTextType(type) || item?.plain_text === true;
        const href = plainText ? '' : String(item?.href ?? formatCtaHref(type, item?.value)).trim();
        if (!text || (!href && !plainText)) {
            return;
        }

        window.dispatchEvent(
            new CustomEvent('seo-editor-insert-cta-link', {
                detail: {
                    text,
                    href,
                    type,
                },
            }),
        );
    };

    const linkCountBadge = internal.length + external.length + domainLinks.length;
    const showAllLinkSections = linkSectionFilter === 'all';
    const showLinksCluster = showAllLinkSections || linkSectionFilter === 'links';
    const showFaqSection = showAllLinkSections || linkSectionFilter === 'faq';
    const showCtaSection = showAllLinkSections || linkSectionFilter === 'cta';

    useEffect(() => {
        window.dispatchEvent(
            new CustomEvent('seo-assistant-navigator-badges', {
                detail: {
                    links: linkCountBadge > 0 ? linkCountBadge : null,
                    faq: faq.length > 0 ? faq.length : null,
                    cta: domainCtas.length > 0 ? domainCtas.length : null,
                },
            }),
        );
    }, [linkCountBadge, faq.length, domainCtas.length]);

    return (
        <ArticleAssistantWidget
            widgetId="links"
            title="Link Assistant"
            icon={Link2}
            badge={linkCountBadge > 0 ? linkCountBadge : null}
            defaultCollapsed={false}
            className="seo-assistant-widget--links"
        >
            <div className="seo-link-assistant">
                {showLinksCluster ? (
                    <>
                <LinkAssistantSection
                    title={`Internal Links (${internal.length})`}
                    count={internal.length}
                    collapsed={internalCollapsed}
                    onToggle={() => setInternalCollapsed((value) => !value)}
                    sectionKey="links"
                >
                    <InternalLinksSection
                        internal={internal}
                        suggestedInternal={suggestedInternal}
                        activeKey={activeKey}
                        hiddenRowKeys={hiddenRowKeys}
                        excludedCount={excludedSuggestionLabels.size}
                        onClearExcluded={clearExcludedSuggestions}
                        onKeywordClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'internal', index, itemKey)
                        }
                        onCopyKeyword={copyKeyword}
                        onRemoveInternalLink={removeInternalLink}
                        onSuggestionClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'internal', index, itemKey, { searchPlainText: true })
                        }
                        onInsertSuggestion={insertSuggestedLink}
                        onExcludeSuggestion={excludeSuggestion}
                    />
                </LinkAssistantSection>

                <LinkAssistantSection
                    title={`External Links (${external.length})`}
                    count={external.length}
                    collapsed={externalCollapsed}
                    onToggle={() => setExternalCollapsed((value) => !value)}
                    sectionKey="links"
                >
                    <KeywordList
                        items={external}
                        title={t('links_external_title', { count: external.length })}
                        activeKey={activeKey}
                        target="editor"
                        hideTitle
                        onKeywordClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'external', index, itemKey)
                        }
                        onCopyKeyword={copyKeyword}
                    />
                </LinkAssistantSection>

                <LinkAssistantSection
                    title={`${t('domain_link_widget_title')} (${domainLinks.length})`}
                    count={domainLinks.length}
                    collapsed={domainLinksCollapsed}
                    onToggle={() => setDomainLinksCollapsed((value) => !value)}
                    sectionKey="links"
                >
                    <p className="wp-article-links-hint">{t('domain_link_widget_hint')}</p>
                    <DomainInsertableList
                        items={domainLinks}
                        variant="domain-link"
                        activeKey={domainLinkActiveKey}
                        hiddenRowKeys={domainHiddenRowKeys}
                        emptyText={
                            domainLinkCatalogCount > 0
                                ? t('domain_link_widget_empty_in_article')
                                : t('domain_link_widget_empty')
                        }
                        onKeywordClick={(item, _index, itemKey) => scrollToDomainItem(item, itemKey, 'domain-link')}
                        onInsert={insertDomainLink}
                    />
                </LinkAssistantSection>
                    </>
                ) : null}

                {showFaqSection ? (
                <LinkAssistantSection
                    title={`FAQ (${faq.length})`}
                    count={faq.length}
                    collapsed={faqCollapsed}
                    onToggle={() => setFaqCollapsed((value) => !value)}
                    sectionKey="faq"
                >
                    <KeywordList
                        items={faq}
                        title={t('links_faq_title', { count: faq.length })}
                        activeKey={activeKey}
                        target="faq"
                        hideTitle
                        interactive={false}
                        onKeywordClick={() => {}}
                        onCopyKeyword={copyKeyword}
                    />
                </LinkAssistantSection>
                ) : null}

                {showCtaSection ? (
                <LinkAssistantSection
                    title={`${t('cta_widget_title')} (${domainCtas.length})`}
                    count={domainCtas.length}
                    collapsed={ctaCollapsed}
                    onToggle={() => setCtaCollapsed((value) => !value)}
                    sectionKey="cta"
                >
                    <p className="wp-article-links-hint">{t('cta_widget_hint')}</p>
                    <DomainInsertableList
                        items={domainCtas}
                        variant="cta"
                        activeKey={ctaActiveKey}
                        emptyText={t('cta_widget_empty')}
                        onKeywordClick={(item, _index, itemKey) => scrollToDomainItem(item, itemKey, 'cta')}
                        onInsert={insertCta}
                    />
                </LinkAssistantSection>
                ) : null}
            </div>
        </ArticleAssistantWidget>
    );
}
