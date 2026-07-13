import React, { useEffect, useRef, useState } from 'react';
import { ChevronDown, ChevronRight, Link2, Phone } from 'lucide-react';
import { t } from '../utils/i18n';
import {
    ctaDisplayLabel,
    formatCtaHref,
    isCtaItemInsertable,
    isCtaPlainTextType,
} from '../utils/ctaLinkFormat';
import {
    filterDomainLinksInArticleContent,
    filterSuggestedInternalLinks,
    normalizeHrefForCompare,
    normalizeLinkLabel,
} from '../utils/articleLinkSuggestionFilter';

/**
 * @typedef {{ text?: string, href?: string, target_url?: string, article_count?: number, can_insert?: boolean, keyword_id?: number|null }} DomainLinkItem
 * @typedef {{ type?: string, value?: string, label?: string, href?: string, can_insert?: boolean }} DomainCtaItem
 */

function InsertableList({
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

function WidgetBox({ title, subtitle, collapsed, onToggle, children }) {
    return (
        <div className="wp-postbox wp-article-links-box">
            <div className="wp-postbox-header">
                <h2>
                    {title}
                    {subtitle ? <span className="wp-article-links-counts">{subtitle}</span> : null}
                </h2>
                <button
                    type="button"
                    className="wp-postbox-toggle"
                    aria-expanded={!collapsed}
                    title={collapsed ? t('links_expand') : t('links_collapse')}
                    onClick={onToggle}
                >
                    {collapsed ? <ChevronRight size={16} /> : <ChevronDown size={16} />}
                </button>
            </div>
            {!collapsed ? <div className="wp-postbox-inside">{children}</div> : null}
        </div>
    );
}

const applyDomainLinkFilters = (allLinks, articlePlainText, internalLinks, externalLinks = []) => {
    const inArticle = filterDomainLinksInArticleContent(allLinks, articlePlainText);

    return filterSuggestedInternalLinks(inArticle, internalLinks, externalLinks).map((item) => ({
        ...item,
        can_insert: item.can_insert !== false,
    }));
};

/**
 * @param {{ initialDomainLinkList?: DomainLinkItem[], initialDomainLinkCatalog?: DomainLinkItem[], initialDomainCtaList?: DomainCtaItem[] }} props
 */
export default function ArticleDomainWidgetsSidebar({
    initialDomainLinkList = [],
    initialDomainLinkCatalog = [],
    initialDomainCtaList = [],
}) {
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
    const [linksCollapsed, setLinksCollapsed] = useState(false);
    const [ctaCollapsed, setCtaCollapsed] = useState(false);
    const [hiddenRowKeys, setHiddenRowKeys] = useState(() => new Set());

    const hideRow = (itemKey) => {
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
            const internal = Array.isArray(event.detail?.links?.internal)
                ? event.detail.links.internal
                : Array.isArray(event.detail?.extracted_links?.internal)
                  ? event.detail.extracted_links.internal
                  : [];
            const external = Array.isArray(event.detail?.links?.external)
                ? event.detail.links.external
                : Array.isArray(event.detail?.extracted_links?.external)
                  ? event.detail.extracted_links.external
                  : [];
            const articlePlainText = String(event.detail?.article_plain_text ?? '');

            setDomainLinks(
                applyDomainLinkFilters(allDomainLinksRef.current, articlePlainText, internal, external),
            );
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
                const articlePlainText = String(detail.article_plain_text ?? '');
                setDomainLinks(
                    applyDomainLinkFilters(
                        allDomainLinksRef.current,
                        articlePlainText,
                        internal,
                        external,
                    ),
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

        const onInserted = (event) => {
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
            setHiddenRowKeys(new Set());
        };

        window.addEventListener('seo-editor-links-updated', onLinksUpdate);
        window.addEventListener('seo-editor-seo-payload-updated', onSeoPayload);
        window.addEventListener('seo-editor-suggested-link-inserted', onInserted);

        return () => {
            window.removeEventListener('seo-editor-links-updated', onLinksUpdate);
            window.removeEventListener('seo-editor-seo-payload-updated', onSeoPayload);
            window.removeEventListener('seo-editor-suggested-link-inserted', onInserted);
        };
    }, []);

    const scrollToItem = (item, itemKey, variant) => {
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
                            ? String(
                                  item?.href ?? formatCtaHref(item?.type, item?.value),
                              ).trim()
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
        hideRow(itemKey);

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

    return (
        <>
            <WidgetBox
                title={t('domain_link_widget_title')}
                subtitle={` (${domainLinks.length})`}
                collapsed={linksCollapsed}
                onToggle={() => setLinksCollapsed((v) => !v)}
            >
                <p className="wp-article-links-hint">{t('domain_link_widget_hint')}</p>
                <InsertableList
                    items={domainLinks}
                    variant="domain-link"
                    activeKey={domainLinkActiveKey}
                    hiddenRowKeys={hiddenRowKeys}
                    emptyText={
                        domainLinkCatalogCount > 0
                            ? t('domain_link_widget_empty_in_article')
                            : t('domain_link_widget_empty')
                    }
                    onKeywordClick={(item, _index, itemKey) => scrollToItem(item, itemKey, 'domain-link')}
                    onInsert={insertDomainLink}
                />
            </WidgetBox>

            <WidgetBox
                title={t('cta_widget_title')}
                subtitle={` (${domainCtas.length})`}
                collapsed={ctaCollapsed}
                onToggle={() => setCtaCollapsed((v) => !v)}
            >
                <p className="wp-article-links-hint">{t('cta_widget_hint')}</p>
                <InsertableList
                    items={domainCtas}
                    variant="cta"
                    activeKey={ctaActiveKey}
                    emptyText={t('cta_widget_empty')}
                    onKeywordClick={(item, _index, itemKey) => scrollToItem(item, itemKey, 'cta')}
                    onInsert={insertCta}
                />
            </WidgetBox>
        </>
    );
}
