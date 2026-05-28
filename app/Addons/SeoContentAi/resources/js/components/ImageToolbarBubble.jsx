import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Maximize2,
    Pencil,
    X,
} from 'lucide-react';
import { getArticleImageSelection } from '../utils/articleImageExtension';
import { t } from '../utils/i18n';

const ALIGN_OPTIONS = [
    { id: 'left', icon: AlignLeft, title: t('toolbar_align_left') },
    { id: 'center', icon: AlignCenter, title: t('toolbar_align_center') },
    { id: 'right', icon: AlignRight, title: t('toolbar_align_right') },
    { id: 'full', icon: Maximize2, title: t('image_align_full_width') },
];

export default function ImageToolbarBubble({ editor, anchorRect, onEditMeta, onClose }) {
    const panelRef = useRef(null);
    const [position, setPosition] = useState({ top: 0, left: 0 });

    const selection = getArticleImageSelection(editor);
    const currentAlign = selection?.attrs?.align ?? 'none';

    useLayoutEffect(() => {
        if (!anchorRect || !panelRef.current) return;

        const width = panelRef.current.offsetWidth;
        const left = anchorRect.left + anchorRect.width / 2 - width / 2;
        const top = anchorRect.top - panelRef.current.offsetHeight - 8;

        setPosition({
            top: Math.max(8, top),
            left: Math.max(8, Math.min(left, window.innerWidth - width - 8)),
        });
    }, [anchorRect]);

    useEffect(() => {
        const onScroll = () => onClose();
        window.addEventListener('scroll', onScroll, true);
        return () => window.removeEventListener('scroll', onScroll, true);
    }, [onClose]);

    const setAlign = (align) => {
        editor.chain().focus().updateAttributes('articleImage', { align }).run();
    };

    const removeImage = () => {
        editor.chain().focus().deleteSelection().run();
        onClose();
    };

    if (!anchorRect || !selection) return null;

    const bubble = (
        <div
            ref={panelRef}
            className="seo-image-toolbar"
            style={{ top: `${position.top}px`, left: `${position.left}px` }}
            onMouseDown={(e) => e.stopPropagation()}
        >
            {ALIGN_OPTIONS.map(({ id, icon: Icon, title }) => (
                <button
                    key={id}
                    type="button"
                    className={`seo-image-toolbar-btn ${currentAlign === id ? 'is-active' : ''}`}
                    title={title}
                    onClick={() => setAlign(id)}
                >
                    <Icon size={18} strokeWidth={1.75} />
                </button>
            ))}
            <span className="seo-image-toolbar-sep" />
            <button
                type="button"
                className="seo-image-toolbar-btn"
                title={t('edit_image_meta')}
                onClick={onEditMeta}
            >
                <Pencil size={18} strokeWidth={1.75} />
            </button>
            <button
                type="button"
                className="seo-image-toolbar-btn is-danger"
                title={t('delete_image')}
                onClick={removeImage}
            >
                <X size={18} strokeWidth={1.75} />
            </button>
        </div>
    );

    return createPortal(bubble, document.body);
}
