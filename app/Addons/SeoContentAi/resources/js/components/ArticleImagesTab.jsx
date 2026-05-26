import React, { useEffect, useMemo, useState } from 'react';
import { ExternalLink, Scissors, Wand2 } from 'lucide-react';
import { collectImagesFromBlocks } from '../utils/articleImagesUtils';
import { SLUG_RENAME_WARNING } from '../utils/imageSlugRenameConfirm';
import { applyWatermarkToImage, buildMediaImageEditorUrl, prepareImageEditorUrl } from '../utils/seoMediaApi';

const LOCAL_MEDIA_PATH = '/storage/uploads/seo_media/';

function isLocalSeoMediaSrc(src) {
    return typeof src === 'string' && src.includes(LOCAL_MEDIA_PATH);
}

function canProcessArticleImage(row) {
    const seoMediaId = Number(row.seoMediaId ?? 0);
    const wpAttachmentId = Number(row.wpAttachmentId ?? 0);

    if (seoMediaId > 0 || wpAttachmentId > 0) {
        return true;
    }

    return isLocalSeoMediaSrc(row.src);
}

function ImageRow({ row, siteId, articleId, onPatch, onSlugChange, onFocusBlock, onNotify }) {
    const [slug, setSlug] = useState(row.slug ?? '');
    const [openingEditor, setOpeningEditor] = useState(false);
    const [applyingWatermark, setApplyingWatermark] = useState(false);
    const altText = (row.alt || row.title || '').trim();
    const showActions = canProcessArticleImage(row);
    const busy = openingEditor || applyingWatermark;

    useEffect(() => {
        setSlug(row.slug ?? '');
    }, [row.slug, row.src]);

    const openImageEditor = async () => {
        if (!siteId || busy) {
            return;
        }

        setOpeningEditor(true);
        try {
            const data = await prepareImageEditorUrl({
                siteId,
                seoMediaId: row.seoMediaId,
                wpAttachmentId: row.wpAttachmentId,
                url: row.src,
                slug: row.slug,
            });
            if (data.editor_url) {
                // noopener khiến window.open trả null dù tab đã mở — không dùng location.assign.
                window.open(data.editor_url, '_blank', 'noopener,noreferrer');
            }
        } catch (error) {
            onNotify?.({
                title: 'Không mở được trình chỉnh sửa',
                body: error?.message ?? 'Thử lại sau.',
                status: 'danger',
            });
        } finally {
            setOpeningEditor(false);
        }
    };

    const handleApplyWatermark = async () => {
        if (!siteId || busy) {
            return;
        }

        setApplyingWatermark(true);
        try {
            const data = await applyWatermarkToImage({
                siteId,
                seoMediaId: row.seoMediaId,
                wpAttachmentId: row.wpAttachmentId,
                url: row.src,
                slug: row.slug,
            });
            if (data.url) {
                onPatch?.(row.blockId, { src: data.url });
            }
            onNotify?.({
                title: 'Đóng dấu ảnh',
                body: data.message ?? 'Đã áp dụng đóng dấu.',
                status: 'success',
            });
        } catch (error) {
            onNotify?.({
                title: 'Không áp dụng được đóng dấu',
                body: error?.message ?? 'Thử lại sau.',
                status: 'danger',
            });
        } finally {
            setApplyingWatermark(false);
        }
    };

    const openImageSplitter = () => {
        const splitterUrl = buildMediaImageEditorUrl({
            seoMediaId: row.seoMediaId,
            tab: 'splitter',
        });
        if (!splitterUrl) {
            onNotify?.({
                title: 'Không mở được tách lưới',
                body: 'Ảnh cần có ID Laravel (seo_media) hoặc ID WordPress.',
                status: 'warning',
            });
            return;
        }
        window.open(splitterUrl, '_blank', 'noopener,noreferrer');
    };

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

                {showActions ? (
                    <div className="seo-article-images-actions">
                        <button
                            type="button"
                            className="seo-article-images-edit-btn"
                            disabled={!siteId || busy}
                            onClick={openImageEditor}
                        >
                            {openingEditor ? 'Đang chuẩn bị…' : 'Chỉnh sửa hình ảnh'}
                        </button>
                        <button
                            type="button"
                            className="seo-article-images-watermark-btn"
                            disabled={!siteId || busy}
                            onClick={handleApplyWatermark}
                        >
                            {applyingWatermark ? 'Đang xử lý…' : 'Áp dụng đóng dấu'}
                        </button>
                        <button
                            type="button"
                            className="seo-article-images-watermark-btn"
                            disabled={!siteId || busy}
                            onClick={openImageSplitter}
                        >
                            <Scissors size={14} />
                            Tách theo lưới
                        </button>
                    </div>
                ) : null}
            </div>
        </li>
    );
}

export default function ArticleImagesTab({
    blocks,
    siteId = null,
    articleId = null,
    focusKeyword,
    articleTitle = '',
    onPatchImage,
    onSlugChange,
    onFocusBlock,
    onQuickFixAll,
    onNotify,
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
                        siteId={siteId}
                        articleId={articleId}
                        onPatch={onPatchImage}
                        onSlugChange={onSlugChange}
                        onFocusBlock={onFocusBlock}
                        onNotify={onNotify}
                    />
                ))}
            </ul>
        </div>
    );
}
