import React, { useEffect, useState } from 'react';
import { ChevronDown, ChevronRight, Copy, Link2, Trash2 } from 'lucide-react';
import { t } from '../utils/i18n';
import {
    filterSuggestedInternalLinks,
    normalizeHrefForCompare,
    normalizeLinkLabel,
} from '../utils/articleLinkSuggestionFilter';

/**
 * @typedef {{ href?: string, text: string, offset?: number, is_nofollow?: boolean, is_suggestion?: boolean, target_url?: string|null, can_insert?: boolean, keyword_id?: number, occurrence_count?: number }} ExtractedLink
 * @typedef {{ text: string, index: number }} FaqLinkItem
 */

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
 * @param {{ items: Array<ExtractedLink|FaqLinkItem>, title: string, activeKey: string, target: 'editor'|'faq', variant?: 'default'|'suggestion', hideTitle?: boolean, hiddenRowKeys?: Set<string>, onKeywordClick: Function, onInsertSuggestion?: Function, onCopyKeyword?: Function, onRemoveInternalLink?: Function }} props
 */
function KeywordList({
    items,
    title,
    activeKey,
    target,
    variant = 'default',
    hideTitle = false,
    hiddenRowKeys,
    onKeywordClick,
    onInsertSuggestion,
    onCopyKeyword,
    onRemoveInternalLink,
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
                            <button
                                type="button"
                                className={`wp-article-links-keyword${isActive ? ' is-active' : ''}${target === 'faq' ? ' is-faq' : ''}${variant === 'suggestion' ? ' is-suggestion' : ''}`}
                                title={fullTitle(item, hint)}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => onKeywordClick(item, index, itemKey, target)}
                            >
                                {labelWithCount}
                            </button>
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
    onKeywordClick,
    onSuggestionClick,
    onInsertSuggestion,
    onCopyKeyword,
    onRemoveInternalLink,
}) {
    const showSuggestions = internal.length < 10 && suggestedInternal.length > 0;

    if (internal.length === 0 && !showSuggestions) {
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
                />
            ) : null}
        </div>
    );
}

export default function ArticleLinksSidebar() {
    const [links, setLinks] = useState({ internal: [], external: [], faq: [] });
    const [suggestedInternal, setSuggestedInternal] = useState([]);
    const [activeKey, setActiveKey] = useState('');
    const [cycleByKey, setCycleByKey] = useState({});
    const [collapsed, setCollapsed] = useState(false);
    const [hiddenRowKeys, setHiddenRowKeys] = useState(() => new Set());

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

    useEffect(() => {
        const onLinksUpdate = (event) => {
            const payload = event.detail?.links ?? event.detail?.extracted_links;
            if (!payload || typeof payload !== 'object') {
                return;
            }
            setLinks((prev) => ({
                ...prev,
                internal: Array.isArray(payload.internal) ? payload.internal : [],
                external: Array.isArray(payload.external) ? payload.external : [],
            }));
            setCycleByKey({});
            setHiddenRowKeys(new Set());

            const internal = Array.isArray(payload.internal) ? payload.internal : [];
            setSuggestedInternal((prevSuggested) => {
                const suggested = Array.isArray(event.detail?.suggested_internal)
                    ? event.detail.suggested_internal
                    : prevSuggested;

                return filterSuggestedInternalLinks(suggested, internal);
            });
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

        const onInserted = (event) => {
            const text = normalizeLinkLabel(event.detail?.text);
            const hrefKey = normalizeHrefForCompare(event.detail?.href);
            if (!text && !hrefKey) {
                return;
            }
            setSuggestedInternal((prev) =>
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
        };

        window.addEventListener('seo-editor-links-updated', onLinksUpdate);
        window.addEventListener('seo-editor-faqs-updated', onFaqUpdate);
        window.addEventListener('seo-editor-suggested-link-inserted', onInserted);

        return () => {
            window.removeEventListener('seo-editor-links-updated', onLinksUpdate);
            window.removeEventListener('seo-editor-faqs-updated', onFaqUpdate);
            window.removeEventListener('seo-editor-suggested-link-inserted', onInserted);
        };
    }, []);

    const internal = links.internal ?? [];
    const external = links.external ?? [];
    const faq = links.faq ?? [];

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

    const faqCountLabel = faq.length > 0 ? `, ${faq.length} faq` : '';

    return (
        <div className="wp-postbox wp-article-links-box">
            <div className="wp-postbox-header">
                <h2>
                    {t('links_heading')}
                    <span className="wp-article-links-counts">
                        ({internal.length} int, {external.length} ext{faqCountLabel})
                    </span>
                </h2>
                <button
                    type="button"
                    className="wp-postbox-toggle"
                    aria-expanded={!collapsed}
                    title={collapsed ? t('links_expand') : t('links_collapse')}
                    onClick={() => setCollapsed((v) => !v)}
                >
                    {collapsed ? <ChevronRight size={16} /> : <ChevronDown size={16} />}
                </button>
            </div>
            {!collapsed ? (
                <div className="wp-postbox-inside">
                    <InternalLinksSection
                        internal={internal}
                        suggestedInternal={suggestedInternal}
                        activeKey={activeKey}
                        hiddenRowKeys={hiddenRowKeys}
                        onKeywordClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'internal', index, itemKey)
                        }
                        onCopyKeyword={copyKeyword}
                        onRemoveInternalLink={removeInternalLink}
                        onSuggestionClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'internal', index, itemKey, { searchPlainText: true })
                        }
                        onInsertSuggestion={insertSuggestedLink}
                    />
                    <KeywordList
                        items={external}
                        title={t('links_external_title', { count: external.length })}
                        activeKey={activeKey}
                        target="editor"
                        onKeywordClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'external', index, itemKey)
                        }
                        onCopyKeyword={copyKeyword}
                    />
                    <KeywordList
                        items={faq}
                        title={t('links_faq_title', { count: faq.length })}
                        activeKey={activeKey}
                        target="faq"
                        onKeywordClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'faq', index, itemKey)
                        }
                        onCopyKeyword={copyKeyword}
                    />
                </div>
            ) : null}
        </div>
    );
}

function normalizeSuggestionText(text) {
    return normalizeLinkLabel(text);
}
