import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ImageIcon, Video, X } from 'lucide-react';

function hydratePromptTemplate(template, variables) {
    return String(template ?? '').replace(/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/g, (match, key) => {
        if (!Object.prototype.hasOwnProperty.call(variables, key)) {
            return match;
        }
        return String(variables[key] ?? '');
    });
}

export default function ArticleAiChatPanel({ articleId, aiDebug = { enabled: false } }) {
    const [selectedText, setSelectedText] = useState('');
    const [selectedHtml, setSelectedHtml] = useState('');
    const [activeBlockId, setActiveBlockId] = useState('');
    const [input, setInput] = useState('');
    const [generatingImage, setGeneratingImage] = useState(false);
    const [generatingVideo, setGeneratingVideo] = useState(false);
    const generateLockRef = useRef(false);

    useEffect(() => {
        const onSelection = (e) => {
            const detail = e.detail ?? {};
            if (detail.hasSelection && detail.text) {
                setSelectedText(detail.text);
                setSelectedHtml(detail.html ?? '');
            } else {
                setSelectedText('');
                setSelectedHtml('');
            }
            setActiveBlockId((detail.activeBlockId ?? '').trim());
        };

        window.addEventListener('seo-editor-text-selection', onSelection);
        return () => window.removeEventListener('seo-editor-text-selection', onSelection);
    }, []);

    useEffect(() => {
        const onMediaFailed = (e) => {
            const type = e.detail?.type;
            if (type === 'image') {
                setGeneratingImage(false);
            } else if (type === 'video') {
                setGeneratingVideo(false);
            }
            releaseLock();
        };

        const releaseLock = () => {
            generateLockRef.current = false;
        };

        const onImageDone = () => {
            setGeneratingImage(false);
            releaseLock();
        };
        const onVideoDone = () => {
            setGeneratingVideo(false);
            releaseLock();
        };

        window.addEventListener('article-ai-media-failed', onMediaFailed);
        window.addEventListener('article-ai-image-generated', onImageDone);
        window.addEventListener('article-ai-video-generated', onVideoDone);

        return () => {
            window.removeEventListener('article-ai-media-failed', onMediaFailed);
            window.removeEventListener('article-ai-image-generated', onImageDone);
            window.removeEventListener('article-ai-video-generated', onVideoDone);
        };
    }, []);

    const handleExtractFaq = useCallback(() => {
        const html = selectedHtml.trim();
        const text = selectedText.trim();
        if (!html && !text) {
            return;
        }

        const payloadHtml =
            html ||
            text
                .split(/\n{2,}/)
                .map((p) => `<p>${p.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>`)
                .join('');

        window.dispatchEvent(
            new CustomEvent('extract-article-faqs', {
                detail: { html: payloadHtml },
            }),
        );
    }, [selectedHtml, selectedText]);

    useEffect(() => {
        const onToolbarExtract = () => handleExtractFaq();

        window.addEventListener('extract-article-faqs-from-toolbar', onToolbarExtract);

        return () => window.removeEventListener('extract-article-faqs-from-toolbar', onToolbarExtract);
    }, [handleExtractFaq]);

    const dispatchGenerate = useCallback(
        (type) => {
            const userBrief = input.trim();
            const selectionText = selectedText.trim();
            const selectionHtml = selectedHtml.trim();

            if (!userBrief && !selectionText) {
                return;
            }

            if (generateLockRef.current) {
                return;
            }

            generateLockRef.current = true;

            const detail = {
                selectionText,
                selectionHtml,
                userBrief,
                activeBlockId,
                articleId,
            };

            if (type === 'image') {
                setGeneratingImage(true);
                window.dispatchEvent(new CustomEvent('generate-article-image', { detail }));
            } else {
                setGeneratingVideo(true);
                window.dispatchEvent(new CustomEvent('generate-article-video', { detail }));
            }
        },
        [activeBlockId, articleId, input, selectedHtml, selectedText],
    );

    const canGenerate = Boolean(input.trim() || selectedText.trim());
    const busy = generatingImage || generatingVideo;
    const userBrief = input.trim();
    const contextText = selectedText.trim();
    const contextHtml = selectedHtml.trim();
    const composedInput = useMemo(() => {
        if (userBrief && contextText) {
            return `${userBrief}\n\n---\nĐoạn ngữ cảnh:\n${contextText}`;
        }

        return userBrief || contextText;
    }, [contextText, userBrief]);
    const debugVariables = useMemo(
        () => ({
            input: composedInput,
            user_brief: userBrief,
            selected_text: contextText,
            selected_html: contextHtml,
            post_title: String(aiDebug?.article_title ?? ''),
            focus_keyword: String(aiDebug?.focus_keyword ?? ''),
        }),
        [aiDebug?.article_title, aiDebug?.focus_keyword, composedInput, contextHtml, contextText, userBrief],
    );
    const imageDebugPrompt = useMemo(
        () => hydratePromptTemplate(aiDebug?.image?.template ?? '', debugVariables),
        [aiDebug?.image?.template, debugVariables],
    );
    const videoDebugPrompt = useMemo(
        () => hydratePromptTemplate(aiDebug?.video?.template ?? '', debugVariables),
        [aiDebug?.video?.template, debugVariables],
    );

    return (
        <div className="seo-ai-chat-panel wp-postbox">
            <div className="wp-postbox-header seo-ai-chat-panel__header">
                <h2>AI ảnh &amp; video</h2>
                <button
                    type="button"
                    className="seo-ai-chat-panel__close"
                    title="Đóng panel"
                    aria-label="Đóng panel"
                    onClick={() => window.dispatchEvent(new CustomEvent('seo-article-ai-chat-close'))}
                >
                    <X size={18} />
                </button>
            </div>
            <div className="seo-ai-chat-body">
                <div className="seo-ai-chat-compose">
                    <textarea
                        className="seo-ai-chat-input"
                        rows={5}
                        value={input}
                        onChange={(e) => setInput(e.target.value)}
                        placeholder="Mô tả ảnh/video hoặc yêu cầu bổ sung cho đoạn đang chọn…"
                        disabled={busy}
                    />
                    <div className="seo-ai-chat-actions">
                        <button
                            type="button"
                            className="seo-ai-chat-generate-image"
                            onClick={() => dispatchGenerate('image')}
                            disabled={!canGenerate || busy || generateLockRef.current}
                            title="Chạy prompt Tạo ảnh (Quy trình)"
                        >
                            <ImageIcon size={15} />
                            {generatingImage ? 'Đang tạo ảnh…' : 'Tạo ảnh'}
                        </button>
                        <button
                            type="button"
                            className="seo-ai-chat-generate-video"
                            onClick={() => dispatchGenerate('video')}
                            disabled={!canGenerate || busy || generateLockRef.current}
                            title="Chạy prompt Tạo video (Quy trình)"
                        >
                            <Video size={15} />
                            {generatingVideo ? 'Đang tạo video…' : 'Tạo video'}
                        </button>
                    </div>
                    {Boolean(aiDebug?.enabled) ? (
                        <div className="mt-3 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-950 space-y-2">
                            <p className="font-semibold">
                                DEBUG Prompt (APP_DEBUG=true) · input hiện tại: <code>{composedInput || '(trống)'}</code>
                            </p>
                            <div>
                                <p className="font-semibold">Tạo ảnh #{aiDebug?.image?.prompt_id ?? 'n/a'}</p>
                                <pre className="max-h-40 overflow-auto whitespace-pre-wrap wrap-break-word bg-white p-2 border rounded">
                                    {imageDebugPrompt || '(Không có nội dung prompt ảnh)'}
                                </pre>
                            </div>
                            <div>
                                <p className="font-semibold">Tạo video #{aiDebug?.video?.prompt_id ?? 'n/a'}</p>
                                <pre className="max-h-40 overflow-auto whitespace-pre-wrap wrap-break-word bg-white p-2 border rounded">
                                    {videoDebugPrompt || '(Không có nội dung prompt video)'}
                                </pre>
                            </div>
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
