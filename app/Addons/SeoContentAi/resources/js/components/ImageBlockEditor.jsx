import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlignCenter, AlignLeft, AlignRight, Maximize2, Pencil, Trash2 } from 'lucide-react';
import { parseImageFromBlockContent, renderImageFigure } from '../utils/blockImageUtils';

const ALIGN_OPTIONS = [
    { id: 'left', icon: AlignLeft, title: 'Căn trái' },
    { id: 'center', icon: AlignCenter, title: 'Căn giữa' },
    { id: 'right', icon: AlignRight, title: 'Căn phải' },
    { id: 'full', icon: Maximize2, title: 'Rộng toàn khối' },
];

function ImageMetaFormPortal({ anchorRef, image, onSave, onCancel }) {
    const panelRef = useRef(null);
    const [position, setPosition] = useState({ top: 0, left: 0 });
    const [alt, setAlt] = useState(image.alt ?? '');
    const [title, setTitle] = useState(image.title ?? '');
    const [caption, setCaption] = useState(image.caption ?? '');

    useLayoutEffect(() => {
        if (!anchorRef.current || !panelRef.current) return;

        const rect = anchorRef.current.getBoundingClientRect();
        const width = panelRef.current.offsetWidth;
        const left = rect.left + rect.width / 2 - width / 2;
        const top = rect.bottom + 8;

        setPosition({
            top: Math.min(top, window.innerHeight - panelRef.current.offsetHeight - 8),
            left: Math.max(8, Math.min(left, window.innerWidth - width - 8)),
        });
    }, [anchorRef]);

    useEffect(() => {
        const onKeyDown = (e) => {
            if (e.key === 'Escape') onCancel();
        };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [onCancel]);

    useEffect(() => {
        const onMouseDown = (e) => {
            if (panelRef.current?.contains(e.target)) return;
            if (anchorRef.current?.contains(e.target)) return;
            onCancel();
        };
        document.addEventListener('mousedown', onMouseDown);
        return () => document.removeEventListener('mousedown', onMouseDown);
    }, [anchorRef, onCancel]);

    const panel = (
        <div
            ref={panelRef}
            className="seo-image-meta-panel"
            style={{ top: `${position.top}px`, left: `${position.left}px` }}
            onMouseDown={(e) => e.stopPropagation()}
        >
            <p className="seo-image-meta-panel-title">Chỉnh sửa ảnh</p>
            <label className="seo-image-meta-label" htmlFor="seo-block-img-alt">
                Văn bản thay thế (alt)
            </label>
            <input
                id="seo-block-img-alt"
                type="text"
                className="seo-image-meta-input"
                value={alt}
                onChange={(e) => setAlt(e.target.value)}
                placeholder="Mô tả ảnh cho SEO và trợ năng"
            />
            <label className="seo-image-meta-label" htmlFor="seo-block-img-title">
                Title
            </label>
            <input
                id="seo-block-img-title"
                type="text"
                className="seo-image-meta-input"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
            />
            <label className="seo-image-meta-label" htmlFor="seo-block-img-caption">
                Chú thích (caption)
            </label>
            <textarea
                id="seo-block-img-caption"
                className="seo-image-meta-textarea"
                rows={2}
                value={caption}
                onChange={(e) => setCaption(e.target.value)}
            />
            <div className="seo-image-meta-actions">
                <button type="button" className="seo-image-meta-btn" onClick={onCancel}>
                    Hủy
                </button>
                <button
                    type="button"
                    className="seo-image-meta-btn is-primary"
                    onClick={() =>
                        onSave({
                            ...image,
                            alt: alt.trim(),
                            title: title.trim(),
                            caption: caption.trim(),
                        })
                    }
                >
                    Áp dụng
                </button>
            </div>
        </div>
    );

    return createPortal(panel, document.body);
}

export default function ImageBlockEditor({
    block,
    isActive,
    isHiddenInMerge,
    canShiftMerge,
    onActivate,
    onShiftMerge,
    onUpdate,
    onDelete,
    canDeleteBlock,
}) {
    const [editingMeta, setEditingMeta] = useState(false);
    const toolbarRef = useRef(null);

    const image = useMemo(() => {
        if (block.image) return block.image;
        return parseImageFromBlockContent(block.content);
    }, [block.image, block.content]);

    const figureHtml = image ? renderImageFigure(image) : block.content;

    const commitImage = (nextImage) => {
        onUpdate(renderImageFigure(nextImage), nextImage);
    };

    useEffect(() => {
        if (!isActive) {
            setEditingMeta(false);
        }
    }, [isActive]);

    const handlePreviewClick = (e) => {
        if (e.target.closest('a')) {
            e.preventDefault();
        }
        if (e.shiftKey && canShiftMerge) {
            e.preventDefault();
            e.stopPropagation();
            onShiftMerge(block.id);
            return;
        }
        onActivate();
    };

    if (isHiddenInMerge) {
        return null;
    }

    if (!isActive) {
        return (
            <div
                className="seo-block-preview seo-wp-content seo-block-image-preview-wrap p-3 -mx-1 rounded border border-transparent hover:border-gray-200 dark:hover:border-slate-600 hover:bg-gray-50/80 dark:hover:bg-slate-800/40 transition-all cursor-pointer"
                dangerouslySetInnerHTML={{ __html: figureHtml }}
                onClick={handlePreviewClick}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        if (e.shiftKey && canShiftMerge) {
                            onShiftMerge(block.id);
                        } else {
                            onActivate();
                        }
                    }
                }}
                role="button"
                tabIndex={0}
                title={
                    canShiftMerge
                        ? 'Click để sửa ảnh · Shift+Click để gộp tạm'
                        : 'Click để chỉnh sửa ảnh'
                }
            />
        );
    }

    if (!image) {
        return (
            <div className="seo-block-preview seo-wp-content p-3" dangerouslySetInnerHTML={{ __html: block.content }} />
        );
    }

    return (
        <div className="block-image-active" onMouseDown={(e) => e.stopPropagation()}>
            <span className="block-editor-badge">Ảnh</span>
            <button
                type="button"
                className="block-image-delete"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => canDeleteBlock && onDelete?.()}
                disabled={!canDeleteBlock}
                title={canDeleteBlock ? 'Xóa ảnh' : 'Không thể xóa block cuối cùng'}
            >
                <Trash2 size={16} />
            </button>

            <div className="seo-block-image-stage seo-wp-content">
                <div className="seo-block-image-edit-wrap">
                    <div ref={toolbarRef} className="seo-image-toolbar seo-image-toolbar--inline">
                        {ALIGN_OPTIONS.map(({ id, icon: Icon, title }) => (
                            <button
                                key={id}
                                type="button"
                                className={`seo-image-toolbar-btn ${image.align === id ? 'is-active' : ''}`}
                                title={title}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => commitImage({ ...image, align: id })}
                            >
                                <Icon size={18} strokeWidth={1.75} />
                            </button>
                        ))}
                        <span className="seo-image-toolbar-sep" />
                        <button
                            type="button"
                            className={`seo-image-toolbar-btn ${editingMeta ? 'is-active' : ''}`}
                            title="Chỉnh sửa ảnh (alt, title, caption)"
                            aria-pressed={editingMeta}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={(e) => {
                                e.stopPropagation();
                                setEditingMeta((v) => !v);
                            }}
                        >
                            <Pencil size={18} strokeWidth={1.75} />
                        </button>
                        <button
                            type="button"
                            className="seo-image-toolbar-btn is-danger"
                            title="Xóa ảnh"
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => canDeleteBlock && onDelete?.()}
                            disabled={!canDeleteBlock}
                        >
                            <Trash2 size={18} strokeWidth={1.75} />
                        </button>
                    </div>
                    <div className="seo-block-image-figure-host" dangerouslySetInnerHTML={{ __html: figureHtml }} />
                </div>
            </div>

            {editingMeta ? (
                <ImageMetaFormPortal
                    anchorRef={toolbarRef}
                    image={image}
                    onSave={(next) => {
                        commitImage(next);
                        setEditingMeta(false);
                    }}
                    onCancel={() => setEditingMeta(false)}
                />
            ) : null}
        </div>
    );
}
