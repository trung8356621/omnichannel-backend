import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ExternalLink, Loader2, RotateCcw, Scissors, ShieldOff, Trash2, Wand2 } from 'lucide-react';
import { collectImagesFromBlocks } from '../utils/articleImagesUtils';
import { SLUG_RENAME_WARNING } from '../utils/imageSlugRenameConfirm';
import {
    AI_PLACEHOLDER_LOADING_URL,
    applyWatermarkToImage,
    buildMediaImageEditorUrl,
    fetchArticleAiMediaJobs,
    prepareImageEditorUrl,
    deleteAiMediaJob,
    retryAiMediaGeneration,
} from '../utils/seoMediaApi';

const LOCAL_MEDIA_PATH = '/storage/uploads/seo_media/';
/** Poll danh sách job AI tối đa 1 lần / phút khi còn job đang xử lý. */
const AI_JOBS_POLL_MS = 60_000;

const AI_STATUS_LABELS = {
    processing: 'Đang tạo…',
    failed: 'Thất bại',
};

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

function distinctUrls(primary, secondary) {
    const a = String(primary || '').trim();
    const b = String(secondary || '').trim();
    if (!a || !b) return false;
    return a !== b;
}

function AiMediaJobRow({ job, onRetry, onFocusBlock, onNotify }) {
    const [retrying, setRetrying] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const status = String(job.status ?? 'processing').toLowerCase();
    const statusLabel = AI_STATUS_LABELS[status] ?? status;
    const previewSrc = job.url?.includes('placeholder-loading')
        ? AI_PLACEHOLDER_LOADING_URL
        : job.url || AI_PLACEHOLDER_LOADING_URL;
    const mediaTypeLabel = job.media_type === 'video' ? 'Video AI' : 'Ảnh AI';
    const editorBlockId = (job.editor_block_id ?? '').trim();

    const handleRetry = async () => {
        if (retrying || deleting || !job.id) {
            return;
        }

        setRetrying(true);
        try {
            const data = await retryAiMediaGeneration(job.id);
            onRetry?.(data);
            onNotify?.({
                title: 'Đã thử lại',
                body: 'Job AI đã được đưa vào hàng đợi.',
                status: 'success',
            });

            if (data.editor_block_id) {
                window.dispatchEvent(
                    new CustomEvent('article-ai-image-generated', {
                        detail: {
                            url: data.url,
                            activeBlockId: data.editor_block_id,
                            seoMediaId: data.id,
                            status: data.status ?? 'processing',
                            mediaType: data.media_type ?? 'image',
                        },
                    }),
                );
            }
        } catch (error) {
            onNotify?.({
                title: 'Không thử lại được',
                body: error?.message ?? 'Thử lại sau.',
                status: 'danger',
            });
        } finally {
            setRetrying(false);
        }
    };

    const handleDelete = async () => {
        if (retrying || deleting || !job.id) {
            return;
        }

        const ok = window.confirm('Xóa prompt ảnh này khỏi danh sách chờ?');
        if (!ok) {
            return;
        }

        setDeleting(true);
        try {
            await deleteAiMediaJob(job.id);
            onRetry?.();
            onNotify?.({
                title: 'Đã xóa prompt ảnh',
                body: `Đã xóa job #${job.id}.`,
                status: 'success',
            });
        } catch (error) {
            onNotify?.({
                title: 'Không xóa được prompt ảnh',
                body: error?.message ?? 'Thử lại sau.',
                status: 'danger',
            });
        } finally {
            setDeleting(false);
        }
    };

    return (
        <li className={`seo-article-images-row seo-article-images-row--ai-job is-${status}`}>
            <div className="seo-article-images-preview">
                <button
                    type="button"
                    className="seo-article-images-thumb-btn"
                    onClick={() => editorBlockId && onFocusBlock?.(editorBlockId)}
                    title={editorBlockId ? 'Chuyển tới vị trí trong editor' : 'Job AI'}
                    disabled={!editorBlockId}
                >
                    <div className="seo-article-images-thumb seo-article-images-thumb--ai-placeholder">
                        <img src={previewSrc} alt="" className="seo-article-images-thumb__img" />
                        {status === 'processing' ? (
                            <span className="seo-article-images-thumb__spinner" aria-hidden="true">
                                <Loader2 size={22} className="animate-spin" />
                            </span>
                        ) : null}
                    </div>
                </button>
                <p className="seo-article-images-alt">
                    {mediaTypeLabel}
                    <span className={`seo-article-images-ai-status is-${status}`}>{statusLabel}</span>
                </p>
            </div>

            <div className="seo-article-images-fields">
                <p className="seo-article-images-ai-meta">
                    Job #{job.id}
                    {job.slug ? ` · ${job.slug}` : ''}
                </p>

                {status === 'failed' && job.error_message ? (
                    <p className="seo-article-images-ai-error" role="alert">
                        {job.error_message}
                    </p>
                ) : null}

                <div className="seo-article-images-actions">
                    {status === 'failed' ? (
                        <button
                            type="button"
                            className="seo-article-images-edit-btn"
                            disabled={retrying || deleting}
                            onClick={handleRetry}
                            title="Chạy lại job AI với cùng prompt (không tạo bản ghi mới)"
                        >
                            <RotateCcw size={14} className={retrying ? 'animate-spin' : ''} />
                            {retrying ? 'Đang thử lại…' : 'Thử lại'}
                        </button>
                    ) : (
                        <span className="seo-article-images-ai-wait">Đang chờ AI xử lý trong hàng đợi…</span>
                    )}
                    <button
                        type="button"
                        className="seo-article-images-delete-btn"
                        disabled={retrying || deleting}
                        onClick={handleDelete}
                        title="Xóa prompt ảnh khỏi danh sách"
                    >
                        <Trash2 size={14} />
                        {deleting ? 'Đang xóa…' : 'Xóa'}
                    </button>
                </div>
            </div>
        </li>
    );
}

