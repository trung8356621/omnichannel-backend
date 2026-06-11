import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ExternalLink, Link2, Loader2, RotateCcw, Scissors, ShieldOff, Trash2, Type } from 'lucide-react';
import { collectImagesFromBlocks } from '../utils/articleImagesUtils';
import { SLUG_RENAME_WARNING } from '../utils/imageSlugRenameConfirm';
import { t } from '../utils/i18n';
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
    processing: t('processing'),
    failed: t('failed'),
};

function isLocalSeoMediaSrc(src) {
    return typeof src === 'string' && src.includes(LOCAL_MEDIA_PATH);
}

function isLegacyRandomFolderSeoMediaSrc(src) {
    if (typeof src !== 'string') {
        return false;
    }
    // /storage/uploads/seo_media/<random>/<filename>
    return /\/storage\/uploads\/seo_media\/[^/]+\/[^/]+$/i.test(src.trim());
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
    const [showRetryInput, setShowRetryInput] = useState(false);
    const [retryInput, setRetryInput] = useState(String(job.retry_input || ''));
    const status = String(job.status ?? 'processing').toLowerCase();
    const statusLabel = AI_STATUS_LABELS[status] ?? status;
    const previewSrc = job.url?.includes('placeholder-loading')
        ? AI_PLACEHOLDER_LOADING_URL
        : job.url || AI_PLACEHOLDER_LOADING_URL;
    const mediaTypeLabel = job.media_type === 'video' ? t('ai_video') : t('ai_image');
    const editorBlockId = (job.editor_block_id ?? '').trim();

    useEffect(() => {
        setRetryInput(String(job.retry_input || ''));
    }, [job.retry_input, job.id]);

    const handleRetry = async () => {
        if (retrying || deleting || !job.id) {
            return;
        }

        setRetrying(true);
        try {
            const data = await retryAiMediaGeneration(job.id, retryInput);
            onRetry?.(data);
            onNotify?.({
                title: t('ai_retry_success'),
                body: t('ai_retry_success_body'),
                status: 'success',
            });
            setShowRetryInput(false);

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
                title: t('ai_retry_failed'),
                body: error?.message ?? t('editor_try_again_later'),
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

        const ok = window.confirm(t('ai_delete_confirm'));
        if (!ok) {
            return;
        }

        setDeleting(true);
        try {
            await deleteAiMediaJob(job.id);
            onRetry?.();
            onNotify?.({
                title: t('ai_delete_success'),
                body: `Đã xóa job #${job.id}.`,
                status: 'success',
            });
        } catch (error) {
            onNotify?.({
                title: t('ai_delete_failed'),
                body: error?.message ?? t('editor_try_again_later'),
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
                    title={editorBlockId ? t('ai_focus_editor_block') : t('ai_job')}
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
                        <>
                            <button
                                type="button"
                                className="seo-article-images-edit-btn"
                                disabled={retrying || deleting}
                                onClick={() => setShowRetryInput((prev) => !prev)}
                                title={t('ai_open_retry_input')}
                            >
                                <RotateCcw size={14} className={retrying ? 'animate-spin' : ''} />
                                {showRetryInput ? t('ai_close_retry_input') : t('retry')}
                            </button>
                            {showRetryInput ? (
                                <div style={{ width: '100%', marginTop: 8 }}>
                                    <textarea
                                        value={retryInput}
                                        onChange={(event) => setRetryInput(event.target.value)}
                                        rows={4}
                                        placeholder={t('ai_retry_input_placeholder')}
                                        className="w-full rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-900"
                                    />
                                    <div className="mt-2 flex items-center gap-2">
                                        <button
                                            type="button"
                                            className="seo-article-images-edit-btn"
                                            disabled={retrying || deleting}
                                            onClick={handleRetry}
                                            title={t('ai_retry_submit_title')}
                                        >
                                            <RotateCcw size={14} className={retrying ? 'animate-spin' : ''} />
                                            {retrying ? t('ai_submitting') : t('submit_retry')}
                                        </button>
                                    </div>
                                </div>
                            ) : null}
                        </>
                    ) : (
                        <p className="seo-article-images-ai-wait">{t('processing_wait')}</p>
                    )}
                    <button
                        type="button"
                        className="seo-article-images-delete-btn"
                        disabled={retrying || deleting}
                        onClick={handleDelete}
                        title={t('ai_delete_prompt_title')}
                    >
                        <Trash2 size={14} />
                        {deleting ? t('processing') : t('delete')}
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
    onQuickFixSlug,
    onQuickFixAltTitle,
    canQuickFix = false,
    onNotify,
}) {
    const [slug, setSlug] = useState(row.slug ?? '');
    const [openingEditor, setOpeningEditor] = useState(false);
    const [applyingWatermark, setApplyingWatermark] = useState(false);
    const canPatchInEditor = Boolean(row.blockId);
    const altText = (row.alt || row.title || '').trim();
    const showActions = canProcessArticleImage(row);
    const busy = openingEditor || applyingWatermark;
    const excluded = Boolean(row.excludeQuickFix);
    const wpUrl = String(row.wpSrc || '').trim();
    const localUrl = String(row.localSrc || '').trim();
    const primaryUrl = wpUrl || String(row.src || '').trim();
    const showLocalExtra = distinctUrls(primaryUrl, localUrl);
    const seoMediaId = Number(row.seoMediaId ?? row.seo_media_id ?? 0);
    const slugReadonly = isLegacyRandomFolderSeoMediaSrc(primaryUrl) && seoMediaId <= 0;

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
                title: t('editor_cannot_open_image_editor'),
                body: error?.message ?? t('editor_try_again_later'),
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
                title: t('image_watermark_applied'),
                body: data.message ?? t('image_watermark_applied_body'),
                status: 'success',
            });
        } catch (error) {
            onNotify?.({
                title: t('image_watermark_failed'),
                body: error?.message ?? t('editor_try_again_later'),
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
                title: t('image_splitter_open_failed'),
                body: t('image_splitter_open_failed_body'),
                status: 'warning',
            });
            return;
        }
        window.open(splitterUrl, '_blank', 'noopener,noreferrer');
    };

    return (
        <li
            className="seo-article-images-row"
            data-seo-media-id={Number(row.seoMediaId ?? 0) > 0 ? Number(row.seoMediaId) : undefined}
            data-image-src={String(row.src || '').trim()}
        >
            <div className="seo-article-images-preview">
                <button
                    type="button"
                    className="seo-article-images-thumb-btn"
                    onClick={() => canPatchInEditor && onFocusBlock?.(row.blockId)}
                    title={canPatchInEditor ? t('image_focus_editor') : t('image_from_featured_or_album')}
                    disabled={!canPatchInEditor}
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
                        {t('slug_file_url')}
                        {row.wpAttachmentId ? (
                            <span className="seo-article-images-hint">{t('wp_slug_hint')}</span>
                        ) : row.seoMediaId ? (
                            <span className="seo-article-images-hint">{t('local_slug_hint')}</span>
                        ) : null}
                    </label>
                    <input
                        type="text"
                        className="seo-image-meta-input"
                        value={slug}
                        readOnly={slugReadonly}
                        onChange={(e) => setSlug(e.target.value)}
                        onBlur={() => {
                            if (slugReadonly) {
                                return;
                            }
                            if (!canPatchInEditor) {
                                return;
                            }
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
                        placeholder={t('image_slug_placeholder')}
                        disabled={!canPatchInEditor}
                    />
                    {slugReadonly ? (
                        <p className="seo-article-images-hint mt-1">
                            {t('readonly_legacy_slug')}
                        </p>
                    ) : null}
                    {row.wpAttachmentId && primaryUrl ? (
                        <a
                            href={primaryUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="seo-article-images-wp-id"
                            title={primaryUrl}
                        >
                            {t('image_used_in_other_article', { url: primaryUrl })}
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
                            onClick={() => onQuickFixSlug?.(row)}
                            title={
                                excluded
                                    ? t('image_except_quick_fix_hint')
                                    : canQuickFix
                                      ? t('image_quick_fix_slug_hint')
                                      : t('image_quick_fix_missing_keyword')
                            }
                        >
                            <Link2 size={14} />
                            {t('fix_slug')}
                        </button>
                        <button
                            type="button"
                            className="seo-article-images-quick-fix-btn"
                            disabled={busy || excluded || !canQuickFix}
                            onClick={() => onQuickFixAltTitle?.(row)}
                            title={
                                excluded
                                    ? t('image_except_quick_fix_hint')
                                    : canQuickFix
                                      ? t('image_quick_fix_alt_title_hint')
                                      : t('image_quick_fix_missing_keyword')
                            }
                        >
                            <Type size={14} />
                            {t('fix_alt_title')}
                        </button>
                        <button
                            type="button"
                            className={`seo-article-images-watermark-btn${excluded ? ' is-except' : ''}`}
                            disabled={busy || !canPatchInEditor}
                            onClick={() => onPatch?.(row.blockId, { excludeQuickFix: !excluded })}
                            title={
                                excluded
                                    ? t('image_except_disable_hint')
                                    : t('image_except_enable_hint')
                            }
                        >
                            <ShieldOff size={14} />
                            {t('except')}
                        </button>
                        <button
                            type="button"
                            className="seo-article-images-edit-btn"
                            disabled={!siteId || busy}
                            onClick={openImageEditor}
                        >
                            {openingEditor ? t('processing') : t('open_image_editor')}
                        </button>
                        <button
                            type="button"
                            className="seo-article-images-watermark-btn"
                            disabled={!siteId || busy}
                            onClick={handleApplyWatermark}
                        >
                            {applyingWatermark ? t('processing') : t('apply_watermark')}
                        </button>
                        <button
                            type="button"
                            className="seo-article-images-watermark-btn"
                            disabled={!siteId || busy}
                            onClick={openImageSplitter}
                        >
                            <Scissors size={14} />
                            {t('split_grid')}
                        </button>
                    </div>
                ) : null}
            </div>
        </li>
    );
}

export default function ArticleImagesTab({
    blocks,
    extraImages = [],
    siteId = null,
    articleId = null,
    jumpTarget = null,
    focusKeyword,
    articleTitle = '',
    onPatchImage,
    onSlugChange,
    onFocusBlock,
    onQuickFixSlugAll,
    quickFixSlugAllBusy = false,
    onQuickFixSlugOne,
    onQuickFixAltTitleAll,
    onQuickFixAltTitleOne,
    onNotify,
}) {
    const blockImages = useMemo(() => collectImagesFromBlocks(blocks), [blocks]);
    const mergedImages = useMemo(() => {
        const normalizeSrc = (value) => {
            const raw = String(value || '').trim();
            if (!raw) return '';
            try {
                const url = new URL(raw, window.location.origin);
                return `${url.pathname}`.toLowerCase();
            } catch {
                return raw.split('?')[0].toLowerCase();
            }
        };

        const mergeRow = (current, next) => {
            if (!current) return next;

            return {
                ...current,
                ...next,
                blockId: String(next?.blockId || '').trim() || String(current?.blockId || '').trim(),
                wpAttachmentId:
                    Number(next?.wpAttachmentId ?? 0) > 0
                        ? Number(next.wpAttachmentId)
                        : Number(current?.wpAttachmentId ?? 0) || null,
                seoMediaId:
                    Number(next?.seoMediaId ?? 0) > 0
                        ? Number(next.seoMediaId)
                        : Number(current?.seoMediaId ?? 0) || null,
                src: String(next?.src || '').trim() || String(current?.src || '').trim(),
                wpSrc: String(next?.wpSrc || '').trim() || String(current?.wpSrc || '').trim(),
                localSrc: String(next?.localSrc || '').trim() || String(current?.localSrc || '').trim(),
                slug: String(next?.slug || '').trim() || String(current?.slug || '').trim(),
                alt: String(next?.alt || '').trim() || String(current?.alt || '').trim(),
                title: String(next?.title || '').trim() || String(current?.title || '').trim(),
                caption: String(next?.caption || '').trim() || String(current?.caption || '').trim(),
                originLabel:
                    String(next?.originLabel || '').trim() || String(current?.originLabel || '').trim(),
            };
        };

        const normalizedRows = [
            ...(Array.isArray(extraImages)
                ? extraImages
                      .map((row, index) => {
                          const src = String(row?.src || '').trim();
                          if (!src) return null;

                          return {
                              key: row?.key || `extra-${index}-${src}`,
                              blockId: String(row?.blockId || row?.block_id || '').trim(),
                              wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0) || null,
                              seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || null,
                              src,
                              wpSrc: String(row?.wpSrc || row?.wp_url || '').trim(),
                              localSrc: String(row?.localSrc || row?.local_src || '').trim(),
                              slug: String(row?.slug || '').trim(),
                              alt: String(row?.alt || '').trim(),
                              title: String(row?.title || '').trim(),
                              caption: String(row?.caption || '').trim(),
                              align: String(row?.align || 'none').trim(),
                              originLabel: String(row?.originLabel || row?.origin_label || '').trim(),
                              excludeQuickFix: Boolean(row?.excludeQuickFix ?? row?.exclude_quick_fix),
                          };
                      })
                      .filter(Boolean)
                : []),
            ...blockImages,
        ];

        const merged = [];
        normalizedRows.forEach((row) => {
            const srcKey = normalizeSrc(row?.src);
            const wpId = Number(row?.wpAttachmentId ?? 0);
            const seoId = Number(row?.seoMediaId ?? 0);

            const index = merged.findIndex((existing) => {
                const eWp = Number(existing?.wpAttachmentId ?? 0);
                const eSeo = Number(existing?.seoMediaId ?? 0);
                const eSrc = normalizeSrc(existing?.src);

                if (wpId > 0 && eWp > 0 && wpId === eWp) return true;
                if (seoId > 0 && eSeo > 0 && seoId === eSeo) return true;
                if (srcKey !== '' && eSrc !== '' && srcKey === eSrc) return true;
                return false;
            });

            if (index < 0) {
                merged.push(row);
                return;
            }

            merged[index] = mergeRow(merged[index], row);
        });

        return merged.map((row, index) => ({
            ...row,
            quickFixIndex: index + 1,
        }));
    }, [blockImages, extraImages]);
    const [aiJobs, setAiJobs] = useState([]);
    const lastJumpTokenRef = useRef(null);

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

    useEffect(() => {
        if (!jumpTarget || jumpTarget.token === lastJumpTokenRef.current) {
            return;
        }

        lastJumpTokenRef.current = jumpTarget.token;
        const targetMediaId = Number(jumpTarget?.seoMediaId ?? 0);
        const targetSrc = String(jumpTarget?.src ?? '').trim();

        const jump = () => {
            let targetNode = null;
            if (targetMediaId > 0) {
                targetNode = document.querySelector(
                    `.seo-article-images-row[data-seo-media-id="${targetMediaId}"]`,
                );
            }

            if (!targetNode && targetSrc !== '') {
                const rows = Array.from(document.querySelectorAll('.seo-article-images-row[data-image-src]'));
                targetNode = rows.find(
                    (node) => String(node?.dataset?.imageSrc ?? '').trim() === targetSrc,
                ) ?? null;
            }

            if (!targetNode) {
                return;
            }

            targetNode.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetNode.classList.add('is-jump-focus');
            window.setTimeout(() => targetNode?.classList.remove('is-jump-focus'), 2000);
        };

        window.setTimeout(jump, 80);
    }, [jumpTarget, mergedImages, aiJobs]);

    const totalCount = aiJobs.length + mergedImages.length;
    const hasWpImages = mergedImages.some((row) => row.wpAttachmentId);
    const hasLocalImages = mergedImages.some((row) => !row.wpAttachmentId && isLocalSeoMediaSrc(row.src));
    const keywordSource = (focusKeyword || articleTitle || '').trim();
    const canQuickFix = keywordSource.length > 0 && mergedImages.length > 0;

    if (!totalCount) {
        return (
            <div className="seo-tab-panel seo-images-tab">
                <p className="seo-images-tab-empty">{t('images_tab_empty')}</p>
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
                            ? ` (${aiJobs.length} job AI, ${mergedImages.length} ảnh)`
                            : ` · ${mergedImages.length} ảnh`}
                        . {t('images_tab_intro_suffix')}
                    </p>
                    {hasLocalImages ? (
                        <p className="seo-images-tab-info">
                            {t('images_tab_local_info')}
                        </p>
                    ) : null}
                    {hasWpImages ? (
                        <details className="seo-images-tab-warning-details">
                            <summary className="seo-images-tab-warning-summary">
                                {t('images_tab_wp_slug_warning')}
                            </summary>
                            <p className="seo-images-tab-warning">{SLUG_RENAME_WARNING}</p>
                        </details>
                    ) : null}
                </div>
                <div className="seo-images-tab-toolbar-actions">
                    <button
                        type="button"
                        className={`seo-images-quick-fix-btn${quickFixSlugAllBusy ? ' is-loading' : ''}`}
                        disabled={!canQuickFix || quickFixSlugAllBusy}
                        title={
                            quickFixSlugAllBusy
                                ? t('fix_slug_all_loading')
                                : canQuickFix
                                  ? t('images_tab_quick_fix_slug_all_hint')
                                  : t('image_quick_fix_missing_keyword')
                        }
                        aria-busy={quickFixSlugAllBusy}
                        onClick={() => onQuickFixSlugAll?.(mergedImages)}
                    >
                        {quickFixSlugAllBusy ? (
                            <Loader2 size={16} className="seo-images-quick-fix-btn__spinner" aria-hidden="true" />
                        ) : (
                            <Link2 size={16} aria-hidden="true" />
                        )}
                        {quickFixSlugAllBusy ? t('fix_slug_all_loading') : t('fix_slug_all')}
                    </button>
                    <button
                        type="button"
                        className="seo-images-quick-fix-btn"
                        disabled={!canQuickFix}
                        title={
                            canQuickFix
                                ? t('images_tab_quick_fix_alt_title_all_hint')
                                : t('image_quick_fix_missing_keyword')
                        }
                        onClick={() => onQuickFixAltTitleAll?.(mergedImages)}
                    >
                        <Type size={16} />
                        {t('fix_alt_title_all')}
                    </button>
                </div>
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
                {mergedImages.map((row) => (
                    <ImageRow
                        key={row.key || row.blockId || row.src}
                        row={row}
                        siteId={siteId}
                        articleId={articleId}
                        onPatch={onPatchImage}
                        onSlugChange={onSlugChange}
                        onFocusBlock={onFocusBlock}
                        onQuickFixSlug={onQuickFixSlugOne}
                        onQuickFixAltTitle={onQuickFixAltTitleOne}
                        canQuickFix={canQuickFix}
                        onNotify={onNotify}
                    />
                ))}
            </ul>
        </div>
    );
}
