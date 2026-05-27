import React, { useEffect, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Copy, Link2 } from 'lucide-react';

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
        parts.push(`URL gợi ý: ${item.target_url}`);
    }
    if (item?.keyword_type) {
        parts.push(`Nguồn: keyword ${item.keyword_type}`);
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
 * @param {{ items: Array<ExtractedLink|FaqLinkItem>, title: string, activeKey: string, target: 'editor'|'faq', variant?: 'default'|'suggestion', hideTitle?: boolean, onKeywordClick: Function, onInsertSuggestion?: Function, onCopyKeyword?: Function }} props
 */
function KeywordList({
    items,
    title,
    activeKey,
    target,
    variant = 'default',
    hideTitle = false,
    onKeywordClick,
    onInsertSuggestion,
    onCopyKeyword,
}) {
    if (!items.length) {
        return (
            <div className="wp-article-links-group">
                {!hideTitle ? <h3 className="wp-article-links-group__title">{title}</h3> : null}
                <p className="wp-article-links-empty">Chưa có mục.</p>
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
                                ? `Gợi ý — chèn link bài viết: ${label}`
                                : `Gợi ý — chưa có URL bài đích: ${label}`
                            : target === 'faq'
                              ? `Tìm trong FAQ: ${label}`
                              : anchorTextPresent
                                ? `Tìm từ khóa trong bài: ${label}`
                                : `Tìm link trong bài: ${label}`;

                    return (
                        <li key={itemKey} className="wp-article-links-keyword-row">
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
                                            ? `Chèn link nội bộ cho ${label}`
                                            : 'Chưa có URL bài đích'
                                    }
                                    title={
                                        insertable
                                            ? `Chèn link nội bộ cho «${label}»`
                                            : 'Chưa gắn bài viết đích cho từ khóa này'
                                    }
                                    disabled={!insertable}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        if (insertable) {
                                            onInsertSuggestion(item, index);
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
                                    aria-label={`Sao chép từ khóa ${label}`}
                                    title={`Copy: ${label}`}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onCopyKeyword(label);
                                    }}
                                >
                                    <Copy size={14} aria-hidden />
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
    onKeywordClick,
    onSuggestionClick,
    onInsertSuggestion,
    onCopyKeyword,
}) {
    const showSuggestions = internal.length < 10 && suggestedInternal.length > 0;

    if (internal.length === 0 && !showSuggestions) {
        return (
            <KeywordList
                items={[]}
                title="Nội bộ (0)"
                activeKey={activeKey}
                target="editor"
                onKeywordClick={onKeywordClick}
                onCopyKeyword={onCopyKeyword}
            />
        );
    }

    return (
        <div className="wp-article-links-group">
            <h3 className="wp-article-links-group__title">Nội bộ ({internal.length})</h3>
            {internal.length > 0 ? (
                <KeywordList
                    items={internal}
                    title=""
                    activeKey={activeKey}
                    target="editor"
                    hideTitle
                    onKeywordClick={onKeywordClick}
                    onCopyKeyword={onCopyKeyword}
                />
            ) : (
                <p className="wp-article-links-empty">Chưa có link nội bộ.</p>
            )}
            {showSuggestions ? (
                <KeywordList
                    items={suggestedInternal}
                    title={`Gợi ý (${suggestedInternal.length})`}
                    activeKey={activeKey}
                    target="editor"
                    variant="suggestion"
                    hideTitle
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

            const suggested = event.detail?.suggested_internal;
            if (Array.isArray(suggested)) {
                setSuggestedInternal(suggested);
            }
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
            const text = String(event.detail?.text ?? '').trim();
            if (!text) {
                return;
            }
            setSuggestedInternal((prev) =>
                prev.filter((item) => normalizeSuggestionText(item.text) !== text),
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
                        title: 'Đã copy từ khóa',
                        body: `«${text}»`,
                        status: 'success',
                    },
                }),
            );
        } catch {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Không copy được',
                        body: 'Trình duyệt chặn quyền clipboard.',
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
        const nextIndex = count > 1 ? currentCycle % count : listIndex;

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

    const insertSuggestedLink = (item) => {
        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? item?.target_url ?? '').trim();
        if (!text || !href) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Không chèn được link',
                        body: 'Từ khóa chưa gắn bài viết đích trên hệ thống.',
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
                },
            }),
        );
    };

    const faqCountLabel = faq.length > 0 ? `, ${faq.length} faq` : '';

    return (
        <div className="wp-postbox wp-article-links-box">
            <div className="wp-postbox-header">
                <h2>
                    Liên kết
                    <span className="wp-article-links-counts">
                        ({internal.length} int, {external.length} ext{faqCountLabel})
                    </span>
                </h2>
                <button
                    type="button"
                    className="wp-postbox-toggle"
                    aria-expanded={!collapsed}
                    title={collapsed ? 'Mở rộng' : 'Thu gọn'}
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
                        onKeywordClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'internal', index, itemKey)
                        }
                        onCopyKeyword={copyKeyword}
                        onSuggestionClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'internal', index, itemKey, { searchPlainText: true })
                        }
                        onInsertSuggestion={insertSuggestedLink}
                    />
                    <KeywordList
                        items={external}
                        title={`Ngoài (${external.length})`}
                        activeKey={activeKey}
                        target="editor"
                        onKeywordClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'external', index, itemKey)
                        }
                        onCopyKeyword={copyKeyword}
                    />
                    <KeywordList
                        items={faq}
                        title={`FAQ (${faq.length})`}
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
    return String(text ?? '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}
