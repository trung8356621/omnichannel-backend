import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { RefreshCw, Plus, Trash2, AlertCircle, Sparkles } from 'lucide-react';
import FaqAnswerEditor from './FaqAnswerEditor';
import { answerHtmlForEditor } from '../utils/faqAnswerHtml';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { loadFaqDraft, saveFaqDraft } from '../utils/articleEditorStorage';
import { t } from '../utils/i18n';

const normalizeQuestion = (text) =>
    (text || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ');

const newFaqRow = (sortOrder = 1) => ({
    id: null,
    question: '',
    answer: '<p></p>',
    sort_order: sortOrder,
    duplicate: false,
    duplicate_scope: null,
});

const applyLocalDuplicates = (rows) => {
    return rows.map((row) => {
        const duplicateScope = row.duplicate_scope === 'site' ? 'site' : null;

        return {
            ...row,
            duplicate: duplicateScope === 'site',
            duplicate_scope: duplicateScope,
        };
    });
};

const pickFaqField = (row, keys) => {
    for (const key of keys) {
        const value = String(row?.[key] ?? '').trim();
        if (value !== '') {
            return value;
        }
    }

    return '';
};

const normalizeFaqRowShape = (row) => {
    const question = pickFaqField(row, ['question', 'q', 'title', 'name', 'label', 'heading']);
    let answer = pickFaqField(row, ['answer', 'a', 'content', 'body', 'text', 'response', 'value']);
    const more = pickFaqField(row, ['more', 'see_more', 'seeMore', 'xem_them', 'intro', 'lead']);

    if (answer === '' && more !== '') {
        answer = more;
    }

    return {
        ...row,
        question: question || row?.question || '',
        answer: answerHtmlForEditor(answer || row?.answer),
        more: more || row?.more || '',
    };
};

const normalizeFaqRows = (rows) =>
    applyLocalDuplicates((rows ?? []).map(normalizeFaqRowShape).filter((row) => String(row.answer ?? '').trim() !== ''));

const reasonLabels = {
    no_pairs: t('faq_debug_no_pairs'),
    no_valid_pairs: t('faq_debug_no_valid_pairs'),
    body_sync_no_pairs: t('faq_debug_body_sync_no_pairs'),
    wp_sync_empty_faqs: t('faq_debug_wp_sync_empty_faqs'),
    wp_pull_no_pairs: t('faq_debug_wp_pull_no_pairs'),
};

const contextLabels = {
    manual_selection: t('faq_context_manual_selection'),
    article_body: t('faq_context_article_body'),
    sync: t('faq_context_sync'),
    wp_pull: t('faq_context_wp_pull'),
    wp_domain_sync: t('faq_context_wp_domain_sync'),
};

function FaqExtractDebugBanner({ debug, onDismiss, onFixed }) {
    if (!debug || typeof debug !== 'object') {
        return null;
    }

    const heading = debug.heading ?? null;
    const headingText = heading?.text?.trim?.() ?? '';
    const headingSource =
        heading?.source === 'article'
            ? t('faq_debug_heading_article')
            : heading?.source === 'selection'
              ? t('faq_debug_heading_selection')
              : null;
    const candidates = Array.isArray(debug.question_candidates) ? debug.question_candidates : [];

    return (
        <div className="seo-faq-extract-debug" role="alert">
            <div className="seo-faq-extract-debug__head">
                <strong>{t('faq_debug_title')}</strong>
                <div className="seo-faq-extract-debug__actions">
                    <button type="button" className="seo-faq-extract-debug__fixed" onClick={onFixed}>
                        {t('faq_debug_fixed')}
                    </button>
                    <button type="button" className="seo-faq-extract-debug__dismiss" onClick={onDismiss}>
                        {t('faq_debug_hide')}
                    </button>
                </div>
            </div>
            {debug.article_id ? (
                <p className="seo-faq-extract-debug__option text-xs opacity-80">
                    Lưu Laravel <code>omi_channel.wp_options</code> →{' '}
                    <code>seo_faq_extract_debug_{debug.article_id}</code>
                </p>
            ) : null}
            <dl className="seo-faq-extract-debug__meta">
                <div>
                    <dt>{t('faq_debug_source')}</dt>
                    <dd>{contextLabels[debug.context] ?? debug.context ?? '—'}</dd>
                </div>
                <div>
                    <dt>{t('faq_debug_reason')}</dt>
                    <dd>{reasonLabels[debug.reason] ?? debug.reason ?? '—'}</dd>
                </div>
                <div>
                    <dt>{t('faq_debug_heading')}</dt>
                    <dd>
                        {headingText !== '' ? (
                            <>
                                {headingSource ? <span className="block text-xs opacity-80">{headingSource}</span> : null}
                                <span className="seo-faq-extract-debug__heading">{headingText}</span>
                            </>
                        ) : (
                            t('faq_debug_not_found')
                        )}
                    </dd>
                </div>
                <div>
                    <dt>{t('faq_debug_parser')}</dt>
                    <dd>
                        {debug.parsed_total ?? 0} dòng · {debug.valid_pairs ?? 0} cặp hợp lệ
                    </dd>
                </div>
            </dl>
            {candidates.length > 0 ? (
                <div className="seo-faq-extract-debug__candidates">
                    <p className="text-xs font-semibold text-amber-900 dark:text-amber-200">
                        {t('faq_debug_candidates', { count: candidates.length })}
                    </p>
                    <ul>
                        {candidates.map((q, i) => (
                            <li key={`${i}-${q.slice(0, 24)}`}>{q}</li>
                        ))}
                    </ul>
                </div>
            ) : null}
            {debug.fragment_preview ? (
                <p className="seo-faq-extract-debug__preview">
                    <span className="font-semibold">{t('faq_debug_fragment')}</span> {debug.fragment_preview}
                </p>
            ) : null}
            {heading?.html ? (
                <details className="seo-faq-extract-debug__html">
                    <summary>{t('faq_debug_heading_html')}</summary>
                    <pre>{heading.html}</pre>
                </details>
            ) : null}
        </div>
    );
}

export default function ArticleFaqEditor({
    articleId,
    initialFaqs = [],
    initialExtractDebug = null,
    canGenerateFaq = false,
}) {
    const [faqs, setFaqs] = useState(() => {
        const localFaqs = loadFaqDraft(articleId);

        return normalizeFaqRows(localFaqs ?? initialFaqs);
    });
    const [extractDebug, setExtractDebug] = useState(
        initialExtractDebug && typeof initialExtractDebug === 'object' ? initialExtractDebug : null,
    );
    const faqsRef = React.useRef(faqs);
    faqsRef.current = faqs;
    const skipBlurDuplicateCheckRef = useRef(false);
    const [renewingIndex, setRenewingIndex] = useState(null);
    const [generatingAll, setGeneratingAll] = useState(false);
    const [saveStatus, setSaveStatus] = useState('saved');

    const flushFaqs = useCallback(() => {
        if (!articleId) return;
        setSaveStatus('saving');
        window.dispatchEvent(
            new CustomEvent('save-article-faqs', {
                detail: { faqs: faqsRef.current },
            }),
        );
    }, [articleId]);

    const { debounced: debouncedSave } = useDebouncedCallback((rows) => {
        if (!articleId) return;
        saveFaqDraft(articleId, rows);
        setSaveStatus('saved');
    }, 1200);

    const persistRows = useCallback(
        (rows) => {
            setFaqs(rows);
            setSaveStatus('pending');
            debouncedSave(rows);
        },
        [debouncedSave],
    );

    const publishFaqsForLinks = useCallback(() => {
        const items = faqs
            .map((row, index) => ({
                text: String(row.question ?? '').trim(),
                index,
            }))
            .filter((item) => item.text !== '');

        window.dispatchEvent(
            new CustomEvent('seo-editor-faqs-updated', {
                detail: { faq: items },
            }),
        );
    }, [faqs]);

    useEffect(() => {
        publishFaqsForLinks();
    }, [publishFaqsForLinks]);

    useEffect(() => {
        const onFaqNavigate = () => {
            skipBlurDuplicateCheckRef.current = true;
            window.setTimeout(() => {
                skipBlurDuplicateCheckRef.current = false;
            }, 400);
        };

        window.addEventListener('seo-editor-faq-navigate', onFaqNavigate);

        return () => window.removeEventListener('seo-editor-faq-navigate', onFaqNavigate);
    }, []);

    const updateRow = useCallback(
        (index, patch) => {
            persistRows(
                applyLocalDuplicates(
                    faqs.map((row, i) => (i === index ? { ...row, ...patch } : row)),
                ),
            );
        },
        [faqs, persistRows],
    );

    const requestCrossDuplicateCheck = useCallback((index, question, faqId) => {
        if (!question.trim()) return;

        window.dispatchEvent(
            new CustomEvent('check-faq-question', {
                detail: {
                    index,
                    question,
                    faqId: faqId ?? null,
                    requestId: `${index}-${Date.now()}`,
                },
            }),
        );
    }, []);

    useEffect(() => {
        const onRenewed = (event) => {
            const { index, question, answer } = event.detail ?? {};
            if (typeof index !== 'number') return;

            setRenewingIndex(null);
            setFaqs((prev) => {
                const next = applyLocalDuplicates(
                    prev.map((row, i) =>
                        i === index
                            ? {
                                  ...row,
                                  question: question ?? row.question,
                                  answer: answer ?? row.answer,
                              }
                            : row,
                    ),
                );
                setSaveStatus('pending');
                debouncedSave(next);

                return next;
            });
        };

        const onDuplicateResult = (event) => {
            const { index, duplicate, duplicate_scope: scope } = event.detail ?? {};
            if (typeof index !== 'number') return;

            setFaqs((prev) =>
                applyLocalDuplicates(
                    prev.map((row, i) =>
                        i === index
                            ? {
                                  ...row,
                                  duplicate: Boolean(duplicate),
                                  duplicate_scope: scope === 'site' ? 'site' : null,
                              }
                            : row,
                    ),
                ),
            );
        };

        const onExtracted = (event) => {
            const incoming = Array.isArray(event.detail?.faqs) ? event.detail.faqs : null;
            if (incoming === null) {
                return;
            }

            if (incoming.length === 0 && !event.detail?.editorHtml) {
                return;
            }

            setExtractDebug(null);
            const next = normalizeFaqRows(incoming);
            setFaqs(next);
            saveFaqDraft(articleId, next);
            setSaveStatus('saved');
        };

        const onExtractDebug = (event) => {
            const payload = event.detail?.debug;
            if (payload && typeof payload === 'object') {
                setExtractDebug(payload);
            }
        };

        window.addEventListener('article-faq-renewed', onRenewed);
        window.addEventListener('faq-duplicate-checked', onDuplicateResult);
        window.addEventListener('article-faqs-extracted', onExtracted);
        const onExtractDebugCleared = () => {
            setExtractDebug(null);
        };

        window.addEventListener('article-faq-extract-debug', onExtractDebug);
        const onFaqsSaveFinished = () => {
            setSaveStatus('saved');
        };

        window.addEventListener('article-faq-extract-debug-cleared', onExtractDebugCleared);
        window.addEventListener('flush-article-faqs', flushFaqs);
        window.addEventListener('article-faqs-save-finished', onFaqsSaveFinished);
        const onGenerateStarted = () => setGeneratingAll(true);
        const onGenerateFinished = () => setGeneratingAll(false);

        window.addEventListener('article-faq-generate-started', onGenerateStarted);
        window.addEventListener('article-faq-generate-finished', onGenerateFinished);

        return () => {
            window.removeEventListener('article-faq-renewed', onRenewed);
            window.removeEventListener('faq-duplicate-checked', onDuplicateResult);
            window.removeEventListener('article-faqs-extracted', onExtracted);
            window.removeEventListener('article-faq-extract-debug', onExtractDebug);
            window.removeEventListener('article-faq-extract-debug-cleared', onExtractDebugCleared);
            window.removeEventListener('flush-article-faqs', flushFaqs);
            window.removeEventListener('article-faqs-save-finished', onFaqsSaveFinished);
            window.removeEventListener('article-faq-generate-started', onGenerateStarted);
            window.removeEventListener('article-faq-generate-finished', onGenerateFinished);
        };
    }, [articleId, debouncedSave, flushFaqs]);

    const generateAllFaqs = () => {
        if (!canGenerateFaq || generatingAll) {
            return;
        }
        setGeneratingAll(true);
        window.dispatchEvent(new CustomEvent('generate-article-faqs'));
    };

    const addFaq = () => {
        persistRows([...faqs, newFaqRow(faqs.length + 1)]);
    };

    const removeFaq = (index) => {
        persistRows(applyLocalDuplicates(faqs.filter((_, i) => i !== index)));
    };

    const renewFaq = (index) => {
        const row = faqs[index];
        if (!row) return;

        setRenewingIndex(index);
        window.dispatchEvent(
            new CustomEvent('renew-article-faq', {
                detail: {
                    index,
                    question: row.question,
                    answer: row.answer,
                },
            }),
        );
    };

    const duplicateHint = useMemo(
        () => ({
            article: t('faq_duplicate_in_article'),
            site: t('faq_duplicate_in_site'),
        }),
        [],
    );

    const saveLabel =
        saveStatus === 'saving'
            ? t('faq_saving')
            : saveStatus === 'pending'
              ? t('faq_pending')
              : t('faq_saved');

    return (
        <div className="seo-article-faq-panel wp-postbox">
            <div className="wp-postbox-header">
                <h2>FAQ</h2>
                <div className="flex items-center gap-2 flex-wrap justify-end">
                    <span className="text-xs text-gray-500">{saveLabel}</span>
                    {canGenerateFaq ? (
                        <button
                            type="button"
                            className="seo-faq-btn-generate"
                            disabled={generatingAll}
                            onClick={generateAllFaqs}
                            title={t('faq_generate_ai')}
                        >
                            <Sparkles size={14} className={generatingAll ? 'animate-pulse' : ''} />
                            {generatingAll ? t('faq_generate_ai_loading') : t('faq_generate_ai')}
                        </button>
                    ) : null}
                    <button type="button" className="seo-faq-btn-add" onClick={addFaq}>
                        <Plus size={14} />
                        {t('faq_add_question')}
                    </button>
                </div>
            </div>
            <div className="wp-postbox-inside space-y-4">
                <FaqExtractDebugBanner
                    debug={extractDebug}
                    onDismiss={() => setExtractDebug(null)}
                    onFixed={() => {
                        setExtractDebug(null);
                        document.getElementById('seo-faq-debug-dismiss-wire')?.click();
                    }}
                />
                {faqs.length === 0 ? (
                    <p className="text-sm text-gray-500 italic">
                        {t('faq_empty_hint')}
                    </p>
                ) : (
                    faqs.map((row, index) => (
                        <div
                            key={row.id ?? `new-${index}`}
                            data-seo-faq-index={index}
                            className={`seo-faq-item ${row.duplicate ? 'is-duplicate' : ''}`}
                        >
                            <div className="seo-faq-item__head">
                                <label className="seo-faq-label">{t('faq_question')}</label>
                                <div className="seo-faq-item__actions">
                                    <button
                                        type="button"
                                        className="seo-faq-btn-icon"
                                        title={t('faq_renew_ai')}
                                        disabled={renewingIndex === index}
                                        onClick={() => renewFaq(index)}
                                    >
                                        <RefreshCw
                                            size={16}
                                            className={renewingIndex === index ? 'animate-spin' : ''}
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        className="seo-faq-btn-icon text-red-600"
                                        title={t('faq_delete')}
                                        onClick={() => removeFaq(index)}
                                    >
                                        <Trash2 size={16} />
                                    </button>
                                </div>
                            </div>
                            <input
                                type="text"
                                className={`seo-faq-question-input ${row.duplicate ? 'is-duplicate' : ''}`}
                                value={row.question}
                                placeholder={t('faq_question_placeholder')}
                                maxLength={500}
                                onChange={(e) => updateRow(index, { question: e.target.value })}
                                onBlur={(e) => {
                                    if (skipBlurDuplicateCheckRef.current) {
                                        return;
                                    }
                                    requestCrossDuplicateCheck(index, e.target.value, row.id);
                                }}
                            />
                            {row.duplicate ? (
                                <p className="seo-faq-duplicate-msg">
                                    <AlertCircle size={14} />
                                    {duplicateHint[row.duplicate_scope] || t('faq_duplicate_generic')}
                                </p>
                            ) : null}

                            <label className="seo-faq-label mt-3 block">{t('faq_answer')}</label>
                            <FaqAnswerEditor
                                key={row.id ?? `faq-answer-${index}`}
                                html={row.answer}
                                onChange={(html) => updateRow(index, { answer: html })}
                            />
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
