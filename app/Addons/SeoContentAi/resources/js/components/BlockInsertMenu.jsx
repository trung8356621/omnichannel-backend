import React, { useEffect, useRef, useState } from 'react';
import { ChevronDown, ChevronUp, FileText, HelpCircle, Image as ImageIcon, Plus } from 'lucide-react';

/**
 * @param {'before'|'after'} position
 * @param {boolean} open
 * @param {() => void} onToggle
 * @param {() => void} [onMoveUp]
 * @param {() => void} [onMoveDown]
 * @param {boolean} [canMoveUp]
 * @param {boolean} [canMoveDown]
 */
export function BlockInsertBar({
    position,
    open,
    onToggle,
    onMoveUp,
    onMoveDown,
    canMoveUp = false,
    canMoveDown = false,
}) {
    return (
        <div className={`seo-block-insert-bar seo-block-insert-bar--${position}`}>
            <button
                type="button"
                className="seo-block-insert-btn seo-block-move-btn"
                disabled={!canMoveUp}
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.stopPropagation();
                    onMoveUp?.();
                }}
                title="Đưa đoạn lên trên"
                aria-label="Đưa đoạn lên trên"
            >
                <ChevronUp size={16} strokeWidth={2.5} />
            </button>
            <button
                type="button"
                className={`seo-block-insert-btn ${open ? 'is-open' : ''}`}
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.stopPropagation();
                    onToggle();
                }}
                title={position === 'before' ? 'Thêm nội dung phía trên' : 'Thêm nội dung phía dưới'}
                aria-expanded={open}
                aria-label={position === 'before' ? 'Thêm phía trên' : 'Thêm phía dưới'}
            >
                <Plus size={16} strokeWidth={2.5} />
            </button>
            <button
                type="button"
                className="seo-block-insert-btn seo-block-move-btn"
                disabled={!canMoveDown}
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.stopPropagation();
                    onMoveDown?.();
                }}
                title="Đưa đoạn xuống dưới"
                aria-label="Đưa đoạn xuống dưới"
            >
                <ChevronDown size={16} strokeWidth={2.5} />
            </button>
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
 */
export function BlockInsertMenuBar({ onClose, onInsert, faqShortcodeDisabled = false }) {
    const ref = useRef(null);

    useEffect(() => {
        const onMouseDown = (e) => {
            if (ref.current?.contains(e.target)) return;
            onClose();
        };
        document.addEventListener('mousedown', onMouseDown);
        return () => document.removeEventListener('mousedown', onMouseDown);
    }, [onClose]);

    return (
        <div ref={ref} className="seo-block-insert-menu" onMouseDown={(e) => e.stopPropagation()}>
            <button
                type="button"
                className="seo-block-insert-menu__item"
                onClick={() => onInsert('text')}
            >
                <FileText size={18} strokeWidth={1.75} />
                <span>Đoạn văn</span>
            </button>
            <button
                type="button"
                className="seo-block-insert-menu__item"
                onClick={() => onInsert('image')}
            >
                <ImageIcon size={18} strokeWidth={1.75} />
                <span>Ảnh</span>
            </button>
            <button
                type="button"
                className={`seo-block-insert-menu__item${faqShortcodeDisabled ? ' is-disabled' : ''}`}
                disabled={faqShortcodeDisabled}
                title={
                    faqShortcodeDisabled
                        ? 'Bài đã có shortcode [omi_faq] — chỉ dùng một khối FAQ'
                        : 'Chèn shortcode [omi_faq]'
                }
                onClick={() => {
                    if (!faqShortcodeDisabled) {
                        onInsert('faq');
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
 * Box chọn / tạo ảnh cho block ảnh trống.
 *
 * @param {() => void} onOpenMediaLibrary
 * @param {(prompt: string) => void} onGenerateRequest
 */
export function ImageBlockPickerBox({ onOpenMediaLibrary, onGenerateRequest }) {
    const [mode, setMode] = useState('actions');
    const [prompt, setPrompt] = useState('');

    if (mode === 'generate') {
        return (
            <div className="seo-image-block-picker seo-image-block-picker--generate">
                <p className="seo-image-block-picker__title">Mô tả ảnh cần tạo</p>
                <textarea
                    className="seo-image-block-picker__textarea"
                    rows={4}
                    value={prompt}
                    onChange={(e) => setPrompt(e.target.value)}
                    placeholder="Ví dụ: Túi vải không dệt màu trắng, in logo, nền studio…"
                />
                <div className="seo-image-block-picker__actions">
                    <button type="button" className="seo-image-block-picker__btn" onClick={() => setMode('actions')}>
                        Quay lại
                    </button>
                    <button
                        type="button"
                        className="seo-image-block-picker__btn is-primary"
                        disabled={!prompt.trim()}
                        onClick={() => onGenerateRequest(prompt.trim())}
                    >
                        Gửi mô tả
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="seo-image-block-picker seo-image-block-picker--row">
            <button
                type="button"
                className="seo-image-block-picker__choice"
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    onOpenMediaLibrary(e);
                }}
            >
                Chọn ảnh
            </button>
            <button type="button" className="seo-image-block-picker__choice is-secondary" onClick={() => setMode('generate')}>
                Tạo ảnh
            </button>
        </div>
    );
}
