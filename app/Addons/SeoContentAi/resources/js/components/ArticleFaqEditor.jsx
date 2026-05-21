import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { RefreshCw, Plus, Trash2, AlertCircle } from 'lucide-react';
import FaqAnswerEditor from './FaqAnswerEditor';
import { answerHtmlForEditor } from '../utils/faqAnswerHtml';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';

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
    const seen = new Map();

    return rows.map((row) => {
        const key = normalizeQuestion(row.question);
        let duplicate = Boolean(row.duplicate);
        let duplicate_scope = row.duplicate_scope;

        if (key !== '') {
            if (seen.has(key)) {
                duplicate = true;
                duplicate_scope = duplicate_scope || 'article';
            }
            seen.set(key, true);
        }

        return { ...row, duplicate, duplicate_scope };
    });
};

const normalizeFaqRows = (rows) =>
    applyLocalDuplicates(
        (rows ?? []).map((row) => ({
            ...row,
            answer: answerHtmlForEditor(row.answer),
        })),
    );

const reasonLabels = {
    no_pairs: 'Parser không trả về cặp Q/A (tách thủ công)',
    no_valid_pairs: 'Có dòng parse nhưng thiếu câu trả lời hợp lệ (tách thủ công)',
    body_sync_no_pairs: 'Lưu bài: có tiêu đề FAQ trong nội dung nhưng không tách được Q/A',
    wp_sync_empty_faqs: 'Đồng bộ WP: panel FAQ trống, nội dung có khối FAQ',
    wp_pull_no_pairs: 'Đồng bộ từ WordPress: có tiêu đề FAQ nhưng không tách được Q/A',
};

const contextLabels = {
    manual_selection: 'Đoạn chọn trong editor',
    article_body: 'Toàn bộ nội dung bài',
    sync: 'Trước khi đồng bộ WordPress',
    wp_pull: 'Mở bài / fetch nội dung từ WordPress',
    wp_domain_sync: 'Đồng bộ domain từ WordPress',
};

function FaqExtractDebugBanner({ debug, onDismiss }) {
    if (!debug || typeof debug !== 'object') {
        return null;
    }

    const heading = debug.heading ?? null;
    const headingText = heading?.text?.trim?.() ?? '';
    const headingSource =
        heading?.source === 'article'
            ? 'Tiêu đề FAQ trong bài (đoạn chọn không gồm H2/H3 FAQ)'
            : heading?.source === 'selection'
              ? 'Tiêu đề FAQ trong đoạn chọn'
              : null;
    const candidates = Array.isArray(debug.question_candidates) ? debug.question_candidates : [];

    return (
        <div className="seo-faq-extract-debug" role="alert">
            <div className="seo-faq-extract-debug__head">
                <strong>Debug tách FAQ</strong>
                <button type="button" className="seo-faq-extract-debug__dismiss" onClick={onDismiss}>
                    Ẩn
                </button>
            </div>
            {debug.article_id ? (
                <p className="seo-faq-extract-debug__option text-xs opacity-80">
                    Lưu Laravel <code>omi_channel.wp_options</code> →{' '}
                    <code>seo_faq_extract_debug_{debug.article_id}</code>
                </p>
            ) : null}
            <dl className="seo-faq-extract-debug__meta">
                <div>
                    <dt>Nguồn</dt>
                    <dd>{contextLabels[debug.context] ?? debug.context ?? '—'}</dd>
                </div>
                <div>
                    <dt>Lý do</dt>
                    <dd>{reasonLabels[debug.reason] ?? debug.reason ?? '—'}</dd>
                </div>
                <div>
                    <dt>Tiêu đề FAQ</dt>
                    <dd>
                        {headingText !== '' ? (
                            <>
                                {headingSource ? <span className="block text-xs opacity-80">{headingSource}</span> : null}
                                <span className="seo-faq-extract-debug__heading">{headingText}</span>
                            </>
                        ) : (
                            'Không tìm thấy H2/H3 khối FAQ'
                        )}
                    </dd>
                </div>
                <div>
                    <dt>Parser</dt>
                    <dd>
                        {debug.parsed_total ?? 0} dòng · {debug.valid_pairs ?? 0} cặp hợp lệ
                    </dd>
                </div>
            </dl>
            {candidates.length > 0 ? (
                <div className="seo-faq-extract-debug__candidates">
                    <p className="text-xs font-semibold text-amber-900 dark:text-amber-200">
                        Câu hỏi nhận diện ({candidates.length}) — thiếu/ghép không được trả lời:
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
                    <span className="font-semibold">Đoạn chọn:</span> {debug.fragment_preview}
                </p>
            ) : null}
            {heading?.html ? (
                <details className="seo-faq-extract-debug__html">
                    <summary>HTML tiêu đề FAQ</summary>
                    <pre>{heading.html}</pre>
                </details>
            ) : null}
        </div>
    );
}

