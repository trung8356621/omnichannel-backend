import React, { useEffect, useState } from 'react';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { loadOutline, saveOutline } from '../utils/articleEditorStorage';

export default function OutlineMarkdownPanel({ articleId, initialOutline = '' }) {
    const [markdown, setMarkdown] = useState('');
    const [loaded, setLoaded] = useState(false);

    const { debounced: debouncedSave } = useDebouncedCallback((value) => {
        if (articleId) {
            saveOutline(articleId, value);
        }
    }, 800);

    useEffect(() => {
        if (!articleId) {
            setMarkdown(initialOutline || '');
            setLoaded(true);
            return;
        }

        const draft = loadOutline(articleId);
        if (draft !== null) {
            setMarkdown(draft);
        } else {
            setMarkdown(initialOutline || '');
        }
        setLoaded(true);
    }, [articleId, initialOutline]);

    const handleChange = (e) => {
        const value = e.target.value;
        setMarkdown(value);
        debouncedSave(value);
    };

    if (!loaded) {
        return (
            <p className="text-gray-400 text-center py-10 italic text-sm">Đang tải dàn ý…</p>
        );
    }

    const hasOutline = Boolean(markdown.trim());

    return (
        <div className="seo-outline-panel">
            <p className="seo-outline-panel-hint">
                {hasOutline
                    ? 'Markdown outline (stored locally in browser).'
                    : 'No outline yet — you can type markdown below.'}
            </p>
            <textarea
                className="seo-outline-editor"
                value={markdown}
                onChange={handleChange}
                placeholder={'# Title\n\n## Section 1\n- Main point...'}
                spellCheck={false}
            />
            {hasOutline ? (
                <details className="seo-outline-preview-wrap">
                    <summary className="seo-outline-preview-summary">Preview</summary>
                    <pre className="seo-outline-preview">{markdown}</pre>
                </details>
            ) : null}
        </div>
    );
}
