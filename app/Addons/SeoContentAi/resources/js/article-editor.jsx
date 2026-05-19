import React from 'react';
import { createRoot } from 'react-dom/client';
import SeoArticleEditor from './components/SeoArticleEditor';
import ArticleAiChatPanel from './components/ArticleAiChatPanel';
import '../css/article-editor.css';

const rootElement = document.getElementById('seo-article-editor-root');

if (rootElement) {
    let initialHtml = '';
    let initialOutline = '';
    let initialSeo = null;
    let editorSettings = { history_step: 20 };
    let articleId = null;

    try {
        const htmlEl = document.getElementById('seo-article-initial-html');
        const raw = htmlEl?.textContent?.trim();
        if (raw) {
            initialHtml = JSON.parse(raw);
        }
    } catch (e) {
        console.warn('Invalid article HTML JSON', e);
    }

    try {
        const seoEl = document.getElementById('seo-article-initial-seo');
        const rawSeo = seoEl?.textContent?.trim();
        if (rawSeo) {
            initialSeo = JSON.parse(rawSeo);
        }
    } catch (e) {
        console.warn('Invalid article SEO JSON', e);
    }

    try {
        const settingsEl = document.getElementById('seo-article-editor-settings');
        const rawSettings = settingsEl?.textContent?.trim();
        if (rawSettings) {
            editorSettings = JSON.parse(rawSettings);
        }
    } catch (e) {
        console.warn('Invalid editor settings JSON', e);
    }

    try {
        const outlineEl = document.getElementById('seo-article-initial-outline');
        const rawOutline = outlineEl?.textContent?.trim();
        if (rawOutline) {
            initialOutline = JSON.parse(rawOutline);
        }
    } catch (e) {
        console.warn('Invalid article outline JSON', e);
    }

    try {
        const metaEl = document.getElementById('seo-article-meta');
        const rawMeta = metaEl?.textContent?.trim();
        if (rawMeta) {
            const meta = JSON.parse(rawMeta);
            articleId = meta?.id ?? null;
        }
    } catch (e) {
        console.warn('Invalid article meta JSON', e);
    }

    const root = createRoot(rootElement);
    root.render(
        <SeoArticleEditor
            articleId={articleId}
            initialHtml={initialHtml}
            initialOutline={initialOutline}
            initialSeo={initialSeo}
            editorSettings={editorSettings}
        />,
    );

    const chatRoot = document.getElementById('seo-article-ai-chat-root');
    if (chatRoot) {
        createRoot(chatRoot).render(<ArticleAiChatPanel articleId={articleId} />);
    }
}