export default function ArticleFaqEditor({ articleId, initialFaqs = [], initialExtractDebug = null }) {
    const [faqs, setFaqs] = useState(() => normalizeFaqRows(initialFaqs));
    const [extractDebug, setExtractDebug] = useState(
        initialExtractDebug && typeof initialExtractDebug === 'object' ? initialExtractDebug : null,
    );
    const faqsRef = React.useRef(faqs);
    faqsRef.current = faqs;
    const [renewingIndex, setRenewingIndex] = useState(null);
    const [saveStatus, setSaveStatus] = useState('saved');

    const flushFaqs = useCallback(() => {
        if (!articleId) return;
        setSaveStatus('saving');
        window.dispatchEvent(
            new CustomEvent('save-article-faqs', {
                detail: { faqs: faqsRef.current },
            }),
        );
        setSaveStatus('saved');
    }, [articleId]);

    const { debounced: debouncedSave } = useDebouncedCallback((rows) => {
        if (!articleId) return;
        setSaveStatus('saving');
        window.dispatchEvent(
            new CustomEvent('save-article-faqs', {
                detail: { faqs: rows },
            }),
        );
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
                                  duplicate: Boolean(duplicate) || row.duplicate_scope === 'article',
                                  duplicate_scope:
                                      scope ||
                                      (duplicate ? 'site' : row.duplicate_scope === 'article' ? 'article' : null),
                              }
                            : row,
                    ),
                ),
            );
        };

        const onExtracted = (event) => {
            const incoming = Array.isArray(event.detail?.faqs) ? event.detail.faqs : [];
            if (incoming.length === 0) {
                return;
            }

            setExtractDebug(null);
            setFaqs(normalizeFaqRows(incoming));
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
        window.addEventListener('article-faq-extract-debug', onExtractDebug);
        window.addEventListener('flush-article-faqs', flushFaqs);

        return () => {
            window.removeEventListener('article-faq-renewed', onRenewed);
            window.removeEventListener('faq-duplicate-checked', onDuplicateResult);
            window.removeEventListener('article-faqs-extracted', onExtracted);
            window.removeEventListener('article-faq-extract-debug', onExtractDebug);
            window.removeEventListener('flush-article-faqs', flushFaqs);
        };
    }, [debouncedSave, flushFaqs]);

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
            article: 'Trùng câu hỏi trong bài này',
            site: 'Trùng câu hỏi với bài khác trên cùng tên miền',
        }),
        [],
    );

    const saveLabel =
        saveStatus === 'saving'
            ? 'Đang lưu FAQ…'
            : saveStatus === 'pending'
              ? 'Sẽ lưu FAQ…'
              : 'FAQ đã lưu';

    return (
        <div className="seo-article-faq-panel wp-postbox">
            <div className="wp-postbox-header">
                <h2>FAQ</h2>
                <div className="flex items-center gap-2">
                    <span className="text-xs text-gray-500">{saveLabel}</span>
                    <button type="button" className="seo-faq-btn-add" onClick={addFaq}>
                        <Plus size={14} />
                        Thêm câu
                    </button>
                </div>
            </div>
            <div className="wp-postbox-inside space-y-4">
                <FaqExtractDebugBanner debug={extractDebug} onDismiss={() => setExtractDebug(null)} />
                {faqs.length === 0 ? (
                    <p className="text-sm text-gray-500 italic">
                        Chưa có FAQ. Chọn đoạn FAQ trong editor → «Tách FAQ» (sidebar), chạy quy trình, hoặc «Thêm câu».
                    </p>
                ) : (
                    faqs.map((row, index) => (
                        <div
                            key={row.id ?? `new-${index}`}
                            className={`seo-faq-item ${row.duplicate ? 'is-duplicate' : ''}`}
                        >
                            <div className="seo-faq-item__head">
                                <label className="seo-faq-label">Câu hỏi</label>
                                <div className="seo-faq-item__actions">
                                    <button
                                        type="button"
                                        className="seo-faq-btn-icon"
                                        title="Làm mới bằng AI"
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
                                        title="Xóa"
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
                                placeholder="Nhập câu hỏi…"
                                maxLength={500}
                                onChange={(e) => updateRow(index, { question: e.target.value })}
                                onBlur={(e) =>
                                    requestCrossDuplicateCheck(index, e.target.value, row.id)
                                }
                            />
                            {row.duplicate ? (
                                <p className="seo-faq-duplicate-msg">
                                    <AlertCircle size={14} />
                                    {duplicateHint[row.duplicate_scope] || 'Câu hỏi bị trùng'}
                                </p>
                            ) : null}

                            <label className="seo-faq-label mt-3 block">Câu trả lời</label>
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
