import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlignCenter, AlignLeft, AlignRight, Maximize2, Pencil, RefreshCcw, Trash2 } from 'lucide-react';
import { parseImageFromBlockContent, renderImageFigure } from '../utils/blockImageUtils';
import { importSeoMediaFromUrl, processClipboardImagePaste } from '../utils/seoMediaApi';
import { ImageBlockPickerBox } from './BlockInsertMenu';
import { t } from '../utils/i18n';

const ALIGN_OPTIONS = [
    { id: 'left', icon: AlignLeft, title: t('toolbar_align_left') },
    { id: 'center', icon: AlignCenter, title: t('toolbar_align_center') },
    { id: 'right', icon: AlignRight, title: t('toolbar_align_right') },
    { id: 'full', icon: Maximize2, title: t('image_align_full_width') },
];
const IMAGE_BLOCK_CLIPBOARD_KEY = '__SEO_IMAGE_BLOCK_CLIPBOARD__';

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
            <p className="seo-image-meta-panel-title">{t('edit_image')}</p>
            <label className="seo-image-meta-label" htmlFor="seo-block-img-alt">
                {t('alt_text')}
            </label>
            <input
                id="seo-block-img-alt"
                type="text"
                className="seo-image-meta-input"
                value={alt}
                onChange={(e) => setAlt(e.target.value)}
                placeholder={t('image_alt_placeholder')}
            />
            <label className="seo-image-meta-label" htmlFor="seo-block-img-title">
                {t('title')}
            </label>
            <input
                id="seo-block-img-title"
                type="text"
                className="seo-image-meta-input"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
            />
            <label className="seo-image-meta-label" htmlFor="seo-block-img-caption">
                {t('caption')}
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
    articleId = null,
    siteId = null,
}) {
    const [editingMeta, setEditingMeta] = useState(false);
    const [pasteUploading, setPasteUploading] = useState(false);
    const [importLoading, setImportLoading] = useState(false);
    const toolbarRef = useRef(null);
    const emptyFrameRef = useRef(null);
    const generatePromptAtRef = useRef(0);

    const image = useMemo(() => {
        if (block.image) return block.image;
        return parseImageFromBlockContent(block.content);
    }, [block.image, block.content]);

    const figureHtml = image ? renderImageFigure(image) : block.content;

    const commitImage = (nextImage) => {
        onUpdate(renderImageFigure(nextImage), nextImage);
    };

    const resetImageToPicker = useCallback(() => {
        setEditingMeta(false);
        onUpdate('', null);
    }, [onUpdate]);

    useEffect(() => {
        if (!isActive) {
            setEditingMeta(false);
            setPasteUploading(false);
            setImportLoading(false);
        }
    }, [isActive]);

    const applyUploadedImageToBlock = useCallback(
        (data) => {
            const url = (data?.url ?? '').trim();
            if (!url) return;

            const slug = (data?.slug ?? '').trim();
            commitImage({
                src: url,
                alt: slug,
                title: slug,
                slug: slug || undefined,
                seoMediaId: data?.id != null ? Number(data.id) : undefined,
            });
        },
        // commitImage closes over onUpdate — stable enough per block session
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [onUpdate, block.id],
    );

    const handleEmptyFramePaste = useCallback(
        (event) => {
            if (pasteUploading) return false;

            const handled = processClipboardImagePaste(event, {
                articleId,
                siteId,
                source: 'clipboard',
                notifyOnSuccess: false,
                onUploaded: (data) => {
                    setPasteUploading(false);
                    applyUploadedImageToBlock(data);
                    window.dispatchEvent(
                        new CustomEvent('seo-article-editor-notify', {
                            detail: {
                            title: t('image_pasted'),
                            body: t('image_pasted_desc'),
                                status: 'success',
                            },
                        }),
                    );
                },
                onError: () => setPasteUploading(false),
            });

            if (handled) {
                setPasteUploading(true);
                event.stopImmediatePropagation();
            }

            return handled;
        },
        [articleId, siteId, pasteUploading, applyUploadedImageToBlock],
    );

    const handleImportFromUrl = useCallback(
        async (remoteUrl) => {
            if (importLoading) return;

            setImportLoading(true);
            try {
                const data = await importSeoMediaFromUrl(remoteUrl, { articleId, siteId });
                applyUploadedImageToBlock(data);
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('image_pasted'),
                            body: data.message ?? t('image_import_success'),
                            status: 'success',
                        },
                    }),
                );
            } catch (error) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('image_import_failed'),
                            body: error?.message ?? t('image_import_failed_body'),
                            status: 'danger',
                        },
                    }),
                );
            } finally {
                setImportLoading(false);
            }
        },
        [articleId, siteId, importLoading, applyUploadedImageToBlock],
    );

    useEffect(() => {
        if (!isActive) {
            return undefined;
        }

        const onWindowPaste = (event) => {
            handleEmptyFramePaste(event);
        };

        window.addEventListener('paste', onWindowPaste, true);

        const focusTimer = window.setTimeout(() => {
            emptyFrameRef.current?.focus({ preventScroll: true });
        }, 0);

        return () => {
            window.removeEventListener('paste', onWindowPaste, true);
            window.clearTimeout(focusTimer);
        };
    }, [isActive, handleEmptyFramePaste]);

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

    const isTypingTarget = (target) =>
        Boolean(
            target?.closest?.(
                'input, textarea, [contenteditable="true"], [contenteditable=""], .ProseMirror',
            ),
        );

    const copyCurrentImage = useCallback(
        async (notify = true) => {
            if (!image?.src) {
                return false;
            }

            window[IMAGE_BLOCK_CLIPBOARD_KEY] = {
                block: {
                    type: block.type,
                    content: block.content,
                    image: block.image ?? image,
                },
                image: {
                    ...image,
                    src: String(image.src).trim(),
                },
                copiedAt: Date.now(),
            };

            try {
                if (navigator?.clipboard?.writeText) {
                    await navigator.clipboard.writeText(String(image.src).trim());
                }
            } catch {
                // Browser can block clipboard API; keep internal clipboard only.
            }

            if (notify) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('image_copied'),
                            body: t('image_copied_desc'),
                            status: 'success',
                        },
                    }),
                );
            }

            return true;
        },
        [block, image],
    );

    const pasteImageFromInternalClipboard = useCallback(() => {
        const payload = window[IMAGE_BLOCK_CLIPBOARD_KEY];
        const copied = payload?.block?.image ?? payload?.image;
        if (!copied?.src) {
            return false;
        }

        commitImage({
            ...copied,
            src: String(copied.src).trim(),
        });

        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('image_pasted'),
                    body: t('image_pasted_desc'),
                    status: 'success',
                },
            }),
        );

        return true;
    }, [commitImage]);

    useEffect(() => {
        if (!isActive) {
            return undefined;
        }

        const onWindowKeyDown = (event) => {
            const mod = event.ctrlKey || event.metaKey;
            if (!mod || event.altKey || isTypingTarget(event.target)) {
                return;
            }

            const key = String(event.key || '').toLowerCase();

            if (key === 'c') {
                if (image?.src) {
                    event.preventDefault();
                    copyCurrentImage();
                }
                return;
            }

            if (key === 'x') {
                if (image?.src) {
                    event.preventDefault();
                    copyCurrentImage(false).then((copied) => {
                        if (!copied) {
                            return;
                        }
                        if (canDeleteBlock) {
                            onDelete?.();
                        } else {
                            // Fallback cho trường hợp block cuối không được xóa.
                            resetImageToPicker();
                        }
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('image_cut'),
                                    body: canDeleteBlock
                                        ? t('image_cut_desc')
                                        : t('image_cut_fallback_desc'),
                                    status: 'success',
                                },
                            }),
                        );
                    });
                }
                return;
            }

            if (key === 'v') {
                const pasted = pasteImageFromInternalClipboard();
                if (pasted) {
                    event.preventDefault();
                }
            }
        };

        window.addEventListener('keydown', onWindowKeyDown, true);

        return () => {
            window.removeEventListener('keydown', onWindowKeyDown, true);
        };
    }, [isActive, image, copyCurrentImage, pasteImageFromInternalClipboard, resetImageToPicker, canDeleteBlock, onDelete]);

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
                        ? t('image_click_edit_shift_merge')
                        : t('image_click_edit')
                }
            />
        );
    }

    if (!image) {
        if (isActive) {
            return (
                <div
                    ref={emptyFrameRef}
                    className="block-image-active block-image-active--empty"
                    tabIndex={0}
                    role="region"
                    aria-label={t('image_block_clipboard_aria')}
                    onMouseDown={(e) => e.stopPropagation()}
                >
                    <span className="block-editor-badge">{t('image_block_label')}</span>
                    <button
                        type="button"
                        className="block-image-delete"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => canDeleteBlock && onDelete?.()}
                        disabled={!canDeleteBlock}
                        title={canDeleteBlock ? t('delete_image_block') : t('cannot_delete_last_block')}
                    >
                        <Trash2 size={16} />
                    </button>
                    {pasteUploading ? (
                        <p className="seo-image-block-paste-status" aria-live="polite">{t('uploading_clipboard')}</p>
                    ) : (
                        <p className="seo-image-block-paste-hint">{t('paste_hint')}</p>
                    )}
                    <ImageBlockPickerBox
                        onOpenMediaLibrary={(event) => {
                            event?.preventDefault?.();
                            event?.stopPropagation?.();
                            const blockId = block.id;
                            window.dispatchEvent(
                                new CustomEvent('seo-open-article-media-picker', {
                                    detail: { blockId },
                                }),
                            );
                            if (typeof Livewire !== 'undefined') {
                                Livewire.dispatch('open-editor-block-media-picker', { blockId });
                            }
                        }}
                        onGenerateRequest={(prompt) => {
                            const now = Date.now();
                            if (now - generatePromptAtRef.current < 3000) {
                                return;
                            }
                            generatePromptAtRef.current = now;
                            window.dispatchEvent(
                                new CustomEvent('seo-editor-image-generate-request', {
                                    detail: { blockId: block.id, prompt },
                                }),
                            );
                        }}
                        onImportFromUrl={handleImportFromUrl}
                        importLoading={importLoading || pasteUploading}
                    />
                </div>
            );
        }

        return (
            <div
                className="seo-block-preview seo-block-image-empty-preview p-3 -mx-1 rounded border border-dashed border-gray-300 dark:border-slate-600 cursor-pointer text-center text-sm text-gray-500"
                onClick={handlePreviewClick}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        onActivate();
                    }
                }}
                role="button"
                tabIndex={0}
            >
                {t('image_block_click_to_choose')}
            </div>
        );
    }

    return (
        <div className="block-image-active" onMouseDown={(e) => e.stopPropagation()}>
                    <span className="block-editor-badge">{t('image_block_label')}</span>
            <button
                type="button"
                className="block-image-delete"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => canDeleteBlock && onDelete?.()}
                disabled={!canDeleteBlock}
                title={canDeleteBlock ? t('delete_image') : t('cannot_delete_last_block')}
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
                            title={t('edit_image_meta')}
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
                            className="seo-image-toolbar-btn"
                            title={t('replace_image')}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={resetImageToPicker}
                        >
                            <RefreshCcw size={18} strokeWidth={1.75} />
                        </button>
                        <button
                            type="button"
                            className="seo-image-toolbar-btn is-danger"
                            title={t('delete_image')}
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
