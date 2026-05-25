import React from 'react';

function questionLabel(question, index) {
    const text = String(question ?? '').trim();
    if (text === '') {
        return `${index + 1}.`;
    }

    if (/^\d+[\.\)]\s/.test(text)) {
        return text;
    }

    return `${index + 1}. ${text}`;
}

function FaqItem({ faq, index, defaultOpen }) {
    const question = questionLabel(faq.question, index);
    const answerHtml = String(faq.answer ?? '').trim();
    const moreHtml = String(faq.more ?? '').trim();

    if (answerHtml === '') {
        return null;
    }

    return (
        <details className="omi-faq-item" open={defaultOpen || undefined}>
            <summary className="omi-faq-item__summary">
                <span className="omi-faq-item__chevron" aria-hidden="true" />
                <span className="omi-faq-item__question">{question}</span>
            </summary>
            <div className="omi-faq-item__body">
                {moreHtml ? (
                    <div className="omi-faq-item__more" dangerouslySetInnerHTML={{ __html: moreHtml }} />
                ) : null}
                <div className="omi-faq-item__answer" dangerouslySetInnerHTML={{ __html: answerHtml }} />
            </div>
        </details>
    );
}

/**
 * @param {{ faqs?: Array<{ question?: string, answer?: string, more?: string, id?: number }>, showHint?: boolean }} props
 */
export default function FaqAccordionPreview({ faqs = [], showHint = true }) {
    const rows = (faqs ?? []).filter((row) => String(row?.answer ?? '').trim() !== '');

    if (rows.length === 0) {
        return (
            <div className="omi-faq-placeholder" data-omi-faq="1">
                [omi_faq]
            </div>
        );
    }

    return (
        <div className="omi-faq-editor-preview">
            <div className="omi-faq-container seo-article-preview-faq">
                {rows.map((row, index) => (
                    <FaqItem key={row.id ?? `faq-${index}`} faq={row} index={index} defaultOpen={index === 0} />
                ))}
            </div>
            {showHint ? (
                <p className="omi-faq-editor-preview__hint">
                    Shortcode [omi_faq] — chỉnh câu hỏi / trả lời tại panel FAQ bên dưới.
                </p>
            ) : null}
        </div>
    );
}
