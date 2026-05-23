import React, { useEffect, useState } from 'react';

/**
 * @typedef {{ href?: string, text: string, offset?: number, is_nofollow?: boolean }} ExtractedLink
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
            return url.pathname.split('/').filter(Boolean).pop() || item.href;
        } catch {
            return item.href;
        }
    }
    return '—';
}

/**
 * @param {{ items: Array<ExtractedLink|FaqLinkItem>, title: string, activeKey: string, target: 'editor'|'faq', onKeywordClick: Function }} props
 */
function KeywordList({ items, title, activeKey, target, onKeywordClick }) {
    if (!items.length) {
        return (
            <div className="wp-article-links-group">
                <h3 className="wp-article-links-group__title">{title}</h3>
                <p className="wp-article-links-empty">Chưa có mục.</p>
            </div>
        );
    }

    return (
        <div className="wp-article-links-group">
            <h3 className="wp-article-links-group__title">{title}</h3>
            <ul className="wp-article-links-keywords">
                {items.map((item, index) => {
                    const itemKey = `${target}-${item.text}-${index}`;
                    const isActive = activeKey === itemKey;
                    const hint =
                        target === 'faq'
                            ? `Tìm trong FAQ: ${keywordLabel(item)}`
                            : `Tìm từ khóa trong bài: ${keywordLabel(item)}`;

                    return (
                        <li key={itemKey}>
                            <button
                                type="button"
                                className={`wp-article-links-keyword${isActive ? ' is-active' : ''}${target === 'faq' ? ' is-faq' : ''}`}
                                title={hint}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => onKeywordClick(item, index, itemKey, target)}
                            >
                                {keywordLabel(item)}
                            </button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

export default function ArticleLinksSidebar() {
    const [links, setLinks] = useState({ internal: [], external: [], faq: [] });
    const [activeKey, setActiveKey] = useState('');

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

        window.addEventListener('seo-editor-links-updated', onLinksUpdate);
        window.addEventListener('seo-editor-faqs-updated', onFaqUpdate);

        return () => {
            window.removeEventListener('seo-editor-links-updated', onLinksUpdate);
            window.removeEventListener('seo-editor-faqs-updated', onFaqUpdate);
        };
    }, []);

    const internal = links.internal ?? [];
    const external = links.external ?? [];
    const faq = links.faq ?? [];

    const scrollToKeyword = (item, type, listIndex, itemKey) => {
        setActiveKey(itemKey);
        const faqIndex = type === 'faq' && typeof item.index === 'number' ? item.index : listIndex;

        window.dispatchEvent(
            new CustomEvent('seo-editor-scroll-to-link', {
                detail: {
                    href: item.href,
                    text: item.text,
                    offset: item.offset,
                    type,
                    index: type === 'faq' ? faqIndex : listIndex,
                    faqIndex: type === 'faq' ? faqIndex : undefined,
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
            </div>
            <div className="wp-postbox-inside">
                <KeywordList
                    items={internal}
                    title={`Nội bộ (${internal.length})`}
                    activeKey={activeKey}
                    target="editor"
                    onKeywordClick={(item, index, itemKey) =>
                        scrollToKeyword(item, 'internal', index, itemKey)
                    }
                />
                <KeywordList
                    items={external}
                    title={`Ngoài (${external.length})`}
                    activeKey={activeKey}
                    target="editor"
                    onKeywordClick={(item, index, itemKey) =>
                        scrollToKeyword(item, 'external', index, itemKey)
                    }
                />
                <KeywordList
                    items={faq}
                    title={`FAQ (${faq.length})`}
                    activeKey={activeKey}
                    target="faq"
                    onKeywordClick={(item, index, itemKey) =>
                        scrollToKeyword(item, 'faq', index, itemKey)
                    }
                />
            </div>
        </div>
    );
}
