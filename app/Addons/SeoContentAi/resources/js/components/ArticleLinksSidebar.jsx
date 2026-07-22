import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, ChevronRight, Copy, Link2, Loader2, OctagonAlert, Phone, RotateCcw, Trash2, TriangleAlert } from 'lucide-react';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { t } from '../utils/i18n';
import ArticleAssistantWidget from './ArticleAssistantWidget';
import KeywordReviewPopover from './KeywordReviewPopover';
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
    isSpecialOrContactHref,
    isSuggestionExcluded,
    mergeSuggestionCatalog,
    normalizeHrefForCompare,
    normalizeLinkLabel,
    partitionSuggestionCatalogBySite,
} from '../utils/articleLinkSuggestionFilter';
import {
    clearExcludedLinkSuggestions,
    loadExcludedLinkSuggestions,
    saveExcludedLinkSuggestions,
} from '../utils/articleExcludedLinkSuggestionsStorage';
import { csrfToken, seoArticleApiFetch } from '../utils/seoArticleApi';

/**
 * On-demand full SEO/links payload (not part of editor bootstrap).
 * @param {number} articleId
 */
async function fetchEditorSeoPayload(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return null;
    }

    const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${id}/editor-seo-payload`, {
        method: 'GET',
        headers: {
            Accept: 'application/json',
            ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
        },
    });

    if (!response.ok || data?.success === false) {
        return null;
    }

    return data?.data && typeof data.data === 'object' ? data.data : null;
}

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

const applyDomainLinkFilters = (allLinks, articlePlainText, internalLinks, externalLinks = []) => {
    const inArticle = filterDomainLinksInArticleContent(allLinks, articlePlainText).filter(
        (item) => !isSpecialOrContactHref(item?.href ?? item?.target_url),
    );

    return filterSuggestedInternalLinks(inArticle, internalLinks, externalLinks).map((item) => ({
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
 * @param {{ items: Array<ExtractedLink|FaqLinkItem>, title: string, activeKey: string, target: 'editor'|'faq', variant?: 'default'|'suggestion', suggestionKind?: 'internal'|'external', hideTitle?: boolean, interactive?: boolean, hiddenRowKeys?: Set<string>, reviewLoadingKey?: string, reviewPopoverItemKey?: string, onKeywordClick: Function, onInsertSuggestion?: Function, onCopyKeyword?: Function, onRemoveInternalLink?: Function, onReviewWarning?: Function, onReviewDanger?: Function }} props
 */
function KeywordList({
    items,
    title,
    activeKey,
    target,
    variant = 'default',
    suggestionKind = 'internal',
    hideTitle = false,
    interactive = true,
    hiddenRowKeys,
    reviewLoadingKey = '',
    reviewPopoverItemKey = '',
    onKeywordClick,
    onInsertSuggestion,
    onCopyKeyword,
    onRemoveInternalLink,
    onReviewWarning,
    onReviewDanger,
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
                                ? t(
                                      suggestionKind === 'external'
                                          ? 'links_suggestion_insert_external_ready'
                                          : 'links_suggestion_insert_ready',
                                      { label },
                                  )
                                : t('links_suggestion_insert_missing', { label })
                            : target === 'faq'
                              ? t('links_find_in_faq', { label })
                              : anchorTextPresent
                                ? t('links_find_keyword', { label })
                                : t('links_find_link', { label });

                    const isRowHiding = hiddenRowKeys?.has(itemKey) === true;
                    const isReviewLoading = reviewLoadingKey === itemKey;
                    const isReviewOpen = reviewPopoverItemKey === itemKey;

                    return (
                        <li
                            key={itemKey}
                            data-keyword-row-key={itemKey}
                            className={`wp-article-links-keyword-row${isRowHiding ? ' is-row-hiding' : ''}${isReviewLoading ? ' is-review-loading' : ''}${isReviewOpen ? ' is-review-open' : ''}`}
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
                                            ? t(
                                                  suggestionKind === 'external'
                                                      ? 'links_insert_external_for'
                                                      : 'links_insert_internal_for',
                                                  { label },
                                              )
                                            : t('links_missing_target_url')
                                    }
                                    title={
                                        insertable
                                            ? t(
                                                  suggestionKind === 'external'
                                                      ? 'links_insert_external_for_label'
                                                      : 'links_insert_internal_for_label',
                                                  { label },
                                              )
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
                            {variant === 'suggestion' && (onReviewWarning || onReviewDanger) ? (
                                <>
                                    {onReviewWarning ? (
                                        <button
                                            type="button"
                                            className="wp-article-links-review-btn is-warning"
                                            aria-label={t('keyword_review_warning_button_label', { label })}
                                            title={t('keyword_review_warning_button_title')}
                                            disabled={isReviewLoading}
                                            onMouseDown={(e) => e.preventDefault()}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                const rowEl = e.currentTarget.closest('.wp-article-links-keyword-row');
                                                onReviewWarning(item, index, itemKey, rowEl);
                                            }}
                                        >
                                            {isReviewLoading ? (
                                                <Loader2 size={13} className="is-spinning" aria-hidden />
                                            ) : (
                                                <TriangleAlert size={13} aria-hidden />
                                            )}
                                        </button>
                                    ) : null}
                                    {onReviewDanger ? (
                                        <button
                                            type="button"
                                            className="wp-article-links-review-btn is-danger"
                                            aria-label={t('keyword_review_danger_button_label', { label })}
                                            title={t('keyword_review_danger_button_title')}
                                            disabled={isReviewLoading}
                                            onMouseDown={(e) => e.preventDefault()}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                const rowEl = e.currentTarget.closest('.wp-article-links-keyword-row');
                                                onReviewDanger(item, index, itemKey, rowEl);
                                            }}
                                        >
                                            {isReviewLoading ? (
                                                <Loader2 size={13} className="is-spinning" aria-hidden />
                                            ) : (
                                                <OctagonAlert size={13} aria-hidden />
                                            )}
                                        </button>
                                    ) : null}
                                </>
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
    reviewLoadingKey = '',
    reviewPopoverItemKey = '',
    onKeywordClick,
    onSuggestionClick,
    onInsertSuggestion,
    onCopyKeyword,
    onRemoveInternalLink,
    onReviewWarning,
    onReviewDanger,
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
                            reviewLoadingKey={reviewLoadingKey}
                            reviewPopoverItemKey={reviewPopoverItemKey}
                            onKeywordClick={onSuggestionClick}
                            onInsertSuggestion={onInsertSuggestion}
                            onCopyKeyword={onCopyKeyword}
                            onReviewWarning={onReviewWarning}
                            onReviewDanger={onReviewDanger}
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

    return {
        domainCatalog: Array.isArray(data?.domain_link_list_catalog) ? data.domain_link_list_catalog : [],
        externalCatalog: mergeSuggestionCatalog(
            data?.suggested_external_links_catalog ?? [],
            data?.suggested_external_links ?? [],
        ),
        siteDomain: String(data?.site_domain ?? '').trim(),
    };
}

export default function ArticleLinksSidebar({
    initialDomainLinkList = [],
    initialDomainLinkCatalog = [],
    initialDomainCtaList = [],
}) {
    const articleMetaRef = useRef(readArticleMetaIds());
    const [reviewPopover, setReviewPopover] = useState(null);
    const [reviewLoadingKey, setReviewLoadingKey] = useState('');
    const [reviewedKeywordIds, setReviewedKeywordIds] = useState(() => new Set());
    const editorSeoBootstrap = useRef(readEditorSeoBootstrap());
    const suggestionBootstrap = useRef(readSuggestionCatalogBootstrap());
    const siteDomainRef = useRef(suggestionBootstrap.current.siteDomain);
    const bootPartitioned = partitionSuggestionCatalogBySite(
        mergeSuggestionCatalog(
            editorSeoBootstrap.current?.suggested_internal_links_catalog ?? [],
            editorSeoBootstrap.current?.suggested_internal_links ?? [],
        ),
        suggestionBootstrap.current.siteDomain,
    );
    const keywordCatalogRef = useRef(bootPartitioned.internal);
    const externalKeywordCatalogRef = useRef(
        mergeSuggestionCatalog(
            suggestionBootstrap.current.externalCatalog,
            bootPartitioned.external,
        ),
    );
    const domainCatalogRef = useRef(suggestionBootstrap.current.domainCatalog);
    const [catalogVersion, setCatalogVersion] = useState(0);
    const stableSuggestionsRef = useRef([]);
    const stableSuggestionsKeyRef = useRef('');
    const stableExternalSuggestionsRef = useRef([]);
    const stableExternalSuggestionsKeyRef = useRef('');
    const [links, setLinks] = useState(() => ({
        internal: editorSeoBootstrap.current?.extracted_links?.internal ?? [],
        external: (editorSeoBootstrap.current?.extracted_links?.external ?? []).filter(
            (item) => !isSpecialOrContactHref(item?.href),
        ),
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

    // Perf Phase 1: Links mount đã deferred — lúc mount mới fetch full SEO/link catalogs.
    useEffect(() => {
        const { articleId } = articleMetaRef.current;
        let cancelled = false;

        void (async () => {
            try {
                const payload = await fetchEditorSeoPayload(articleId);
                if (cancelled || !payload) {
                    return;
                }

                window.dispatchEvent(
                    new CustomEvent('seo-editor-seo-payload-updated', { detail: payload }),
                );
                window.dispatchEvent(
                    new CustomEvent('seo-editor-links-updated', {
                        detail: {
                            internal: payload.extracted_links?.internal ?? [],
                            external: payload.extracted_links?.external ?? [],
                            suggested_internal: payload.suggested_internal_links ?? [],
                            suggested_internal_links_catalog: payload.suggested_internal_links_catalog ?? [],
                            suggested_external_links: payload.suggested_external_links ?? [],
                            suggested_external_links_catalog: payload.suggested_external_links_catalog ?? [],
                            domain_link_list_catalog: payload.domain_link_list_catalog ?? [],
                        },
                    }),
                );
            } catch {
                // Panel vẫn mở với bootstrap rỗng; user có thể refresh sau.
            }
        })();

        return () => {
            cancelled = true;
        };
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

    const openReviewPopover = (item, itemKey, severity, anchorEl) => {
        const keywordId = Number(item?.keyword_id ?? 0);
        const text = String(item?.text ?? '').trim();
        if (keywordId <= 0 || text === '' || !(anchorEl instanceof HTMLElement)) {
            return;
        }

        setReviewPopover({
            itemKey,
            keywordId,
            text,
            severity,
            anchorEl,
        });
    };

    const focusNextSuggestionAfter = (currentItemKey) => {
        window.requestAnimationFrame(() => {
            const currentRow = document.querySelector(`[data-keyword-row-key="${currentItemKey}"]`);
            if (!(currentRow instanceof HTMLElement)) {
                return;
            }

            let sibling = currentRow.nextElementSibling;
            while (sibling instanceof HTMLElement) {
                if (sibling.matches('.wp-article-links-keyword-row')) {
                    const button = sibling.querySelector('.wp-article-links-keyword');
                    if (button instanceof HTMLElement) {
                        button.focus();
                        return;
                    }
                }

                sibling = sibling.nextElementSibling;
            }
        });
    };

    const handleReviewSubmitted = ({ keywordId, itemKey, text }) => {
        if (keywordId > 0) {
            setReviewedKeywordIds((prev) => {
                const next = new Set(prev);
                next.add(keywordId);
                return next;
            });
        }

        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('keyword_review_submitted_title'),
                    body: t('keyword_review_submitted_body', { label: String(text ?? '').trim() }),
                    status: 'success',
                },
            }),
        );

        focusNextSuggestionAfter(itemKey);
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
                    external: (Array.isArray(payload.external) ? payload.external : []).filter(
                        (item) => !isSpecialOrContactHref(item?.href),
                    ),
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
            const external = Array.isArray(payload?.external)
                ? payload.external
                : Array.isArray(event.detail?.extracted_links?.external)
                  ? event.detail.extracted_links.external
                  : [];
            setDomainLinks(applyDomainLinkFilters(allDomainLinksRef.current, articlePlain, internal, external));

            const incomingSuggested = Array.isArray(event.detail?.suggested_internal)
                ? event.detail.suggested_internal
                : [];
            const incomingKeywordCatalog = Array.isArray(event.detail?.suggested_internal_links_catalog)
                ? event.detail.suggested_internal_links_catalog
                : [];
            const incomingExternalSuggested = Array.isArray(event.detail?.suggested_external)
                ? event.detail.suggested_external
                : Array.isArray(event.detail?.suggested_external_links)
                  ? event.detail.suggested_external_links
                  : [];
            const incomingExternalCatalog = Array.isArray(event.detail?.suggested_external_links_catalog)
                ? event.detail.suggested_external_links_catalog
                : [];
            const incomingCatalog = Array.isArray(event.detail?.domain_link_list_catalog)
                ? event.detail.domain_link_list_catalog
                : [];
            const incomingSiteDomain = String(event.detail?.site_domain ?? '').trim();
            if (incomingSiteDomain !== '') {
                siteDomainRef.current = incomingSiteDomain;
            }

            if (incomingKeywordCatalog.length > 0) {
                const partitioned = partitionSuggestionCatalogBySite(
                    mergeSuggestionCatalog(incomingKeywordCatalog, incomingSuggested),
                    siteDomainRef.current,
                );
                keywordCatalogRef.current = mergeSuggestionCatalog(
                    keywordCatalogRef.current,
                    partitioned.internal,
                );
                externalKeywordCatalogRef.current = mergeSuggestionCatalog(
                    externalKeywordCatalogRef.current,
                    partitioned.external,
                    incomingExternalCatalog,
                    incomingExternalSuggested,
                );
            } else if (incomingSuggested.length > 0) {
                const partitioned = partitionSuggestionCatalogBySite(
                    incomingSuggested,
                    siteDomainRef.current,
                );
                keywordCatalogRef.current = mergeSuggestionCatalog(
                    keywordCatalogRef.current,
                    partitioned.internal,
                );
                externalKeywordCatalogRef.current = mergeSuggestionCatalog(
                    externalKeywordCatalogRef.current,
                    partitioned.external,
                );
            }

            if (incomingExternalCatalog.length > 0 || incomingExternalSuggested.length > 0) {
                externalKeywordCatalogRef.current = mergeSuggestionCatalog(
                    externalKeywordCatalogRef.current,
                    incomingExternalCatalog,
                    incomingExternalSuggested,
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
                const external = Array.isArray(detail.extracted_links?.external)
                    ? detail.extracted_links.external
                    : [];
                const articlePlain = String(detail.article_plain_text ?? '');
                setDomainLinks(
                    applyDomainLinkFilters(allDomainLinksRef.current, articlePlain, internal, external),
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
        const partitioned = partitionSuggestionCatalogBySite(
            keywordCatalogRef.current,
            siteDomainRef.current,
        );
        const pool = partitioned.internal;
        const internalSignature = (internal ?? [])
            .map((item) => {
                const label = normalizeLinkLabel(item?.text);
                const href = normalizeHrefForCompare(item?.href);

                return `${label}|${href}`;
            })
            .join(';');
        const externalSignature = (external ?? [])
            .map((item) => {
                const label = normalizeLinkLabel(item?.text);
                const href = normalizeHrefForCompare(item?.href);

                return `${label}|${href}`;
            })
            .join(';');
        const poolKey = `${catalogVersion}:${internalSignature}:${externalSignature}:${plain}:internal`;

        if (stableSuggestionsKeyRef.current !== poolKey) {
            stableSuggestionsKeyRef.current = poolKey;
            stableSuggestionsRef.current = buildVisibleInternalSuggestions({
                catalog: pool,
                internal,
                external,
                excludedLabels: [],
                skipContentFilter: true,
            });
        }

        return stableSuggestionsRef.current.filter((item) => {
            const keywordId = Number(item?.keyword_id ?? 0);
            if (keywordId > 0 && reviewedKeywordIds.has(keywordId)) {
                return false;
            }

            return !isSuggestionExcluded(String(item?.text ?? ''), excludedSuggestionLabels);
        });
    }, [internal, external, excludedSuggestionLabels, reviewedKeywordIds, articlePlainText, catalogVersion]);

    const suggestedExternal = useMemo(() => {
        const plain = articlePlainText.trim();
        const fromKeywords = partitionSuggestionCatalogBySite(
            keywordCatalogRef.current,
            siteDomainRef.current,
        ).external;
        const fromExternalCatalog = externalKeywordCatalogRef.current;
        const pool = mergeSuggestionCatalog(fromExternalCatalog, fromKeywords);
        const internalSignature = (internal ?? [])
            .map((item) => `${normalizeLinkLabel(item?.text)}|${normalizeHrefForCompare(item?.href)}`)
            .join(';');
        const externalSignature = (external ?? [])
            .map((item) => `${normalizeLinkLabel(item?.text)}|${normalizeHrefForCompare(item?.href)}`)
            .join(';');
        const poolKey = `${catalogVersion}:${internalSignature}:${externalSignature}:${plain}:external`;

        if (stableExternalSuggestionsKeyRef.current !== poolKey) {
            stableExternalSuggestionsKeyRef.current = poolKey;
            stableExternalSuggestionsRef.current = buildVisibleInternalSuggestions({
                catalog: pool,
                internal,
                external,
                excludedLabels: [],
                skipContentFilter: true,
                maxSlots: Number.MAX_SAFE_INTEGER,
            });
        }

        return stableExternalSuggestionsRef.current.filter((item) => {
            const href = String(item?.href ?? item?.target_url ?? '').trim();
            if (href === '' || isSpecialOrContactHref(href)) {
                return false;
            }

            const keywordId = Number(item?.keyword_id ?? 0);
            if (keywordId > 0 && reviewedKeywordIds.has(keywordId)) {
                return false;
            }

            return !isSuggestionExcluded(String(item?.text ?? ''), excludedSuggestionLabels);
        });
    }, [internal, external, excludedSuggestionLabels, reviewedKeywordIds, articlePlainText, catalogVersion]);

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
                        reviewLoadingKey={reviewLoadingKey}
                        reviewPopoverItemKey={reviewPopover?.itemKey ?? ''}
                        onReviewWarning={(item, _index, itemKey, anchorEl) =>
                            openReviewPopover(item, itemKey, 'warning', anchorEl)
                        }
                        onReviewDanger={(item, _index, itemKey, anchorEl) =>
                            openReviewPopover(item, itemKey, 'danger', anchorEl)
                        }
                    />
                </LinkAssistantSection>

                <LinkAssistantSection
                    title={`External Links (${external.length})`}
                    count={external.length}
                    collapsed={externalCollapsed}
                    onToggle={() => setExternalCollapsed((value) => !value)}
                    sectionKey="links"
                >
                    <div className="wp-article-links-group">
                        <h3 className="wp-article-links-group__title">
                            {t('links_external_title', { count: external.length })}
                        </h3>
                        {external.length > 0 ? (
                            <KeywordList
                                items={external}
                                title=""
                                activeKey={activeKey}
                                target="editor"
                                hideTitle
                                onKeywordClick={(item, index, itemKey) =>
                                    scrollToKeyword(item, 'external', index, itemKey)
                                }
                                onCopyKeyword={copyKeyword}
                            />
                        ) : (
                            <p className="wp-article-links-empty">{t('links_external_empty')}</p>
                        )}
                        {suggestedExternal.length > 0 ? (
                            <KeywordList
                                items={suggestedExternal}
                                title={t('links_external_suggestion_title', {
                                    count: suggestedExternal.length,
                                })}
                                activeKey={activeKey}
                                target="editor"
                                variant="suggestion"
                                suggestionKind="external"
                                hideTitle
                                hiddenRowKeys={hiddenRowKeys}
                                reviewLoadingKey={reviewLoadingKey}
                                reviewPopoverItemKey={reviewPopover?.itemKey ?? ''}
                                onKeywordClick={(item, index, itemKey) =>
                                    scrollToKeyword(item, 'external', index, itemKey, {
                                        searchPlainText: true,
                                    })
                                }
                                onInsertSuggestion={insertSuggestedLink}
                                onCopyKeyword={copyKeyword}
                                onReviewWarning={(item, _index, itemKey, anchorEl) =>
                                    openReviewPopover(item, itemKey, 'warning', anchorEl)
                                }
                                onReviewDanger={(item, _index, itemKey, anchorEl) =>
                                    openReviewPopover(item, itemKey, 'danger', anchorEl)
                                }
                            />
                        ) : null}
                    </div>
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
            <KeywordReviewPopover
                state={reviewPopover}
                articleId={articleMetaRef.current.articleId}
                onClose={() => setReviewPopover(null)}
                onSubmitted={handleReviewSubmitted}
                onError={() => {}}
                onLoadingChange={setReviewLoadingKey}
            />
        </ArticleAssistantWidget>
    );
}
