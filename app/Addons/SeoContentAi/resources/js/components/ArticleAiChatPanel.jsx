import React, { useCallback, useEffect, useState } from 'react';
import { ImageIcon, ListTree, Video, X } from 'lucide-react';

export default function ArticleAiChatPanel({ articleId }) {
    const [selectedText, setSelectedText] = useState('');
    const [selectedHtml, setSelectedHtml] = useState('');
    const [activeBlockId, setActiveBlockId] = useState('');
    const [input, setInput] = useState('');
    const [generatingImage, setGeneratingImage] = useState(false);
    const [generatingVideo, setGeneratingVideo] = useState(false);

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
        };

        const onImageDone = () => setGeneratingImage(false);
        const onVideoDone = () => setGeneratingVideo(false);

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
                            className="seo-ai-chat-extract-faq"
                            onClick={handleExtractFaq}
                            disabled={!selectedText.trim() || busy}
                            title="Bóc tách FAQ từ đoạn đang chọn"
                        >
                            <ListTree size={15} />
                            Tách FAQ
                        </button>
                        <button
                            type="button"
                            className="seo-ai-chat-generate-image"
                            onClick={() => dispatchGenerate('image')}
                            disabled={!canGenerate || busy}
                            title="Chạy prompt Tạo ảnh (Quy trình)"
                        >
                            <ImageIcon size={15} />
                            {generatingImage ? 'Đang tạo ảnh…' : 'Tạo ảnh'}
                        </button>
                        <button
                            type="button"
                            className="seo-ai-chat-generate-video"
                            onClick={() => dispatchGenerate('video')}
                            disabled={!canGenerate || busy}
                            title="Chạy prompt Tạo video (Quy trình)"
                        >
                            <Video size={15} />
                            {generatingVideo ? 'Đang tạo video…' : 'Tạo video'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
