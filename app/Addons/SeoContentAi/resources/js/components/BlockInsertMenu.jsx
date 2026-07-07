import React, { useEffect, useRef, useState } from 'react';
import { ChevronsDown, ChevronsUp, FileText, HelpCircle, Image as ImageIcon, Plus } from 'lucide-react';
import { t } from '../utils/i18n';

/**
 * @param {'before'|'after'} position
 * @param {boolean} open
 * @param {() => void} onToggle
 * @param {() => void} [onMovePrevSection]
 * @param {() => void} [onMoveNextSection]
 * @param {boolean} [canMovePrevSection]
 * @param {boolean} [canMoveNextSection]
 * @param {boolean} [showMoveButtons]
 */
export function BlockInsertBar({
    position,
    open,
    onToggle,
    onMovePrevSection,
    onMoveNextSection,
    canMovePrevSection = false,
    canMoveNextSection = false,
    showMoveButtons = true,
}) {
    return (
        <div
            className={`seo-block-insert-bar seo-block-insert-bar--${position}${showMoveButtons ? '' : ' seo-block-insert-bar--insert-only'}`}
            onMouseDown={(e) => e.stopPropagation()}
        >
            {showMoveButtons ? (
                <button
                    type="button"
                    className="seo-block-insert-btn seo-block-move-btn"
                    disabled={!canMovePrevSection}
                    onMouseDown={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        if (canMovePrevSection) {
                            onMovePrevSection?.();
                        }
                    }}
                    title={t('editor_move_block_prev_section')}
                    aria-label={t('editor_move_block_prev_section')}
                >
                    <ChevronsUp size={16} strokeWidth={2.5} />
                </button>
            ) : null}
            <button
                type="button"
                className={`seo-block-insert-btn ${open ? 'is-open' : ''}`}
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.stopPropagation();
                    onToggle?.();
                }}
                title={position === 'before' ? 'Insert content above' : 'Insert content below'}
                aria-expanded={open}
                aria-label={position === 'before' ? 'Insert above' : 'Insert below'}
            >
                <Plus size={16} strokeWidth={2.5} />
            </button>
            {showMoveButtons ? (
                <button
                    type="button"
                    className="seo-block-insert-btn seo-block-move-btn"
                    disabled={!canMoveNextSection}
                    onMouseDown={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        if (canMoveNextSection) {
                            onMoveNextSection?.();
                        }
                    }}
                    title={t('editor_move_block_next_section')}
                    aria-label={t('editor_move_block_next_section')}
                >
                    <ChevronsDown size={16} strokeWidth={2.5} />
                </button>
            ) : null}
        </div>
    );
}

/** @deprecated Dùng BlockInsertBar */
export function BlockInsertTrigger(props) {
    return <BlockInsertBar {...props} />;
}

/**
 * @param {() => void} onClose
 * @param {(type: 'text'|'image'|'faq') => void} onInsert
 * @param {boolean} [faqShortcodeDisabled]
 * @param {boolean} [imageInsertDisabled]
 */
export function BlockInsertMenuBar({
    onClose,
    onInsert,
    faqShortcodeDisabled = false,
    imageInsertDisabled = false,
}) {
    const ref = useRef(null);

    useEffect(() => {
        const onMouseDown = (e) => {
            if (ref.current?.contains(e.target)) return;
            onClose();
        };
        document.addEventListener('mousedown', onMouseDown);
        return () => document.removeEventListener('mousedown', onMouseDown);
    }, [onClose]);

    const handleInsert = (type) => {
        onInsert(type);
        onClose();
    };

    return (
        <div ref={ref} className="seo-block-insert-menu" onMouseDown={(e) => e.stopPropagation()}>
            <button
                type="button"
                className="seo-block-insert-menu__item"
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.stopPropagation();
                    handleInsert('text');
                }}
            >
                <FileText size={18} strokeWidth={1.75} />
                <span>{t('editor_add_paragraph')}</span>
            </button>
            {!imageInsertDisabled ? (
                <button
                    type="button"
                    className="seo-block-insert-menu__item"
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={(e) => {
                        e.stopPropagation();
                        handleInsert('image');
                    }}
                >
                    <ImageIcon size={18} strokeWidth={1.75} />
                    <span>{t('image_block_label')}</span>
                </button>
            ) : null}
            <button
                type="button"
                className={`seo-block-insert-menu__item${faqShortcodeDisabled ? ' is-disabled' : ''}`}
                disabled={faqShortcodeDisabled}
                title={
                    faqShortcodeDisabled
                        ? 'FAQ shortcode already exists [omi_faq]'
                        : 'Insert FAQ shortcode [omi_faq]'
                }
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.stopPropagation();
                    if (!faqShortcodeDisabled) {
                        handleInsert('faq');
                    }
                }}
            >
                <HelpCircle size={18} strokeWidth={1.75} />
                <span>Shortcode FAQ</span>
            </button>
        </div>
    );
}

/**
 * Box chọn / tạo / tải nhanh ảnh cho block ảnh trống.
 *
 * @param {() => void} onOpenMediaLibrary
 * @param {(prompt: string, mediaKind?: 'image'|'video') => void} onGenerateRequest
 * @param {(url: string) => void|Promise<void>} [onImportFromUrl]
 * @param {boolean} [importLoading]
 */
