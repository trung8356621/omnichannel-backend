import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import SeoSelect from './SeoSelect';
import { fetchSeoMediaStatus } from '../utils/seoMediaApi';
import { loadProductAlbum } from '../utils/articleProductAlbumStorage';
import { t } from '../utils/i18n';

function readProductGalleryUrlsFromStorage(articleId) {
    if (!articleId) {
        return [];
    }

    return loadProductAlbum(articleId)
        .map((item) => String(item?.url ?? '').trim())
        .filter(Boolean);
}

function readProductGalleryUrlsFromDom() {
    return Array.from(document.querySelectorAll('[data-gallery-url]'))
        .map((node) => String(node.dataset.galleryUrl ?? '').trim())
        .filter(Boolean);
}

function mergeUniqueUrls(...lists) {
    const seen = new Set();
    const merged = [];

    lists.flat().forEach((url) => {
        const normalized = String(url ?? '').trim();
        if (!normalized || seen.has(normalized)) {
            return;
        }
        seen.add(normalized);
        merged.push(normalized);
    });

    return merged;
}

function requestPromptPreview(detail) {
    window.dispatchEvent(
        new CustomEvent('preview-generate-article-image-prompt', {
            detail,
        }),
    );
}

/**
 * @param {{
 *   open: boolean,
 *   onClose: () => void,
 *   onSubmit: (payload: {
 *     userBrief: string,
 *     loaiSanPhamCategoryArticleId?: number,
 *     loaiSanPhamCustom?: string,
 *   }) => void,
 *   initialPrompt?: string,
 *   mode?: 'editor' | 'product-gallery',
 *   productCategoryOptions?: Array<{ id: number, label: string }>,
 *   articleId?: number | string | null,
 *   productGalleryUrls?: string[],
 * }} props
 */
