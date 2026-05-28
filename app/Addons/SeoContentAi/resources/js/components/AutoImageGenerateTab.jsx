import React, { useState } from 'react';
import { t } from '../utils/i18n';

export default function AutoImageGenerateTab({ onNotify }) {
    const [prompt, setPrompt] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const handleSubmit = () => {
        const brief = String(prompt || '').trim();
        if (!brief || submitting) {
            return;
        }

        setSubmitting(true);
        window.dispatchEvent(
            new CustomEvent('generate-article-image', {
                detail: {
                    selectionText: '',
                    selectionHtml: '',
                    userBrief: brief,
                    activeBlockId: '',
                },
            }),
        );

        onNotify?.({
            title: t('generate_image'),
            body: t('generating_image'),
            status: 'success',
        });
        setPrompt('');
        window.setTimeout(() => setSubmitting(false), 500);
    };

    return (
        <div className="seo-tab-panel seo-auto-image-tab">
            <label className="seo-auto-image-label" htmlFor="seo-auto-image-prompt">
                {t('generate_image')}
            </label>
            <textarea
                id="seo-auto-image-prompt"
                value={prompt}
                onChange={(event) => setPrompt(event.target.value)}
                className="seo-auto-image-textarea"
                placeholder={t('compose_placeholder')}
                rows={8}
            />
            <div className="seo-auto-image-actions">
                <button
                    type="button"
                    className="seo-auto-image-submit"
                    onClick={handleSubmit}
                    disabled={submitting || prompt.trim() === ''}
                >
                    {submitting ? t('processing') : t('generate_image')}
                </button>
            </div>
        </div>
    );
}
