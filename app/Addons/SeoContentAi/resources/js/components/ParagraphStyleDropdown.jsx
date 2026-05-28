import React, { useState, useRef, useEffect } from 'react';
import { ChevronDown } from 'lucide-react';
import { t } from '../utils/i18n';

const STYLES = [
    { value: 'p', label: t('style_paragraph'), previewClass: 'seo-fmt-preview-p' },
    { value: 'h1', label: t('style_heading_1'), previewClass: 'seo-fmt-preview-h1' },
    { value: 'h2', label: t('style_heading_2'), previewClass: 'seo-fmt-preview-h2' },
    { value: 'h3', label: t('style_heading_3'), previewClass: 'seo-fmt-preview-h3' },
    { value: 'h4', label: t('style_heading_4'), previewClass: 'seo-fmt-preview-h4' },
    { value: 'h5', label: t('style_heading_5'), previewClass: 'seo-fmt-preview-h5' },
    { value: 'h6', label: t('style_heading_6'), previewClass: 'seo-fmt-preview-h6' },
    { value: 'pre', label: t('style_preformatted'), previewClass: 'seo-fmt-preview-pre' },
];

function getActiveStyle(editor) {
    if (editor.isActive('codeBlock')) return 'pre';
    for (let level = 1; level <= 6; level += 1) {
        if (editor.isActive('heading', { level })) return `h${level}`;
    }
    return 'p';
}

function applyStyle(editor, value) {
    const chain = editor.chain().focus();
    if (value === 'p') {
        chain.setParagraph().run();
        return;
    }
    if (value === 'pre') {
        chain.toggleCodeBlock().run();
        return;
    }
    const level = parseInt(value.replace('h', ''), 10);
    chain.setHeading({ level }).run();
}

export default function ParagraphStyleDropdown({ editor }) {
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);

    const activeValue = getActiveStyle(editor);
    const activeLabel = STYLES.find((s) => s.value === activeValue)?.label ?? t('style_paragraph');

    useEffect(() => {
        if (!open) return;
        const onDocClick = (e) => {
            if (rootRef.current && !rootRef.current.contains(e.target)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, [open]);

    return (
        <div ref={rootRef} className="seo-fmt-dropdown">
            <button
                type="button"
                className="seo-fmt-dropdown-trigger"
                onClick={() => setOpen((v) => !v)}
                title={t('style_block_type')}
                aria-expanded={open}
            >
                <span className="seo-fmt-dropdown-label">{activeLabel}</span>
                <ChevronDown size={14} className={`seo-fmt-dropdown-chevron${open ? ' is-open' : ''}`} />
            </button>
            {open ? (
                <div className="seo-fmt-dropdown-menu" role="listbox">
                    {STYLES.map((style) => (
                        <button
                            key={style.value}
                            type="button"
                            role="option"
                            aria-selected={activeValue === style.value}
                            className={`seo-fmt-dropdown-item${activeValue === style.value ? ' is-active' : ''}`}
                            onClick={() => {
                                applyStyle(editor, style.value);
                                setOpen(false);
                            }}
                        >
                            <span className={style.previewClass}>{style.label}</span>
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
