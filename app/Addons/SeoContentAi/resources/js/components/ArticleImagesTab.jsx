import React, { useEffect, useMemo, useState } from 'react';
import { ExternalLink, Wand2 } from 'lucide-react';
import { collectImagesFromBlocks } from '../utils/articleImagesUtils';
import { SLUG_RENAME_WARNING } from '../utils/imageSlugRenameConfirm';

function ImageRow({ row, onPatch, onSlugChange, onFocusBlock }) {
    const [slug, setSlug] = useState(row.slug ?? '');
    const [altTitle, setAltTitle] = useState(row.alt || row.title || '');
    const [caption, setCaption] = useState(row.caption ?? '');

    useEffect(() => {
        setSlug(row.slug ?? '');
        setAltTitle(row.alt || row.title || '');
        setCaption(row.caption ?? '');
    }, [row.slug, row.alt, row.title, row.caption, row.src]);

    const commitMeta = (patch) => {
        onPatch(row.blockId, patch);
    };

    return (
        <li className="seo-article-images-row">
            <button
                type="button"
                className="seo-article-images-thumb-btn"
                onClick={() => onFocusBlock?.(row.blockId)}
                title="Chuyển tới ảnh trong editor"
            >
                <img
                    key={`${row.blockId}-${row.src}`}
                    src={row.src}
                    alt={row.alt || row.title || ''}
                    className="seo-article-images-thumb"
                />
            </button>

            <div className="seo-article-images-fields">
                <div className="seo-article-images-field-row">
                    <label className="seo-image-meta-label">
                        Slug tệp (URL)
                        {row.wpAttachmentId ? (
                            <span className="seo-article-images-hint"> — đổi tên trên WordPress</span>
                        ) : null}
                    </label>
                    <input
                        type="text"
                        className="seo-image-meta-input"
                        value={slug}
                        onChange={(e) => setSlug(e.target.value)}
                        onBlur={() => {
                            const trimmed = slug.trim();
                            if (trimmed !== row.slug) {
                                const ok = onSlugChange?.(row, trimmed, (patch) => commitMeta(patch));
                                if (ok === false) {
                                    setSlug(row.slug ?? '');
                                }
                            }
                        }}
                        placeholder="tu-khoa-chinh-1"
                    />
                    {row.wpAttachmentId ? (
                        <span className="seo-article-images-wp-id">WP #{row.wpAttachmentId}</span>
                    ) : null}
                </div>

                <div className="seo-article-images-field-row">
                    <label className="seo-image-meta-label">
                        Alt / Title{' '}
                        <span className="seo-article-images-hint">(chỉ HTML bài viết)</span>
                    </label>
                    <input
                        type="text"
                        className="seo-image-meta-input"
                        value={altTitle}
                        onChange={(e) => setAltTitle(e.target.value)}
                        onBlur={() => {
                            const trimmed = altTitle.trim();
                            const current = (row.alt || row.title || '').trim();
                            if (trimmed !== current) {
                                commitMeta({ alt: trimmed, title: trimmed });
                            }
                        }}
                    />
                </div>

                <div className="seo-article-images-field-row">
                    <label className="seo-image-meta-label">Caption</label>
                    <textarea
                        className="seo-image-meta-textarea"
                        rows={2}
                        value={caption}
                        onChange={(e) => setCaption(e.target.value)}
                        onBlur={() => {
                            if (caption !== row.caption) {
                                commitMeta({ caption: caption.trim() });
                            }
                        }}
                    />
                </div>

                <a
                    href={row.src}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="seo-article-images-src-link"
                >
                    <ExternalLink size={14} />
                    <span className="truncate">{row.src}</span>
                </a>
            </div>
        </li>
    );
}

export default function ArticleImagesTab({
    blocks,
    focusKeyword,
    articleTitle = '',
    onPatchImage,
    onSlugChange,
    onFocusBlock,
    onQuickFixAll,
}) {
    const images = useMemo(() => collectImagesFromBlocks(blocks), [blocks]);
    const keywordSource = (focusKeyword || articleTitle || '').trim();
    const canQuickFix = keywordSource.length > 0 && images.length > 0;

    if (!images.length) {
        return (
            <div className="seo-tab-panel seo-images-tab">
                <p className="seo-images-tab-empty">Chưa có ảnh trong bài viết.</p>
            </div>
        );
    }

    return (
        <div className="seo-tab-panel seo-images-tab">
            <div className="seo-images-tab-toolbar">
                <div className="seo-images-tab-intro-wrap">
                    <p className="seo-images-tab-intro">
                        {images.length} ảnh · Alt/Title và caption chỉ sửa HTML bài viết. Đổi slug sẽ đổi tên file
                        trên WordPress và thay URL trong mọi bài trên site.
                    </p>
                    <p className="seo-images-tab-warning">{SLUG_RENAME_WARNING}</p>
                </div>
                <button
                    type="button"
                    className="seo-images-quick-fix-btn"
                    disabled={!canQuickFix}
                    title={
                        canQuickFix
                            ? 'Alt/Title = từ khóa · Slug = kebab-case-1… (có xác nhận trước khi đổi WP)'
                            : 'Cần từ khóa chính hoặc tiêu đề bài viết'
                    }
                    onClick={() => onQuickFixAll?.()}
                >
                    <Wand2 size={16} />
                    Fix nhanh
                </button>
            </div>
            <ul className="seo-article-images-list">
                {images.map((row) => (
                    <ImageRow
                        key={row.blockId}
                        row={row}
                        onPatch={onPatchImage}
                        onSlugChange={onSlugChange}
                        onFocusBlock={onFocusBlock}
                    />
                ))}
            </ul>
        </div>
    );
}
