import React, { useEffect, useMemo, useState } from 'react';
import { ExternalLink, Wand2 } from 'lucide-react';
import { collectImagesFromBlocks } from '../utils/articleImagesUtils';
import { SLUG_RENAME_WARNING } from '../utils/imageSlugRenameConfirm';

const LOCAL_MEDIA_PATH = '/storage/uploads/seo_media/';

function isLocalSeoMediaSrc(src) {
    return typeof src === 'string' && src.includes(LOCAL_MEDIA_PATH);
}

function ImageRow({ row, onPatch, onSlugChange, onFocusBlock }) {
    const [slug, setSlug] = useState(row.slug ?? '');
    const altText = (row.alt || row.title || '').trim();

    useEffect(() => {
        setSlug(row.slug ?? '');
    }, [row.slug, row.src]);

    return (
        <li className="seo-article-images-row">
            <div className="seo-article-images-preview">
                <button
                    type="button"
                    className="seo-article-images-thumb-btn"
                    onClick={() => onFocusBlock?.(row.blockId)}
                    title="Chuyển tới ảnh trong editor"
                >
                    <img
                        key={`${row.blockId}-${row.src}`}
                        src={row.src}
                        alt={altText}
                        className="seo-article-images-thumb"
                    />
                </button>
                <p className="seo-article-images-alt">{altText || '—'}</p>
            </div>

            <div className="seo-article-images-fields">
                <div className="seo-article-images-field-row">
                    <label className="seo-image-meta-label">
                        Slug tệp (URL)
                        {row.wpAttachmentId ? (
                            <span className="seo-article-images-hint"> — đổi tên trên WordPress</span>
                        ) : row.seoMediaId ? (
                            <span className="seo-article-images-hint"> — đổi tên file nội bộ</span>
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
                                const ok = onSlugChange?.(row, trimmed, (patch) => onPatch?.(row.blockId, patch));
                                if (ok === false) {
                                    setSlug(row.slug ?? '');
                                }
                            }
                        }}
                        placeholder="tu-khoa-chinh-1"
                    />
                    {row.wpAttachmentId ? (
                        <span className="seo-article-images-wp-id">Ảnh nằm trong bài viết khác: WP #{row.wpAttachmentId}</span>
                    ) : null}
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
    const hasWpImages = images.some((row) => row.wpAttachmentId);
    const hasLocalImages = images.some((row) => !row.wpAttachmentId && isLocalSeoMediaSrc(row.src));
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
                        {images.length} ảnh · Alt sửa trong editor hoặc Fix nhanh. Đổi slug để đổi tên file ảnh.
                    </p>
                    {hasLocalImages ? (
                        <p className="seo-images-tab-info">
                            Ảnh dán/tải nội bộ (Laravel): đổi slug chỉ đổi file trên server, không ảnh hưởng
                            WordPress.
                        </p>
                    ) : null}
                    {hasWpImages ? (
                        <details className="seo-images-tab-warning-details">
                            <summary className="seo-images-tab-warning-summary">
                                Lưu ý khi đổi slug ảnh WordPress
                            </summary>
                            <p className="seo-images-tab-warning">{SLUG_RENAME_WARNING}</p>
                        </details>
                    ) : null}
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
