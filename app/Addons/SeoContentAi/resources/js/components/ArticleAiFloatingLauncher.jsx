import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ListTree, MessageCircle, Sparkles, X } from 'lucide-react';

export default function ArticleAiFloatingLauncher() {
    const [menuOpen, setMenuOpen] = useState(false);
    const [hasSelection, setHasSelection] = useState(false);
    const rootRef = useRef(null);

    useEffect(() => {
        const onSelection = (e) => {
            setHasSelection(Boolean(e.detail?.hasSelection && e.detail?.text));
        };

        window.addEventListener('seo-editor-text-selection', onSelection);

        return () => {
            window.removeEventListener('seo-editor-text-selection', onSelection);
        };
    }, []);

    useEffect(() => {
        if (!menuOpen) {
            return undefined;
        }

        const onPointerDown = (e) => {
            if (rootRef.current?.contains(e.target)) {
                return;
            }
            setMenuOpen(false);
        };

        const onKeyDown = (e) => {
            if (e.key === 'Escape') {
                setMenuOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [menuOpen]);

    const openChat = useCallback(() => {
        setMenuOpen(false);
        window.dispatchEvent(new CustomEvent('seo-article-ai-chat-open'));
    }, []);

    const extractFaq = useCallback(() => {
        setMenuOpen(false);
        window.dispatchEvent(new CustomEvent('extract-article-faqs-from-toolbar'));
    }, []);

    return (
        <div ref={rootRef} className="seo-ai-fab" aria-live="polite">
            {menuOpen ? (
                <div className="seo-ai-fab__menu" role="menu">
                    <button
                        type="button"
                        className="seo-ai-fab__menu-item"
                        role="menuitem"
                        onClick={extractFaq}
                        disabled={!hasSelection}
                        title={
                            hasSelection
                                ? 'Tách FAQ từ đoạn đang chọn'
                                : 'Chọn đoạn văn trong editor trước'
                        }
                    >
                        <ListTree size={16} aria-hidden />
                        Tách FAQ
                    </button>
                    <button
                        type="button"
                        className="seo-ai-fab__menu-item seo-ai-fab__menu-item--primary"
                        role="menuitem"
                        onClick={openChat}
                    >
                        <MessageCircle size={16} aria-hidden />
                        AI ảnh & video
                    </button>
                </div>
            ) : null}

            <button
                type="button"
                className={`seo-ai-fab__trigger ${menuOpen ? 'is-open' : ''}`}
                aria-expanded={menuOpen}
                aria-haspopup="menu"
                title="Trợ lý AI"
                onClick={() => setMenuOpen((open) => !open)}
            >
                {menuOpen ? <X size={22} aria-hidden /> : <Sparkles size={22} aria-hidden />}
            </button>
        </div>
    );
}
