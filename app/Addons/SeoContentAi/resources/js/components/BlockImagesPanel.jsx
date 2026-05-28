import React, { useState } from 'react';
import { AlignCenter, AlignLeft, AlignRight, Maximize2, Pencil, Trash2 } from 'lucide-react';
import { t } from '../utils/i18n';
const ALIGN_OPTIONS = [
    { id: 'left', icon: AlignLeft, title: t('toolbar_align_left') },
    { id: 'center', icon: AlignCenter, title: t('toolbar_align_center') },
    { id: 'right', icon: AlignRight, title: t('toolbar_align_right') },
    { id: 'full', icon: Maximize2, title: t('image_align_full_width') },
];

function ImageMetaForm({ image, onSave, onCancel }) {
    const [alt, setAlt] = useState(image.alt ?? '');
    const [title, setTitle] = useState(image.title ?? '');
    const [caption, setCaption] = useState(image.caption ?? '');

    return (
        <div className="seo-block-image-meta-form">
            <label className="seo-image-meta-label">Alt</label>
            <input
                type="text"
                className="seo-image-meta-input"
                value={alt}
                onChange={(e) => setAlt(e.target.value)}
            />
            <label className="seo-image-meta-label">Title</label>
            <input
                type="text"
                className="seo-image-meta-input"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
            />
            <label className="seo-image-meta-label">Caption</label>
            <textarea
                className="seo-image-meta-textarea"
                rows={2}
                value={caption}
                onChange={(e) => setCaption(e.target.value)}
            />
            <div className="seo-image-meta-actions">
                <button type="button" className="seo-image-meta-btn" onClick={onCancel}>
                    {t('cancel')}
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
                    {t('apply')}
                </button>
            </div>
        </div>
    );
}

export default function BlockImagesPanel({ images, onChange }) {
    const [editingId, setEditingId] = useState(null);

    if (!images?.length) {
        return null;
    }

    const updateImage = (id, patch) => {
        onChange(images.map((img) => (img.id === id ? { ...img, ...patch } : img)));
    };

    const removeImage = (id) => {
        onChange(images.filter((img) => img.id !== id));
        if (editingId === id) setEditingId(null);
    };

    return (
        <div className="seo-block-images-panel" onMouseDown={(e) => e.stopPropagation()}>
            <p className="seo-block-images-title">{`${t('image_block_label')} (${images.length})`}</p>
            <ul className="seo-block-images-list">
                {images.map((image) => (
                    <li key={image.id} className="seo-block-image-card">
                        <div className="seo-block-image-preview">
                            <img src={image.src} alt={image.alt || ''} />
                        </div>
                        <div className="seo-block-image-actions">
                            {ALIGN_OPTIONS.map(({ id, icon: Icon, title }) => (
                                <button
                                    key={id}
                                    type="button"
                                    className={`seo-image-toolbar-btn ${image.align === id ? 'is-active' : ''}`}
                                    title={title}
                                    onClick={() => updateImage(image.id, { align: id })}
                                >
                                    <Icon size={16} />
                                </button>
                            ))}
                            <button
                                type="button"
                                className="seo-image-toolbar-btn"
                                title="Alt, title, caption"
                                onClick={() => setEditingId(editingId === image.id ? null : image.id)}
                            >
                                <Pencil size={16} />
                            </button>
                            <button
                                type="button"
                                className="seo-image-toolbar-btn is-danger"
                                title={t('delete_image')}
                                onClick={() => removeImage(image.id)}
                            >
                                <Trash2 size={16} />
                            </button>
                        </div>
                        {editingId === image.id ? (
                            <ImageMetaForm
                                image={image}
                                onSave={(next) => {
                                    updateImage(image.id, next);
                                    setEditingId(null);
                                }}
                                onCancel={() => setEditingId(null)}
                            />
                        ) : null}
                    </li>
                ))}
            </ul>
        </div>
    );
}
