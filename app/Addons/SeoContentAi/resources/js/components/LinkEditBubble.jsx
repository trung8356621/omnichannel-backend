import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ExternalLink, Trash2 } from 'lucide-react';

export default function LinkEditBubble({ editor, anchorRect, onClose }) {
    const [url, setUrl] = useState('');
    const inputRef = useRef(null);
    const panelRef = useRef(null);

    useEffect(() => {
        if (!editor) return;
        if (editor.isActive('link')) {
            editor.chain().focus().extendMarkRange('link').run();
            setUrl(editor.getAttributes('link').href ?? '');
        } else {
            setUrl('');
        }
        setTimeout(() => inputRef.current?.focus(), 0);
    }, [editor, anchorRect]);

    useEffect(() => {
        const onDocMouseDown = (e) => {
            if (panelRef.current?.contains(e.target)) return;
            onClose();
        };
        document.addEventListener('mousedown', onDocMouseDown);
        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, [onClose]);

    const applyLink = () => {
        const trimmed = url.trim();
        const chain = editor.chain().focus();
        if (trimmed === '') {
            if (editor.isActive('link')) {
                chain.extendMarkRange('link').unsetLink().run();
            }
        } else if (editor.isActive('link')) {
            chain.extendMarkRange('link').setLink({ href: trimmed }).run();
        } else {
            chain.setLink({ href: trimmed }).run();
        }
        onClose();
    };

    const removeLink = () => {
        editor.chain().focus().extendMarkRange('link').unsetLink().run();
        onClose();
    };

    const openHref = () => {
        const trimmed = url.trim();
        if (trimmed) window.open(trimmed, '_blank', 'noopener,noreferrer');
    };

    if (!anchorRect) return null;

    const bubble = (
        <div
            ref={panelRef}
            className="seo-link-bubble"
            style={{ top: `${anchorRect.bottom + 6}px`, left: `${anchorRect.left}px` }}
            onMouseDown={(e) => e.stopPropagation()}
        >
            <label className="seo-link-bubble-label" htmlFor="seo-link-url-input">
                URL
            </label>
            <div className="seo-link-bubble-row">
                <input
                    id="seo-link-url-input"
                    ref={inputRef}
                    type="url"
                    className="seo-link-bubble-input"
                    value={url}
                    onChange={(e) => setUrl(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            applyLink();
                        }
                        if (e.key === 'Escape') {
                            e.preventDefault();
                            onClose();
                        }
                    }}
                    placeholder="https://"
                />
                <button type="button" className="seo-link-bubble-icon-btn" title="Mở link" onClick={openHref}>
                    <ExternalLink size={15} />
                </button>
                <button type="button" className="seo-link-bubble-icon-btn is-danger" title="Gỡ link" onClick={removeLink}>
                    <Trash2 size={15} />
                </button>
            </div>
            <div className="seo-link-bubble-actions">
                <button type="button" className="seo-link-bubble-btn" onClick={onClose}>
                    Hủy
                </button>
                <button type="button" className="seo-link-bubble-btn is-primary" onClick={applyLink}>
                    Áp dụng
                </button>
            </div>
        </div>
    );

    return createPortal(bubble, document.body);
}
