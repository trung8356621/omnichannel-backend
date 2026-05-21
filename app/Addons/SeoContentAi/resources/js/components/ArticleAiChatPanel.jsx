import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Send, Sparkles, ListTree } from 'lucide-react';
import { loadChat, saveChat } from '../utils/articleEditorStorage';

export default function ArticleAiChatPanel({ articleId }) {
    const [messages, setMessages] = useState([]);
    const [selectedText, setSelectedText] = useState('');
    const [selectedHtml, setSelectedHtml] = useState('');
    const [input, setInput] = useState('');
    const [sending, setSending] = useState(false);
    const historyRef = useRef(null);

    useEffect(() => {
        setMessages(articleId ? loadChat(articleId) : []);
    }, [articleId]);

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
        };

        window.addEventListener('seo-editor-text-selection', onSelection);
        return () => window.removeEventListener('seo-editor-text-selection', onSelection);
    }, []);

    useEffect(() => {
        if (historyRef.current) {
            historyRef.current.scrollTop = historyRef.current.scrollHeight;
        }
    }, [messages]);

    const persistMessages = useCallback(
        (next) => {
            setMessages(next);
            if (articleId) {
                saveChat(articleId, next);
            }
        },
        [articleId],
    );

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

    const handleSend = () => {
        const prompt = input.trim();
        if (!prompt || !selectedText.trim() || sending) return;

        setSending(true);
        const userMsg = {
            id: Date.now(),
            role: 'user',
            content: prompt,
            quote: selectedText,
            ts: Date.now(),
        };

        const withUser = [...messages, userMsg];
        persistMessages(withUser);
        setInput('');

        setTimeout(() => {
            const assistantMsg = {
                id: Date.now() + 1,
                role: 'assistant',
                content:
                    'Đây là phản hồi demo từ AI cho đoạn đã chọn. Tích hợp API thật sẽ thay thế nội dung này.',
                ts: Date.now(),
            };
            persistMessages([...withUser, assistantMsg]);
            setSending(false);
        }, 600);
    };

    return (
        <div className="seo-ai-chat-panel wp-postbox">
            <div className="wp-postbox-header">
                <h2>Chat AI</h2>
            </div>
            <div className="seo-ai-chat-body">
                <div ref={historyRef} className="seo-ai-chat-history">
                    {messages.length === 0 ? (
                        <p className="seo-ai-chat-empty">Chưa có hội thoại cho bài viết này.</p>
                    ) : (
                        <ul className="seo-ai-chat-list">
                            {messages.map((msg) => (
                                <li
                                    key={msg.id}
                                    className={`seo-ai-chat-msg is-${msg.role}`}
                                >
                                    <span className="seo-ai-chat-msg-role">
                                        {msg.role === 'user' ? 'Bạn' : 'AI'}
                                    </span>
                                    {msg.quote ? (
                                        <blockquote className="seo-ai-chat-msg-quote">
                                            {msg.quote}
                                        </blockquote>
                                    ) : null}
                                    <p className="seo-ai-chat-msg-text">{msg.content}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="seo-ai-chat-compose">
                    <p className="seo-ai-chat-compose-label">
                        <Sparkles size={14} className="inline -mt-0.5 mr-1" />
                        Nội dung đoạn đang sửa
                    </p>
                    <blockquote className="seo-ai-chat-selected">
                        {selectedText || '—'}
                    </blockquote>
                    <textarea
                        className="seo-ai-chat-input"
                        rows={3}
                        value={input}
                        onChange={(e) => setInput(e.target.value)}
                        placeholder="Nhập yêu cầu cho AI với đoạn đã chọn…"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                handleSend();
                            }
                        }}
                    />
                    <div className="seo-ai-chat-actions">
                        <button
                            type="button"
                            className="seo-ai-chat-extract-faq"
                            onClick={handleExtractFaq}
                            disabled={!selectedText.trim()}
                            title="Bóc tách FAQ từ đoạn đang chọn (hoặc cả block đang sửa) và lưu xuống panel FAQ"
                        >
                            <ListTree size={15} />
                            Tách FAQ
                        </button>
                        <button
                            type="button"
                            className="seo-ai-chat-send"
                            onClick={handleSend}
                            disabled={!input.trim() || !selectedText.trim() || sending}
                        >
                            <Send size={15} />
                            Chat AI
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
