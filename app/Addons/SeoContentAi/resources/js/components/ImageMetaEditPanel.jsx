import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { getArticleImageSelection } from '../utils/articleImageExtension';

export default function ImageMetaEditPanel({ editor, anchorRect, onClose }) {
    const panelRef = useRef(null);
    const [position, setPosition] = useState({ top: 0, left: 0 });
    const [alt, setAlt] = useState('');
    const [title, setTitle] = useState('');
    const [caption, setCaption] = useState('');

    const selection = getArticleImageSelection(editor);

    useEffect(() => {
        if (!selection) return;
        setAlt(selection.attrs.alt ?? '');
        setTitle(selection.attrs.title ?? '');
        setCaption(selection.attrs.caption ?? '');
    }, [selection?.attrs?.src, selection?.attrs?.alt, selection?.attrs?.title, selection?.attrs?.caption]);

    useLayoutEffect(() => {
        if (!anchorRect || !panelRef.current) return;

        const width = panelRef.current.offsetWidth;
        const left = anchorRect.left + anchorRect.width / 2 - width / 2;
        const top = anchorRect.bottom + 12;

        setPosition({
            top: Math.min(top, window.innerHeight - panelRef.current.offsetHeight - 8),
            left: Math.max(8, Math.min(left, window.innerWidth - width - 8)),
        });
    }, [anchorRect]);

    useEffect(() => {
        const onDocMouseDown = (e) => {
            if (panelRef.current?.contains(e.target)) return;
            onClose();
        };
        document.addEventListener('mousedown', onDocMouseDown);
        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, [onClose]);

    const applyMeta = () => {
        editor
            .chain()
            .focus()
            .updateAttributes('articleImage', {
                alt: alt.trim(),
                title: title.trim(),
                caption: caption.trim(),
            })
            .run();
        onClose();
    };

    if (!anchorRect || !selection) return null;

    const panel = (
        <div
            ref={panelRef}
            className="seo-image-meta-panel"
            style={{ top: `${position.top}px`, left: `${position.left}px` }}
            onMouseDown={(e) => e.stopPropagation()}
        >
            <p className="seo-image-meta-panel-title">Chỉnh sửa ảnh</p>

            <label className="seo-image-meta-label" htmlFor="seo-img-alt">
                Văn bản thay thế (alt)
            </label>
            <input
                id="seo-img-alt"
                type="text"
                className="seo-image-meta-input"
                value={alt}
                onChange={(e) => setAlt(e.target.value)}
                placeholder="Mô tả ảnh cho SEO và trợ năng"
            />

            <label className="seo-image-meta-label" htmlFor="seo-img-title">
                Title
            </label>
            <input
                id="seo-img-title"
                type="text"
                className="seo-image-meta-input"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="Thuộc tính title của thẻ img"
            />

            <label className="seo-image-meta-label" htmlFor="seo-img-caption">
                Chú thích (caption)
            </label>
            <textarea
                id="seo-img-caption"
                className="seo-image-meta-textarea"
                value={caption}
                onChange={(e) => setCaption(e.target.value)}
                rows={2}
                placeholder="Hiển thị dưới ảnh (figcaption)"
            />

            <div className="seo-image-meta-actions">
                <button type="button" className="seo-image-meta-btn" onClick={onClose}>
                    Hủy
                </button>
                <button type="button" className="seo-image-meta-btn is-primary" onClick={applyMeta}>
                    Áp dụng
                </button>
            </div>
        </div>
    );

    return createPortal(panel, document.body);
}