export function ImageBlockPickerBox({
    onOpenMediaLibrary,
    onGenerateRequest,
    onImportFromUrl,
    importLoading = false,
    interactionReady = true,
}) {
    const [mode, setMode] = useState('actions');
    const [prompt, setPrompt] = useState('');
    const [importUrl, setImportUrl] = useState('');
    const [generateKind, setGenerateKind] = useState('image');
    const importInputRef = useRef(null);
    const generateTextareaRef = useRef(null);
    const actionsDisabled = !interactionReady || importLoading;

    const stopPickerPointer = (event) => {
        event.stopPropagation();
        if (!interactionReady) {
            event.preventDefault();
        }
    };

    // Auto-focus vào input/textarea mỗi khi mode chuyển
    useEffect(() => {
        if (!interactionReady) {
            return;
        }

        if (mode === 'import') {
            importInputRef.current?.focus();
        } else if (mode === 'generate') {
            generateTextareaRef.current?.focus();
        }
    }, [interactionReady, mode]);

    if (mode === 'import') {
        return (
            <div
                className="seo-image-block-picker"
                onMouseDown={stopPickerPointer}
                onPointerDown={stopPickerPointer}
            >
                <button type="button" className="seo-image-block-picker__back" onMouseDown={(e) => e.stopPropagation()} onClick={() => setMode('actions')}>
                    ← Back
                </button>
                <div className="seo-image-block-picker__url-row">
                    <input
                        ref={importInputRef}
                        type="url"
                        className="seo-image-block-picker__input"
                        value={importUrl}
                        onChange={(e) => setImportUrl(e.target.value)}
                        placeholder="https://example.com/image.jpg"
                        disabled={importLoading}
                        onMouseDown={(e) => {
                            // Chặn event bubble để không bị handleClickOutside interfere
                            e.stopPropagation();
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && importUrl.trim() && !importLoading) {
                                e.preventDefault();
                                onImportFromUrl?.(importUrl.trim());
                            }
                        }}
                    />
                    <button
                        type="button"
                        className="seo-image-block-picker__choice"
                        disabled={!importUrl.trim() || importLoading || !onImportFromUrl}
                        onMouseDown={(e) => e.stopPropagation()}
                        onClick={() => onImportFromUrl?.(importUrl.trim())}
                    >
                        {importLoading ? t('processing') : 'Import'}
                    </button>
                </div>
            </div>
        );
    }

    if (mode === 'generate') {
        return (
            <div
                className="seo-image-block-picker seo-image-block-picker--generate"
                onMouseDown={stopPickerPointer}
                onPointerDown={stopPickerPointer}
            >
                <p className="seo-image-block-picker__title">{t('compose_placeholder')}</p>
                <div className="seo-image-block-picker__actions">
                    <button
                        type="button"
                        className={`seo-image-block-picker__choice ${generateKind === 'image' ? '' : 'is-secondary'}`}
                        onClick={() => setGenerateKind('image')}
                    >
                        {t('image_block_label')}
                    </button>
                    <button
                        type="button"
                        className={`seo-image-block-picker__choice ${generateKind === 'video' ? '' : 'is-secondary'}`}
                        onClick={() => setGenerateKind('video')}
                    >
                        {t('generate_video')}
                    </button>
                </div>
                <textarea
                    ref={generateTextareaRef}
                    className="seo-image-block-picker__textarea"
                    rows={4}
                    value={prompt}
                    onChange={(e) => setPrompt(e.target.value)}
                    placeholder="e.g. White nonwoven tote bag, logo print, studio background..."
                    onMouseDown={(e) => {
                        e.stopPropagation();
                    }}
                />
                <div className="seo-image-block-picker__actions">
                    <button type="button" className="seo-image-block-picker__btn" onMouseDown={(e) => e.stopPropagation()} onClick={() => setMode('actions')}>
                        {t('cancel')}
                    </button>
                    <button
                        type="button"
                        className="seo-image-block-picker__btn is-primary"
                        disabled={!prompt.trim()}
                        onMouseDown={(e) => e.stopPropagation()}
                        onClick={() => onGenerateRequest(prompt.trim(), generateKind)}
                    >
                        {t('submit_retry')}
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div
            className={`seo-image-block-picker seo-image-block-picker--row${actionsDisabled ? ' is-booting' : ''}`}
            onMouseDown={stopPickerPointer}
            onPointerDown={stopPickerPointer}
        >
            <button
                type="button"
                className="seo-image-block-picker__choice"
                disabled={actionsDisabled}
                onMouseDown={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }}
                onClick={(e) => {
                    if (actionsDisabled) {
                        return;
                    }

                    e.preventDefault();
                    e.stopPropagation();
                    onOpenMediaLibrary(e);
                }}
            >
                {t('image_block_label')}/{t('generate_video')}
            </button>
            <button
                type="button"
                className="seo-image-block-picker__choice is-secondary"
                disabled={actionsDisabled}
                onMouseDown={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }}
                onClick={(e) => {
                    if (actionsDisabled) {
                        return;
                    }

                    e.stopPropagation();
                    setMode('generate');
                }}
            >
                {t('generate_image')}/{t('generate_video')}
            </button>
            <button
                type="button"
                className="seo-image-block-picker__choice is-secondary"
                disabled={actionsDisabled}
                onMouseDown={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }}
                onClick={(e) => {
                    if (actionsDisabled) {
                        return;
                    }

                    e.stopPropagation();
                    setMode('import');
                }}
            >
                Quick download
            </button>
        </div>
    );
}