export default function GenerateImageModal({
    open,
    onClose,
    onSubmit,
    initialPrompt = '',
    initialLoaiSanPhamCustom = '',
    mode = 'editor',
    productCategoryOptions = [],
    articleId = null,
    productGalleryUrls = [],
}) {
    const [prompt, setPrompt] = useState(initialPrompt);
    const [productCategoryId, setProductCategoryId] = useState('');
    const [loaiSanPhamCustom, setLoaiSanPhamCustom] = useState(initialLoaiSanPhamCustom);
    const [submitting, setSubmitting] = useState(false);
    const [renderedPrompt, setRenderedPrompt] = useState('');
    const [renderedPromptMeta, setRenderedPromptMeta] = useState({ promptId: 0, promptName: '' });
    const [promptPreviewLoading, setPromptPreviewLoading] = useState(false);
    const [promptPreviewError, setPromptPreviewError] = useState('');
    const [galleryUrls, setGalleryUrls] = useState([]);
    const [livePreviewUrl, setLivePreviewUrl] = useState('');
    const [processingPreviewUrl, setProcessingPreviewUrl] = useState('');
    const [pendingMediaId, setPendingMediaId] = useState(null);
    const [generationError, setGenerationError] = useState('');
    const pollTimerRef = useRef(null);
    const pendingMediaIdRef = useRef(null);

    const isProductGallery = mode === 'product-gallery';
    const twoColumn = isProductGallery;

    useEffect(() => {
        pendingMediaIdRef.current = pendingMediaId;
    }, [pendingMediaId]);

    const refreshGalleryUrls = useCallback(() => {
        setGalleryUrls(
            mergeUniqueUrls(
                productGalleryUrls,
                readProductGalleryUrlsFromDom(),
                readProductGalleryUrlsFromStorage(articleId),
            ),
        );
    }, [articleId, productGalleryUrls]);

    useEffect(() => {
        if (productGalleryUrls.length > 0) {
            refreshGalleryUrls();
        }
    }, [productGalleryUrls, refreshGalleryUrls]);

    useEffect(() => {
        if (open) {
            setPrompt(initialPrompt);
            setProductCategoryId('');
            setLoaiSanPhamCustom(initialLoaiSanPhamCustom);
            setSubmitting(false);
            setRenderedPrompt('');
            setRenderedPromptMeta({ promptId: 0, promptName: '' });
            setPromptPreviewError('');
            setLivePreviewUrl('');
            setProcessingPreviewUrl('');
            setPendingMediaId(null);
            setGenerationError('');
            refreshGalleryUrls();
        }
    }, [open, initialPrompt, initialLoaiSanPhamCustom, refreshGalleryUrls]);

    const applyGalleryPreviewFromPayload = useCallback((payload) => {
        const url = String(payload?.url ?? '').trim();
        const status = String(payload?.status ?? '').toLowerCase();
        const galleryItems = Array.isArray(payload?.gallery_urls)
            ? payload.gallery_urls
            : Array.isArray(payload?.galleryUrls)
              ? payload.galleryUrls
              : [];

        if (url) {
            if (status === 'processing' || status === 'pending') {
                setProcessingPreviewUrl(url);
                setLivePreviewUrl('');
            } else {
                setLivePreviewUrl(url);
                setProcessingPreviewUrl('');
            }
        }

        if (galleryItems.length > 0) {
            const urls = galleryItems
                .map((item) => (typeof item === 'string' ? item : String(item?.url ?? '').trim()))
                .filter(Boolean);
            if (urls.length > 0) {
                setGalleryUrls((prev) => mergeUniqueUrls(urls, prev));
                setProcessingPreviewUrl('');
            }
        }

        if (status === 'completed' || status === 'failed') {
            setSubmitting(false);
            setPendingMediaId(null);
        }

        if (status === 'failed') {
            setGenerationError(String(payload?.error_message ?? payload?.message ?? t('editor_ai_failed')));
        }
    }, []);

    useEffect(() => {
        if (!open || !isProductGallery) {
            return undefined;
        }

        const onGalleryUpdated = () => refreshGalleryUrls();
        window.addEventListener('seo-product-gallery-updated', onGalleryUpdated);

        return () => window.removeEventListener('seo-product-gallery-updated', onGalleryUpdated);
    }, [open, isProductGallery, refreshGalleryUrls]);

    useEffect(() => {
        if (!open || !isProductGallery) {
            return undefined;
        }

        const onPromptPreview = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            setPromptPreviewLoading(false);
            if (detail.error) {
                setPromptPreviewError(String(detail.error));
                setRenderedPrompt('');
                return;
            }

            setPromptPreviewError('');
            setRenderedPrompt(String(detail.rendered ?? ''));
            setRenderedPromptMeta({
                promptId: Number(detail.prompt_id ?? detail.promptId ?? 0) || 0,
                promptName: String(detail.prompt_name ?? detail.promptName ?? ''),
            });
        };

        const onImageGenerated = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const target = String(detail.target ?? '').trim();
            if (target !== '' && target !== 'product-gallery') {
                return;
            }

            const mediaId = Number(detail.seoMediaId ?? detail.seo_media_id ?? 0) || 0;
            const status = String(detail.status ?? '').toLowerCase();

            if (mediaId > 0 && (status === 'processing' || status === 'pending')) {
                setPendingMediaId(mediaId);
            }

            setGenerationError('');
            applyGalleryPreviewFromPayload(detail);
        };

        const onMediaFailed = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const mediaId = Number(detail.seoMediaId ?? 0) || 0;
            const pendingId = pendingMediaIdRef.current;
            if (pendingId && mediaId > 0 && mediaId !== pendingId) {
                return;
            }

            setSubmitting(false);
            setPendingMediaId(null);
            setProcessingPreviewUrl('');
            setGenerationError(String(detail.message ?? t('editor_generate_image_failed')));
        };

        window.addEventListener('article-generate-image-prompt-preview', onPromptPreview);
        window.addEventListener('article-ai-image-generated', onImageGenerated);
        window.addEventListener('article-ai-media-failed', onMediaFailed);

        return () => {
            window.removeEventListener('article-generate-image-prompt-preview', onPromptPreview);
            window.removeEventListener('article-ai-image-generated', onImageGenerated);
            window.removeEventListener('article-ai-media-failed', onMediaFailed);
        };
    }, [open, isProductGallery, applyGalleryPreviewFromPayload]);

    useEffect(() => {
        if (!open || !isProductGallery || !pendingMediaId) {
            return undefined;
        }

        let cancelled = false;
        let attempt = 0;
        const maxAttempts = 72;

        const poll = async () => {
            if (cancelled) {
                return;
            }

            attempt += 1;

            try {
                const payload = await fetchSeoMediaStatus(pendingMediaId);
                applyGalleryPreviewFromPayload(payload);

                const status = String(payload?.status ?? '').toLowerCase();
                if (status === 'completed' || status === 'failed') {
                    return;
                }
            } catch {
                if (attempt >= maxAttempts) {
                    setSubmitting(false);
                    setGenerationError(t('editor_ai_failed'));
                }
            }

            if (attempt >= maxAttempts) {
                setSubmitting(false);
                return;
            }

            pollTimerRef.current = window.setTimeout(poll, 5000);
        };

        pollTimerRef.current = window.setTimeout(poll, 3000);

        return () => {
            cancelled = true;
            if (pollTimerRef.current) {
                window.clearTimeout(pollTimerRef.current);
                pollTimerRef.current = null;
            }
        };
    }, [open, isProductGallery, pendingMediaId, applyGalleryPreviewFromPayload]);

    const categoryId = Number.parseInt(String(productCategoryId || ''), 10) || 0;
    const customValue = String(loaiSanPhamCustom || '').trim();
    const brief = String(prompt || '').trim();

    const fetchPromptPreview = useCallback(() => {
        if (!isProductGallery) {
            return;
        }

        setPromptPreviewLoading(true);
        setPromptPreviewError('');
        requestPromptPreview({
            userBrief: brief,
            target: 'product-gallery',
            loaiSanPhamCategoryArticleId: categoryId,
            loaiSanPhamCustom: customValue || brief,
        });
    }, [isProductGallery, brief, categoryId, customValue]);

    useEffect(() => {
        if (!open || !isProductGallery) {
            return undefined;
        }

        const timer = window.setTimeout(fetchPromptPreview, 300);

        return () => window.clearTimeout(timer);
    }, [open, isProductGallery, fetchPromptPreview]);

    const loaiSanPhamPreview = useMemo(() => {
        if (!isProductGallery) {
            return '';
        }

        const parts = [];
        const selected = productCategoryOptions.find(
            (option) => String(option?.id ?? '') === String(productCategoryId),
        );
        const categoryLabel = String(selected?.label ?? '').trim();

        if (categoryLabel) {
            parts.push(categoryLabel);
        }
        if (customValue) {
            parts.push(customValue);
        } else if (brief) {
            parts.push(brief);
        }

        return parts.join(' — ');
    }, [isProductGallery, customValue, productCategoryId, productCategoryOptions]);

    const previewImageUrls = useMemo(() => {
        return mergeUniqueUrls(
            processingPreviewUrl ? [processingPreviewUrl] : [],
            livePreviewUrl ? [livePreviewUrl] : [],
            galleryUrls,
        );
    }, [galleryUrls, livePreviewUrl, processingPreviewUrl]);

    if (!open) {
        return null;
    }

    const hasLoaiSanPham = categoryId > 0 || customValue !== '' || brief !== '';
    const canSubmit = brief !== '' && (!isProductGallery || hasLoaiSanPham) && !submitting;

    const handleSubmit = () => {
        if (!canSubmit) {
            return;
        }

        const payload = {
            userBrief: brief,
            loaiSanPhamCategoryArticleId: categoryId,
            loaiSanPhamCustom: customValue || brief,
        };

        setSubmitting(true);
        setGenerationError('');
        setProcessingPreviewUrl('');
        setLivePreviewUrl('');
        setPendingMediaId(null);

        if (isProductGallery) {
            onSubmit(payload);
            return;
        }

        onClose();
        onSubmit(payload);
        setPrompt('');
        setProductCategoryId('');
        setLoaiSanPhamCustom('');
        window.setTimeout(() => setSubmitting(false), 500);
    };

    const formColumn = (
        <div className="seo-generate-image-modal__col seo-generate-image-modal__col--form">
            {isProductGallery ? (
                <>
                    <label className="seo-generate-image-modal__label" htmlFor="seo-generate-image-product-cat">
                        {t('generate_image_product_cat_label')}
                    </label>
                    <SeoSelect
                        id="seo-generate-image-product-cat"
                        value={productCategoryId}
                        onChange={(event) => setProductCategoryId(event.target.value)}
                        placeholder={t('generate_image_product_cat_placeholder')}
                        options={productCategoryOptions.map((option) => ({
                            value: option.id,
                            label: option.label,
                        }))}
                    />
                    <p className="seo-generate-image-modal__helper">{t('generate_image_product_cat_helper')}</p>

                    <label className="seo-generate-image-modal__label" htmlFor="seo-generate-image-loai-custom">
                        {t('generate_image_loai_san_pham_custom_label')}
                    </label>
                    <input
                        id="seo-generate-image-loai-custom"
                        type="text"
                        value={loaiSanPhamCustom}
                        onChange={(event) => setLoaiSanPhamCustom(event.target.value)}
                        className="seo-generate-image-modal__input"
                        placeholder={t('generate_image_loai_san_pham_custom_placeholder')}
                    />
                    <p className="seo-generate-image-modal__helper">{t('generate_image_loai_san_pham_custom_helper')}</p>

                    {loaiSanPhamPreview ? (
                        <p className="seo-generate-image-modal__preview">
                            <span className="seo-generate-image-modal__preview-label">
                                {t('generate_image_loai_san_pham_preview_label')}
                            </span>
                            {loaiSanPhamPreview}
                        </p>
                    ) : null}
                </>
            ) : null}

            <label className="seo-generate-image-modal__label" htmlFor="seo-generate-image-prompt">
                {t('generate_image_prompt_label')}
            </label>
            <textarea
                id="seo-generate-image-prompt"
                value={prompt}
                onChange={(event) => setPrompt(event.target.value)}
                className="seo-generate-image-modal__textarea"
                placeholder={t('compose_placeholder')}
                rows={isProductGallery ? 5 : 8}
                autoFocus={!isProductGallery}
            />
        </div>
    );

    const previewColumn = twoColumn ? (
        <div className="seo-generate-image-modal__col seo-generate-image-modal__col--preview">
            <section className="seo-generate-image-modal__preview-section">
                <h4 className="seo-generate-image-modal__preview-heading">{t('generate_image_preview_tab_image')}</h4>
                {generationError ? (
                    <p className="seo-generate-image-modal__error">{generationError}</p>
                ) : null}
                {submitting && previewImageUrls.length === 0 ? (
                    <p className="seo-generate-image-modal__empty">{t('generating_image')}</p>
                ) : null}
                {previewImageUrls.length > 0 ? (
                    <div className="seo-generate-image-modal__image-grid">
                        {previewImageUrls.map((url) => (
                            <a
                                key={url}
                                href={url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={`seo-generate-image-modal__image-thumb${
                                    processingPreviewUrl === url ? ' is-processing' : ''
                                }`}
                            >
                                <img src={url} alt="" loading="lazy" />
                                {processingPreviewUrl === url ? (
                                    <span className="seo-generate-image-modal__image-badge">{t('processing')}</span>
                                ) : null}
                            </a>
                        ))}
                    </div>
                ) : (
                    <p className="seo-generate-image-modal__empty">{t('generate_image_preview_no_images')}</p>
                )}
            </section>

            <section className="seo-generate-image-modal__preview-section">
                <div className="seo-generate-image-modal__preview-heading-row">
                    <h4 className="seo-generate-image-modal__preview-heading">{t('generate_image_preview_tab_prompt')}</h4>
                    <button
                        type="button"
                        className="seo-generate-image-modal__refresh-preview"
                        onClick={fetchPromptPreview}
                        disabled={promptPreviewLoading}
                    >
                        {promptPreviewLoading ? t('generate_image_preview_prompt_loading') : t('generate_image_preview_refresh')}
                    </button>
                </div>
                <div className="seo-generate-image-modal__prompt-render">
                    {renderedPromptMeta.promptName ? (
                        <p className="seo-generate-image-modal__prompt-meta">
                            {renderedPromptMeta.promptName}
                            {renderedPromptMeta.promptId > 0 ? ` (#${renderedPromptMeta.promptId})` : ''}
                        </p>
                    ) : null}
                    {promptPreviewError ? (
                        <p className="seo-generate-image-modal__error">{promptPreviewError}</p>
                    ) : null}
                    {renderedPrompt ? (
                        <pre className="seo-generate-image-modal__prompt-pre">{renderedPrompt}</pre>
                    ) : (
                        <p className="seo-generate-image-modal__empty">
                            {promptPreviewLoading
                                ? t('generate_image_preview_prompt_loading')
                                : t('generate_image_preview_prompt_empty')}
                        </p>
                    )}
                </div>
            </section>
        </div>
    ) : null;

    return createPortal(
        <div
            className="seo-generate-image-modal-backdrop"
            role="dialog"
            aria-modal="true"
            aria-label={t('generate_image')}
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div className={`seo-generate-image-modal${twoColumn ? ' seo-generate-image-modal--split' : ''}`}>
                <div className="seo-generate-image-modal__head">
                    <h3>{isProductGallery ? t('generate_product_gallery_image') : t('generate_image')}</h3>
                    <button
                        type="button"
                        className="seo-generate-image-modal__close"
                        onClick={onClose}
                        aria-label={t('magic_close')}
                    >
                        <X size={18} />
                    </button>
                </div>
                <div className={`seo-generate-image-modal__body${twoColumn ? ' seo-generate-image-modal__body--split' : ''}`}>
                    {formColumn}
                    {previewColumn}
                </div>
                <div className="seo-generate-image-modal__actions">
                    <button type="button" className="seo-generate-image-modal__cancel" onClick={onClose}>
                        {t('magic_close')}
                    </button>
                    <button
                        type="button"
                        className="seo-generate-image-modal__submit"
                        onClick={handleSubmit}
                        disabled={!canSubmit}
                        title={isProductGallery && !hasLoaiSanPham ? t('generate_image_loai_san_pham_required') : undefined}
                    >
                        {submitting ? t('processing') : t('generate_image')}
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