function ImageRow({
    row,
    siteId,
    articleId,
    onPatch,
    onSlugChange,
    onFocusBlock,
    onQuickFix,
    canQuickFix = false,
    onNotify,
}) {
    const [slug, setSlug] = useState(row.slug ?? '');
    const [openingEditor, setOpeningEditor] = useState(false);
    const [applyingWatermark, setApplyingWatermark] = useState(false);
    const altText = (row.alt || row.title || '').trim();
    const showActions = canProcessArticleImage(row);
    const busy = openingEditor || applyingWatermark;
    const excluded = Boolean(row.excludeQuickFix);
    const wpUrl = String(row.wpSrc || '').trim();
    const localUrl = String(row.localSrc || '').trim();
    const primaryUrl = wpUrl || String(row.src || '').trim();
    const showLocalExtra = distinctUrls(primaryUrl, localUrl);

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
                                const ok = onSlugChange?.(row, trimmed, (patch) =>
                                    onPatch?.(row.blockId, patch),
                                );
                                if (ok === false) {
                                    setSlug(row.slug ?? '');
                                }
                            }
                        }}
                        placeholder="tu-khoa-chinh-1"
                    />
                    {row.wpAttachmentId && primaryUrl ? (
                        <a
                            href={primaryUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="seo-article-images-wp-id"
                            title={primaryUrl}
                        >
                            {`Ảnh nằm trong bài viết khác: ${primaryUrl}`}
                        </a>
                    ) : null}
                </div>

                {primaryUrl ? (
                    <a
                        href={primaryUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="seo-article-images-src-link"
                    >
                        <ExternalLink size={14} />
                        <span className="truncate">
                            {wpUrl ? `WP: ${primaryUrl}` : primaryUrl}
                        </span>
                    </a>
                ) : null}
                {showLocalExtra ? (
                    <a
                        href={localUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="seo-article-images-src-link"
                    >
                        <ExternalLink size={14} />
                        <span className="truncate">{`Local: ${localUrl}`}</span>
                    </a>
                ) : null}

                {showActions ? (
                    <div className="seo-article-images-actions">
                        <button
                            type="button"
                            className="seo-article-images-quick-fix-btn"
                            disabled={busy || excluded || !canQuickFix}
                            onClick={() => onQuickFix?.(row.blockId)}
                            title={
                                excluded
                                    ? 'Ảnh đang Except — bỏ Except để Fix nhanh'
                                    : canQuickFix
                                      ? 'Alt/Title = từ khóa · Slug theo thứ tự ảnh trong bài'
                                      : 'Cần từ khóa chính hoặc tiêu đề bài viết'
                            }
                        >
                            <Wand2 size={14} />
                            Fix nhanh
                        </button>
                        <button
                            type="button"
                            className={`seo-article-images-watermark-btn${excluded ? ' is-except' : ''}`}
                            disabled={busy}
                            onClick={() => onPatch?.(row.blockId, { excludeQuickFix: !excluded })}
                            title={
                                excluded
                                    ? 'Đang loại khỏi Fix nhanh (bấm để cho phép Fix nhanh lại)'
                                    : 'Except: Loại ảnh này khỏi Fix nhanh (không đổi slug/alt/title)'
                            }
                        >
                            <ShieldOff size={14} />
                            Except
                        </button>
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
    onQuickFixOne,
    onNotify,
}) {
    const blockImages = useMemo(() => collectImagesFromBlocks(blocks), [blocks]);
    const [aiJobs, setAiJobs] = useState([]);

    const loadAiJobs = useCallback(async () => {
        if (!articleId) {
            setAiJobs([]);
            return;
        }

        try {
            const items = await fetchArticleAiMediaJobs(articleId);
            setAiJobs(items);
        } catch {
            // Giữ danh sách cũ khi poll lỗi mạng tạm thời.
        }
    }, [articleId]);

    useEffect(() => {
        loadAiJobs();
    }, [loadAiJobs]);

    useEffect(() => {
        const refresh = () => loadAiJobs();
        window.addEventListener('article-ai-image-generated', refresh);
        window.addEventListener('article-ai-video-generated', refresh);
        window.addEventListener('article-ai-media-job-updated', refresh);

        return () => {
            window.removeEventListener('article-ai-image-generated', refresh);
            window.removeEventListener('article-ai-video-generated', refresh);
            window.removeEventListener('article-ai-media-job-updated', refresh);
        };
    }, [loadAiJobs]);

    useEffect(() => {
        if (!articleId || aiJobs.length === 0) {
            return undefined;
        }

        const hasProcessing = aiJobs.some((job) => String(job.status).toLowerCase() === 'processing');
        if (!hasProcessing) {
            return undefined;
        }

        const timer = window.setInterval(loadAiJobs, AI_JOBS_POLL_MS);
        return () => window.clearInterval(timer);
    }, [articleId, aiJobs, loadAiJobs]);

    const totalCount = aiJobs.length + blockImages.length;
    const hasWpImages = blockImages.some((row) => row.wpAttachmentId);
    const hasLocalImages = blockImages.some((row) => !row.wpAttachmentId && isLocalSeoMediaSrc(row.src));
    const keywordSource = (focusKeyword || articleTitle || '').trim();
    const canQuickFix = keywordSource.length > 0 && blockImages.length > 0;

    if (!totalCount) {
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
                        {totalCount} mục
                        {aiJobs.length > 0
                            ? ` (${aiJobs.length} job AI, ${blockImages.length} ảnh trong bài)`
                            : ` · ${blockImages.length} ảnh`}
                        . Alt sửa trong editor hoặc Fix nhanh.
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
                {aiJobs.map((job) => (
                    <AiMediaJobRow
                        key={`ai-job-${job.id}`}
                        job={job}
                        onRetry={loadAiJobs}
                        onFocusBlock={onFocusBlock}
                        onNotify={onNotify}
                    />
                ))}
                {blockImages.map((row) => (
                    <ImageRow
                        key={row.blockId}
                        row={row}
                        siteId={siteId}
                        articleId={articleId}
                        onPatch={onPatchImage}
                        onSlugChange={onSlugChange}
                        onFocusBlock={onFocusBlock}
                        onQuickFix={onQuickFixOne}
                        canQuickFix={canQuickFix}
                        onNotify={onNotify}
                    />
                ))}
            </ul>
        </div>
    );
}
