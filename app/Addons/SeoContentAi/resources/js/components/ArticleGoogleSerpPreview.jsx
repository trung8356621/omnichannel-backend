import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Monitor, Smartphone, X } from 'lucide-react';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { normalizeArticleSlug } from '../utils/articleSlugUtils';
import {
    SLUG_LENGTH_MAX,
    computeGoogleSerpLineScores,
    slugLengthMeterClass,
    slugLengthMeterPercent,
} from '../utils/googleSerpLineScores';
import GoogleSerpSnippetPreview from './GoogleSerpSnippetPreview';

function previewTitle(preview) {
    const title = String(preview?.title ?? '').trim();
    return title !== '' ? title : 'Tiêu đề SEO sẽ hiển thị ở đây';
}

function previewDescription(preview) {
    const description = String(preview?.description ?? '').trim();
    return description !== '' ? description : 'Mô tả meta sẽ hiển thị tại đây.';
}

function previewUrl(preview, fallbackUrl = '#') {
    const display = String(preview?.display_url ?? '').trim();
    if (display !== '') {
        return display;
    }

    const url = String(preview?.url ?? '').trim();
    return url !== '' ? url : fallbackUrl;
}

function buildLiveDisplayUrl(slugPrefix, slug, slugSuffix, fallbackUrl) {
    const normalizedSlug = normalizeArticleSlug(slug);
    const prefix = String(slugPrefix ?? '').trim();
    const suffix = String(slugSuffix ?? '').trim();

    if (prefix === '' && normalizedSlug === '') {
        return previewUrl({}, fallbackUrl);
    }

    let host = '';
    try {
        const parsed = new URL(prefix.includes('://') ? prefix : `https://${prefix.replace(/^\/+/, '')}`);
        host = parsed.hostname;
    } catch {
        host = prefix.replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
    }

    if (host === '') {
        return normalizedSlug !== '' ? normalizedSlug : previewUrl({}, fallbackUrl);
    }

    const path = [normalizedSlug, suffix.replace(/^\//, '')].filter(Boolean).join('/');
    return path !== '' ? `${host} › ${path.replace(/\//g, ' › ')}` : host;
}

function PreviewDeviceToggle({ device, onChange }) {
    return (
        <div className="google-serp-device-toggle" role="group" aria-label="Chế độ xem trước">
            <button
                type="button"
                className={`google-serp-device-toggle__btn${device === 'desktop' ? ' is-active' : ''}`}
                onClick={() => onChange('desktop')}
                title="Desktop"
                aria-label="Xem trước desktop"
                aria-pressed={device === 'desktop'}
            >
                <Monitor size={16} strokeWidth={1.75} aria-hidden />
            </button>
            <button
                type="button"
                className={`google-serp-device-toggle__btn${device === 'mobile' ? ' is-active' : ''}`}
                onClick={() => onChange('mobile')}
                title="Mobile"
                aria-label="Xem trước mobile"
                aria-pressed={device === 'mobile'}
            >
                <Smartphone size={16} strokeWidth={1.75} aria-hidden />
            </button>
        </div>
    );
}

export default function ArticleGoogleSerpPreview({
    initialPreview = null,
    fallbackUrl = '#',
    skipSeoScore = false,
    initialFocusKeyword = '',
    initialSlug = '',
    permalinkBase = '',
    permalinkSuffix = '',
}) {
    const [device, setDevice] = useState('desktop');
    const [preview, setPreview] = useState(initialPreview ?? {});
    const [focusKeyword, setFocusKeyword] = useState(String(initialFocusKeyword ?? '').trim());
    const [modalOpen, setModalOpen] = useState(false);
    const [draftFocusKeyword, setDraftFocusKeyword] = useState(String(initialFocusKeyword ?? '').trim());
    const [draftDescription, setDraftDescription] = useState('');
    const [articleSlug, setArticleSlug] = useState(String(initialSlug ?? '').trim());
    const [draftSlug, setDraftSlug] = useState(String(initialSlug ?? '').trim());
    const [slugPrefix, setSlugPrefix] = useState(String(permalinkBase ?? '').trim());
    const [slugSuffix, setSlugSuffix] = useState(String(permalinkSuffix ?? '').trim());
    const [saving, setSaving] = useState(false);

    const applyPreview = useCallback((nextPreview) => {
        if (!nextPreview || typeof nextPreview !== 'object') {
            return;
        }

        setPreview(nextPreview);
    }, []);

    useEffect(() => {
        applyPreview(initialPreview);
    }, [initialPreview, applyPreview]);

    useEffect(() => {
        setDraftFocusKeyword(String(initialFocusKeyword ?? '').trim());
    }, [initialFocusKeyword]);

    useEffect(() => {
        setFocusKeyword(String(initialFocusKeyword ?? '').trim());
    }, [initialFocusKeyword]);

    useEffect(() => {
        setArticleSlug(String(initialSlug ?? '').trim());
    }, [initialSlug]);

    useEffect(() => {
        setSlugPrefix(String(permalinkBase ?? '').trim());
    }, [permalinkBase]);

    useEffect(() => {
        setSlugSuffix(String(permalinkSuffix ?? '').trim());
    }, [permalinkSuffix]);

    useEffect(() => {
        if (!modalOpen) {
            return;
        }

        setDraftFocusKeyword(focusKeyword);
        setDraftDescription(String(preview?.description ?? '').trim());
        setDraftSlug(articleSlug);
    }, [modalOpen, focusKeyword, preview?.description, articleSlug]);

    useEffect(() => {
        const onPreviewUpdated = (event) => {
            applyPreview(event.detail?.preview ?? event.detail);
        };

        const onOpenEdit = () => {
            setModalOpen(true);
        };

        window.addEventListener('google-serp-preview-updated', onPreviewUpdated);
        window.addEventListener('google-serp-preview-open-edit', onOpenEdit);

        return () => {
            window.removeEventListener('google-serp-preview-updated', onPreviewUpdated);
            window.removeEventListener('google-serp-preview-open-edit', onOpenEdit);
        };
    }, [applyPreview]);

    const showScore = !skipSeoScore;
    const slugPrefixDisplay = slugPrefix !== '' ? `${slugPrefix.replace(/\/$/, '')}/` : '';
    const descriptionLength = draftDescription.trim().length;
    const slugLength = draftSlug.trim().length;

    const sidebarLineScores = useMemo(
        () =>
            computeGoogleSerpLineScores({
                title: previewTitle(preview),
                description: previewDescription(preview),
                slug: articleSlug,
                focusKeyword,
            }),
        [preview, articleSlug, focusKeyword],
    );

    const modalLineScores = useMemo(
        () =>
            computeGoogleSerpLineScores({
                title: previewTitle(preview),
                description: draftDescription.trim() !== '' ? draftDescription : previewDescription(preview),
                slug: draftSlug,
                focusKeyword: draftFocusKeyword,
            }),
        [preview, draftDescription, draftSlug, draftFocusKeyword],
    );

    const modalLiveUrl = useMemo(
        () => buildLiveDisplayUrl(slugPrefix, draftSlug, slugSuffix, fallbackUrl),
        [slugPrefix, draftSlug, slugSuffix, fallbackUrl],
    );

    const sidebarPreviewProps = useMemo(
        () => ({
            device,
            title: previewTitle(preview),
            url: previewUrl(preview, fallbackUrl),
            description: previewDescription(preview),
            lineScores: sidebarLineScores,
            showScore,
            previewMeta: preview?.meta,
            previewType: preview?.type,
        }),
        [device, preview, fallbackUrl, sidebarLineScores, showScore],
    );

    const modalPreviewProps = useMemo(
        () => ({
            device,
            title: previewTitle(preview),
            url: modalLiveUrl,
            description: draftDescription.trim() !== '' ? draftDescription : previewDescription(preview),
            lineScores: modalLineScores,
            showScore,
            previewMeta: preview?.meta,
            previewType: preview?.type,
        }),
        [device, preview, modalLiveUrl, draftDescription, modalLineScores, showScore],
    );

    const openModal = () => {
        setModalOpen(true);
    };

    const closeModal = () => {
        if (saving) {
            return;
        }

        setModalOpen(false);
    };

    const saveSeoMeta = async () => {
        setSaving(true);

        try {
            const result = await callEditArticleLivewire(
                'updateSeoMetaFromEditor',
                draftFocusKeyword,
                draftDescription,
                draftSlug,
            );
            applyPreview(result?.google_serp_preview ?? result);
            if (result?.focus_keyword != null) {
                setFocusKeyword(String(result.focus_keyword).trim());
                window.dispatchEvent(
                    new CustomEvent('seo-focus-keyword-updated', {
                        detail: { focus_keyword: result.focus_keyword },
                    }),
                );
            }
            if (result?.article_slug != null) {
                setArticleSlug(String(result.article_slug).trim());
            }
            if (result?.permalink_base != null) {
                setSlugPrefix(String(result.permalink_base).trim());
            }
            if (result?.permalink_suffix != null) {
                setSlugSuffix(String(result.permalink_suffix).trim());
            }
            setModalOpen(false);
        } catch (error) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Không lưu được trường SEO',
                        body: error?.message ?? 'Thử lại sau.',
                        status: 'danger',
                    },
                }),
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <aside className="seo-article-editor-google-preview-rail" aria-label="Xem trước Google">
                <div className="wp-postbox wp-seo-preview-box">
                    <div className="wp-postbox-header">
                        <div className="wp-seo-preview-header-title">
                            <h2>Xem trước Google</h2>
                        </div>
                        <PreviewDeviceToggle device={device} onChange={setDevice} />
                    </div>
                    <div className="wp-postbox-inside wp-seo-preview-box__inside">
                        <GoogleSerpSnippetPreview
                            {...sidebarPreviewProps}
                            variant="card"
                            clickable
                            onClick={openModal}
                        />
                    </div>
                </div>
            </aside>

            {modalOpen ? (
                <div className="seo-google-preview-modal" role="dialog" aria-modal="true" aria-labelledby="seo-google-preview-modal-title">
                    <button type="button" className="seo-google-preview-modal__backdrop" onClick={closeModal} aria-label="Đóng" />
                    <div className="seo-google-preview-modal__panel">
                        <div className="seo-google-preview-modal__header">
                            <h3 id="seo-google-preview-modal-title">Chỉnh sửa trường SEO</h3>
                            <button type="button" className="seo-google-preview-modal__close" onClick={closeModal} aria-label="Đóng">
                                <X size={18} aria-hidden />
                            </button>
                        </div>
                        <div className="seo-google-preview-modal__body">
                            <div className="seo-google-preview-modal__preview-section">
                                <div className="seo-google-preview-modal__preview-head">
                                    <h4>Xem trước Google</h4>
                                    <PreviewDeviceToggle device={device} onChange={setDevice} />
                                </div>
                                <GoogleSerpSnippetPreview {...modalPreviewProps} variant="modal" />
                            </div>

                            <div className="seo-google-preview-modal__field">
                                <div className="seo-google-preview-modal__label-row">
                                    <label htmlFor="seo-google-preview-focus-keyword">Từ khóa chính</label>
                                    <span>{draftFocusKeyword.trim().length} ký tự</span>
                                </div>
                                <input
                                    id="seo-google-preview-focus-keyword"
                                    type="text"
                                    value={draftFocusKeyword}
                                    onChange={(event) => setDraftFocusKeyword(event.target.value)}
                                    className="seo-google-preview-modal__input"
                                    placeholder="Nhập từ khóa chính cho bài viết..."
                                />
                            </div>

                            <div className="seo-google-preview-modal__field">
                                <div className="seo-google-preview-modal__label-row">
                                    <label htmlFor="seo-google-preview-description">Thẻ mô tả</label>
                                    <span>{descriptionLength} / 160</span>
                                </div>
                                <textarea
                                    id="seo-google-preview-description"
                                    value={draftDescription}
                                    onChange={(event) => setDraftDescription(event.target.value)}
                                    rows={5}
                                    className="seo-google-preview-modal__textarea"
                                    placeholder="Mô tả ngắn để hiển thị trên kết quả tìm kiếm..."
                                />
                                <div className="seo-google-preview-modal__meter" aria-hidden="true">
                                    <div
                                        className={`seo-google-preview-modal__meter-fill${
                                            descriptionLength > 160
                                                ? ' is-over'
                                                : descriptionLength >= 120
                                                  ? ' is-good'
                                                  : ' is-warn'
                                        }`}
                                        style={{ width: `${Math.min(100, Math.round((descriptionLength / 160) * 100))}%` }}
                                    />
                                </div>
                            </div>

                            <div className="seo-google-preview-modal__field">
                                <div className="seo-google-preview-modal__label-row">
                                    <label htmlFor="seo-google-preview-slug">Liên kết cố định</label>
                                    <span>{slugLength} / {SLUG_LENGTH_MAX}</span>
                                </div>
                                <div className="seo-google-preview-modal__slug-row">
                                    {slugPrefixDisplay !== '' ? (
                                        <span className="seo-google-preview-modal__slug-prefix">{slugPrefixDisplay}</span>
                                    ) : null}
                                    <input
                                        id="seo-google-preview-slug"
                                        type="text"
                                        value={draftSlug}
                                        onChange={(event) => setDraftSlug(normalizeArticleSlug(event.target.value))}
                                        className="seo-google-preview-modal__input seo-google-preview-modal__slug-input"
                                        placeholder="ten-lien-ket-co-dinh"
                                    />
                                    {slugSuffix !== '' ? (
                                        <span className="seo-google-preview-modal__slug-suffix">{slugSuffix}</span>
                                    ) : null}
                                </div>
                                <div className="seo-google-preview-modal__meter" aria-hidden="true">
                                    <div
                                        className={`seo-google-preview-modal__meter-fill${slugLengthMeterClass(slugLength)}`}
                                        style={{ width: `${slugLengthMeterPercent(slugLength)}%` }}
                                    />
                                </div>
                            </div>
                        </div>
                        <div className="seo-google-preview-modal__footer">
                            <button type="button" className="seo-google-preview-modal__btn" onClick={closeModal} disabled={saving}>
                                Hủy
                            </button>
                            <button
                                type="button"
                                className="seo-google-preview-modal__btn is-primary"
                                onClick={saveSeoMeta}
                                disabled={saving}
                            >
                                {saving ? 'Đang lưu…' : 'Lưu SEO'}
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </>
    );
}
