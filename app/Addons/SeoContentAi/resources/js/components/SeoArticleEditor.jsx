import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import BlockFormatToolbar from './BlockFormatToolbar';
import { BlockInsertBar, BlockInsertMenuBar } from './BlockInsertMenu';
import BlockEditorResizeHandle, { useBlockEditorHeight } from './BlockEditorResizeHandle';
import LinkEditBubble from './LinkEditBubble';
import ImageBlockEditor from './ImageBlockEditor';
import {
    countMatchingAnchorsInHtml,
    countPlainTextInHtml,
    extractLinksFromBlocks,
    findBlockIdForExportOffset,
    removeMatchingAnchorsFromHtml,
    scrollToFaqByIndex,
    scrollToFaqKeyword,
    scrollToKeywordAnchor,
    scrollToPlainTextInBlock,
} from '../utils/articleLinkScroll';
import {
    wrapFirstPlainTextWithLink,
    wrapPlainTextWithLinkInBlocks,
    replaceFirstPlainTextWithLink,
    replaceFirstPlainTextWithText,
} from '../utils/articleLinkInsert';
import { findPlainTextRangeInRoot } from '../utils/articlePlainTextRange';
import { insertCtaInEditor, insertLinkInEditor } from '../utils/editorSelectionUtils';
import { isCtaPlainTextType } from '../utils/ctaLinkFormat';
import { SEO_EDITOR_LINK_CLASS } from '../utils/articleEditorTransientMarkup';
import {
    filterSuggestedInternalLinks,
    normalizeHrefForCompare,
    normalizeLinkLabel,
} from '../utils/articleLinkSuggestionFilter';
import { articleShortcutActionFromEvent } from '../utils/articleEditorShortcuts';
import SeoScorePanel from './SeoScorePanel';
import ArticleImagesTab from './ArticleImagesTab';
import ArticleGoogleSerpPreview from './ArticleGoogleSerpPreview';
import ArticleOutlineTab from './ArticleOutlineTab';
import ArticleReviewsTab from './ArticleReviewsTab';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { setArticleAutosaveLock } from '../utils/articleAutosaveLock';
import { appendProductAlbumItems, loadProductAlbum } from '../utils/articleProductAlbumStorage';
import GenerateImageModal from './GenerateImageModal';
import EditorBusyOverlay from './EditorBusyOverlay';
import {
    applyImagePatchToBlocks,
    applyQuickFixAltTitleToBlock,
    applyQuickFixAltTitleToBlocks,
    applyQuickFixSlugToBlock,
    applyQuickFixSlugToBlocks,
    buildMergedEditorImagesForPicker,
    collectImagesFromBlocks,
    computeQuickFixAltTitleSupplementalOutcome,
    computeQuickFixSlugSupplementalOutcome,
    finalizeBlocksAfterWpRename,
    enrichBlocksWithPostImages,
    imageSlugFromKeyword,
    slugFromUrl,
} from '../utils/articleImagesUtils';
import {
    confirmSlugRename,
    dispatchWordPressSlugRename,
} from '../utils/imageSlugRenameConfirm';
import { dispatchWordPressAttachmentMetaUpdate } from '../utils/imageAttachmentMetaUpdate';
import {
    AI_PLACEHOLDER_LOADING_URL,
    createClipboardPasteHandler,
    fetchSeoMediaStatus,
    renameSeoMedia,
    renameSeoMediaByUrl,
    updateSeoMediaMeta,
} from '../utils/seoMediaApi';
import { t } from '../utils/i18n';
import { articleEditorExtensions } from '../utils/editorExtensions';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { useArticleEditorHistory } from '../hooks/useArticleEditorHistory';
import {
    ARTICLE_EDITOR_DRAFT_VERSION,
    loadDraft,
    saveDraft,
} from '../utils/articleEditorStorage';
import {
    htmlToPlainText,
    isMeaningfulHtml,
    isWordPressImageElement,
    normalizeBlocks,
    parseImageFromBlockContent,
    parseFeaturedSnippetNewSectionBlocks,
    renderImageFigure,
} from '../utils/blockImageUtils';
import {
    cleanBlockHtmlForEditorDisplay,
    ensureTiptapHeadingCursorParagraph,
    FAQ_SHORTCODE_HTML,
    flattenHtmlBodyNodes,
    isFaqPlaceholderHtml,
    normalizeSectionHeadingBlockHtml,
    persistBlockHtmlFromEditor,
} from '../utils/editorHtmlUtils';
import { resolveArticleImageSrc, resolveFullWordPressImageUrl, isLocalSeoMediaSrc, supportsWordPressImageSizes } from '../utils/wordpressImageUrl';
import { applyWordPressImageSize } from '../utils/wordpressImageSize';
import {
    SEO_EDITOR_LINK_MARK_CLASS,
    SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS,
    stripEditorTransientMarkup,
} from '../utils/articleEditorTransientMarkup';
import FaqAccordionPreview from './FaqAccordionPreview';
import { Undo2, Redo2, Plus, ChevronDown, ChevronRight, ImageIcon, Table, Link2, Wand2, AlertTriangle, Search, ListPlus, Sparkles, ListCollapse, Trash2 } from 'lucide-react';
import {
    getSelectionHtmlFromEditor,
    getSelectionTextFromEditor,
} from '../utils/editorSelectionUtils';

const newBlockId = (prefix) => `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`;

const createEmptyTextBlock = () => ({
    id: newBlockId('classic'),
    type: 'text',
    isWp: false,
    prefix: '',
    content: '<p></p>',
    suffix: '',
});

const createEmptyImageBlock = () => ({
    id: newBlockId('image'),
    type: 'image',
    isWp: false,
    prefix: '',
    content: '',
    suffix: '',
    image: null,
});

const createFaqShortcodeBlock = () => ({
    id: newBlockId('classic'),
    type: 'text',
    isWp: false,
    prefix: '',
    content: FAQ_SHORTCODE_HTML,
    suffix: '',
});

const createEmptySectionBlock = () => {
    const id = newBlockId('classic');
    const suffix = String(id).split('-').pop()?.slice(-4) ?? String(Date.now()).slice(-4);
    const headingText = `${t('editor_new_section_heading')} ${suffix}`;

    return {
        id,
        type: 'text',
        isWp: false,
        prefix: '',
        content: `<h2>${headingText}</h2><p></p>`,
        suffix: '',
    };
};

const articleHasFaqShortcode = (blocks) =>
    blocks.some((block) => isFaqPlaceholderHtml(block.content || ''));

const stripLeadingH1FromHtml = (html) => {
    const trimmed = String(html || '').trim();
    if (!trimmed) {
        return trimmed;
    }

    try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(trimmed, 'text/html');
        const h1 = doc.body.querySelector('h1');
        if (!h1) {
            return trimmed;
        }

        h1.remove();

        return doc.body.innerHTML.trim();
    } catch {
        return trimmed.replace(/<h1\b[^>]*>[\s\S]*?<\/h1>\s*/i, '').trim();
    }
};

const requiresClassicInlineRegroup = (html) => {
    const source = String(html || '').trim();
    if (!source) {
        return false;
    }

    try {
        const doc = new DOMParser().parseFromString(source, 'text/html');
        const nodes = Array.from(doc.body.childNodes);
        const hasLooseText = nodes.some(
            (node) => node.nodeType === 3 && Boolean(node.textContent?.trim()),
        );
        const hasTopLevelInline = nodes.some(
            (node) =>
                node.nodeType === 1 &&
                ![
                    'ADDRESS',
                    'ASIDE',
                    'BLOCKQUOTE',
                    'DETAILS',
                    'DL',
                    'FIELDSET',
                    'FIGURE',
                    'FOOTER',
                    'FORM',
                    'H1',
                    'H2',
                    'H3',
                    'H4',
                    'H5',
                    'H6',
                    'HEADER',
                    'HR',
                    'MAIN',
                    'NAV',
                    'OL',
                    'P',
                    'PRE',
                    'TABLE',
                    'UL',
                ].includes(node.tagName),
        );

        return hasLooseText && hasTopLevelInline;
    } catch {
        return false;
    }
};

const extractSectionHeading = (block) => {
    if (!block || block.type === 'image' || typeof block.content !== 'string' || !block.content.trim()) {
        return null;
    }

    const normalized = normalizeSectionHeadingBlockHtml(block.content);
    const parser = new DOMParser();
    const doc = parser.parseFromString(normalized, 'text/html');
    const heading =
        doc.body.querySelector(':scope > h2') ||
        doc.body.querySelector('h2');
    if (!heading) {
        return null;
    }

    const text = (heading.textContent || '').replace(/\s+/g, ' ').trim();

    return text !== '' ? text : t('editor_section_untitled');
};

const blockHasOutlineHeading = (block) => {
    if (!block || block.type === 'image' || typeof block.content !== 'string' || !block.content.trim()) {
        return false;
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(block.content, 'text/html');

    return doc.body.querySelector('h2, h3, h4') !== null;
};

const normalizeOutlineHeadingText = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();

const flattenOutlineNodes = (nodes, result = []) => {
    for (const node of nodes ?? []) {
        result.push(node);
        flattenOutlineNodes(node.children, result);
    }

    return result;
};

const extractOutlineHeadingFromBlock = (block) => {
    if (!block || block.type === 'image' || typeof block.content !== 'string' || !block.content.trim()) {
        return null;
    }

    const doc = new DOMParser().parseFromString(block.content, 'text/html');
    const heading = doc.body.querySelector('h2, h3, h4');
    if (!heading) {
        return null;
    }

    const text = normalizeOutlineHeadingText(heading.textContent);
    if (text === '') {
        return null;
    }

    return {
        level: Number.parseInt(heading.tagName.charAt(1), 10),
        headingText: text,
    };
};

const findBlockIdForOutlineHeading = (blocks, level, headingText) => {
    const target = normalizeOutlineHeadingText(headingText);
    if (!target) {
        return null;
    }

    const selector = level >= 2 && level <= 4 ? `h${level}` : 'h2, h3, h4';

    for (const block of blocks) {
        if (block.type !== 'text' || !block.content) {
            continue;
        }

        const doc = new DOMParser().parseFromString(block.content, 'text/html');
        const match = Array.from(doc.body.querySelectorAll(selector)).find(
            (node) => normalizeOutlineHeadingText(node.textContent) === target,
        );
        if (match) {
            return block.id;
        }
    }

    return null;
};

const flattenOutlineHeadingKeys = (nodes) => {
    const keys = new Set();

    const walk = (items) => {
        if (!Array.isArray(items)) {
            return;
        }

        for (const node of items) {
            const level = Number(node?.level ?? 0);
            const text = normalizeOutlineHeadingText(node?.heading_text);
            if (level >= 2 && text !== '') {
                keys.add(`${level}|${text}`);
            }
            if (Array.isArray(node?.children) && node.children.length > 0) {
                walk(node.children);
            }
        }
    };

    walk(nodes);

    return keys;
};

const outlineHeadingKey = (level, headingText) =>
    `${Number(level)}|${normalizeOutlineHeadingText(headingText)}`;

const isSectionHeadingBlock = (block, section) =>
    !section?.isIntro &&
    section?.blockIds?.[0] === block?.id &&
    blockHasOutlineHeading(block);

/** Section mới / lỗi: chỉ có H2 + đoạn trống, không block nội dung khác. */
const sectionHasOnlyEmptyHeadingBody = (section, blockById) => {
    if (section?.isIntro || !section?.blockIds?.length) {
        return false;
    }

    for (let index = 1; index < section.blockIds.length; index += 1) {
        const block = blockById.get(section.blockIds[index]);
        if (!block) {
            continue;
        }

        if (block.type === 'image') {
            return false;
        }

        const plain = String(block.content ?? '')
            .replace(/<[^>]*>/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        if (plain !== '') {
            return false;
        }
    }

    const headingBlock = blockById.get(section.blockIds[0]);
    if (!headingBlock || headingBlock.type === 'image' || typeof headingBlock.content !== 'string') {
        return false;
    }

    try {
        const doc = new DOMParser().parseFromString(headingBlock.content, 'text/html');
        doc.body.querySelector('h2, h3, h4')?.remove();
        const rest = (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();

        return rest === '';
    } catch {
        return false;
    }
};

const outlineApiCsrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

async function outlineApiRequest(articleId, path, options = {}) {
    const response = await fetch(`/api/seo/articles/${articleId}/outline${path}`, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(outlineApiCsrfToken() ? { 'X-CSRF-TOKEN': outlineApiCsrfToken() } : {}),
            ...(options.headers ?? {}),
        },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) {
        throw new Error(data.message ?? 'Yêu cầu outline thất bại.');
    }

    return data;
}

const INTRO_SECTION_ID = 'section-intro';

const buildEditorSections = (blocks) => {
    if (!Array.isArray(blocks) || blocks.length === 0) {
        return [];
    }

    const sections = [];
    let currentSection = {
        id: 'section-intro',
        title: t('editor_intro'),
        isIntro: true,
        blockIds: [],
    };

    for (const block of blocks) {
        const heading = extractSectionHeading(block);

        if (heading !== null) {
            if (currentSection.blockIds.length > 0) {
                sections.push(currentSection);
            }

            currentSection = {
                id: `section-${block.id}`,
                title: heading,
                isIntro: false,
                blockIds: [block.id],
            };

            continue;
        }

        currentSection.blockIds.push(block.id);
    }

    if (currentSection.blockIds.length > 0) {
        sections.push(currentSection);
    }

    return sections;
};

const introSectionHasImageBlock = (blocks) => {
    const intro = buildEditorSections(blocks).find((section) => section.isIntro);
    if (!intro?.blockIds?.length) {
        return false;
    }

    return intro.blockIds.some((blockId) => {
        const block = blocks.find((item) => item.id === blockId);

        return block?.type === 'image';
    });
};

const countKeywordInSectionBlocks = (section, blockById, needle) => {
    if (!needle || !section?.blockIds?.length) {
        return 0;
    }

    let total = 0;
    for (const blockId of section.blockIds) {
        const block = blockById.get(blockId);
        if (!block || block.type === 'image' || !block.content) {
            continue;
        }
        total += countPlainTextInHtml(block.content, needle);
    }

    return total;
};

const buildSectionStats = (editorSections, blockById) => {
    const statsMap = new Map();

    for (const section of editorSections) {
        let imageCount = 0;
        let emptyImageSrcCount = 0;
        let tableCount = 0;
        let linkCount = 0;
        let wordCount = 0;

        for (const blockId of section.blockIds) {
            const block = blockById.get(blockId);
            if (!block) continue;

            if (block.type === 'image') {
                const src = String(block?.image?.src ?? '').trim();
                if (src !== '') {
                    imageCount += 1;
                } else {
                    emptyImageSrcCount += 1;
                }
                continue;
            }

            const html = typeof block.content === 'string' ? block.content : '';
            if (!html) continue;

            wordCount += countWordsFromHtml(html);

            const imageStats = countImagesFromHtml(html);
            imageCount += imageStats.withSrc;
            emptyImageSrcCount += imageStats.emptySrc;

            const tableMatches = html.match(/<table\b/gi);
            if (tableMatches) {
                tableCount += tableMatches.length;
            }

            const linkMatches = html.match(/<a\b/gi);
            if (linkMatches) {
                linkCount += linkMatches.length;
            }
        }

        statsMap.set(section.id, {
            imageCount,
            emptyImageSrcCount,
            hasEmptyImageSrc: emptyImageSrcCount > 0,
            tableCount,
            hasTable: tableCount > 0,
            linkCount,
            wordCount,
        });
    }

    return statsMap;
};

const countWordsFromText = (text) => {
    const normalized = String(text || '')
        .replace(/\s+/g, ' ')
        .trim();
    if (!normalized) {
        return 0;
    }

    return normalized.split(' ').filter(Boolean).length;
};

const countWordsFromHtml = (html) => {
    if (!html?.trim()) {
        return 0;
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const text = (doc.body.textContent || '').replace(/\s+/g, ' ').trim();

    return countWordsFromText(text);
};

const countImagesFromHtml = (html) => {
    if (!html?.trim()) {
        return { withSrc: 0, emptySrc: 0 };
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const images = Array.from(doc.body.querySelectorAll('img'));

    let withSrc = 0;
    let emptySrc = 0;

    for (const img of images) {
        const src = (img.getAttribute('src') || '').trim();
        if (src !== '') {
            withSrc += 1;
        } else {
            emptySrc += 1;
        }
    }

    return { withSrc, emptySrc };
};

const normalizeImageSrcKey = (src) => {
    const raw = String(src || '').trim();
    if (!raw) return '';
    try {
        const url = new URL(raw, window.location.origin);
        return `${url.pathname}`.toLowerCase();
    } catch {
        return raw.split('?')[0].toLowerCase();
    }
};

const hasBlockH2 = (block) => {
    if (!block || String(block.type || '') !== 'text') {
        return false;
    }

    const html = String(block.content || '').trim();
    if (!html) {
        return false;
    }

    try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        return Boolean(doc.body.querySelector('h2'));
    } catch {
        return /<h2[\s>]/i.test(html);
    }
};

const DISTRIBUTE_IMAGE_ALIGN = 'center';
const DISTRIBUTE_IMAGE_SIZE = 'full';

const resolveDistributeImageSrc = (row) => {
    const wpRaw = String(row?.wpSrc || row?.wp_url || '').trim();
    const wpFull = wpRaw ? resolveFullWordPressImageUrl(wpRaw) : '';
    if (wpFull && !isLocalSeoMediaSrc(wpFull)) {
        return wpFull;
    }

    const src = String(row?.src || '').trim();
    if (src && !isLocalSeoMediaSrc(src)) {
        return resolveFullWordPressImageUrl(src);
    }

    const local = String(row?.localSrc || row?.local_src || '').trim();
    return local || resolveArticleImageSrc(row) || src;
};

const buildDistributedImage = (media) => {
    const wpSrc = resolveFullWordPressImageUrl(String(media.wpSrc || '').trim());
    let image = {
        src: media.src,
        alt: media.alt || '',
        title: media.title || media.alt || '',
        caption: media.caption || '',
        align: DISTRIBUTE_IMAGE_ALIGN,
        size: DISTRIBUTE_IMAGE_SIZE,
        wpAttachmentId: media.wpAttachmentId,
        seoMediaId: media.seoMediaId,
        slug: media.slug || '',
        wpSrc: wpSrc || (isLocalSeoMediaSrc(media.src) ? '' : resolveFullWordPressImageUrl(media.src)),
        localSrc: media.localSrc || (isLocalSeoMediaSrc(media.src) ? media.src : ''),
    };

    if (supportsWordPressImageSizes(image)) {
        image = applyWordPressImageSize(image, DISTRIBUTE_IMAGE_SIZE);
    }

    return image;
};

const distributeProductImagesToEmptySections = (blocks, supplementalImages) => {
    if (!Array.isArray(blocks) || blocks.length === 0) {
        return blocks;
    }

    const usedSrc = new Set();
    blocks.forEach((block) => {
        if (block.type !== 'image') {
            return;
        }
        const image = block.image ?? parseImageFromBlockContent(block.content);
        const key = normalizeImageSrcKey(image?.src);
        if (key) {
            usedSrc.add(key);
        }
    });

    const pool = [];
    const poolSeen = new Set();
    (Array.isArray(supplementalImages) ? supplementalImages : []).forEach((row) => {
        if (String(row?.origin || '').trim() !== 'gallery') {
            return;
        }
        const src = String(row?.src || '').trim();
        const srcKey = normalizeImageSrcKey(src);
        if (!srcKey || usedSrc.has(srcKey) || poolSeen.has(srcKey)) {
            return;
        }
        poolSeen.add(srcKey);
        const displaySrc = resolveDistributeImageSrc(row);
        const isLocal = isLocalSeoMediaSrc(displaySrc);
        pool.push({
            src: displaySrc,
            slug: String(row?.slug || '').trim() || slugFromUrl(displaySrc),
            alt: String(row?.alt || '').trim(),
            title: String(row?.title || '').trim(),
            caption: String(row?.caption || '').trim(),
            align: DISTRIBUTE_IMAGE_ALIGN,
            size: DISTRIBUTE_IMAGE_SIZE,
            wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0) || null,
            seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || null,
            wpSrc: resolveFullWordPressImageUrl(String(row?.wpSrc || row?.wp_url || src).trim()),
            localSrc: String(row?.localSrc || row?.local_src || '').trim() || (isLocal ? displaySrc : ''),
        });
    });

    if (pool.length === 0) {
        return blocks;
    }

    const sections = buildEditorSections(blocks).filter((section) => {
        if (section.isIntro) {
            return false;
        }

        const hasFaqShortcode = section.blockIds.some((blockId) => {
            const block = blocks.find((item) => item.id === blockId);
            if (!block || block.type !== 'text') {
                return false;
            }

            return isFaqPlaceholderHtml(String(block.content || ''));
        });

        return !hasFaqShortcode;
    });
    if (sections.length === 0) {
        return blocks;
    }

    const next = [...blocks];
    let cursor = 0;
    let inserted = 0;

    for (const section of sections) {
        const hasImage = section.blockIds.some((blockId) => {
            const block = next.find((item) => item.id === blockId);
            if (!block) return false;

            if (block.type === 'image') {
                const image = block.image ?? parseImageFromBlockContent(block.content);
                return normalizeImageSrcKey(image?.src) !== '';
            }

            const html = String(block.content || '').trim();
            if (!html) return false;
            return countImagesFromHtml(html).withSrc > 0;
        });
        if (hasImage) {
            continue;
        }

        if (cursor >= pool.length) {
            break;
        }

        let anchorId = '';
        for (const blockId of section.blockIds ?? []) {
            const block = next.find((item) => item.id === blockId);
            if (hasBlockH2(block)) {
                anchorId = String(blockId || '').trim();
                break;
            }
        }
        if (!anchorId) {
            anchorId = String(section.blockIds?.[0] || '').trim();
        }
        if (!anchorId) {
            continue;
        }
        const anchorIndex = next.findIndex((block) => block.id === anchorId);
        if (anchorIndex < 0) {
            continue;
        }

        const media = pool[cursor++];
        const image = buildDistributedImage(media);
        const imageBlock = {
            ...createEmptyImageBlock(),
            image,
            content: renderImageFigure(image),
        };

        next.splice(anchorIndex + 1, 0, imageBlock);
        inserted += 1;
    }

    return {
        blocks: inserted > 0 ? normalizeBlocks(next) : blocks,
        inserted,
    };
};

const buildGallerySupplementalRows = (supplementalImages, storageAlbum, articleId) => {
    const rows = [];
    const seen = new Set();

    const append = (row) => {
        const src = String(row?.src || '').trim();
        if (!src) {
            return;
        }

        const key = normalizeImageSrcKey(src);
        if (!key || seen.has(key)) {
            return;
        }

        seen.add(key);
        rows.push(row);
    };

    (Array.isArray(supplementalImages) ? supplementalImages : []).forEach((row) => {
        if (String(row?.origin || '').trim() !== 'gallery') {
            return;
        }

        append({
            src: String(row?.src || '').trim(),
            slug: String(row?.slug || '').trim(),
            alt: String(row?.alt || '').trim(),
            title: String(row?.title || row?.alt || '').trim(),
            caption: String(row?.caption || '').trim(),
            align: String(row?.align || 'none').trim(),
            wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0) || null,
            seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || null,
            wpSrc: String(row?.wpSrc || row?.wp_url || '').trim(),
            localSrc: String(row?.localSrc || row?.local_src || '').trim(),
            origin: 'gallery',
        });
    });

    const albumItems = Array.isArray(storageAlbum) && storageAlbum.length > 0
        ? storageAlbum
        : loadProductAlbum(articleId);

    albumItems.forEach((item) => {
        const src = String(item?.url || item?.src || '').trim();
        if (!src) {
            return;
        }

        const isLocal = src.includes('/storage/uploads/seo_media/');
        const itemId = Number(item?.id ?? 0) || null;
        const wpId = Number(item?.wp_attachment_id ?? item?.wpAttachmentId ?? 0) || null;
        const seoId = Number(item?.seo_media_id ?? item?.seoMediaId ?? 0) || null;
        append({
            src,
            slug: String(item?.slug || '').trim() || src.split('/').pop()?.replace(/\.\w+$/, '') || '',
            alt: String(item?.alt || '').trim(),
            title: String(item?.alt || '').trim(),
            caption: '',
            align: 'none',
            wpAttachmentId: isLocal ? null : (wpId || itemId),
            seoMediaId: isLocal ? (seoId || itemId) : seoId,
            wpSrc: isLocal ? '' : src,
            localSrc: isLocal ? src : '',
            origin: 'gallery',
        });
    });

    return rows;
};

const escapeRegExp = (value) => String(value ?? '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const replaceTextInHtmlContent = (html, findText, replaceText) => {
    const source = String(html ?? '');
    const needle = String(findText ?? '');
    if (!source || !needle) {
        return { html: source, replacements: 0 };
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const pattern = new RegExp(escapeRegExp(needle), 'g');
    const textNodes = [];
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
    let current = walker.nextNode();
    while (current) {
        textNodes.push(current);
        current = walker.nextNode();
    }

    let replacements = 0;
    for (const node of textNodes) {
        const original = String(node.nodeValue ?? '');
        if (!original) continue;

        const matches = original.match(pattern);
        if (!matches?.length) continue;

        replacements += matches.length;
        node.nodeValue = original.replace(pattern, () => String(replaceText ?? ''));
    }

    return {
        html: doc.body.innerHTML,
        replacements,
    };
};

const parseVideoMediaFromHtml = (html) => {
    const source = String(html ?? '').trim();
    if (!source) {
        return null;
    }
    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const figure = doc.body.querySelector('figure.wp-block-video, figure');
    const video = doc.body.querySelector('video');
    if (!video) {
        return null;
    }
    const src = String(video.getAttribute('src') ?? '').trim();
    if (!src) {
        return null;
    }

    const className = String(figure?.getAttribute('class') ?? '');
    let align = 'none';
    if (className.includes('alignfull')) align = 'full';
    else if (className.includes('alignright')) align = 'right';
    else if (className.includes('aligncenter')) align = 'center';
    else if (className.includes('alignleft')) align = 'left';

    const wpAttachmentId = Number(figure?.getAttribute('data-id') ?? video.getAttribute('data-id') ?? 0);
    const seoMediaId = Number(
        figure?.getAttribute('data-seo-media-id') ?? video.getAttribute('data-seo-media-id') ?? 0,
    );
    const slug = slugFromUrl(src);

    return {
        src,
        alt: '',
        title: '',
        slug: slug || undefined,
        align,
        mediaType: 'video',
        wpAttachmentId: wpAttachmentId > 0 ? wpAttachmentId : undefined,
        seoMediaId: seoMediaId > 0 ? seoMediaId : undefined,
        wpSrc: src,
    };
};

const parseHtmlToBlocks = (html) => {
    if (!html) return [];
    const blocks = [];
    const wpRegex =
        /(<!--\s*wp:[a-zA-Z0-9\-\/]+\s*(?:\{.*?\})?\s*-->)(.*?)(<!--\s*\/wp:[a-zA-Z0-9\-\/]+\s*-->)/gs;
    let lastIndex = 0;
    let match;

    const splitClassic = (htmlContent) => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(htmlContent, 'text/html');
        const chunks = [];

        flattenHtmlBodyNodes(doc.body).forEach((node) => {
            if (node.nodeType === 3 && !node.textContent.trim()) return;

            if (node.nodeType === 1 && isWordPressImageElement(node)) {
                const tempDiv = document.createElement('div');
                tempDiv.appendChild(node.cloneNode(true));
                const content = tempDiv.innerHTML.trim();
                const image = parseImageFromBlockContent(content) ?? parseVideoMediaFromHtml(content);
                chunks.push({
                    id: newBlockId('image'),
                    type: 'image',
                    isWp: false,
                    prefix: '',
                    content: image && image.mediaType !== 'video' ? renderImageFigure(image) : content,
                    suffix: '',
                    image: image ?? undefined,
                });
                return;
            }

            const tempDiv = document.createElement('div');
            tempDiv.appendChild(node.cloneNode(true));
            const content = cleanBlockHtmlForEditorDisplay(tempDiv.innerHTML.trim());
            if (!isMeaningfulHtml(content)) {
                return;
            }

            chunks.push({
                id: newBlockId('classic'),
                type: 'text',
                isWp: false,
                prefix: '',
                content,
                suffix: '',
            });
        });

        return chunks;
    };

    while ((match = wpRegex.exec(html)) !== null) {
        if (match.index > lastIndex) {
            const textBefore = html.substring(lastIndex, match.index);
            if (textBefore.trim()) blocks.push(...splitClassic(textBefore));
        }
        blocks.push({
            id: newBlockId('wp'),
            isWp: true,
            type: 'text',
            prefix: match[1],
            content: match[2].trim(),
            suffix: match[3],
        });
        lastIndex = wpRegex.lastIndex;
    }

    if (lastIndex < html.length) {
        const textAfter = html.substring(lastIndex);
        if (textAfter.trim()) blocks.push(...splitClassic(textAfter));
    }

    return regroupParsedBlocksByH2(normalizeBlocks(blocks));
};

const splitHtmlAtH2Sections = (htmlContent) => {
    const source = String(htmlContent || '').trim();
    if (!source) {
        return [];
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    if (doc.body.querySelectorAll('h2').length <= 1) {
        return [source];
    }

    const sections = [];
    let current = document.createElement('div');

    const flushCurrent = () => {
        const html = cleanBlockHtmlForEditorDisplay(current.innerHTML.trim());
        if (isMeaningfulHtml(html)) {
            sections.push(html);
        }
        current = document.createElement('div');
    };

    const walkNodes = (parent) => {
        Array.from(parent.childNodes).forEach((node) => {
            if (node.nodeType === 3 && !node.textContent?.trim()) {
                return;
            }

            if (node.nodeType === 1 && node.tagName === 'H2') {
                flushCurrent();
                current.appendChild(node.cloneNode(true));
                return;
            }

            if (node.nodeType === 1 && typeof node.querySelector === 'function' && node.querySelector('h2')) {
                walkNodes(node);
                return;
            }

            current.appendChild(node.cloneNode(true));
        });
    };

    walkNodes(doc.body);
    flushCurrent();

    return sections.length > 0 ? sections : [source];
};

const regroupParsedBlocksByH2 = (blocks) => {
    const result = [];

    blocks.forEach((block) => {
        if (block.type !== 'text' || block.isWp || typeof block.content !== 'string' || !block.content.trim()) {
            result.push(block);
            return;
        }

        const parts = splitHtmlAtH2Sections(block.content);
        if (parts.length <= 1) {
            result.push(block);
            return;
        }

        parts.forEach((part) => {
            result.push({
                id: newBlockId('classic'),
                type: 'text',
                isWp: false,
                prefix: '',
                content: part,
                suffix: '',
            });
        });
    });

    return normalizeBlocks(result);
};

const hasMeaningfulExportHtml = (html) => {
    const source = String(html ?? '').trim();
    if (!source) return false;
    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const text = (doc.body.textContent || '').replace(/\u00a0/g, ' ').trim();
    if (text) return true;
    return Boolean(
        doc.body.querySelector(
            'img,video,iframe,table,ul,ol,li,blockquote,pre,code,h1,h2,h3,h4,h5,h6,hr',
        ),
    );
};

const exportBlocksToHtml = (blocks) =>
    blocks
        .map((b) => {
            let part;
            if (b.prefix || b.suffix) {
                part = [b.prefix, b.content, b.suffix].filter(Boolean).join('\n');
            } else {
                part = b.content;
            }
            if (typeof part !== 'string') {
                return part;
            }
            const cleaned = stripEditorTransientMarkup(part);
            return hasMeaningfulExportHtml(cleaned) ? cleaned : '';
        })
        .filter(Boolean)
        .join('\n\n');

const getBlocksInRange = (blocks, fromId, toId) => {
    const fromIdx = blocks.findIndex((b) => b.id === fromId);
    const toIdx = blocks.findIndex((b) => b.id === toId);
    if (fromIdx === -1 || toIdx === -1) return [];

    const start = Math.min(fromIdx, toIdx);
    const end = Math.max(fromIdx, toIdx);

    return blocks.slice(start, end + 1);
};

/** Gộp HTML nhiều block — chỉ hiển thị tạm trong editor, không ghi vào state block. */
const mergeBlockHtmlContents = (rangeBlocks) => {
    const container = document.createElement('div');
    const parser = new DOMParser();

    rangeBlocks.forEach((block) => {
        const raw = block.content?.trim();
        if (!raw) return;

        const doc = parser.parseFromString(raw, 'text/html');
        flattenHtmlBodyNodes(doc.body).forEach((node) => {
            if (node.nodeType === 3 && !node.textContent?.trim()) return;
            container.appendChild(node.cloneNode(true));
        });
    });

    return container.innerHTML.trim();
};

/** Plain text từ nhiều block — ngữ cảnh AI. */
const getPlainTextFromBlocks = (rangeBlocks) => {
    const parser = new DOMParser();

    return rangeBlocks
        .map((block) => {
            const raw = block.content?.trim();
            if (!raw) return '';
            const doc = parser.parseFromString(raw, 'text/html');
            return (doc.body.textContent || '').trim();
        })
        .filter(Boolean)
        .join('\n\n');
};

/** Plain text từ heading trong block tới heading cùng/cao hơn level kế tiếp — ngữ cảnh AI theo outline. */
const extractHeadingScopedPlainText = (html, level, headingText) => {
    const raw = String(html ?? '').trim();
    const target = normalizeOutlineHeadingText(headingText);
    if (raw === '' || target === '') {
        return '';
    }

    const doc = new DOMParser().parseFromString(raw, 'text/html');
    const selector = level >= 2 && level <= 4 ? `h${level}` : 'h2, h3, h4';
    const headings = Array.from(doc.body.querySelectorAll(selector));
    const startIdx = headings.findIndex(
        (node) => normalizeOutlineHeadingText(node.textContent) === target,
    );
    if (startIdx < 0) {
        return '';
    }

    const startHeading = headings[startIdx];
    const startLevel = Number.parseInt(startHeading.tagName.charAt(1), 10);
    const parts = [normalizeOutlineHeadingText(startHeading.textContent)];

    let el = startHeading.nextElementSibling;
    while (el) {
        if (/^H[234]$/i.test(el.tagName)) {
            const nextLevel = Number.parseInt(el.tagName.charAt(1), 10);
            if (nextLevel <= startLevel) {
                break;
            }
        }

        const text = String(el.textContent ?? '')
            .replace(/\s+/g, ' ')
            .trim();
        if (text !== '') {
            parts.push(text);
        }

        el = el.nextElementSibling;
    }

    return parts.filter(Boolean).join('\n');
};

function getActiveBlockContextText(blocks, activeBlockId, tempMerge) {
    if (!activeBlockId) return '';

    if (tempMerge?.rangeIds?.length) {
        const rangeBlocks = blocks.filter((b) => tempMerge.rangeIds.includes(b.id));
        return getPlainTextFromBlocks(rangeBlocks);
    }

    const block = blocks.find((b) => b.id === activeBlockId);
    if (!block?.content) return '';

    if (block.type === 'image') {
        const caption = block.image?.caption ?? '';
        const alt = block.image?.alt ?? '';
        return [alt, caption].filter(Boolean).join(' — ');
    }

    return htmlToPlainText(block.content);
}

function getHtmlFromBlocks(blocks, activeBlockId, tempMerge) {
    if (!activeBlockId) {
        return '';
    }

    if (tempMerge?.rangeIds?.length) {
        const rangeBlocks = blocks.filter((b) => tempMerge.rangeIds.includes(b.id));

        return mergeBlockHtmlContents(rangeBlocks);
    }

    const block = blocks.find((b) => b.id === activeBlockId);
    if (!block) {
        return '';
    }

    return block.content?.trim() ?? '';
}

function dispatchActiveBlockContext(articleId, text, html, open, activeBlockId) {
    const trimmedText = text.trim();
    const trimmedHtml = html.trim();

    window.dispatchEvent(
        new CustomEvent('seo-editor-text-selection', {
            detail: {
                hasSelection: open && Boolean(trimmedText),
                text: trimmedText,
                html: trimmedHtml,
                articleId,
                activeBlockId: open ? (activeBlockId ?? '') : '',
            },
        }),
    );
}

function isSameTiptapBlockContent(sourceHtml, currentHtml, nextHtml) {
    return (
        persistBlockHtmlFromEditor(sourceHtml, currentHtml) ===
        persistBlockHtmlFromEditor(sourceHtml, nextHtml)
    );
}

function ActiveBlockEditor({
    block,
    displayContent,
    suppressBlockUpdate,
    onUpdate,
    onRegisterFlush,
    onRegisterEditor,
    setGlobalEditor,
    onDelete,
    canDeleteBlock,
    articleId,
    siteId,
}) {
    const [linkAnchor, setLinkAnchor] = useState(null);
    const sourceHtml = displayContent ?? block.content;
    const isHydratingRef = useRef(false);
    const { minHeight, setMinHeight, persistHeight, minH, maxH } = useBlockEditorHeight(block.id);

    const pushHtml = useCallback(
        (html) => {
            if (suppressBlockUpdate || isHydratingRef.current) return;
            onUpdate(persistBlockHtmlFromEditor(sourceHtml, html));
        },
        [suppressBlockUpdate, onUpdate, sourceHtml],
    );

    const clipboardPasteHandler = useCallback(
        createClipboardPasteHandler({
            articleId,
            siteId,
            defaultAltTitle: (window.__SEO_MAIN_KEYWORD__ ?? '').trim(),
        }),
        [articleId, siteId],
    );

    const editor = useEditor({
        extensions: articleEditorExtensions,
        content: '',
        onUpdate: ({ editor: ed }) => {
            pushHtml(ed.getHTML());
        },
        onFocus: ({ editor: ed }) => {
            setGlobalEditor(ed);
        },
        editorProps: {
            attributes: {
                class: 'prose prose-slate max-w-none dark:prose-invert min-h-[48px] focus:outline-none tiptap-editor-content',
                'data-placeholder': t('editor_enter_content'),
            },
            handlePaste: clipboardPasteHandler,
        },
    });

    useEffect(() => {
        if (!editor) return;

        const nextHtml = ensureTiptapHeadingCursorParagraph(sourceHtml) || '<p></p>';
        // Khi user đang gõ, parent state đổi theo từng key stroke. Nếu hydrate lại
        // bằng setContent dù HTML tương đương, Tiptap sẽ reset selection/caret về cuối đoạn.
        if (isSameTiptapBlockContent(sourceHtml, editor.getHTML(), nextHtml)) {
            return;
        }

        isHydratingRef.current = true;
        editor.commands.setContent(nextHtml, {
            emitUpdate: false,
        });
        isHydratingRef.current = false;
    }, [editor, block.id, sourceHtml]);

    useEffect(() => {
        if (!editor) {
            return undefined;
        }

        onRegisterEditor?.(editor);

        return () => {
            onRegisterEditor?.(null);
        };
    }, [editor, onRegisterEditor]);

    useEffect(() => {
        if (!onRegisterFlush) return undefined;

        onRegisterFlush(() => {
            if (!editor || editor.isDestroyed) return;
            pushHtml(editor.getHTML());
        });

        return () => onRegisterFlush(null);
    }, [editor, onRegisterFlush, pushHtml]);

    useEffect(() => {
        if (editor) {
            editor.commands.focus();
            setGlobalEditor(editor);
        }
    }, [editor, setGlobalEditor]);

    useEffect(() => {
        if (!editor) {
            return undefined;
        }

        const publishIntraSelection = () => {
            const text = getSelectionTextFromEditor(editor);
            const html = getSelectionHtmlFromEditor(editor);

            window.dispatchEvent(
                new CustomEvent('seo-editor-intra-selection', {
                    detail: { text, html },
                }),
            );
        };

        editor.on('selectionUpdate', publishIntraSelection);
        publishIntraSelection();

        return () => {
            editor.off('selectionUpdate', publishIntraSelection);
        };
    }, [editor]);

    const openLinkEditorAtSelection = useCallback(() => {
        if (!editor) return;
        if (editor.isActive('link')) {
            editor.chain().focus().extendMarkRange('link').run();
            const { from } = editor.state.selection;
            const domAt = editor.view.domAtPos(from);
            const el = domAt.node instanceof Element ? domAt.node : domAt.node?.parentElement;
            const anchor = el?.closest?.('a');
            if (anchor) {
                setLinkAnchor(anchor.getBoundingClientRect());
                return;
            }
        }
        const { from } = editor.state.selection;
        const coords = editor.view.coordsAtPos(from);
        setLinkAnchor({
            top: coords.top,
            left: coords.left,
            right: coords.right,
            bottom: coords.bottom,
        });
    }, [editor]);

    useEffect(() => {
        if (!editor) return undefined;

        const onLinkClick = (event) => {
            const link = event.target?.closest?.('a');
            if (!link || !editor.view.dom.contains(link)) return;

            event.preventDefault();
            event.stopPropagation();

            const start = editor.view.posAtDOM(link, 0);
            const end = editor.view.posAtDOM(link, link.childNodes.length);
            editor.chain().focus().setTextSelection({ from: start, to: end }).run();
            setLinkAnchor(link.getBoundingClientRect());
        };

        editor.view.dom.addEventListener('click', onLinkClick, true);
        return () => editor.view.dom.removeEventListener('click', onLinkClick, true);
    }, [editor]);

    return (
        <div className="block-editor-active">
            <span className="block-editor-badge">
                {suppressBlockUpdate ? t('editor_temp_merge') : block.isWp ? 'WP Block' : t('editor_paragraph')}
            </span>
            <BlockFormatToolbar
                editor={editor}
                onDelete={onDelete}
                canDelete={canDeleteBlock}
                onEditLink={openLinkEditorAtSelection}
            />
            <div className="seo-block-editor-body px-2 pb-2" style={{ minHeight }}>
                <EditorContent editor={editor} />
            </div>
            <BlockEditorResizeHandle
                minHeight={minHeight}
                minH={minH}
                maxH={maxH}
                onMinHeightChange={setMinHeight}
                onResizeEnd={persistHeight}
            />
            {linkAnchor && editor ? (
                <LinkEditBubble editor={editor} anchorRect={linkAnchor} onClose={() => setLinkAnchor(null)} />
            ) : null}
        </div>
    );
}

function SectionHeaderTitle({ sectionNumber, title, onSave, onFocusOutline, autoEditToken = 0 }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(title);
    const inputRef = useRef(null);
    const clickTimerRef = useRef(null);

    useEffect(
        () => () => {
            if (clickTimerRef.current) {
                window.clearTimeout(clickTimerRef.current);
            }
        },
        [],
    );

    useEffect(() => {
        if (!autoEditToken) {
            return;
        }

        setEditing(true);
    }, [autoEditToken]);

    useEffect(() => {
        if (!editing) {
            setDraft(title);
        }
    }, [title, editing]);

    useEffect(() => {
        if (!editing || !inputRef.current) {
            return;
        }

        inputRef.current.focus();
        inputRef.current.select();
    }, [editing]);

    const commit = useCallback(() => {
        const next = draft.replace(/\s+/g, ' ').trim();
        setEditing(false);

        if (next === '' || next === title) {
            setDraft(title);
            return;
        }

        onSave?.(next);
    }, [draft, onSave, title]);

    const handleTitleClick = useCallback(
        (event) => {
            event.stopPropagation();
            if (editing) {
                return;
            }

            if (clickTimerRef.current) {
                window.clearTimeout(clickTimerRef.current);
            }

            clickTimerRef.current = window.setTimeout(() => {
                clickTimerRef.current = null;
                onFocusOutline?.();
            }, 220);
        },
        [editing, onFocusOutline],
    );

    const handleTitleDoubleClick = useCallback((event) => {
        event.stopPropagation();
        if (clickTimerRef.current) {
            window.clearTimeout(clickTimerRef.current);
            clickTimerRef.current = null;
        }
        setEditing(true);
    }, []);

    return (
        <h3 className="seo-section-header-title min-w-0 truncate text-sm font-semibold text-gray-700 dark:text-gray-200">
            <span
                className="seo-section-header-title__prefix cursor-pointer"
                onClick={handleTitleClick}
            >
                {`Section ${sectionNumber}: `}
            </span>
            {editing ? (
                <input
                    ref={inputRef}
                    type="text"
                    className="seo-section-header-title__input"
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    onBlur={commit}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            commit();
                        }
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            setDraft(title);
                            setEditing(false);
                        }
                    }}
                    onClick={(event) => event.stopPropagation()}
                />
            ) : (
                <span
                    className="seo-section-header-title__text"
                    onClick={handleTitleClick}
                    onDoubleClick={handleTitleDoubleClick}
                    title={t('editor_section_title_edit_hint')}
                >
                    {title}
                </span>
            )}
        </h3>
    );
}

function OutlineLockedHeadingBlock({ block, isSectionHeading = false, onActivate, onOutlineHeadingCommand }) {
    const lockedClickTimerRef = useRef(null);

    useEffect(
        () => () => {
            if (lockedClickTimerRef.current) {
                window.clearTimeout(lockedClickTimerRef.current);
            }
        },
        [],
    );

    const dispatchOutlineCommand = useCallback(
        (action, event) => {
            event.preventDefault();
            event.stopPropagation();
            onOutlineHeadingCommand?.(action, block);
        },
        [block, onOutlineHeadingCommand],
    );

    const sharedProps = {
        onClick: (event) => {
            if (lockedClickTimerRef.current) {
                window.clearTimeout(lockedClickTimerRef.current);
            }
            lockedClickTimerRef.current = window.setTimeout(() => {
                lockedClickTimerRef.current = null;
                onActivate?.();
                dispatchOutlineCommand('focus', event);
            }, 220);
        },
        onDoubleClick: (event) => {
            if (lockedClickTimerRef.current) {
                window.clearTimeout(lockedClickTimerRef.current);
                lockedClickTimerRef.current = null;
            }
            onActivate?.();
            dispatchOutlineCommand('edit', event);
        },
        onKeyDown: (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                dispatchOutlineCommand('focus', e);
            }
        },
        role: 'button',
        tabIndex: 0,
        title: 'Click: focus Outline · Double-click: sửa trong Outline',
    };

    if (isSectionHeading) {
        return (
            <div
                className="seo-block-preview seo-block-preview--outline-locked seo-block-preview--section-heading-only rounded border p-3 -mx-1"
                {...sharedProps}
            >
                <p className="seo-section-heading-locked-hint">{t('editor_section_heading_outline_hint')}</p>
            </div>
        );
    }

    return (
        <div
            className="seo-block-preview seo-block-preview--outline-locked seo-wp-content p-3 -mx-1 rounded border prose prose-slate max-w-none dark:prose-invert"
            dangerouslySetInnerHTML={{
                __html: block.content || `<p class="text-gray-400 italic">${t('editor_click_to_edit')}</p>`,
            }}
            {...sharedProps}
        />
    );
}

function BlockEditor({
    block,
    isActive,
    isHiddenInMerge,
    canShiftMerge,
    onActivate,
    onShiftMerge,
    displayContent,
    suppressBlockUpdate,
    onUpdate,
    onRegisterFlush,
    onRegisterEditor,
    setGlobalEditor,
    onDelete,
    canDeleteBlock,
    articleId,
    siteId,
    supportsProductGallery = false,
    panelFaqs,
    introImagesLocked = false,
    outlineHeadingsLocked = false,
    isSectionHeadingBlock = false,
    onOutlineHeadingCommand,
}) {
    const blockHtml = displayContent ?? block.content;
    const isFaqShortcodeBlock = block.type === 'text' && isFaqPlaceholderHtml(blockHtml);
    const isOutlineHeadingLocked = outlineHeadingsLocked && blockHasOutlineHeading(block);

    if (block.type === 'image') {
        return (
            <ImageBlockEditor
                block={block}
                isActive={isActive}
                isHiddenInMerge={isHiddenInMerge}
                canShiftMerge={canShiftMerge}
                onActivate={onActivate}
                onShiftMerge={onShiftMerge}
                onUpdate={onUpdate}
                onDelete={onDelete}
                canDeleteBlock={canDeleteBlock}
                articleId={articleId}
                siteId={siteId}
                supportsProductGallery={supportsProductGallery}
                imagesLocked={introImagesLocked}
            />
        );
    }

    const handlePreviewClick = (e) => {
        if (e.target.closest('a')) {
            e.preventDefault();
        }
        if (e.target.closest('figure, img, .wp-block-image')) {
            e.preventDefault();
        }
        if (e.shiftKey && canShiftMerge) {
            e.preventDefault();
            e.stopPropagation();
            onShiftMerge(block.id);
            return;
        }
        onActivate();
    };

    if (isHiddenInMerge) {
        return null;
    }

    if (isFaqShortcodeBlock) {
        return (
            <div className={`seo-faq-shortcode-block${isActive ? ' is-active' : ''}`}>
                {isActive ? (
                    <div className="seo-block-toolbar seo-faq-shortcode-toolbar">
                        <span className="block-editor-badge">FAQ Shortcode</span>
                        <button
                            type="button"
                            className={`seo-toolbar-btn seo-toolbar-delete${!canDeleteBlock ? ' is-disabled' : ''}`}
                            disabled={!canDeleteBlock}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={(e) => {
                                e.stopPropagation();
                                if (canDeleteBlock) {
                                    onDelete?.();
                                }
                            }}
                            title={
                                canDeleteBlock
                                    ? t('toolbar_delete_paragraph')
                                    : t('toolbar_cannot_delete_last')
                            }
                        >
                            <Trash2 size={16} />
                        </button>
                    </div>
                ) : null}
                <div
                    className="seo-faq-shortcode-block__body"
                    onClick={onActivate}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            onActivate();
                        }
                    }}
                    role="button"
                    tabIndex={0}
                    title={t('editor_faq_shortcode_hint')}
                >
                    <FaqAccordionPreview faqs={panelFaqs} />
                </div>
            </div>
        );
    }

    if (isOutlineHeadingLocked) {
        return (
            <OutlineLockedHeadingBlock
                block={block}
                isSectionHeading={isSectionHeadingBlock}
                onActivate={onActivate}
                onOutlineHeadingCommand={onOutlineHeadingCommand}
            />
        );
    }

    if (!isActive) {
        return (
            <div
                className="seo-block-preview seo-wp-content p-3 -mx-1 rounded border border-transparent hover:border-gray-200 dark:hover:border-slate-600 hover:bg-gray-50/80 dark:hover:bg-slate-800/40 transition-all cursor-text prose prose-slate max-w-none dark:prose-invert"
                dangerouslySetInnerHTML={{
                    __html: block.content || `<p class="text-gray-400 italic">${t('editor_click_to_edit')}</p>`,
                }}
                onClick={handlePreviewClick}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        if (e.shiftKey && canShiftMerge) {
                            e.preventDefault();
                            onShiftMerge(block.id);
                        } else {
                            onActivate();
                        }
                    }
                }}
                role="button"
                tabIndex={0}
                title={
                    canShiftMerge
                        ? t('editor_click_to_edit_shift_merge')
                        : t('editor_click_to_edit_paragraph')
                }
            />
        );
    }

    return (
        <ActiveBlockEditor
            key={`${block.id}-${suppressBlockUpdate ? 'merge' : 'edit'}`}
            block={block}
            displayContent={displayContent}
            suppressBlockUpdate={suppressBlockUpdate}
            onUpdate={onUpdate}
            onRegisterFlush={onRegisterFlush}
            onRegisterEditor={onRegisterEditor}
            setGlobalEditor={setGlobalEditor}
            onDelete={onDelete}
            canDeleteBlock={canDeleteBlock}
        />
    );
}

const BASE_TABS = [
    { id: 'editor', label: 'Editor' },
    { id: 'images', label: t('image_block_label') },
    { id: 'seo', label: 'SEO point' },
];

const REVIEWS_TAB = { id: 'reviews', label: t('reviews_tab_label') };

export default function SeoArticleEditor({
    articleId,
    siteId = null,
    initialHtml,
    initialSeo,
    initialPostImages = [],
    initialSupplementalImages = [],
    initialPostType = '',
    contentRevision = '',
    supportsProductGallery = false,
    productCategoryOptions = [],
    initialProductGallery = [],
    initialFaqs = [],
    initialVirtualReviews = [],
    articleTitle = '',
    editorSettings = {},
    mediaPickerUrl = '',
    initialLoaiSanPham = '',
    initialGalleryDescription = '',
}) {
    const historyStep = editorSettings?.history_step ?? 20;

    useEffect(() => {
        window.__SEO_ARTICLE_MEDIA_PICKER_ENDPOINT__ = mediaPickerUrl;

        return () => {
            delete window.__SEO_ARTICLE_MEDIA_PICKER_ENDPOINT__;
        };
    }, [mediaPickerUrl]);

    const [blocks, setBlocks] = useState([]);
    const [activeBlockId, setActiveBlockId] = useState(null);
    const [tempMerge, setTempMerge] = useState(null);
    const [globalEditor, setGlobalEditor] = useState(null);
    const [activeTab, setActiveTab] = useState('editor');
    const outlineRailRef = useRef(null);
    const [virtualReviews, setVirtualReviews] = useState(() =>
        Array.isArray(initialVirtualReviews) ? initialVirtualReviews : [],
    );
    const isProductPost = String(initialPostType ?? '').trim() === 'product' || Boolean(supportsProductGallery);
    const showReviewsTab = editorSettings?.show_reviews_tab !== false;
    const canGenerateFeaturedSnippet = editorSettings?.can_generate_featured_snippet === true;
    const canGenerateOutlineHeading = editorSettings?.can_generate_outline_heading === true;
    const editorTabs = useMemo(() => {
        if (!isProductPost || !showReviewsTab) {
            return BASE_TABS;
        }

        const tabs = [...BASE_TABS];
        tabs.splice(2, 0, REVIEWS_TAB);

        return tabs;
    }, [isProductPost, showReviewsTab]);

    useEffect(() => {
        if (activeTab === 'reviews' && !showReviewsTab) {
            setActiveTab('editor');
        }
    }, [activeTab, showReviewsTab]);

    useEffect(() => {
        const onReviewsUpdated = (event) => {
            const detail = event?.detail ?? {};
            const next = detail.reviews ?? detail.params?.reviews;
            if (Array.isArray(next)) {
                setVirtualReviews(next);
            }
        };

        window.addEventListener('virtual-reviews-updated', onReviewsUpdated);

        return () => window.removeEventListener('virtual-reviews-updated', onReviewsUpdated);
    }, []);

    const [saveStatus, setSaveStatus] = useState('saved');
    const activeTabRef = useRef('editor');
    const [analyzing, setAnalyzing] = useState(false);
    const [imageRenameBusy, setImageRenameBusy] = useState(false);
    const [imageRenameBusyCount, setImageRenameBusyCount] = useState(0);
    const [quickFixSlugAllBusy, setQuickFixSlugAllBusy] = useState(false);
    const [imagesReloadKey, setImagesReloadKey] = useState(0);
    const [imagesTabJumpTarget, setImagesTabJumpTarget] = useState(null);
    const [outlineHasSavedHeadings, setOutlineHasSavedHeadings] = useState(false);
    const [outlineHeadingCommand, setOutlineHeadingCommand] = useState(null);
    const [outlineTreeSync, setOutlineTreeSync] = useState(null);
    const [sectionTitleEditRequest, setSectionTitleEditRequest] = useState(null);
    const [outlineHeadingKeys, setOutlineHeadingKeys] = useState(() => new Set());
    const outlineHeadingIdsByBlockIdRef = useRef(new Map());
    const outlineHeadingIdsByKeyRef = useRef(new Map());
    const outlineAppendInflightRef = useRef(new Set());
    const outlineAppendDoneRef = useRef(new Set());
    const [insertMenu, setInsertMenu] = useState(null);
    const [collapsedSectionIds, setCollapsedSectionIds] = useState({});
    const [supplementalImages, setSupplementalImages] = useState(() =>
        Array.isArray(initialSupplementalImages) ? initialSupplementalImages : [],
    );
    const [postImages, setPostImages] = useState(() =>
        Array.isArray(initialPostImages) ? initialPostImages : [],
    );
    const postImagesRef = useRef(postImages);
    postImagesRef.current = postImages;
    const [quickReplaceFind, setQuickReplaceFind] = useState('');
    const [quickReplaceValue, setQuickReplaceValue] = useState('');
    const [editorSearchMatchCount, setEditorSearchMatchCount] = useState(null);
    const [panelFaqs, setPanelFaqs] = useState(Array.isArray(initialFaqs) ? initialFaqs : []);
    const pendingQuickFixKeywordRef = useRef('');
    const generateImageTargetRef = useRef('editor');
    const [generateImageModalOpen, setGenerateImageModalOpen] = useState(false);
    const [generateImageModalPrompt, setGenerateImageModalPrompt] = useState('');
    const [generateImageModalTarget, setGenerateImageModalTarget] = useState('editor');
    const [generateImageModalInitialCustom, setGenerateImageModalInitialCustom] = useState('');
    const [featuredSnippetGenerating, setFeaturedSnippetGenerating] = useState(false);
    const featuredSnippetTargetRef = useRef(null);

    useEffect(() => {
        if (!articleId) {
            return undefined;
        }

        let cancelled = false;

        const loadOutlineStatus = async () => {
            try {
                const response = await fetch(`/api/seo/articles/${articleId}/outline`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await response.json().catch(() => ({}));
                if (cancelled || !response.ok || data.success === false) {
                    return;
                }

                setOutlineHasSavedHeadings(Array.isArray(data.outline) && data.outline.length > 0);
            } catch {
                // Không khóa editor nếu không đọc được trạng thái outline.
            }
        };

        void loadOutlineStatus();

        return () => {
            cancelled = true;
        };
    }, [articleId]);

    const parseGalleryUrlList = useCallback((items) => {
        if (!Array.isArray(items)) {
            return [];
        }

        return items
            .map((item) => {
                if (typeof item === 'string') {
                    return item.trim();
                }
                return String(item?.url ?? item?.src ?? '').trim();
            })
            .filter(Boolean);
    }, []);

    const [productGalleryUrls, setProductGalleryUrls] = useState(() => parseGalleryUrlList(initialProductGallery));

    useEffect(() => {
        const onGalleryUpdated = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const gallery = detail.gallery;
            if (!Array.isArray(gallery)) {
                return;
            }
            setProductGalleryUrls(parseGalleryUrlList(gallery));
        };

        window.addEventListener('seo-product-gallery-updated', onGalleryUpdated);

        return () => window.removeEventListener('seo-product-gallery-updated', onGalleryUpdated);
    }, [parseGalleryUrlList]);

    const [siteDomain] = useState(() => String(initialSeo?.site_domain ?? '').trim());
    const [focusKeyword, setFocusKeyword] = useState(initialSeo?.focus_keyword ?? null);
    const [analysis, setAnalysis] = useState(initialSeo?.analysis ?? null);
    const [contentBonus, setContentBonus] = useState(
        initialSeo?.content_bonus ?? initialSeo?.analysis?.content_bonus ?? null,
    );
    const [extractedLinks, setExtractedLinks] = useState(
        initialSeo?.extracted_links ?? { internal: [], external: [] },
    );
    const [suggestedInternalLinks, setSuggestedInternalLinks] = useState(() =>
        filterSuggestedInternalLinks(
            initialSeo?.suggested_internal_links ?? [],
            initialSeo?.extracted_links?.internal ?? [],
        ),
    );

    const mainKeyword = useMemo(() => {
        const fromFocus = String(focusKeyword ?? '').trim();
        if (fromFocus) {
            return fromFocus;
        }
        return String(articleTitle ?? '').trim();
    }, [focusKeyword, articleTitle]);

    useEffect(() => {
        // Dùng cho clipboard paste handler (tiptap) và các luồng insert ảnh.
        window.__SEO_MAIN_KEYWORD__ = mainKeyword;
        return () => {
            if (window.__SEO_MAIN_KEYWORD__ === mainKeyword) {
                delete window.__SEO_MAIN_KEYWORD__;
            }
        };
    }, [mainKeyword]);

    useEffect(() => {
        if (activeTab === 'auto-image') {
            setActiveTab('editor');
        }
    }, []);

    useEffect(() => {
        const onOpenGenerateImageModal = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const target = String(detail.target ?? 'editor').trim() || 'editor';
            generateImageTargetRef.current = target;
            setGenerateImageModalTarget(target);

            const preset = String(detail.prompt ?? detail.userBrief ?? '').trim();
            if (target === 'product-gallery') {
                // Custom field ← loai_san_pham, Image prompt ← gallery_description (nếu có)
                setGenerateImageModalInitialCustom(
                    String(detail.loaiSanPhamCustom ?? '').trim() || initialLoaiSanPham,
                );
                const galleryPrompt = String(detail.prompt ?? '').trim()
                    || initialGalleryDescription
                    || mainKeyword;
                setGenerateImageModalPrompt(galleryPrompt);
            } else {
                setGenerateImageModalInitialCustom('');
                setGenerateImageModalPrompt(preset || '');
            }
            setGenerateImageModalOpen(true);
        };

        window.addEventListener('seo-open-generate-image-modal', onOpenGenerateImageModal);

        return () => {
            window.removeEventListener('seo-open-generate-image-modal', onOpenGenerateImageModal);
        };
    }, [mainKeyword, initialLoaiSanPham, initialGalleryDescription]);

    useEffect(() => {
        setArticleAutosaveLock('generate-image-modal', generateImageModalOpen);

        return () => setArticleAutosaveLock('generate-image-modal', false);
    }, [generateImageModalOpen]);

    const submitGenerateImageFromModal = useCallback(
        (payload) => {
            const normalized = payload != null && typeof payload === 'object'
                ? payload
                : { userBrief: String(payload ?? '') };
            const userBrief = String(normalized.userBrief ?? '').trim();
            const target = generateImageTargetRef.current || 'editor';
            const galleryBlockId = target === 'product-gallery' ? 'product-gallery' : '';

            window.dispatchEvent(
                new CustomEvent('generate-article-image', {
                    detail: {
                        selectionText: '',
                        selectionHtml: '',
                        userBrief,
                        activeBlockId: galleryBlockId,
                        target,
                        loaiSanPhamCategoryArticleId: Number.parseInt(
                            String(normalized.loaiSanPhamCategoryArticleId ?? 0),
                            10,
                        ) || 0,
                        loaiSanPhamCustom: String(
                            normalized.loaiSanPhamCustom ?? normalized.userBrief ?? '',
                        ).trim(),
                    },
                }),
            );

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('generate_image'),
                        body: t('generating_image'),
                        status: 'success',
                    },
                }),
            );
        },
        [],
    );

    const enrichLinksWithOccurrences = useCallback((links) => {
        const source = links && typeof links === 'object' ? links : { internal: [], external: [] };
        const currentBlocks = blocksRef.current;

        const buildKey = (item) =>
            `${String(item?.href ?? '').trim()}\u0000${String(item?.text ?? '').trim()}`;

        const countCache = new Map();
        const withCounts = (items) =>
            (Array.isArray(items) ? items : [])
                .map((item) => {
                    const key = buildKey(item);
                    if (!countCache.has(key)) {
                        let count = 0;
                        for (const block of currentBlocks) {
                            if (block.type === 'image' || !block.content) {
                                continue;
                            }
                            count += countMatchingAnchorsInHtml(
                                block.content,
                                String(item?.text ?? ''),
                                String(item?.href ?? ''),
                            );
                        }
                        countCache.set(key, count);
                    }

                    const occurrenceCount = countCache.get(key) ?? 0;
                    if (occurrenceCount <= 0) {
                        return null;
                    }

                    return {
                        ...item,
                        occurrence_count: occurrenceCount,
                    };
                })
                .filter(Boolean);

        return {
            internal: withCounts(source.internal),
            external: withCounts(source.external),
        };
    }, []);

    const publishExtractedLinks = useCallback((links, suggestedInternal = suggestedInternalLinks) => {
        const enrichedLinks = enrichLinksWithOccurrences(links);
        const filteredSuggested = filterSuggestedInternalLinks(
            suggestedInternal,
            enrichedLinks.internal ?? [],
        );
        const articlePlainText = htmlToPlainText(exportBlocksToHtml(blocksRef.current));
        window.dispatchEvent(
            new CustomEvent('seo-editor-links-updated', {
                detail: {
                    links: enrichedLinks,
                    suggested_internal: filteredSuggested,
                    article_plain_text: articlePlainText,
                },
            }),
        );
    }, [suggestedInternalLinks, enrichLinksWithOccurrences]);

    const blocksRef = useRef(blocks);
    blocksRef.current = blocks;

    const blockById = useMemo(() => {
        const map = new Map();
        for (const block of blocks) {
            map.set(block.id, block);
        }

        return map;
    }, [blocks]);

    const blockIndexMap = useMemo(() => {
        const map = new Map();
        blocks.forEach((block, index) => map.set(block.id, index));

        return map;
    }, [blocks]);

    const editorSections = useMemo(() => buildEditorSections(blocks), [blocks]);

    const sectionByBlockId = useMemo(() => {
        const map = new Map();
        for (const section of editorSections) {
            for (const blockId of section.blockIds) {
                map.set(blockId, section.id);
            }
        }

        return map;
    }, [editorSections]);

    /** Block đầu mỗi section (H2 section) — luôn khóa TipTap, chỉ sửa qua Outline. */
    const sectionHeadingBlockIds = useMemo(() => {
        const ids = new Set();
        for (const section of editorSections) {
            if (section.isIntro || !section.blockIds?.length) {
                continue;
            }
            ids.add(section.blockIds[0]);
        }

        return ids;
    }, [editorSections]);

    const isIntroBlockId = useCallback(
        (blockId) => sectionByBlockId.get(String(blockId ?? '').trim()) === INTRO_SECTION_ID,
        [sectionByBlockId],
    );

    const notifyIntroNoImages = useCallback(() => {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('editor_intro'),
                    body: t('editor_intro_no_images'),
                    status: 'warning',
                },
            }),
        );
    }, []);

    const sectionStats = useMemo(
        () => buildSectionStats(editorSections, blockById),
        [editorSections, blockById],
    );
    const totalWordCount = useMemo(
        () => editorSections.reduce((sum, section) => sum + (sectionStats.get(section.id)?.wordCount ?? 0), 0),
        [editorSections, sectionStats],
    );
    const imageTabCount = useMemo(() => {
        const normalizedRows = [
            ...collectImagesFromBlocks(blocks),
            ...(Array.isArray(supplementalImages) ? supplementalImages : []).map((row) => ({
                wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0) || null,
                seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || null,
                src: String(row?.src || '').trim(),
                wpSrc: String(row?.wpSrc || row?.wp_url || '').trim(),
                localSrc: String(row?.localSrc || row?.local_src || '').trim(),
            })),
        ];

        const merged = [];
        normalizedRows.forEach((row) => {
            const primarySrc = String(row?.src || row?.wpSrc || row?.localSrc || '').trim();
            const srcKey = normalizeImageSrcKey(primarySrc);
            const wpId = Number(row?.wpAttachmentId ?? 0);
            const seoId = Number(row?.seoMediaId ?? 0);

            const existingIndex = merged.findIndex((existing) => {
                const existingWpId = Number(existing?.wpAttachmentId ?? 0);
                if (wpId > 0 && existingWpId > 0 && existingWpId === wpId) {
                    return true;
                }

                const existingSeoId = Number(existing?.seoMediaId ?? 0);
                if (seoId > 0 && existingSeoId > 0 && existingSeoId === seoId) {
                    return true;
                }

                if (srcKey) {
                    const existingSrcKey = normalizeImageSrcKey(
                        String(existing?.src || existing?.wpSrc || existing?.localSrc || ''),
                    );
                    if (existingSrcKey && existingSrcKey === srcKey) {
                        return true;
                    }
                }

                return false;
            });

            if (existingIndex === -1) {
                merged.push({
                    wpAttachmentId: wpId || null,
                    seoMediaId: seoId || null,
                    src: String(row?.src || '').trim(),
                    wpSrc: String(row?.wpSrc || '').trim(),
                    localSrc: String(row?.localSrc || '').trim(),
                });
            }
        });

        return merged.length;
    }, [blocks, supplementalImages]);

    const tempMergeRef = useRef(tempMerge);
    tempMergeRef.current = tempMerge;
    const blockFlushRef = useRef(null);
    const activeBlockIdRef = useRef(null);
    const linkScrollTokenRef = useRef(0);
    const intraSelectionRef = useRef({ text: '', html: '' });
    const focusedOutlineHeadingRef = useRef(null);
    const globalEditorRef = useRef(null);
    const blockEditorsRef = useRef(new Map());
    const pendingAiMediaRef = useRef(new Map());
    const mediaPollTimersRef = useRef(new Map());

    useEffect(() => {
        activeBlockIdRef.current = activeBlockId;
    }, [activeBlockId]);

    useEffect(() => {
        globalEditorRef.current = globalEditor;
    }, [globalEditor]);

    const getExportHtml = useCallback(() => exportBlocksToHtml(blocksRef.current), []);

    const requestAnalyze = useCallback(() => {
        window.dispatchEvent(
            new CustomEvent('seo-analyze-draft', {
                detail: { html: getExportHtml() },
            }),
        );
    }, [getExportHtml]);

    const { debounced: debouncedLocalSave } = useDebouncedCallback(() => {
        if (!articleId) return;
        setSaveStatus('saving');
        saveDraft(articleId, {
            blocks: blocksRef.current,
            html: getExportHtml(),
        });
        setSaveStatus('saved');
    }, 2000);

    const { debounced: debouncedAnalyze } = useDebouncedCallback(() => {
        setAnalyzing(true);
        requestAnalyze();
    }, 2000);

    const scheduleAutosave = useCallback(() => {
        setSaveStatus('pending');
        debouncedLocalSave();
        debouncedAnalyze();
    }, [debouncedLocalSave, debouncedAnalyze]);

    const skipNextAutosave = useRef(true);
    const loadedArticleIdRef = useRef(null);

    const clearTempMerge = useCallback(() => {
        setTempMerge(null);
    }, []);

    const {
        undo,
        redo,
        canUndo,
        canRedo,
        historySteps,
        updateBlocksWithoutHistory,
    } = useArticleEditorHistory({
        articleId,
        historyStep,
        blocks,
        setBlocks,
        setActiveBlockId: (id) => {
            clearTempMerge();
            setActiveBlockId(id);
        },
        getExportHtml,
    });

    useEffect(() => {
        const isTypingTarget = (target) =>
            Boolean(
                target?.closest?.(
                    'input, textarea, [contenteditable="true"], [contenteditable=""], .ProseMirror',
                ),
            );

        const onWindowKeyDown = (event) => {
            const articleAction = articleShortcutActionFromEvent(event);
            if (articleAction) {
                event.preventDefault();
                if (articleAction === 'analyze') {
                    setAnalyzing(true);
                    requestAnalyze();
                } else {
                    window.dispatchEvent(
                        new CustomEvent('article-editor-shortcut', {
                            detail: { action: articleAction },
                        }),
                    );
                }
                return;
            }

            const mod = event.ctrlKey || event.metaKey;
            if (!mod || event.altKey || isTypingTarget(event.target)) {
                return;
            }

            const key = String(event.key || '').toLowerCase();
            if (key === 'z') {
                event.preventDefault();
                if (event.shiftKey) {
                    if (canRedo) {
                        redo();
                    }
                } else if (canUndo) {
                    undo();
                }
                return;
            }

            if (key === 'y') {
                event.preventDefault();
                if (canRedo) {
                    redo();
                }
            }
        };

        window.addEventListener('keydown', onWindowKeyDown, true);

        return () => {
            window.removeEventListener('keydown', onWindowKeyDown, true);
        };
    }, [undo, redo, canUndo, canRedo, requestAnalyze]);

    useEffect(() => {
        if (!articleId) return;
        if (loadedArticleIdRef.current === articleId) return;

        loadedArticleIdRef.current = articleId;
        skipNextAutosave.current = true;
        clearTempMerge();

        const draft = loadDraft(articleId);
        const serverRevision = String(contentRevision ?? '').trim();
        const draftRevision = String(draft?.contentRevision ?? '').trim();
        const draftParserVersion = Number(draft?.parserVersion ?? 0);
        const acceptsLegacyDraft =
            !requiresClassicInlineRegroup(initialHtml) ||
            draftParserVersion >= ARTICLE_EDITOR_DRAFT_VERSION;
        const canUseDraft =
            draft &&
            acceptsLegacyDraft &&
            (serverRevision === '' || draftRevision === serverRevision);
        let parsed = [];
        if (canUseDraft && draft?.blocks?.length) {
            parsed = normalizeBlocks(draft.blocks);
        } else if (canUseDraft && draft?.html) {
            parsed = parseHtmlToBlocks(stripLeadingH1FromHtml(draft.html));
        } else {
            parsed = parseHtmlToBlocks(stripLeadingH1FromHtml(initialHtml));
        }
        let nextBlocks = enrichBlocksWithPostImages(parsed, postImagesRef.current);
        setBlocks(nextBlocks);
        if (articleId && nextBlocks.length > 0) {
            saveDraft(articleId, {
                blocks: nextBlocks,
                html: exportBlocksToHtml(nextBlocks),
                contentRevision: serverRevision,
            });
        }

        setActiveBlockId(null);
        setGlobalEditor(null);
    }, [articleId, initialHtml, initialPostImages, contentRevision, clearTempMerge]);

    useEffect(() => {
        if (initialSeo) {
            setFocusKeyword(initialSeo.focus_keyword ?? null);
            setAnalysis(initialSeo.analysis ?? null);
            setContentBonus(initialSeo.content_bonus ?? initialSeo.analysis?.content_bonus ?? null);
            setExtractedLinks(initialSeo.extracted_links ?? { internal: [], external: [] });
            setSuggestedInternalLinks(
                filterSuggestedInternalLinks(
                    initialSeo.suggested_internal_links ?? [],
                    initialSeo.extracted_links?.internal ?? [],
                ),
            );
        }
    }, [initialSeo]);

    const updateBlockContent = useCallback((id, newContent, imageData) => {
        setBlocks((prev) =>
            prev.map((b) =>
                b.id === id
                    ? {
                          ...b,
                          content: newContent,
                          ...(imageData === null
                              ? { image: undefined }
                              : imageData
                                ? { image: imageData }
                                : {}),
                      }
                    : b,
            ),
        );
    }, []);

    const registerBlockFlush = useCallback((fn) => {
        blockFlushRef.current = fn;
    }, []);

    const registerBlockEditor = useCallback((blockId, editor) => {
        if (!blockId) {
            return;
        }

        if (editor) {
            blockEditorsRef.current.set(blockId, editor);
            return;
        }

        blockEditorsRef.current.delete(blockId);
    }, []);

    const resolveActiveEditor = useCallback(() => {
        const activeId = activeBlockIdRef.current;
        if (globalEditorRef.current && !globalEditorRef.current.isDestroyed) {
            return globalEditorRef.current;
        }

        if (!activeId) {
            return null;
        }

        const blockEditor = blockEditorsRef.current.get(activeId);
        if (blockEditor && !blockEditor.isDestroyed) {
            return blockEditor;
        }

        return null;
    }, []);

    const commitActiveBlock = useCallback(() => {
        if (tempMergeRef.current) return;
        blockFlushRef.current?.();
    }, []);

    const selectPlainTextInBlock = useCallback((blockId, text, occurrenceIndex = 0, onSelected) => {
        const maxAttempts = 30;

        const attempt = (attemptNo) => {
            const editor = blockEditorsRef.current.get(blockId);
            if (!editor || editor.isDestroyed) {
                if (attemptNo < maxAttempts) {
                    window.setTimeout(() => attempt(attemptNo + 1), 20);
                }
                return;
            }

            const match = findPlainTextRangeInRoot(editor.view.dom, text, occurrenceIndex);
            if (!match) {
                return;
            }

            const from = editor.view.posAtDOM(match.node, match.start);
            const to = editor.view.posAtDOM(match.endNode, match.endOffset);
            if (to <= from) {
                return;
            }

            editor.chain().focus().setTextSelection({ from, to }).run();
            const domAt = editor.view.domAtPos(from);
            const target =
                domAt.node instanceof Element
                    ? domAt.node
                    : domAt.node?.parentElement;
            target?.scrollIntoView?.({
                behavior: attemptNo === 0 ? 'smooth' : 'auto',
                block: 'center',
            });
            onSelected?.(editor);
        };

        attempt(0);
    }, []);

    const persistEditorContentImmediately = useCallback(
        (editor, blockId) => {
            const currentBlocks = blocksRef.current;
            const block = currentBlocks.find((item) => item.id === blockId);
            if (!block || !editor || editor.isDestroyed) {
                return;
            }

            const content = persistBlockHtmlFromEditor(block.content ?? '', editor.getHTML());
            const nextBlocks = currentBlocks.map((item) =>
                item.id === blockId ? { ...item, content } : item,
            );

            blocksRef.current = nextBlocks;
            setBlocks(nextBlocks);
            if (articleId) {
                saveDraft(articleId, {
                    blocks: nextBlocks,
                    html: exportBlocksToHtml(nextBlocks),
                });
                setSaveStatus('saved');
            }
        },
        [articleId],
    );

    const distributeProductGalleryImages = useCallback(() => {
        if (!supportsProductGallery) {
            return;
        }

        commitActiveBlock();

        const galleryRows = buildGallerySupplementalRows(supplementalImages, null, articleId);
        if (galleryRows.length === 0) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('product_gallery_distribute_none_title'),
                        body: t('product_gallery_distribute_no_images'),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        let inserted = 0;
        const result = distributeProductImagesToEmptySections(blocksRef.current, galleryRows);
        inserted = result.inserted;
        if (inserted > 0) {
            setBlocks(result.blocks);
            setActiveTab('editor');
            scheduleAutosave();
            requestAnalyze();
        }

        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: inserted > 0
                    ? {
                          title: t('product_gallery_distribute_success_title'),
                          body: t('product_gallery_distribute_success', { count: inserted }),
                          status: 'success',
                      }
                    : {
                          title: t('product_gallery_distribute_none_title'),
                          body: t('product_gallery_distribute_no_sections'),
                          status: 'warning',
                      },
            }),
        );
    }, [supportsProductGallery, supplementalImages, articleId, commitActiveBlock, requestAnalyze, scheduleAutosave]);

    useEffect(() => {
        const onDistributeGallery = () => {
            distributeProductGalleryImages();
        };

        window.addEventListener('seo-editor-distribute-product-gallery', onDistributeGallery);

        return () => {
            window.removeEventListener('seo-editor-distribute-product-gallery', onDistributeGallery);
        };
    }, [distributeProductGalleryImages]);

    const patchImageInBlocks = useCallback(
        (blockId, patch, withoutHistory = false) => {
            const updater = (prev) => applyImagePatchToBlocks(prev, blockId, patch);
            if (withoutHistory) {
                updateBlocksWithoutHistory(updater);
            } else {
                setBlocks(updater);
            }
        },
        [updateBlocksWithoutHistory],
    );

    const requestWordPressRenames = useCallback((items) => {
        dispatchWordPressSlugRename(items);
    }, []);

    const renameLocalMediaByUrl = useCallback(
        (mediaUrl, newSlug) => renameSeoMediaByUrl(mediaUrl, newSlug, { siteId, articleId }),
        [siteId, articleId],
    );

    const requestWordPressAttachmentMetaUpdate = useCallback((items) => {
        dispatchWordPressAttachmentMetaUpdate(items);
    }, []);

    const handleImageSlugChange = useCallback(
        (row, newSlug, applyPatch) => {
            const trimmed = newSlug.trim();
            if (!trimmed || trimmed === (row.slug || '').trim()) {
                return true;
            }

            if (row.wpAttachmentId && !confirmSlugRename({ count: 1 })) {
                return false;
            }

            if (row.wpAttachmentId) {
                pendingQuickFixKeywordRef.current = '';
                requestWordPressRenames([
                    {
                        attachment_id: row.wpAttachmentId,
                        new_slug: trimmed,
                        old_url: row.src,
                    },
                ]);

                return true;
            }

            if (row.seoMediaId) {
                renameSeoMedia(row.seoMediaId, trimmed)
                    .then((data) => {
                        applyPatch({
                            slug: data.slug,
                            src: data.url,
                        });
                    })
                    .catch((error) => {
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('editor_cannot_rename_image_slug'),
                                    body: error?.message ?? t('editor_try_again_later'),
                                    status: 'danger',
                                },
                            }),
                        );
                    });

                return true;
            }

            if (!row.wpAttachmentId && row.src && String(row.src).includes('/storage/uploads/seo_media/')) {
                const oldSlug = (row.slug || '').trim();
                renameLocalMediaByUrl(row.src, trimmed)
                    .then((data) => {
                        applyPatch({
                            slug: data.slug,
                            src: data.url,
                            seoMediaId: data.id ?? row.seoMediaId,
                            originalSlug: oldSlug,
                        });
                    })
                    .catch((error) => {
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('editor_cannot_rename_image_slug'),
                                    body: error?.message ?? t('editor_try_again_later'),
                                    status: 'danger',
                                },
                            }),
                        );
                    });

                return true;
            }

            applyPatch({ slug: trimmed });

            return true;
        },
        [renameLocalMediaByUrl, requestWordPressRenames],
    );

    const patchSupplementalImageRow = useCallback((targetRow, patch = {}) => {
        const targetWpId = Number(targetRow?.wpAttachmentId ?? targetRow?.wp_attachment_id ?? 0);
        const targetSeoId = Number(targetRow?.seoMediaId ?? targetRow?.seo_media_id ?? 0);
        const targetSrc = normalizeImageSrcKey(targetRow?.src);

        setSupplementalImages((prev) =>
            (Array.isArray(prev) ? prev : []).map((row) => {
                const rowWpId = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
                const rowSeoId = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
                const rowSrc = normalizeImageSrcKey(row?.src);
                const matched =
                    (targetWpId > 0 && rowWpId > 0 && targetWpId === rowWpId) ||
                    (targetSeoId > 0 && rowSeoId > 0 && targetSeoId === rowSeoId) ||
                    (targetSrc !== '' && rowSrc !== '' && targetSrc === rowSrc);

                if (!matched) {
                    return row;
                }

                const nextSrc = String(patch.src ?? row.src ?? '').trim();
                const isLocal = nextSrc.includes('/storage/uploads/seo_media/');
                return {
                    ...row,
                    ...patch,
                    src: nextSrc || row.src,
                    wp_url:
                        patch.wp_url ??
                        (isLocal ? String(row.wp_url ?? '') : nextSrc || String(row.wp_url ?? '')),
                    local_src:
                        patch.local_src ??
                        (isLocal ? nextSrc || String(row.local_src ?? '') : String(row.local_src ?? '')),
                };
            }),
        );
    }, []);

    const enrichSupplementalRow = useCallback((row, fallbackIndex = 0) => {
        return Number(row?.quickFixIndex ?? 0) > 0
            ? row
            : {
                  ...row,
                  quickFixIndex: Number(fallbackIndex ?? 0) > 0 ? Number(fallbackIndex) : 0,
              };
    }, []);

    const runSupplementalLocalRenames = useCallback(
        (localRenames, supplementalOnlyRows) => {
            if (!localRenames.length) {
                return Promise.resolve();
            }

            const supplementalLocalRenameKeys = new Set();
            const uniqueLocalRenames = [];
            localRenames.forEach((item) => {
                const localKey =
                    Number(item.seo_media_id ?? 0) > 0
                        ? `id:${Number(item.seo_media_id)}`
                        : `src:${normalizeImageSrcKey(item.src)}`;
                if (supplementalLocalRenameKeys.has(localKey)) {
                    return;
                }
                supplementalLocalRenameKeys.add(localKey);
                uniqueLocalRenames.push(item);
            });

            return (async () => {
                for (const item of uniqueLocalRenames) {
                    try {
                        const newSlug = String(item.new_slug ?? '').trim();
                        const src = String(item.src ?? '').trim();
                        const id = Number(item.seo_media_id ?? 0);
                        if (!newSlug || !src) {
                            continue;
                        }

                        const data =
                            id > 0 ? await renameSeoMedia(id, newSlug) : await renameLocalMediaByUrl(src, newSlug);

                        const matchedRow = supplementalOnlyRows.find((row) => {
                            const rowId = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
                            const rowSrc = normalizeImageSrcKey(row?.src);
                            return (
                                (id > 0 && rowId === id) ||
                                normalizeImageSrcKey(src) === rowSrc
                            );
                        });

                        if (matchedRow) {
                            patchSupplementalImageRow(matchedRow, {
                                slug: data.slug,
                                src: data.url,
                                seoMediaId: data.id ?? id,
                            });
                        }
                    } catch (error) {
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('editor_cannot_rename_image_slug'),
                                    body: error?.message ?? t('editor_try_again_later'),
                                    status: 'danger',
                                },
                            }),
                        );
                    }
                }
                setImagesReloadKey((k) => k + 1);
            })();
        },
        [patchSupplementalImageRow, renameLocalMediaByUrl],
    );

    const buildQuickFixContext = useCallback(
        (imageRows = null) => {
            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword) {
                return null;
            }

            const baseRows = (Array.isArray(imageRows) ? imageRows : supplementalImages ?? []).filter(
                (row) => !row?.excludeQuickFix,
            );
            const sourceRows = [];
            const seenRows = new Set();
            const appendSourceRow = (row) => {
                if (!row || row?.excludeQuickFix) {
                    return;
                }

                const key =
                    normalizeImageSrcKey(row?.src) ||
                    String(row?.blockId ?? row?.block_id ?? '').trim() ||
                    `row:${sourceRows.length}`;
                if (!key || seenRows.has(key)) {
                    return;
                }

                seenRows.add(key);
                sourceRows.push(row);
            };

            baseRows.forEach(appendSourceRow);
            buildGallerySupplementalRows(baseRows, null, articleId).forEach(appendSourceRow);

            const indexByBlockId = {};
            sourceRows.forEach((row) => {
                const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();
                const quickFixIndex = Number(row?.quickFixIndex ?? 0);
                if (blockId && quickFixIndex > 0) {
                    indexByBlockId[blockId] = quickFixIndex;
                }
            });

            const supplementalOnlyRows = sourceRows.filter(
                (row) => String(row?.blockId ?? row?.block_id ?? '').trim() === '',
            );

            return { keyword, sourceRows, indexByBlockId, supplementalOnlyRows };
        },
        [focusKeyword, articleTitle, supplementalImages, articleId],
    );

    const applyQuickFixSlugPreview = useCallback(
        (preview, keyword) => {
            const renameCount = preview.renameQueue.length;
            const localRenameCount = (preview.localRenameQueue ?? []).length;

            setBlocks(preview.blocks);
            pendingQuickFixKeywordRef.current = keyword;

            const tasks = [];

            if (renameCount > 0) {
                requestWordPressRenames(preview.renameQueue);
            } else if (localRenameCount === 0) {
                setImagesReloadKey((k) => k + 1);
            }

            if (localRenameCount > 0) {
                tasks.push(
                    (async () => {
                        for (const item of preview.localRenameQueue) {
                            try {
                                const newSlug = String(item.new_slug ?? '').trim();
                                const src = String(item.src ?? '').trim();
                                const blockId = String(item.block_id ?? '').trim();
                                const id = Number(item.seo_media_id ?? 0);

                                if (!newSlug || !src || !blockId) {
                                    continue;
                                }

                                const data = id > 0
                                    ? await renameSeoMedia(id, newSlug)
                                    : await renameLocalMediaByUrl(src, newSlug);

                                const oldSrc = normalizeImageSrcKey(src);
                                const resolvedSeoId = Number(data.id ?? id ?? 0);
                                setBlocks((prev) =>
                                    prev.map((block) => {
                                        if (block.type !== 'image') {
                                            return block;
                                        }

                                        const image = block.image ?? parseImageFromBlockContent(block.content);
                                        if (!image?.src) {
                                            return block;
                                        }

                                        const imageSeoId = Number(image.seoMediaId ?? image.seo_media_id ?? 0);
                                        const imageSrc = normalizeImageSrcKey(image.src);
                                        const matched =
                                            (resolvedSeoId > 0 && imageSeoId > 0 && imageSeoId === resolvedSeoId) ||
                                            (resolvedSeoId <= 0 && oldSrc !== '' && imageSrc === oldSrc);
                                        if (!matched) {
                                            return block;
                                        }

                                        const nextImage = {
                                            ...image,
                                            slug: data.slug,
                                            src: data.url,
                                            seoMediaId: resolvedSeoId > 0 ? resolvedSeoId : image.seoMediaId,
                                            originalSlug: item.old_slug ?? '',
                                        };

                                        return {
                                            ...block,
                                            image: nextImage,
                                            content: renderImageFigure(nextImage),
                                        };
                                    }),
                                );
                            } catch (error) {
                                window.dispatchEvent(
                                    new CustomEvent('seo-article-editor-notify', {
                                        detail: {
                                            title: t('editor_cannot_rename_local_image_slug'),
                                            body: error?.message ?? t('editor_try_again_later'),
                                            status: 'danger',
                                        },
                                    }),
                                );
                            }
                        }

                        setImagesReloadKey((k) => k + 1);
                    })(),
                );
            }

            return tasks.length > 0 ? Promise.all(tasks) : Promise.resolve();
        },
        [renameLocalMediaByUrl, requestWordPressRenames],
    );

    const waitForWordPressSlugRenameFinished = useCallback((batchCount = 1) => {
        const total = Number(batchCount);
        if (total <= 0) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            let remaining = total;

            const onFinished = () => {
                remaining -= 1;
                if (remaining > 0) {
                    return;
                }

                window.removeEventListener('seo-attachment-slugs-rename-finished', onFinished);
                resolve();
            };

            window.addEventListener('seo-attachment-slugs-rename-finished', onFinished);
        });
    }, []);

    const quickFixSlugAllImages = useCallback(
        async (imageRows = null) => {
            if (quickFixSlugAllBusy) {
                return;
            }

            const context = buildQuickFixContext(imageRows);
            if (!context) {
                return;
            }

            const { keyword, indexByBlockId, supplementalOnlyRows } = context;

            const supplementalOutcomes = supplementalOnlyRows.map((row, index) => ({
                row,
                outcome: computeQuickFixSlugSupplementalOutcome(
                    Number(row?.quickFixIndex ?? 0) > 0 ? row : { ...row, quickFixIndex: index + 1 },
                    keyword,
                ),
            }));

            const preview = applyQuickFixSlugToBlocks(blocksRef.current, keyword, indexByBlockId);
            const supplementalWpRenames = supplementalOutcomes
                .map(({ outcome }) => outcome.wpRename)
                .filter(Boolean);
            const supplementalLocalRenames = supplementalOutcomes
                .map(({ outcome }) => outcome.localRename)
                .filter(Boolean);

            const previewWpRenameCount = preview.renameQueue.length;
            const supplementalWpRenameCount = supplementalWpRenames.length;
            const totalWpRenames = previewWpRenameCount + supplementalWpRenameCount;
            const totalLocalRenames =
                (preview.localRenameQueue ?? []).length + supplementalLocalRenames.length;

            if (totalWpRenames > 0 && !confirmSlugRename({ count: totalWpRenames, isQuickFix: true })) {
                return;
            }

            if (totalWpRenames === 0 && totalLocalRenames === 0 && preview.applied === 0) {
                return;
            }

            setQuickFixSlugAllBusy(true);
            await new Promise((resolve) => {
                window.requestAnimationFrame(() => resolve());
            });

            try {
                const localTasks = [applyQuickFixSlugPreview(preview, keyword)];

                supplementalOutcomes.forEach(({ row, outcome }) => {
                    if (Object.keys(outcome.patch ?? {}).length > 0) {
                        patchSupplementalImageRow(row, outcome.patch);
                    }
                });

                let wpBatchCount = 0;
                if (previewWpRenameCount > 0) {
                    wpBatchCount += 1;
                }

                if (supplementalWpRenames.length > 0) {
                    wpBatchCount += 1;
                    requestWordPressRenames(supplementalWpRenames);
                }

                localTasks.push(runSupplementalLocalRenames(supplementalLocalRenames, supplementalOnlyRows));
                localTasks.push(waitForWordPressSlugRenameFinished(wpBatchCount));

                await Promise.all(localTasks);
            } finally {
                setQuickFixSlugAllBusy(false);
            }
        },
        [
            applyQuickFixSlugPreview,
            buildQuickFixContext,
            patchSupplementalImageRow,
            quickFixSlugAllBusy,
            requestWordPressRenames,
            runSupplementalLocalRenames,
            waitForWordPressSlugRenameFinished,
        ],
    );

    const quickFixAltTitleAllImages = useCallback(
        (imageRows = null) => {
            const context = buildQuickFixContext(imageRows);
            if (!context) {
                return;
            }

            const { keyword, sourceRows, supplementalOnlyRows } = context;

            const supplementalOutcomes = supplementalOnlyRows.map((row, index) => ({
                row,
                outcome: computeQuickFixAltTitleSupplementalOutcome(
                    Number(row?.quickFixIndex ?? 0) > 0 ? row : { ...row, quickFixIndex: index + 1 },
                    keyword,
                ),
            }));

            const preview = applyQuickFixAltTitleToBlocks(blocksRef.current, keyword);
            const wpMetaQueue = [...(preview.wpMetaQueue ?? [])];
            supplementalOutcomes.forEach(({ outcome }) => {
                if (outcome.wpMeta) {
                    wpMetaQueue.push(outcome.wpMeta);
                }
            });

            const dedupedMeta = [];
            const seenMeta = new Set();
            wpMetaQueue.forEach((item) => {
                const id = Number(item?.attachment_id ?? 0);
                if (id <= 0 || seenMeta.has(id)) {
                    return;
                }
                seenMeta.add(id);
                dedupedMeta.push(item);
            });

            const localMetaQueue = [];
            const seenLocalMeta = new Set();
            sourceRows.forEach((row) => {
                const id = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
                if (id <= 0 || seenLocalMeta.has(id)) {
                    return;
                }

                seenLocalMeta.add(id);
                localMetaQueue.push({ id, alt_text: keyword, title: keyword });
            });

            if (preview.applied === 0 && supplementalOutcomes.length === 0) {
                return;
            }

            if (!window.confirm(t('editor_quick_fix_alt_title_all_confirm'))) {
                return;
            }

            setBlocks(preview.blocks);

            sourceRows.forEach((row) => {
                patchSupplementalImageRow(row, { alt: keyword, title: keyword });
            });

            supplementalOutcomes.forEach(({ row, outcome }) => {
                patchSupplementalImageRow(row, outcome.patch);
            });

            if (dedupedMeta.length > 0) {
                requestWordPressAttachmentMetaUpdate(dedupedMeta);
            }

            if (localMetaQueue.length > 0) {
                updateSeoMediaMeta(localMetaQueue).catch((error) => {
                    window.dispatchEvent(
                        new CustomEvent('seo-article-editor-notify', {
                            detail: {
                                title: t('editor_cannot_update_image_meta'),
                                body: error?.message ?? t('editor_try_again_later'),
                                status: 'danger',
                            },
                        }),
                    );
                });
            }

            setImagesReloadKey((k) => k + 1);
        },
        [
            buildQuickFixContext,
            patchSupplementalImageRow,
            requestWordPressAttachmentMetaUpdate,
        ],
    );

    const quickFixSlugSingleImage = useCallback(
        (target) => {
            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword || !target) {
                return;
            }

            const blockId =
                typeof target === 'string'
                    ? target
                    : String(target?.blockId ?? target?.block_id ?? '').trim();

            if (blockId) {
                const preview = applyQuickFixSlugToBlock(blocksRef.current, keyword, blockId);
                if (preview.applied === 0) {
                    return;
                }

                const renameCount = preview.renameQueue.length;
                const localRenameCount = (preview.localRenameQueue ?? []).length;

                if (renameCount > 0 && !confirmSlugRename({ count: 1, isQuickFix: true })) {
                    return;
                }

                if (renameCount === 0 && localRenameCount === 0) {
                    return;
                }

                applyQuickFixSlugPreview(preview, keyword);

                return;
            }

            const row = typeof target === 'object' ? target : null;
            if (!row || row.excludeQuickFix) {
                return;
            }

            const sourceRows = supplementalImages ?? [];
            const fallbackIndex = Math.max(
                1,
                sourceRows.findIndex((item) => {
                    const srcMatched =
                        normalizeImageSrcKey(item?.src) !== '' &&
                        normalizeImageSrcKey(item?.src) === normalizeImageSrcKey(row?.src);
                    const wpMatched =
                        Number(item?.wpAttachmentId ?? item?.wp_attachment_id ?? 0) > 0 &&
                        Number(item?.wpAttachmentId ?? item?.wp_attachment_id ?? 0) ===
                            Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
                    const seoMatched =
                        Number(item?.seoMediaId ?? item?.seo_media_id ?? 0) > 0 &&
                        Number(item?.seoMediaId ?? item?.seo_media_id ?? 0) ===
                            Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
                    return srcMatched || wpMatched || seoMatched;
                }) + 1,
            );

            const enrichedRow = enrichSupplementalRow(row, fallbackIndex);
            const outcome = computeQuickFixSlugSupplementalOutcome(enrichedRow, keyword);

            if (Object.keys(outcome.patch ?? {}).length > 0) {
                patchSupplementalImageRow(enrichedRow, outcome.patch);
            }

            const hasWpRename = Boolean(outcome?.wpRename);
            const hasLocalRename = Boolean(outcome?.localRename);

            if (hasWpRename) {
                if (!confirmSlugRename({ count: 1, isQuickFix: true })) {
                    return;
                }
                pendingQuickFixKeywordRef.current = keyword;
                requestWordPressRenames([outcome.wpRename]);
                patchSupplementalImageRow(row, { slug: outcome.wpRename.new_slug });
            }

            if (outcome?.localRename) {
                const newSlug = String(outcome.localRename.new_slug ?? '').trim();
                const src = String(outcome.localRename.src ?? '').trim();
                const id = Number(outcome.localRename.seo_media_id ?? 0);
                if (!newSlug || !src) {
                    return;
                }
                const renamePromise = id > 0 ? renameSeoMedia(id, newSlug) : renameLocalMediaByUrl(src, newSlug);
                renamePromise
                    .then((data) => {
                        patchSupplementalImageRow(row, {
                            slug: data.slug,
                            src: data.url,
                            seoMediaId: data.id ?? id,
                        });
                        setImagesReloadKey((k) => k + 1);
                    })
                    .catch((error) => {
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('editor_cannot_rename_image_slug'),
                                    body: error?.message ?? t('editor_try_again_later'),
                                    status: 'danger',
                                },
                            }),
                        );
                    });
            }
        },
        [
            focusKeyword,
            articleTitle,
            applyQuickFixSlugPreview,
            enrichSupplementalRow,
            patchSupplementalImageRow,
            requestWordPressRenames,
            supplementalImages,
        ],
    );

    const quickFixAltTitleSingleImage = useCallback(
        (target) => {
            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword || !target) {
                return;
            }

            const blockId =
                typeof target === 'string'
                    ? target
                    : String(target?.blockId ?? target?.block_id ?? '').trim();

            if (blockId) {
                const preview = applyQuickFixAltTitleToBlock(blocksRef.current, keyword, blockId);
                if (preview.applied === 0) {
                    return;
                }

                if (!window.confirm(t('editor_quick_fix_alt_title_one_confirm'))) {
                    return;
                }

                setBlocks(preview.blocks);
                if ((preview.wpMetaQueue ?? []).length > 0) {
                    requestWordPressAttachmentMetaUpdate(preview.wpMetaQueue);
                }
                setImagesReloadKey((k) => k + 1);

                return;
            }

            const row = typeof target === 'object' ? target : null;
            if (!row || row.excludeQuickFix) {
                return;
            }

            if (!window.confirm(t('editor_quick_fix_alt_title_one_confirm'))) {
                return;
            }

            const outcome = computeQuickFixAltTitleSupplementalOutcome(row, keyword);
            patchSupplementalImageRow(row, outcome.patch);
            if (outcome.wpMeta) {
                requestWordPressAttachmentMetaUpdate([outcome.wpMeta]);
            }
            setImagesReloadKey((k) => k + 1);
        },
        [
            focusKeyword,
            articleTitle,
            patchSupplementalImageRow,
            requestWordPressAttachmentMetaUpdate,
        ],
    );

    useEffect(() => {
        const onLoading = (e) => {
            setImageRenameBusy(true);
            setImageRenameBusyCount(Number(e.detail?.count ?? 0));
        };

        const onFinished = (e) => {
            setImageRenameBusy(false);
            setImageRenameBusyCount(0);

            const renamed = Array.isArray(e.detail?.renamed) ? e.detail.renamed : [];
            const keyword = pendingQuickFixKeywordRef.current;

            setBlocks((prev) => finalizeBlocksAfterWpRename(prev, renamed, keyword));
            setSupplementalImages((prev) =>
                (Array.isArray(prev) ? prev : []).map((row) => {
                    const wpId = Number(row?.wp_attachment_id ?? row?.wpAttachmentId ?? 0);
                    if (wpId <= 0) {
                        return row;
                    }

                    const item = renamed.find((entry) => Number(entry?.attachment_id ?? entry?.attachmentId ?? 0) === wpId);
                    if (!item) {
                        return row;
                    }

                    const newUrl = String(item?.new_url ?? item?.newUrl ?? row?.src ?? '').trim();
                    const newSlug = String(item?.new_slug ?? item?.newSlug ?? row?.slug ?? '').trim();

                    return {
                        ...row,
                        src: newUrl || row.src,
                        wp_url: newUrl || row.wp_url,
                        slug: newSlug || row.slug,
                    };
                }),
            );
            pendingQuickFixKeywordRef.current = '';
            setImagesReloadKey((k) => k + 1);
        };

        window.addEventListener('seo-rename-attachment-slugs-loading', onLoading);
        window.addEventListener('seo-attachment-slugs-rename-finished', onFinished);

        return () => {
            window.removeEventListener('seo-rename-attachment-slugs-loading', onLoading);
            window.removeEventListener('seo-attachment-slugs-rename-finished', onFinished);
        };
    }, []);

    const focusImageBlock = useCallback(
        (blockId) => {
            if (!blockId) {
                return;
            }

            setActiveTab('editor');
            clearTempMerge();
            blockFlushRef.current = null;

            const currentActive = activeBlockIdRef.current;
            const needsSwitch = currentActive !== blockId;

            if (needsSwitch && currentActive) {
                commitActiveBlock();
                blockFlushRef.current = null;
            }

            if (needsSwitch) {
                setActiveBlockId(blockId);
            }

            const jump = () => {
                const slot = document.querySelector(`[data-seo-block-id="${blockId}"]`);
                if (!slot) {
                    return;
                }

                slot.scrollIntoView({ behavior: 'smooth', block: 'center' });
                slot.classList.add(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS);
                window.setTimeout(
                    () =>
                        slot.classList.remove(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS),
                    2400,
                );
            };

            window.setTimeout(jump, needsSwitch ? 90 : 0);
        },
        [clearTempMerge, commitActiveBlock],
    );

    const quickGenerateImageForSection = useCallback(
        (section) => {
            if (section?.isIntro) {
                notifyIntroNoImages();

                return;
            }

            const sectionBlocks = section.blockIds
                .map((blockId) => blockById.get(blockId))
                .filter(Boolean);
            const sectionText = getPlainTextFromBlocks(sectionBlocks).trim();
            if (!sectionText) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('generate_image'),
                            body: 'Section has no plain text to build prompt.',
                            status: 'warning',
                        },
                    }),
                );
                return;
            }

            const keyword = (focusKeyword || articleTitle || '').trim();
            const promptInput = keyword ? `${keyword}\n\n${sectionText}` : sectionText;
            const targetBlockId = String(section.blockIds?.[0] ?? activeBlockId ?? '').trim();

            window.dispatchEvent(
                new CustomEvent('generate-article-image', {
                    detail: {
                        selectionText: sectionText,
                        selectionHtml: '',
                        userBrief: promptInput,
                        activeBlockId: targetBlockId,
                    },
                }),
            );
        },
        [activeBlockId, articleTitle, blockById, focusKeyword, notifyIntroNoImages],
    );

    const scrollToFeaturedSnippetTable = useCallback(() => {
        const currentBlocks = blocksRef.current;
        let targetBlockId = null;

        for (const block of currentBlocks) {
            if (block.type === 'image' || !block.content) {
                continue;
            }

            if (/<table\b/i.test(block.content)) {
                targetBlockId = block.id;
                break;
            }
        }

        if (!targetBlockId) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Table not found',
                        body: 'No table found in current content.',
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        setActiveTab('editor');
        clearTempMerge();

        const currentActive = activeBlockIdRef.current;
        const needsSwitch = currentActive !== targetBlockId;

        if (needsSwitch && currentActive) {
            commitActiveBlock();
        }

        if (needsSwitch) {
            setActiveBlockId(targetBlockId);
        }

        const jump = () => {
            const slot = document.querySelector(`[data-seo-block-id="${targetBlockId}"]`);
            const table = slot?.querySelector?.('table');
            const target = table || slot;
            if (!target) {
                return;
            }

            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.classList.add(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS);
            window.setTimeout(
                () =>
                    target.classList.remove(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS),
                2400,
            );
        };

        window.setTimeout(jump, needsSwitch ? 90 : 0);
    }, [clearTempMerge, commitActiveBlock]);

    const scrollToExtractedLink = useCallback(
        (detail) => {
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            const preferHrefMatch = detail?.preferHrefMatch === true;
            if (!text && !href) {
                return;
            }

            const listIndex = Number(detail?.index) || 0;
            const linkType = String(detail?.type ?? 'internal');
            const searchPlainText = detail?.searchPlainText === true;
            const offset = typeof detail?.offset === 'number' ? detail.offset : null;
            const scrollToken = ++linkScrollTokenRef.current;

            setActiveTab('editor');
            clearTempMerge();

            if (linkType === 'faq') {
                const faqIndex =
                    typeof detail?.faqIndex === 'number' ? detail.faqIndex : listIndex;

                window.setTimeout(() => {
                    if (scrollToken !== linkScrollTokenRef.current) {
                        return;
                    }
                    if (!scrollToFaqByIndex(faqIndex)) {
                        scrollToFaqKeyword(text, 0);
                    }
                }, 0);
                return;
            }

            const currentBlocks = blocksRef.current;
            let targetBlockId = offset != null ? findBlockIdForExportOffset(currentBlocks, offset) : null;
            let localAnchorIndex = listIndex;

            if (targetBlockId) {
                const offsetBlock = currentBlocks.find((block) => block.id === targetBlockId);
                const countInOffsetBlock = offsetBlock?.content
                    ? (searchPlainText
                        ? countPlainTextInHtml(offsetBlock.content, text)
                        : countMatchingAnchorsInHtml(offsetBlock.content, text, href))
                    : 0;
                if (countInOffsetBlock < 1) {
                    // Offset stale after user edits: fallback to keyword+href scan.
                    targetBlockId = null;
                }
            }

            if (!targetBlockId) {
                let global = 0;
                for (const block of currentBlocks) {
                    if (block.type === 'image' || !block.content) {
                        continue;
                    }
                    const count = searchPlainText
                        ? countPlainTextInHtml(block.content, text)
                        : countMatchingAnchorsInHtml(
                            block.content,
                            preferHrefMatch ? '' : text,
                            href,
                        );
                    if (count === 0) {
                        continue;
                    }
                    if (listIndex < global + count) {
                        targetBlockId = block.id;
                        localAnchorIndex = listIndex - global;
                        break;
                    }
                    global += count;
                }
            } else {
                let before = 0;
                for (const block of currentBlocks) {
                    if (block.id === targetBlockId) {
                        break;
                    }
                    if (block.type !== 'image' && block.content) {
                        before += searchPlainText
                            ? countPlainTextInHtml(block.content, text)
                            : countMatchingAnchorsInHtml(
                                block.content,
                                preferHrefMatch ? '' : text,
                                href,
                            );
                    }
                }
                localAnchorIndex = Math.max(0, listIndex - before);
            }

            if (!targetBlockId) {
                for (const block of currentBlocks) {
                    if (block.type === 'image' || !block.content) {
                        continue;
                    }
                    const count = searchPlainText
                        ? countPlainTextInHtml(block.content, text)
                        : countMatchingAnchorsInHtml(
                            block.content,
                            preferHrefMatch ? '' : text,
                            href,
                        );
                    if (count > 0) {
                        targetBlockId = block.id;
                        localAnchorIndex = 0;
                        break;
                    }
                }
            }

            if (!targetBlockId) {
                window.setTimeout(() => {
                    if (scrollToken !== linkScrollTokenRef.current) {
                        return;
                    }
                    scrollToFaqKeyword(text, listIndex);
                }, 0);
                return;
            }

            const targetSectionId = sectionByBlockId.get(targetBlockId);
            const sectionWasCollapsed =
                targetSectionId != null && collapsedSectionIds[targetSectionId] === true;

            if (sectionWasCollapsed) {
                setCollapsedSectionIds((prev) => ({ ...prev, [targetSectionId]: false }));
            }

            const currentActive = activeBlockIdRef.current;
            const needsBlockSwitch = currentActive !== targetBlockId;

            if (needsBlockSwitch && currentActive) {
                commitActiveBlock();
            }

            if (needsBlockSwitch) {
                setActiveBlockId(targetBlockId);
            }

            const runScroll = () => {
                if (scrollToken !== linkScrollTokenRef.current) {
                    return;
                }
                if (searchPlainText) {
                    scrollToPlainTextInBlock(targetBlockId, text, localAnchorIndex, {
                        onMiss: () => scrollToFaqKeyword(text, listIndex),
                    });
                    return;
                }
                scrollToKeywordAnchor(targetBlockId, preferHrefMatch ? '' : text, localAnchorIndex, href, {
                    onMiss: () => scrollToFaqKeyword(text, listIndex),
                });
            };

            const scrollDelay = needsBlockSwitch || sectionWasCollapsed ? 90 : 0;

            if (scrollDelay > 0) {
                window.setTimeout(runScroll, scrollDelay);
            } else {
                runScroll();
            }
        },
        [clearTempMerge, collapsedSectionIds, commitActiveBlock, sectionByBlockId],
    );

    const insertSuggestedLinkIntoContent = useCallback(
        (detail) => {
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            const occurrenceIndex = Math.max(0, Number(detail?.occurrence_index) || 0);
            if (!text || !href) {
                return;
            }

            const notifyInserted = (blockId, nextHtml) => {
                const currentBlocks = blocksRef.current;
                const nextBlocks = currentBlocks.map((item) =>
                    item.id === blockId ? { ...item, content: nextHtml } : item,
                );
                blocksRef.current = nextBlocks;
                setBlocks(nextBlocks);

                const activeId = activeBlockIdRef.current;
                if (activeId === blockId) {
                    const editor = blockEditorsRef.current.get(blockId);
                    if (editor && !editor.isDestroyed) {
                        editor.commands.setContent(nextHtml, { emitUpdate: false });
                    }
                }

                if (articleId) {
                    saveDraft(articleId, {
                        blocks: nextBlocks,
                        html: exportBlocksToHtml(nextBlocks),
                    });
                    setSaveStatus('saved');
                }

                setExtractedLinks((prev) => {
                    const current = prev && typeof prev === 'object'
                        ? prev
                        : { internal: [], external: [] };
                    const internal = Array.isArray(current.internal) ? current.internal : [];
                    const alreadyAdded = internal.some(
                        (item) =>
                            normalizeLinkLabel(item?.text) === normalizeLinkLabel(text) ||
                            normalizeHrefForCompare(item?.href) === normalizeHrefForCompare(href),
                    );

                    return alreadyAdded
                        ? current
                        : {
                              ...current,
                              internal: [...internal, { text, href, occurrence_count: 1 }],
                          };
                });
                setSuggestedInternalLinks((prev) =>
                    filterSuggestedInternalLinks(prev, [{ text, href }]),
                );
                window.dispatchEvent(
                    new CustomEvent('seo-editor-suggested-link-inserted', {
                        detail: { text, href },
                    }),
                );
            };

            setActiveTab('editor');
            activeTabRef.current = 'editor';
            commitActiveBlock();

            const domResult = wrapPlainTextWithLinkInBlocks(
                blocksRef.current,
                text,
                href,
                occurrenceIndex,
            );
            if (domResult) {
                notifyInserted(domResult.blockId, domResult.html);
                return;
            }

            let remainingIndex = occurrenceIndex;
            let targetBlockId = null;
            let localIndex = 0;

            for (const block of blocksRef.current) {
                if (block.type === 'image' || !block.content) {
                    continue;
                }

                const count = countPlainTextInHtml(block.content, text);
                if (count <= 0) {
                    continue;
                }
                if (remainingIndex < count) {
                    targetBlockId = block.id;
                    localIndex = remainingIndex;
                    break;
                }
                remainingIndex -= count;
            }

            if (!targetBlockId) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_keyword_not_found'),
                            body: t('editor_keyword_not_found_body', { text }),
                            status: 'warning',
                        },
                    }),
                );
                return;
            }

            const currentActive = activeBlockIdRef.current;
            if (currentActive !== targetBlockId) {
                setActiveBlockId(targetBlockId);
            }

            selectPlainTextInBlock(targetBlockId, text, localIndex, (editor) => {
                if (!insertLinkInEditor(editor, text, href)) {
                    return;
                }
                persistEditorContentImmediately(editor, targetBlockId);
                notifyInserted(targetBlockId, blocksRef.current.find((item) => item.id === targetBlockId)?.content ?? '');
            });
        },
        [
            articleId,
            commitActiveBlock,
            persistEditorContentImmediately,
            selectPlainTextInBlock,
        ],
    );

    const removeInternalLinkFromContent = useCallback(
        (detail) => {
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            if (!text && !href) {
                return;
            }

            commitActiveBlock();

            let removedCount = 0;
            const nextBlocks = blocksRef.current.map((block) => {
                if (block.type === 'image' || !block.content) {
                    return block;
                }

                const nextContent = removeMatchingAnchorsFromHtml(block.content, text, href);
                if (nextContent === block.content) {
                    return block;
                }

                removedCount += countMatchingAnchorsInHtml(block.content, text, href);
                return { ...block, content: nextContent };
            });

            if (removedCount <= 0) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('links_remove_not_found_title'),
                            body: t('links_remove_not_found_body', { label: text || href }),
                            status: 'warning',
                        },
                    }),
                );
                return;
            }

            const activeId = activeBlockIdRef.current;
            if (activeId) {
                const activeEditor = blockEditorsRef.current.get(activeId);
                const activeBlock = nextBlocks.find((block) => block.id === activeId);
                if (activeEditor && !activeEditor.isDestroyed && activeBlock?.content) {
                    activeEditor.commands.setContent(activeBlock.content, { emitUpdate: false });
                }
            }

            blocksRef.current = nextBlocks;
            setBlocks(nextBlocks);
            setExtractedLinks((prev) => {
                const current = prev && typeof prev === 'object'
                    ? prev
                    : { internal: [], external: [] };
                const textKey = normalizeLinkLabel(text);
                const hrefKey = normalizeHrefForCompare(href);

                return {
                    ...current,
                    internal: (Array.isArray(current.internal) ? current.internal : []).filter(
                        (item) => {
                            const itemText = normalizeLinkLabel(item?.text);
                            const itemHref = normalizeHrefForCompare(item?.href);
                            const textMatches = !textKey || itemText === textKey;
                            const hrefMatches = !hrefKey || itemHref === hrefKey;

                            return !(textMatches && hrefMatches);
                        },
                    ),
                };
            });
            scheduleAutosave();

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('links_removed_title'),
                        body: t('links_removed_body', { label: text || href }),
                        status: 'success',
                    },
                }),
            );
        },
        [commitActiveBlock, scheduleAutosave],
    );

    const insertCtaLinkIntoContent = useCallback(
        (detail) => {
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            const type = String(detail?.type ?? '').toLowerCase();
            const plainText = isCtaPlainTextType(type) || detail?.is_placeholder === true;

            if (!text || (!href && !plainText)) {
                return;
            }

            setActiveTab('editor');

            const editor = resolveActiveEditor();
            if (insertCtaInEditor(editor, text, href, type)) {
                commitActiveBlock();
                requestAnalyze();
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_cta_link_inserted'),
                            body: `«${text}»`,
                            status: 'success',
                        },
                    }),
                );

                return;
            }

            const selectedText = intraSelectionRef.current.text;
            const activeId = activeBlockIdRef.current;
            if (selectedText) {
                const activeBlock = blocksRef.current.find(
                    (block) => block.id === activeId && block.type !== 'image',
                );
                if (activeBlock) {
                    const replaceResult = plainText
                        ? replaceFirstPlainTextWithText(activeBlock.content ?? '', selectedText, text)
                        : replaceFirstPlainTextWithLink(
                              activeBlock.content ?? '',
                              selectedText,
                              text,
                              href,
                          );
                    if (replaceResult.replaced) {
                        updateBlockContent(activeBlock.id, replaceResult.html);
                        requestAnalyze();
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('editor_cta_link_inserted'),
                                    body: `«${text}»`,
                                    status: 'success',
                                },
                            }),
                        );

                        return;
                    }
                }
            }

            commitActiveBlock();

            const currentBlocks = blocksRef.current;

            if (!plainText) {
                for (const block of currentBlocks) {
                    if (block.type === 'image' || !block.content) {
                        continue;
                    }

                    const { html, replaced } = wrapFirstPlainTextWithLink(block.content, text, href);
                    if (!replaced) {
                        continue;
                    }

                    updateBlockContent(block.id, html);
                    requestAnalyze();
                    window.dispatchEvent(
                        new CustomEvent('seo-article-editor-notify', {
                            detail: {
                                title: t('editor_cta_link_inserted'),
                                body: `«${text}»`,
                                status: 'success',
                            },
                        }),
                    );

                    return;
                }
            }

            const targetBlock =
                currentBlocks.find((block) => block.id === activeId && block.type !== 'image') ??
                currentBlocks.find((block) => block.type !== 'image' && block.content);

            if (!targetBlock) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_cta_insert_failed'),
                            body: t('editor_cta_insert_failed_body'),
                            status: 'warning',
                        },
                    }),
                );
                return;
            }

            const placeholderHtml =
                detail?.is_placeholder === true ? String(detail?.html ?? '').trim() : '';
            const safeText = text.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const insertion =
                placeholderHtml !== ''
                    ? placeholderHtml
                    : plainText
                      ? safeText
                      : `<a href="${href.replace(/"/g, '&quot;')}" class="${SEO_EDITOR_LINK_CLASS}">${safeText}</a>`;
            const base = String(targetBlock.content ?? '').trim();
            const nextHtml = base !== '' ? `${base} ${insertion}` : `<p>${insertion}</p>`;

            updateBlockContent(targetBlock.id, nextHtml);
            requestAnalyze();
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_cta_link_inserted'),
                        body: `«${text}»`,
                        status: 'success',
                    },
                }),
            );
        },
        [commitActiveBlock, updateBlockContent, requestAnalyze, resolveActiveEditor],
    );

    useEffect(() => {
        if (blocks.length === 0) {
            return;
        }

        const freshLinks = extractLinksFromBlocks(blocks, siteDomain);
        setExtractedLinks((prev) => {
            const prevInternal = JSON.stringify(prev?.internal ?? []);
            const prevExternal = JSON.stringify(prev?.external ?? []);
            const nextInternal = JSON.stringify(freshLinks.internal ?? []);
            const nextExternal = JSON.stringify(freshLinks.external ?? []);

            if (prevInternal === nextInternal && prevExternal === nextExternal) {
                return prev;
            }

            return freshLinks;
        });
    }, [blocks, siteDomain]);

    useEffect(() => {
        publishExtractedLinks(extractedLinks, suggestedInternalLinks);
    }, [extractedLinks, suggestedInternalLinks, publishExtractedLinks]);

    useEffect(() => {
        const onScrollToLink = (event) => {
            scrollToExtractedLink(event.detail ?? {});
        };

        const onInsertSuggestedLink = (event) => {
            insertSuggestedLinkIntoContent(event.detail ?? {});
        };

        const onInsertCtaLink = (event) => {
            insertCtaLinkIntoContent(event.detail ?? {});
        };

        const onRemoveInternalLink = (event) => {
            removeInternalLinkFromContent(event.detail ?? {});
        };

        const onScrollToFeaturedSnippetTable = () => {
            scrollToFeaturedSnippetTable();
        };

        window.addEventListener('seo-editor-scroll-to-link', onScrollToLink);
        window.addEventListener('seo-editor-insert-suggested-link', onInsertSuggestedLink);
        window.addEventListener('seo-editor-insert-cta-link', onInsertCtaLink);
        window.addEventListener('seo-editor-remove-internal-link', onRemoveInternalLink);
        window.addEventListener('seo-editor-scroll-to-featured-snippet-table', onScrollToFeaturedSnippetTable);

        return () => {
            window.removeEventListener('seo-editor-scroll-to-link', onScrollToLink);
            window.removeEventListener('seo-editor-insert-suggested-link', onInsertSuggestedLink);
            window.removeEventListener('seo-editor-insert-cta-link', onInsertCtaLink);
            window.removeEventListener('seo-editor-remove-internal-link', onRemoveInternalLink);
            window.removeEventListener('seo-editor-scroll-to-featured-snippet-table', onScrollToFeaturedSnippetTable);
        };
    }, [scrollToExtractedLink, insertSuggestedLinkIntoContent, insertCtaLinkIntoContent, removeInternalLinkFromContent, scrollToFeaturedSnippetTable]);

    const deleteBlock = useCallback(
        (id, { skipConfirm = false } = {}) => {
            if (blocksRef.current.length <= 1) return;

            const block = blocksRef.current.find((b) => b.id === id);
            if (!block) return;

            if (block.isWp && !skipConfirm && !window.confirm(t('editor_delete_wp_block_confirm'))) {
                return;
            }

            const isDeletingActive = activeBlockId === id;

            if (tempMergeRef.current?.rangeIds?.includes(id)) {
                clearTempMerge();
            }

            if (isDeletingActive) {
                blockFlushRef.current = null;
                setActiveBlockId(null);
                setGlobalEditor(null);
                dispatchActiveBlockContext(articleId, '', '', false, null);
            } else {
                commitActiveBlock();
            }

            setBlocks((prev) => prev.filter((b) => b.id !== id));
        },
        [activeBlockId, articleId, commitActiveBlock, clearTempMerge],
    );

    const removeImageBlock = useCallback(
        (row) => {
            const blockId = String(row?.blockId || '').trim();
            if (!blockId) {
                return;
            }

            if (blocksRef.current.length <= 1) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('cannot_delete_last_block'),
                            body: t('image_tab_remove_last_block_hint'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            const block = blocksRef.current.find((item) => item.id === blockId);
            if (!block || block.type !== 'image') {
                return;
            }

            deleteBlock(blockId, { skipConfirm: true });
        },
        [deleteBlock],
    );

    useEffect(() => {
        const publishSelectionContext = () => {
            if (activeTab !== 'editor') {
                dispatchActiveBlockContext(articleId, '', '', false, null);
                return;
            }

            if (!activeBlockId) {
                dispatchActiveBlockContext(articleId, '', '', false, null);
                return;
            }

            const intra = intraSelectionRef.current;
            const blockText = getActiveBlockContextText(
                blocksRef.current,
                activeBlockId,
                tempMergeRef.current,
            );
            const blockHtml = getHtmlFromBlocks(
                blocksRef.current,
                activeBlockId,
                tempMergeRef.current,
            );

            const text = intra.text.length >= 12 ? intra.text : blockText;
            const html = intra.html.length >= 12 ? intra.html : blockHtml;
            let contextText = text;
            let contextHtml = html;

            if (intra.text.length < 12) {
                const focused = focusedOutlineHeadingRef.current;
                if (focused?.headingText && blockHtml) {
                    const scoped = extractHeadingScopedPlainText(
                        blockHtml,
                        Number(focused.level ?? 0),
                        String(focused.headingText ?? ''),
                    );
                    if (scoped.length >= 12) {
                        contextText = scoped;
                        contextHtml = scoped;
                    }
                }
            }

            dispatchActiveBlockContext(articleId, contextText, contextHtml, true, activeBlockId);
        };

        const onIntra = (event) => {
            intraSelectionRef.current = {
                text: (event.detail?.text ?? '').trim(),
                html: (event.detail?.html ?? '').trim(),
            };
            publishSelectionContext();
        };

        window.addEventListener('seo-editor-intra-selection', onIntra);
        publishSelectionContext();

        return () => window.removeEventListener('seo-editor-intra-selection', onIntra);
    }, [activeTab, activeBlockId, blocks, tempMerge, articleId]);

    const syncOutlineFocusFromBlock = useCallback((block, action = 'focus') => {
        const meta = extractOutlineHeadingFromBlock(block);
        const headingId = outlineHeadingIdsByBlockIdRef.current.get(block?.id);
        if (!meta && headingId == null) {
            return;
        }

        if (headingId == null && outlineHasSavedHeadings && meta) {
            const key = outlineHeadingKey(meta.level, meta.headingText);
            if (!outlineHeadingKeys.has(key)) {
                return;
            }
        }

        setOutlineHeadingCommand({
            token: Date.now(),
            level: meta?.level,
            headingText: meta?.headingText,
            headingId: headingId ?? null,
            action,
        });
    }, [outlineHasSavedHeadings, outlineHeadingKeys]);

    const isBlockOutlineSynced = useCallback(
        (block) => {
            if (outlineHeadingIdsByBlockIdRef.current.has(block?.id)) {
                return true;
            }

            const meta = extractOutlineHeadingFromBlock(block);
            if (!meta) {
                return false;
            }

            const key = outlineHeadingKey(meta.level, meta.headingText);
            if (!outlineHeadingKeys.has(key)) {
                return false;
            }

            const matchedBlockId = findBlockIdForOutlineHeading(
                blocksRef.current,
                meta.level,
                meta.headingText,
            );

            return matchedBlockId === block.id;
        },
        [outlineHeadingKeys],
    );

    const activateBlock = useCallback(
        (id) => {
            setInsertMenu(null);
            const sectionId = sectionByBlockId.get(id);
            if (sectionId && collapsedSectionIds[sectionId]) {
                setCollapsedSectionIds((prev) => ({ ...prev, [sectionId]: false }));
            }
            if (tempMergeRef.current) {
                clearTempMerge();
                setGlobalEditor(null);
                setActiveBlockId(id);
                if (outlineHasSavedHeadings) {
                    const block = blocksRef.current.find((item) => item.id === id);
                    if (block) {
                        syncOutlineFocusFromBlock(block);
                    }
                }
                return;
            }
            if (id === activeBlockId) {
                return;
            }
            commitActiveBlock();
            setActiveBlockId(id);
            setGlobalEditor(null);
            if (outlineHasSavedHeadings) {
                const block = blocksRef.current.find((item) => item.id === id);
                if (block) {
                    syncOutlineFocusFromBlock(block);
                }
            }
        },
        [
            activeBlockId,
            collapsedSectionIds,
            commitActiveBlock,
            clearTempMerge,
            outlineHasSavedHeadings,
            sectionByBlockId,
            syncOutlineFocusFromBlock,
        ],
    );

    const insertBlockRelative = useCallback(
        (refBlockId, position, type) => {
            if (tempMergeRef.current) return;

            if (type === 'image' && isIntroBlockId(refBlockId)) {
                setInsertMenu(null);
                notifyIntroNoImages();

                return;
            }

            if (type === 'faq' && articleHasFaqShortcode(blocksRef.current)) {
                setInsertMenu(null);
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_faq_shortcode_exists'),
                            body: t('editor_faq_shortcode_exists_body'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            commitActiveBlock();

            const newBlock =
                type === 'image'
                    ? createEmptyImageBlock()
                    : type === 'faq'
                      ? createFaqShortcodeBlock()
                      : createEmptyTextBlock();
            const newId = newBlock.id;

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === refBlockId);
                if (index < 0) return prev;
                const insertAt = position === 'before' ? index : index + 1;
                const next = [...prev];
                next.splice(insertAt, 0, newBlock);
                return next;
            });

            setInsertMenu(null);
            setActiveBlockId(newId);
            setGlobalEditor(null);
        },
        [commitActiveBlock, isIntroBlockId, notifyIntroNoImages],
    );

    const insertHtmlBlockRelative = useCallback(
        (refBlockId, position, html) => {
            if (tempMergeRef.current) {
                return;
            }

            const content = String(html ?? '').trim();
            if (!content) {
                return;
            }

            commitActiveBlock();

            const newBlock = {
                ...createEmptyTextBlock(),
                content,
            };
            const newId = newBlock.id;

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === refBlockId);
                if (index < 0) {
                    return prev;
                }

                const insertAt = position === 'before' ? index : index + 1;
                const next = [...prev];
                next.splice(insertAt, 0, newBlock);

                return normalizeBlocks(next);
            });

            setInsertMenu(null);
            setActiveBlockId(newId);
            setGlobalEditor(null);
        },
        [commitActiveBlock],
    );

    const applyCompletedMediaToPlaceholder = useCallback((mediaId, mediaType, finalUrl) => {
        const pending = pendingAiMediaRef.current.get(mediaId);
        let targetBlockId = pending?.blockId ?? '';

        if (!targetBlockId && mediaId > 0) {
            const fallback = blocksRef.current.find((block) => {
                const image = block?.image ?? null;
                const seoId = Number(image?.seoMediaId ?? image?.seo_media_id ?? 0);
                return seoId === mediaId && Boolean(image?.isProcessing);
            });
            targetBlockId = fallback?.id ?? '';
        }

        if (!targetBlockId || !finalUrl) {
            return false;
        }

        if (mediaType === 'video') {
            const safeUrl = finalUrl
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;');

            updateBlocksWithoutHistory((prev) =>
                prev.map((block) =>
                    block.id === targetBlockId
                        ? {
                              ...block,
                              type: 'text',
                              image: undefined,
                              content: `<figure class="wp-block-video"><video controls src="${safeUrl}"></video></figure>`,
                          }
                        : block,
                ),
            );
        } else {
            patchImageInBlocks(
                targetBlockId,
                {
                    src: finalUrl,
                    title: '',
                    alt: '',
                    isProcessing: false,
                },
                true,
            );
        }

        pendingAiMediaRef.current.delete(mediaId);
        window.dispatchEvent(new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }));
        setImagesReloadKey((k) => k + 1);
        return true;
    }, [patchImageInBlocks, updateBlocksWithoutHistory]);

    const applyCompletedMediaToProductGallery = useCallback((mediaId, finalUrl, galleryItems = null) => {
        if (!articleId) {
            return false;
        }

        const rawItems = Array.isArray(galleryItems) && galleryItems.length > 0
            ? galleryItems
            : [{ id: mediaId, url: finalUrl }];

        const appended = appendProductAlbumItems(articleId, rawItems);
        if (appended.length === 0) {
            return false;
        }

        pendingAiMediaRef.current.delete(mediaId);
        window.dispatchEvent(new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }));

        const galleryUrls = appended
            .map((item) => ({
                id: Number(item?.id ?? 0),
                url: String(item?.url ?? '').trim(),
            }))
            .filter((item) => item.url !== '');

        window.dispatchEvent(
            new CustomEvent('article-ai-image-generated', {
                detail: {
                    target: 'product-gallery',
                    status: 'completed',
                    url: String(galleryUrls[0]?.url ?? finalUrl ?? '').trim(),
                    seoMediaId: mediaId,
                    gallery_urls: galleryUrls,
                    galleryUrls,
                },
            }),
        );

        return true;
    }, [articleId]);

    const clearMediaPolling = useCallback((mediaId) => {
        const timer = mediaPollTimersRef.current.get(mediaId);
        if (timer) {
            window.clearTimeout(timer);
        }
        mediaPollTimersRef.current.delete(mediaId);
    }, []);

    const AI_JOBS_POLL_MS = 60_000;
    const AI_JOBS_INITIAL_POLL_MS = 4_000;

    const startMediaStatusPolling = useCallback((mediaId, mediaType) => {
        if (!mediaId || mediaPollTimersRef.current.has(mediaId)) {
            return;
        }

        let attempt = 0;
        const maxAttempts = 12;

        const poll = async () => {
            attempt += 1;

            try {
                const payload = await fetchSeoMediaStatus(mediaId);
                const status = String(payload?.status ?? '').toLowerCase();
                const url = String(payload?.url ?? '').trim();

                if (status === 'completed' && url) {
                    const pending = pendingAiMediaRef.current.get(mediaId);
                    if (pending?.target === 'product-gallery' && mediaType === 'image') {
                        const galleryItems = Array.isArray(payload?.gallery_urls) && payload.gallery_urls.length > 0
                            ? payload.gallery_urls
                            : null;
                        applyCompletedMediaToProductGallery(mediaId, url, galleryItems);
                        clearMediaPolling(mediaId);
                        return;
                    }

                    applyCompletedMediaToPlaceholder(mediaId, mediaType, url);
                    clearMediaPolling(mediaId);
                    return;
                }

                if (status === 'failed') {
                    clearMediaPolling(mediaId);
                    pendingAiMediaRef.current.delete(mediaId);
                    window.dispatchEvent(
                        new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                    );
                    setImagesReloadKey((k) => k + 1);
                    window.dispatchEvent(
                        new CustomEvent('seo-article-editor-notify', {
                            detail: {
                                title: mediaType === 'video' ? t('editor_generate_video_failed') : t('editor_generate_image_failed'),
                                body: payload?.error_message || t('editor_ai_failed'),
                                status: 'danger',
                            },
                        }),
                    );
                    return;
                }
            } catch {
                if (attempt >= maxAttempts) {
                    clearMediaPolling(mediaId);
                    return;
                }
            }

            if (attempt >= maxAttempts) {
                clearMediaPolling(mediaId);
                return;
            }

            const nextTimer = window.setTimeout(poll, AI_JOBS_POLL_MS);
            mediaPollTimersRef.current.set(mediaId, nextTimer);
        };

        const initialTimer = window.setTimeout(poll, AI_JOBS_INITIAL_POLL_MS);
        mediaPollTimersRef.current.set(mediaId, initialTimer);
    }, [applyCompletedMediaToPlaceholder, applyCompletedMediaToProductGallery, clearMediaPolling]);

    useEffect(() => {
        for (const block of blocks) {
            const image = block?.image ?? null;
            if (!image?.isProcessing) {
                continue;
            }

            const mediaId = Number(image?.seoMediaId ?? image?.seo_media_id ?? 0);
            if (mediaId <= 0) {
                continue;
            }

            if (!pendingAiMediaRef.current.has(mediaId)) {
                pendingAiMediaRef.current.set(mediaId, {
                    blockId: block.id,
                    mediaType: 'image',
                });
            }

            startMediaStatusPolling(mediaId, 'image');
        }
    }, [blocks, startMediaStatusPolling]);

    const insertImageAfterBlock = useCallback(
        (refBlockId, imageUrl, imagePatch = {}) => {
            const url = (imageUrl ?? '').trim();
            if (!refBlockId || !url) {
                return '';
            }
            if (tempMergeRef.current) {
                return '';
            }
            if (isIntroBlockId(refBlockId)) {
                notifyIntroNoImages();

                return '';
            }

            commitActiveBlock();

            const image = {
                src: url,
                alt: '',
                title: '',
                ...imagePatch,
            };
            const html = renderImageFigure(image);
            const newBlock = {
                ...createEmptyImageBlock(),
                content: html,
                image,
            };

            const applyInsert = (prev) => {
                const index = prev.findIndex((b) => b.id === refBlockId);
                if (index < 0) {
                    return prev;
                }
                const next = [...prev];
                const anchor = next[index];
                if (anchor?.type !== 'image' && anchor?.content) {
                    next[index] = {
                        ...anchor,
                        content: normalizeSectionHeadingBlockHtml(anchor.content),
                    };
                }
                next.splice(index + 1, 0, newBlock);
                return normalizeBlocks(next);
            };

            if (image.isProcessing) {
                updateBlocksWithoutHistory(applyInsert);
            } else {
                setBlocks(applyInsert);
            }

            setActiveBlockId(newBlock.id);
            setGlobalEditor(null);
            setImagesReloadKey((k) => k + 1);
            return newBlock.id;
        },
        [commitActiveBlock, isIntroBlockId, notifyIntroNoImages, updateBlocksWithoutHistory],
    );

    const resolveAiRefBlockId = useCallback((blockId) => {
        const id = String(blockId ?? '').trim();
        if (id) {
            return id;
        }
        const list = blocksRef.current;
        if (!list?.length) {
            return '';
        }
        return list[list.length - 1].id;
    }, []);

    const findImageBlockByMediaId = useCallback((mediaId) => {
        const id = Number(mediaId ?? 0);
        if (id <= 0) {
            return null;
        }

        return (
            blocksRef.current.find((block) => {
                if (block?.type !== 'image') {
                    return false;
                }

                const blockMediaId = Number(block?.image?.seoMediaId ?? block?.image?.seo_media_id ?? 0);

                return blockMediaId === id;
            }) ?? null
        );
    }, []);

    const placeProcessingImagePlaceholder = useCallback(
        (refBlockId, imageUrl, imagePatch = {}) => {
            const url = (imageUrl ?? '').trim();
            const refId = resolveAiRefBlockId(refBlockId);
            if (!refId || !url) {
                return '';
            }
            if (tempMergeRef.current) {
                return '';
            }
            if (isIntroBlockId(refId)) {
                notifyIntroNoImages();

                return '';
            }

            commitActiveBlock();

            const kw = String(window.__SEO_MAIN_KEYWORD__ ?? '').trim();
            const patchMediaId = Number(imagePatch?.seoMediaId ?? imagePatch?.seo_media_id ?? 0);
            const existingPlaceholder = blocksRef.current.find((block) => {
                if (block?.type !== 'image') {
                    return false;
                }
                const current = block?.image ?? parseImageFromBlockContent(block?.content ?? '');
                if (!current?.src) {
                    return false;
                }
                const currentMediaId = Number(current?.seoMediaId ?? current?.seo_media_id ?? 0);
                if (patchMediaId > 0 && currentMediaId === patchMediaId) {
                    return true;
                }
                return Boolean(current?.isProcessing) && String(current.src).trim() === url;
            });
            if (existingPlaceholder) {
                const baseImage = existingPlaceholder.image ?? parseImageFromBlockContent(existingPlaceholder.content) ?? {};
                const nextImage = {
                    ...baseImage,
                    ...imagePatch,
                    src: url,
                    alt: kw || baseImage.alt || '',
                    title: kw || baseImage.title || '',
                };
                const nextHtml = renderImageFigure(nextImage);
                updateBlocksWithoutHistory((prev) =>
                    prev.map((block) =>
                        block.id === existingPlaceholder.id
                            ? {
                                  ...block,
                                  type: 'image',
                                  content: nextHtml,
                                  image: nextImage,
                                  pendingImagePrompt: undefined,
                              }
                            : block,
                    ),
                );
                return existingPlaceholder.id;
            }

            const image = {
                src: url,
                alt: kw,
                title: kw,
                ...imagePatch,
            };
            const html = renderImageFigure(image);

            const refBlock = blocksRef.current.find((b) => b.id === refId);
            const refSrc = String(
                refBlock?.image?.src ?? parseImageFromBlockContent(refBlock?.content ?? '')?.src ?? '',
            ).trim();
            const isEmptyImageBlock = refBlock?.type === 'image' && !refSrc;

            if (isEmptyImageBlock) {
                updateBlocksWithoutHistory((prev) =>
                    prev.map((b) =>
                        b.id === refId
                            ? {
                                  ...b,
                                  content: html,
                                  image,
                                  pendingImagePrompt: undefined,
                              }
                            : b,
                    ),
                );
                setActiveBlockId(refId);
                setGlobalEditor(null);
                setImagesReloadKey((k) => k + 1);
                return refId;
            }

            return insertImageAfterBlock(refId, url, imagePatch);
        },
        [
            commitActiveBlock,
            insertImageAfterBlock,
            isIntroBlockId,
            notifyIntroNoImages,
            resolveAiRefBlockId,
            updateBlocksWithoutHistory,
        ],
    );

    const clearAwaitingClientImagePlaceholders = useCallback(() => {
        for (const [key, pending] of [...pendingAiMediaRef.current.entries()]) {
            if (!pending?.awaitingServer || !pending?.blockId) {
                continue;
            }

            pendingAiMediaRef.current.delete(key);
            updateBlocksWithoutHistory((prev) => prev.filter((block) => block.id !== pending.blockId));
        }

        setImagesReloadKey((value) => value + 1);
    }, [updateBlocksWithoutHistory]);

    const requestGenerateArticleImage = useCallback(
        async (detail) => {
            const payload = detail != null && typeof detail === 'object' ? detail : {};
            const target = String(payload.target ?? 'editor').trim() || 'editor';
            const userBrief = String(payload.userBrief ?? '').trim();
            const selectionText = String(payload.selectionText ?? '').trim();
            const activeBlockIdFromPayload = String(payload.activeBlockId ?? '').trim();

            if (!userBrief && !selectionText && target !== 'product-gallery') {
                return;
            }

            if (target !== 'product-gallery') {
                const refBlockId = resolveAiRefBlockId(
                    activeBlockIdFromPayload || String(activeBlockIdRef.current ?? '').trim(),
                );
                if (refBlockId) {
                    commitActiveBlock();
                    const placeholderId = placeProcessingImagePlaceholder(refBlockId, AI_PLACEHOLDER_LOADING_URL, {
                        isProcessing: true,
                    });
                    if (placeholderId) {
                        pendingAiMediaRef.current.set(`client:${placeholderId}`, {
                            blockId: placeholderId,
                            mediaType: 'image',
                            awaitingServer: true,
                        });
                    }

                    if (articleId) {
                        setSaveStatus('saving');
                        saveDraft(articleId, {
                            blocks: blocksRef.current,
                            html: getExportHtml(),
                        });
                        setSaveStatus('saved');
                    }
                }
            }

            setArticleAutosaveLock('generate-image-request', true);

            try {
                await callEditArticleLivewire(
                    'generateArticleImageFromEditor',
                    selectionText,
                    String(payload.selectionHtml ?? ''),
                    userBrief,
                    activeBlockIdFromPayload,
                    target,
                    Number.parseInt(String(payload.loaiSanPhamCategoryArticleId ?? 0), 10) || 0,
                    String(payload.loaiSanPhamCustom ?? '').trim(),
                );
            } catch (error) {
                clearAwaitingClientImagePlaceholders();
                window.dispatchEvent(
                    new CustomEvent('article-ai-media-failed', {
                        detail: {
                            type: 'image',
                            message: error?.message ?? t('editor_generate_image_failed'),
                        },
                    }),
                );
            } finally {
                setArticleAutosaveLock('generate-image-request', false);
            }
        },
        [
            articleId,
            clearAwaitingClientImagePlaceholders,
            commitActiveBlock,
            getExportHtml,
            placeProcessingImagePlaceholder,
            resolveAiRefBlockId,
        ],
    );

    const insertVideoAfterBlock = useCallback(
        (refBlockId, videoUrl) => {
            const url = (videoUrl ?? '').trim();
            if (!refBlockId || !url) {
                return;
            }
            if (tempMergeRef.current) {
                return;
            }

            commitActiveBlock();

            const safeUrl = url
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;');
            const newBlock = {
                ...createEmptyTextBlock(),
                content: `<figure class="wp-block-video"><video controls src="${safeUrl}"></video></figure>`,
            };

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === refBlockId);
                if (index < 0) {
                    return prev;
                }
                const next = [...prev];
                next.splice(index + 1, 0, newBlock);
                return normalizeBlocks(next);
            });

            setActiveBlockId(newBlock.id);
            setGlobalEditor(null);
        },
        [commitActiveBlock],
    );

    const toggleInsertMenu = useCallback((blockId, position) => {
        setInsertMenu((current) =>
            current?.blockId === blockId && current?.position === position
                ? null
                : { blockId, position },
        );
    }, []);

    const moveBlockToSection = useCallback(
        (blockId, direction) => {
            if (tempMergeRef.current) {
                return;
            }

            const id = String(blockId ?? '').trim();
            if (!id) {
                return;
            }

            const movingBlock = blocksRef.current.find((block) => block.id === id);
            if (
                movingBlock &&
                outlineHasSavedHeadings &&
                blockHasOutlineHeading(movingBlock) &&
                !sectionHeadingBlockIds.has(id)
            ) {
                return;
            }

            const currentSectionIndex = editorSections.findIndex((section) => section.blockIds.includes(id));
            if (currentSectionIndex < 0) {
                return;
            }

            const targetSectionIndex =
                direction === 'prev' ? currentSectionIndex - 1 : currentSectionIndex + 1;
            if (targetSectionIndex < 0 || targetSectionIndex >= editorSections.length) {
                return;
            }

            const targetSection = editorSections[targetSectionIndex];
            const targetIds = (targetSection?.blockIds ?? []).filter((candidateId) => candidateId !== id);

            setInsertMenu(null);

            const activeId = activeBlockIdRef.current;
            let flushedHtml = null;
            const activeEditor = activeId ? blockEditorsRef.current.get(activeId) : null;

            if (activeEditor && !activeEditor.isDestroyed) {
                const sourceHtml = blocksRef.current.find((block) => block.id === activeId)?.content ?? '';
                flushedHtml = persistBlockHtmlFromEditor(sourceHtml, activeEditor.getHTML());
            } else {
                blockFlushRef.current?.();
            }

            setBlocks((prev) => {
                let working = prev;
                if (flushedHtml != null && activeId) {
                    working = working.map((block) =>
                        block.id === activeId ? { ...block, content: flushedHtml } : block,
                    );
                }

                const fromIndex = working.findIndex((block) => block.id === id);
                if (fromIndex < 0) {
                    return prev;
                }

                const moving = working[fromIndex];
                if (targetSection?.isIntro && moving?.type === 'image') {
                    notifyIntroNoImages();

                    return prev;
                }

                const next = [...working];
                next.splice(fromIndex, 1);

                let insertAt = next.length;
                if (direction === 'prev') {
                    const lastTargetId = targetIds[targetIds.length - 1];
                    const lastIndex = lastTargetId ? next.findIndex((block) => block.id === lastTargetId) : -1;
                    insertAt = lastIndex >= 0 ? lastIndex + 1 : 0;
                } else {
                    const lastTargetId = targetIds[targetIds.length - 1];
                    const lastIndex = lastTargetId ? next.findIndex((block) => block.id === lastTargetId) : -1;
                    insertAt = lastIndex >= 0 ? lastIndex + 1 : next.length;
                }

                next.splice(insertAt, 0, moving);

                return next;
            });

            setActiveBlockId(id);
            setGlobalEditor(null);
        },
        [editorSections, notifyIntroNoImages, outlineHasSavedHeadings, sectionHeadingBlockIds],
    );

    const moveSection = useCallback(
        (sectionId, direction) => {
            if (tempMergeRef.current) {
                return;
            }

            const sections = editorSections;
            const currentIndex = sections.findIndex((section) => section.id === sectionId);
            if (currentIndex < 0) {
                return;
            }

            const section = sections[currentIndex];
            if (section?.isIntro) {
                return;
            }

            const headingBlock = blocksRef.current.find((block) => block.id === section.blockIds[0]);
            if (!isSectionHeadingBlock(headingBlock, section)) {
                return;
            }

            const targetIndex = direction === 'prev' ? currentIndex - 1 : currentIndex + 1;
            if (targetIndex < 0 || targetIndex >= sections.length) {
                return;
            }

            const targetSection = sections[targetIndex];
            if (targetSection?.isIntro) {
                return;
            }

            setInsertMenu(null);

            const activeId = activeBlockIdRef.current;
            let flushedHtml = null;
            const activeEditor = activeId ? blockEditorsRef.current.get(activeId) : null;

            if (activeEditor && !activeEditor.isDestroyed) {
                const sourceHtml = blocksRef.current.find((block) => block.id === activeId)?.content ?? '';
                flushedHtml = persistBlockHtmlFromEditor(sourceHtml, activeEditor.getHTML());
            } else {
                blockFlushRef.current?.();
            }

            setBlocks((prev) => {
                let working = prev;
                if (flushedHtml != null && activeId) {
                    working = working.map((block) =>
                        block.id === activeId ? { ...block, content: flushedHtml } : block,
                    );
                }

                const fromBlocks = section.blockIds
                    .map((blockId) => working.find((block) => block.id === blockId))
                    .filter(Boolean);
                if (fromBlocks.length !== section.blockIds.length) {
                    return prev;
                }

                const withoutMoved = working.filter((block) => !section.blockIds.includes(block.id));
                const targetStart = withoutMoved.findIndex((block) => block.id === targetSection.blockIds[0]);
                if (targetStart < 0) {
                    return prev;
                }

                const insertAt =
                    direction === 'prev'
                        ? targetStart
                        : targetStart + targetSection.blockIds.length;

                return [
                    ...withoutMoved.slice(0, insertAt),
                    ...fromBlocks,
                    ...withoutMoved.slice(insertAt),
                ];
            });

            setActiveBlockId(section.blockIds.find((blockId) => !sectionHeadingBlockIds.has(blockId)) ?? section.blockIds[0] ?? null);
            setGlobalEditor(null);
        },
        [editorSections, sectionHeadingBlockIds],
    );

    const deleteSection = useCallback(
        (section, options = {}) => {
            if (section?.isIntro) {
                return;
            }

            const headingBlockId = section.blockIds[0];
            const headingBlock = blocksRef.current.find((block) => block.id === headingBlockId);
            if (!isSectionHeadingBlock(headingBlock, section)) {
                return;
            }

            if (blocksRef.current.length <= section.blockIds.length) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('cannot_delete_last_block'),
                            body: t('editor_delete_section_last_block_hint'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            const skipConfirm = options.skipConfirm === true;
            if (!skipConfirm && !window.confirm(t('editor_delete_section_confirm'))) {
                return;
            }

            commitActiveBlock();
            setInsertMenu(null);

            const idsToRemove = new Set(section.blockIds);
            if (activeBlockId && idsToRemove.has(activeBlockId)) {
                blockFlushRef.current = null;
                setActiveBlockId(null);
                setGlobalEditor(null);
                dispatchActiveBlockContext(articleId, '', '', false, null);
            }

            setBlocks((prev) => {
                const next = prev.filter((block) => !idsToRemove.has(block.id));
                return next.length > 0 ? normalizeBlocks(next) : prev;
            });

            outlineAppendDoneRef.current.delete(headingBlockId);
            outlineAppendInflightRef.current.delete(headingBlockId);

            const headingId = outlineHeadingIdsByBlockIdRef.current.get(headingBlockId);
            if (headingId != null) {
                outlineHeadingIdsByBlockIdRef.current.delete(headingBlockId);
                void outlineApiRequest(articleId, `/${headingId}`, { method: 'DELETE' }).catch(() => {});
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'remove',
                    headingId,
                });
            } else {
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'remove',
                    headingId: `pending-${headingBlockId}`,
                });
                const meta = extractOutlineHeadingFromBlock(headingBlock);
                if (meta) {
                    setOutlineHeadingKeys((prev) => {
                        const next = new Set(prev);
                        next.delete(outlineHeadingKey(meta.level, meta.headingText));
                        return next;
                    });
                }
            }
        },
        [activeBlockId, articleId, commitActiveBlock],
    );

    const resolveSectionForOutlineNode = useCallback(
        (node) => {
            if (!node || Number(node.level) !== 2) {
                return null;
            }

            for (const section of editorSections) {
                if (section.isIntro) {
                    continue;
                }

                const blockId = section.blockIds[0];
                const headingId = outlineHeadingIdsByBlockIdRef.current.get(blockId);
                if (headingId != null && Number(headingId) === Number(node.id)) {
                    return section;
                }
            }

            const blockId = findBlockIdForOutlineHeading(
                blocksRef.current,
                Number(node.level),
                String(node.heading_text ?? ''),
            );
            if (!blockId) {
                return null;
            }

            const section = editorSections.find((item) => item.blockIds[0] === blockId) ?? null;
            if (!section || section.isIntro) {
                return null;
            }

            const block = blocksRef.current.find((item) => item.id === blockId);
            if (!isSectionHeadingBlock(block, section)) {
                return null;
            }

            return section;
        },
        [editorSections],
    );

    const removeHeadingFromBlocks = useCallback((level, headingText) => {
        const targetLevel = Number(level) || 0;
        const target = normalizeOutlineHeadingText(headingText);
        if (target === '') {
            return;
        }

        const selector = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2, h3, h4';

        setBlocks((prev) =>
            prev.map((block) => {
                if (block.type !== 'text' || !block.content) {
                    return block;
                }

                const doc = new DOMParser().parseFromString(block.content, 'text/html');
                const headingNode = Array.from(doc.body.querySelectorAll(selector)).find(
                    (node) => normalizeOutlineHeadingText(node.textContent) === target,
                );
                if (!headingNode) {
                    return block;
                }

                headingNode.remove();
                const nextContent = doc.body.innerHTML.trim();

                return {
                    ...block,
                    content: nextContent !== '' ? nextContent : '<p></p>',
                };
            }),
        );
    }, []);

    const purgeOutlineHeadingState = useCallback((node) => {
        const level = Number(node?.level ?? 0);
        const text = normalizeOutlineHeadingText(node?.heading_text);

        for (const [blockId, headingId] of outlineHeadingIdsByBlockIdRef.current.entries()) {
            if (Number(headingId) === Number(node?.id)) {
                outlineHeadingIdsByBlockIdRef.current.delete(blockId);
                outlineAppendDoneRef.current.delete(blockId);
            }
        }

        if (text !== '') {
            setOutlineHeadingKeys((prev) => {
                const next = new Set(prev);
                next.delete(outlineHeadingKey(level, text));
                return next;
            });
        }
    }, []);

    const handleOutlineMoveHeading = useCallback(
        (node, direction) => {
            if (!node) {
                return;
            }

            const level = Number(node.level ?? 0);

            if (level === 2) {
                const section = resolveSectionForOutlineNode(node);
                if (section) {
                    moveSection(section.id, direction);
                }

                return;
            }

            const blockId = findBlockIdForOutlineHeading(
                blocksRef.current,
                level,
                String(node.heading_text ?? ''),
            );
            if (!blockId) {
                return;
            }

            const section = editorSections.find((item) => item.blockIds[0] === blockId) ?? null;
            if (!section || section.isIntro) {
                return;
            }

            const block = blocksRef.current.find((item) => item.id === blockId);
            if (!isSectionHeadingBlock(block, section)) {
                return;
            }

            moveSection(section.id, direction);
        },
        [editorSections, moveSection, resolveSectionForOutlineNode],
    );

    const handleOutlineDeleteHeading = useCallback(
        (node) => {
            if (!node?.id) {
                return;
            }

            const level = Number(node.level ?? 0);

            if (level === 2) {
                const section = resolveSectionForOutlineNode(node);
                if (section) {
                    deleteSection(section);

                    return;
                }

                if (!window.confirm(t('editor_delete_section_confirm'))) {
                    return;
                }

                purgeOutlineHeadingState(node);
                void outlineApiRequest(articleId, `/${node.id}`, { method: 'DELETE' }).catch(() => {});
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'remove',
                    headingId: node.id,
                });

                return;
            }

            if (
                !window.confirm(
                    `Xóa heading H${level} "${String(node.heading_text ?? '').trim()}" khỏi bài viết?`,
                )
            ) {
                return;
            }

            commitActiveBlock();
            removeHeadingFromBlocks(level, node.heading_text);
            purgeOutlineHeadingState(node);
            void outlineApiRequest(articleId, `/${node.id}`, { method: 'DELETE' }).catch(() => {});
            setOutlineTreeSync({
                token: Date.now(),
                action: 'remove',
                headingId: node.id,
            });
        },
        [
            articleId,
            commitActiveBlock,
            deleteSection,
            purgeOutlineHeadingState,
            removeHeadingFromBlocks,
            resolveSectionForOutlineNode,
        ],
    );

    const startTempMerge = useCallback(
        (targetId) => {
            if (!activeBlockId || !targetId || activeBlockId === targetId) return;

            const rangeBlocks = getBlocksInRange(blocksRef.current, activeBlockId, targetId);
            if (rangeBlocks.length < 2) return;

            setTempMerge({
                anchorId: activeBlockId,
                rangeIds: rangeBlocks.map((b) => b.id),
                mergedHtml: mergeBlockHtmlContents(rangeBlocks),
            });
        },
        [activeBlockId],
    );

    useEffect(() => {
        const enrichExtractFaq = (event) => {
            const selectionHtml = event.detail?.html ?? '';
            if (!selectionHtml.trim()) {
                return;
            }

            window.dispatchEvent(
                new CustomEvent('extract-article-faqs-with-context', {
                    detail: {
                        html: selectionHtml,
                        articleHtml: getExportHtml(),
                    },
                }),
            );
        };

        const applyEditorHtml = (event) => {
            const html = stripLeadingH1FromHtml(event.detail?.editorHtml ?? '');
            if (!html) {
                return;
            }

            skipNextAutosave.current = true;
            clearTempMerge();
            blockFlushRef.current = null;
            setActiveBlockId(null);
            setGlobalEditor(null);
            setBlocks(enrichBlocksWithPostImages(parseHtmlToBlocks(html), postImagesRef.current));
            saveDraft(articleId, { blocks: parseHtmlToBlocks(html), html });
            setSaveStatus('saved');
        };

        const onRevisionRestore = (event) => {
            const html = stripLeadingH1FromHtml(event.detail?.content ?? event.detail?.html ?? '');
            if (!html) {
                return;
            }

            skipNextAutosave.current = true;
            clearTempMerge();
            blockFlushRef.current = null;
            setActiveBlockId(null);
            setGlobalEditor(null);
            const parsedBlocks = parseHtmlToBlocks(html);
            setBlocks(enrichBlocksWithPostImages(parsedBlocks, postImagesRef.current));
            saveDraft(articleId, { blocks: parsedBlocks, html });
            setSaveStatus('saved');
        };

        const onCollectEditorHtml = (event) => {
            blockFlushRef.current?.();
            clearTempMerge();
            setActiveBlockId(null);
            setGlobalEditor(null);

            const detail =
                event.detail != null && typeof event.detail === 'object' && !Array.isArray(event.detail)
                    ? event.detail
                    : {};
            const target = detail.target ?? 'save';
            window.dispatchEvent(
                new CustomEvent('editor-html-collected', {
                    detail: {
                        html: getExportHtml(),
                        target,
                    },
                }),
            );
        };

        window.addEventListener('collect-editor-html', onCollectEditorHtml);
        window.addEventListener('extract-article-faqs', enrichExtractFaq);
        window.addEventListener('article-faqs-extracted', applyEditorHtml);
        window.addEventListener('seo-article-revision-restore', onRevisionRestore);

        const onPostImagesSynced = (event) => {
            const images = event.detail?.images;
            if (!Array.isArray(images)) {
                return;
            }
            setPostImages(images);
            postImagesRef.current = images;
        };

        const onSupplementalImagesSynced = (event) => {
            const images = event.detail?.images;
            if (!Array.isArray(images)) {
                return;
            }
            setSupplementalImages(images);
        };

        window.addEventListener('article-post-images-synced', onPostImagesSynced);
        window.addEventListener('article-supplemental-images-synced', onSupplementalImagesSynced);

        const onRequestEditorImagesCatalog = () => {
            const images = buildMergedEditorImagesForPicker(blocksRef.current, supplementalImages);
            window.dispatchEvent(
                new CustomEvent('seo-editor-images-catalog', {
                    detail: { images, tab: 'article' },
                }),
            );
        };

        window.addEventListener('seo-request-editor-images-catalog', onRequestEditorImagesCatalog);

        const syncPanelFaqs = (event) => {
            const fromExtract = event.detail?.faqs;
            if (Array.isArray(fromExtract)) {
                setPanelFaqs(fromExtract);
            }
        };

        const syncPanelFaqsFromEditor = (event) => {
            const rows = event.detail?.faq;
            if (Array.isArray(rows)) {
                setPanelFaqs(rows);
            }
        };

        window.addEventListener('article-faqs-extracted', syncPanelFaqs);
        window.addEventListener('seo-editor-faqs-updated', syncPanelFaqsFromEditor);

        const handleAnalyzeResult = (e) => {
            const result = e.detail?.result ?? e.detail;
            if (!result || typeof result !== 'object') return;
            setAnalyzing(false);
            setAnalysis({
                score: result.score ?? 0,
                good: result.good ?? [],
                errors: result.errors ?? [],
                warnings: result.warnings ?? [],
                content_bonus: result.content_bonus ?? null,
            });
            if (result.content_bonus) {
                setContentBonus(result.content_bonus);
            }
            if (result.extracted_links) {
                const suggested = Array.isArray(result.suggested_internal_links)
                    ? result.suggested_internal_links
                    : [];
                const filteredSuggested = filterSuggestedInternalLinks(
                    suggested,
                    result.extracted_links.internal ?? [],
                );
                setExtractedLinks(result.extracted_links);
                setSuggestedInternalLinks(filteredSuggested);
                publishExtractedLinks(result.extracted_links, filteredSuggested);
            }
        };

        const handleClickOutside = (e) => {
            if (
                e.target.closest('.block-editor-active') ||
                e.target.closest('.block-image-active') ||
                e.target.closest('.seo-block-toolbar') ||
                e.target.closest('.seo-block-preview') ||
                e.target.closest('.seo-link-bubble') ||
                e.target.closest('.seo-block-images-panel') ||
                e.target.closest('.seo-ai-chat-panel') ||
                e.target.closest('.seo-ai-fab') ||
                e.target.closest('.wp-article-links-box') ||
                e.target.closest('.wp-article-links-keyword') ||
                e.target.closest('.seo-article-faq-panel') ||
                e.target.closest('.seo-faq-item') ||
                e.target.closest('.seo-faq-shortcode-block') ||
                e.target.closest('.omi-faq-editor-preview') ||
                e.target.closest('.seo-fmt-dropdown-menu') ||
                e.target.closest('.seo-block-insert-bar') ||
                e.target.closest('.seo-block-insert-trigger') ||
                e.target.closest('.seo-block-insert-menu') ||
                e.target.closest('.seo-editor-block-slot') ||
                e.target.closest('.seo-section-element-actions') ||
                e.target.closest('.seo-block-editor-resize') ||
                e.target.closest('.seo-image-block-picker')
            ) {
                return;
            }

            setInsertMenu(null);

            if (tempMergeRef.current) {
                clearTempMerge();
                setActiveBlockId(null);
                setGlobalEditor(null);
                return;
            }

            blockFlushRef.current?.();
            setActiveBlockId(null);
            setGlobalEditor(null);
        };

        const handleFocusKeywordUpdated = (e) => {
            const keyword = e.detail?.focus_keyword ?? null;
            setFocusKeyword(keyword);
            setAnalyzing(true);
            requestAnalyze();
        };

        window.addEventListener('seo-editor-analyze-result', handleAnalyzeResult);
        window.addEventListener('seo-focus-keyword-updated', handleFocusKeywordUpdated);
        document.addEventListener('mousedown', handleClickOutside);

        const onImageGenerateRequest = (event) => {
            const blockId = event.detail?.blockId;
            const prompt = (event.detail?.prompt ?? '').trim();
            const mediaKind = String(event.detail?.mediaKind ?? 'image').toLowerCase() === 'video' ? 'video' : 'image';
            if (!blockId || !prompt) {
                return;
            }

            if (mediaKind === 'image' && sectionByBlockId.get(String(blockId)) === INTRO_SECTION_ID) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_intro'),
                            body: t('editor_intro_no_images'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            if (mediaKind === 'video') {
                window.dispatchEvent(
                    new CustomEvent('generate-article-video', {
                        detail: {
                            selectionText: '',
                            selectionHtml: '',
                            userBrief: prompt,
                            activeBlockId: blockId,
                            articleId,
                        },
                    }),
                );
                return;
            }

            window.dispatchEvent(
                new CustomEvent('generate-article-image', {
                    detail: {
                        selectionText: '',
                        selectionHtml: '',
                        userBrief: prompt,
                        activeBlockId: blockId,
                        articleId,
                    },
                }),
            );
        };

        window.addEventListener('seo-editor-image-generate-request', onImageGenerateRequest);

        const persistSelectedMediaBlock = (blockId, content, image) => {
            const nextBlocks = blocksRef.current.map((block) =>
                block.id === blockId
                    ? {
                          ...block,
                          content,
                          image,
                      }
                    : block,
            );

            blocksRef.current = nextBlocks;
            setBlocks(nextBlocks);

            if (articleId) {
                saveDraft(articleId, {
                    blocks: nextBlocks,
                    html: exportBlocksToHtml(nextBlocks),
                });
                setSaveStatus('saved');
            }
        };

        const onEditorBlockImageSelected = (event) => {
            const blockId = String(event.detail?.blockId ?? '').trim();
            const rawUrl = (event.detail?.url ?? '').trim();
            const attachmentId = Number(event.detail?.attachmentId ?? 0);
            const mediaType = String(event.detail?.mediaType ?? event.detail?.media_type ?? 'image').toLowerCase();
            if (!blockId || !rawUrl) return;

            if (mediaType !== 'video' && sectionByBlockId.get(blockId) === INTRO_SECTION_ID) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_intro'),
                            body: t('editor_intro_no_images'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            const url = resolveFullWordPressImageUrl(rawUrl);
            if (mediaType === 'video') {
                const video = {
                    src: url,
                    alt: '',
                    title: '',
                    slug: (event.detail?.slug ?? '').trim() || slugFromUrl(url) || undefined,
                    align: 'none',
                    mediaType: 'video',
                    wpAttachmentId: attachmentId > 0 ? attachmentId : undefined,
                    seoMediaId: Number(event.detail?.seoMediaId ?? event.detail?.id ?? 0) || undefined,
                    wpSrc: url,
                };
                const safeUrl = url
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;')
                    .replace(/</g, '&lt;');
                persistSelectedMediaBlock(
                    blockId,
                    `<figure class="wp-block-video"><video controls src="${safeUrl}"></video></figure>`,
                    video,
                );
                return;
            }

            const slug = (event.detail?.slug ?? '').trim() || slugFromUrl(url);
            const kw = String(window.__SEO_MAIN_KEYWORD__ ?? '').trim();
            const alt = kw || (event.detail?.alt ?? '').trim();
            const seoMediaId = Number(event.detail?.seoMediaId ?? event.detail?.id ?? 0);
            const image = {
                src: url,
                alt,
                title: alt,
                wpAttachmentId: attachmentId > 0 ? attachmentId : undefined,
                seoMediaId: seoMediaId > 0 ? seoMediaId : undefined,
                slug: slug || undefined,
                wpSrc: url,
            };
            const html = renderImageFigure(image);
            persistSelectedMediaBlock(blockId, html, image);
        };

        window.addEventListener('editor-block-image-selected', onEditorBlockImageSelected);

        const onArticleAiImageGenerated = (event) => {
            const requestedBlockId = (event.detail?.activeBlockId ?? '').trim();
            const url = (event.detail?.url ?? '').trim();
            const status = String(event.detail?.status ?? '').toLowerCase();
            const mediaId = Number(event.detail?.seoMediaId ?? 0);
            const target = String(event.detail?.target ?? generateImageTargetRef.current ?? 'editor').trim() || 'editor';
            if (!url && status !== 'processing') {
                return;
            }

            if (target === 'product-gallery') {
                if (status === 'processing' && mediaId > 0) {
                    pendingAiMediaRef.current.set(mediaId, {
                        target: 'product-gallery',
                        mediaType: 'image',
                    });
                    startMediaStatusPolling(mediaId, 'image');
                    generateImageTargetRef.current = 'editor';
                    return;
                }

                if (status === 'completed' && mediaId > 0) {
                    const galleryItems = Array.isArray(event.detail?.gallery_urls) && event.detail.gallery_urls.length > 0
                        ? event.detail.gallery_urls
                        : (Array.isArray(event.detail?.galleryUrls) && event.detail.galleryUrls.length > 0
                            ? event.detail.galleryUrls
                            : null);
                    applyCompletedMediaToProductGallery(mediaId, url, galleryItems);
                    generateImageTargetRef.current = 'editor';
                }

                return;
            }

            const fallbackActiveBlockId = String(activeBlockIdRef.current ?? '').trim();
            const refBlockId = resolveAiRefBlockId(requestedBlockId || fallbackActiveBlockId);
            if (!refBlockId) {
                return;
            }

            const isProcessingStatus = status === 'processing' || status === 'pending';

            if (isProcessingStatus && mediaId > 0) {
                const awaitingEntry = [...pendingAiMediaRef.current.entries()].find(
                    ([, value]) => value?.awaitingServer && value?.blockId,
                );
                if (awaitingEntry) {
                    const [clientKey, pending] = awaitingEntry;
                    pendingAiMediaRef.current.delete(clientKey);
                    patchImageInBlocks(
                        pending.blockId,
                        {
                            seoMediaId: mediaId,
                            isProcessing: true,
                            src: url || AI_PLACEHOLDER_LOADING_URL,
                        },
                        true,
                    );
                    pendingAiMediaRef.current.set(mediaId, {
                        blockId: pending.blockId,
                        mediaType: 'image',
                    });
                    startMediaStatusPolling(mediaId, 'image');
                    window.dispatchEvent(
                        new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                    );

                    return;
                }
            }

            if (mediaId > 0 && isProcessingStatus) {
                const existingBlock = findImageBlockByMediaId(mediaId);
                if (existingBlock) {
                    if (!pendingAiMediaRef.current.has(mediaId)) {
                        pendingAiMediaRef.current.set(mediaId, {
                            blockId: existingBlock.id,
                            mediaType: 'image',
                        });
                    }
                    startMediaStatusPolling(mediaId, 'image');
                    return;
                }
            }

            if (isProcessingStatus && mediaId > 0) {
                if (pendingAiMediaRef.current.has(mediaId)) {
                    // Đảm bảo luôn có polling kể cả khi event "processing" đến lặp/khôi phục.
                    const pending = pendingAiMediaRef.current.get(mediaId);
                    const pendingBlockId = String(pending?.blockId ?? '').trim();
                    const hasPendingBlock = pendingBlockId
                        ? blocksRef.current.some((block) => block.id === pendingBlockId)
                        : false;

                    if (!hasPendingBlock) {
                        const recreatedId = placeProcessingImagePlaceholder(refBlockId, url, {
                            seoMediaId: mediaId,
                            isProcessing: true,
                        });
                        if (recreatedId) {
                            pendingAiMediaRef.current.set(mediaId, {
                                blockId: recreatedId,
                                mediaType: 'image',
                            });
                        }
                    }

                    startMediaStatusPolling(mediaId, 'image');
                    return;
                }

                const placeholderId = placeProcessingImagePlaceholder(refBlockId, url, {
                    seoMediaId: mediaId,
                    isProcessing: true,
                });
                if (placeholderId) {
                    pendingAiMediaRef.current.set(mediaId, {
                        blockId: placeholderId,
                        mediaType: 'image',
                    });
                    startMediaStatusPolling(mediaId, 'image');
                }

                window.dispatchEvent(
                    new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                );

                return;
            }

            if (status === 'completed' && mediaId > 0 && url) {
                if (applyCompletedMediaToPlaceholder(mediaId, 'image', url)) {
                    return;
                }

                const existingBlock = findImageBlockByMediaId(mediaId);
                if (existingBlock) {
                    patchImageInBlocks(
                        existingBlock.id,
                        {
                            src: url,
                            title: '',
                            alt: '',
                            isProcessing: false,
                        },
                        true,
                    );
                    pendingAiMediaRef.current.delete(mediaId);
                    setImagesReloadKey((k) => k + 1);
                    return;
                }

                const insertedId = placeProcessingImagePlaceholder(refBlockId, url, {
                    seoMediaId: mediaId,
                    isProcessing: false,
                });
                if (insertedId) {
                    pendingAiMediaRef.current.set(mediaId, {
                        blockId: insertedId,
                        mediaType: 'image',
                    });
                }

                return;
            }

            if (status === 'failed') {
                return;
            }

            // Legacy: đồng bộ URL ngay, không có mediaId (tránh chèn trùng khi đã có job processing).
            if (url && mediaId <= 0) {
                placeProcessingImagePlaceholder(refBlockId, url);
            }
        };

        const onArticleAiVideoGenerated = (event) => {
            const requestedBlockId = (event.detail?.activeBlockId ?? '').trim();
            const url = (event.detail?.url ?? '').trim();
            const status = String(event.detail?.status ?? '').toLowerCase();
            const mediaId = Number(event.detail?.seoMediaId ?? 0);

            if (!url) {
                return;
            }

            const fallbackActiveBlockId = String(activeBlockIdRef.current ?? '').trim();
            const refBlockId = resolveAiRefBlockId(requestedBlockId || fallbackActiveBlockId);
            if (!refBlockId) {
                return;
            }

            if (status === 'processing' && mediaId > 0) {
                if (pendingAiMediaRef.current.has(mediaId)) {
                    // Đảm bảo luôn có polling kể cả khi event "processing" đến lặp/khôi phục.
                    const pending = pendingAiMediaRef.current.get(mediaId);
                    const pendingBlockId = String(pending?.blockId ?? '').trim();
                    const hasPendingBlock = pendingBlockId
                        ? blocksRef.current.some((block) => block.id === pendingBlockId)
                        : false;

                    if (!hasPendingBlock) {
                        const recreatedId = insertImageAfterBlock(refBlockId, url, {
                            seoMediaId: mediaId,
                            isProcessing: true,
                        });
                        if (recreatedId) {
                            pendingAiMediaRef.current.set(mediaId, {
                                blockId: recreatedId,
                                mediaType: 'video',
                            });
                        }
                    }

                    startMediaStatusPolling(mediaId, 'video');
                    return;
                }

                const placeholderId = insertImageAfterBlock(refBlockId, url, {
                    seoMediaId: mediaId,
                    isProcessing: true,
                });
                if (placeholderId) {
                    pendingAiMediaRef.current.set(mediaId, {
                        blockId: placeholderId,
                        mediaType: 'video',
                    });
                    startMediaStatusPolling(mediaId, 'video');
                }

                window.dispatchEvent(
                    new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                );

                return;
            }

            if (status === 'completed' && mediaId > 0 && applyCompletedMediaToPlaceholder(mediaId, 'video', url)) {
                return;
            }

            if (status === 'failed') {
                return;
            }

            if (status === 'completed' || status === 'processing' || status === 'pending') {
                return;
            }

            if (url) {
                insertVideoAfterBlock(refBlockId, url);
            }
        };

        window.addEventListener('article-ai-image-generated', onArticleAiImageGenerated);
        window.addEventListener('article-ai-video-generated', onArticleAiVideoGenerated);

        return () => {
            window.removeEventListener('article-ai-video-generated', onArticleAiVideoGenerated);
            window.removeEventListener('article-ai-image-generated', onArticleAiImageGenerated);
            window.removeEventListener('editor-block-image-selected', onEditorBlockImageSelected);
            window.removeEventListener('seo-editor-image-generate-request', onImageGenerateRequest);
            window.removeEventListener('collect-editor-html', onCollectEditorHtml);
            window.removeEventListener('extract-article-faqs', enrichExtractFaq);
            window.removeEventListener('article-faqs-extracted', applyEditorHtml);
            window.removeEventListener('seo-article-revision-restore', onRevisionRestore);
            window.removeEventListener('article-post-images-synced', onPostImagesSynced);
            window.removeEventListener('article-supplemental-images-synced', onSupplementalImagesSynced);
            window.removeEventListener('seo-request-editor-images-catalog', onRequestEditorImagesCatalog);
            window.removeEventListener('article-faqs-extracted', syncPanelFaqs);
            window.removeEventListener('seo-editor-faqs-updated', syncPanelFaqsFromEditor);
            window.removeEventListener('seo-editor-analyze-result', handleAnalyzeResult);
            window.removeEventListener('seo-focus-keyword-updated', handleFocusKeywordUpdated);
            document.removeEventListener('mousedown', handleClickOutside);
            for (const timer of mediaPollTimersRef.current.values()) {
                window.clearTimeout(timer);
            }
            mediaPollTimersRef.current.clear();
            pendingAiMediaRef.current.clear();
        };
    }, [
        activeBlockId,
        globalEditor,
        applyCompletedMediaToPlaceholder,
        applyCompletedMediaToProductGallery,
        findImageBlockByMediaId,
        patchImageInBlocks,
        placeProcessingImagePlaceholder,
        resolveAiRefBlockId,
        updateBlockContent,
        clearTempMerge,
        articleId,
        getExportHtml,
        initialPostImages,
        insertImageAfterBlock,
        insertVideoAfterBlock,
        startMediaStatusPolling,
    ]);

    useEffect(() => {
        if (blocks.length === 0) return;
        if (skipNextAutosave.current) {
            skipNextAutosave.current = false;
            requestAnalyze();
            return;
        }
        scheduleAutosave();
    }, [blocks, scheduleAutosave, requestAnalyze]);

    useEffect(() => {
        if (activeTab === 'seo') {
            requestAnalyze();
        }
    }, [activeTab, requestAnalyze]);

    useEffect(() => {
        const onGenerateImage = (event) => {
            void requestGenerateArticleImage(event.detail);
        };

        const onImageFailed = (event) => {
            if (String(event.detail?.type ?? '') !== 'image') {
                return;
            }

            clearAwaitingClientImagePlaceholders();
        };

        window.addEventListener('generate-article-image', onGenerateImage);
        window.addEventListener('article-ai-media-failed', onImageFailed);

        return () => {
            window.removeEventListener('generate-article-image', onGenerateImage);
            window.removeEventListener('article-ai-media-failed', onImageFailed);
        };
    }, [clearAwaitingClientImagePlaceholders, requestGenerateArticleImage]);

    useEffect(() => {
        activeTabRef.current = activeTab;
    }, [activeTab]);

    const saveLabel =
        saveStatus === 'saving'
            ? t('editor_saving_draft')
            : saveStatus === 'pending'
              ? t('editor_draft_pending')
              : t('editor_draft_saved_local');

    const mergedDisplay =
        tempMerge && activeBlockId === tempMerge.anchorId ? tempMerge.mergedHtml : undefined;

    // Sync text heading từ tab Outline về block tương ứng trong editor chính.
    const resolveBlockIdForOutlineHeadingId = useCallback((headingId) => {
        const targetId = Number(headingId);
        if (!Number.isFinite(targetId)) {
            return null;
        }

        for (const [blockId, mappedId] of outlineHeadingIdsByBlockIdRef.current.entries()) {
            if (Number(mappedId) === targetId) {
                return blockId;
            }
        }

        return null;
    }, []);

    const applyOutlineHeadingText = useCallback(({ level, oldText, newText, headingId = null }) => {
        const normalizeText = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();
        const targetLevel = Number(level) || 0;
        const target = normalizeText(oldText);
        const replacement = normalizeText(newText);
        if (target === '' || replacement === '' || target === replacement) {
            return;
        }

        const selector = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2, h3, h4';
        const mappedBlockId = resolveBlockIdForOutlineHeadingId(headingId);
        const headingTag = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2';
        let replaced = false;

        setBlocks((prev) =>
            prev.map((block) => {
                if (replaced || block.type !== 'text' || !block.content) {
                    return block;
                }

                if (mappedBlockId && block.id !== mappedBlockId) {
                    return block;
                }

                const doc = new DOMParser().parseFromString(block.content, 'text/html');
                let headingNode = Array.from(doc.body.querySelectorAll(selector)).find(
                    (node) => normalizeText(node.textContent) === target,
                );

                if (!headingNode && mappedBlockId === block.id) {
                    headingNode = doc.body.querySelector(selector);
                }

                if (!headingNode && mappedBlockId === block.id) {
                    replaced = true;

                    return {
                        ...block,
                        content: `<${headingTag}>${replacement}</${headingTag}><p></p>`,
                    };
                }

                if (!headingNode) {
                    return block;
                }

                headingNode.textContent = replacement;
                replaced = true;

                return { ...block, content: doc.body.innerHTML };
            }),
        );

        setOutlineHeadingKeys((prev) => {
            const next = new Set(prev);
            const oldKey = outlineHeadingKey(targetLevel, target);
            const newKey = outlineHeadingKey(targetLevel, replacement);
            if (next.has(oldKey)) {
                next.delete(oldKey);
            }
            next.add(newKey);

            const headingId = outlineHeadingIdsByKeyRef.current.get(oldKey);
            if (headingId != null) {
                outlineHeadingIdsByKeyRef.current.delete(oldKey);
                outlineHeadingIdsByKeyRef.current.set(newKey, headingId);
            }

            return next;
        });
    }, [resolveBlockIdForOutlineHeadingId]);

    const resolveHeadingInnerHtml = useCallback((node) => {
        const level = Number(node?.level ?? 0);
        const headingText = normalizeOutlineHeadingText(node?.heading_text);
        if (headingText === '') {
            return '';
        }

        const blockId =
            resolveBlockIdForOutlineHeadingId(node?.id) ??
            findBlockIdForOutlineHeading(blocksRef.current, level, headingText);
        if (!blockId) {
            return '';
        }

        const block = blocksRef.current.find((item) => item.id === blockId);
        if (!block?.content) {
            return '';
        }

        const selector = level >= 2 && level <= 4 ? `h${level}` : 'h2, h3, h4';
        const doc = new DOMParser().parseFromString(block.content, 'text/html');
        const headingNode =
            doc.body.querySelector(selector) ??
            Array.from(doc.body.querySelectorAll(selector)).find(
                (item) => normalizeOutlineHeadingText(item.textContent) === headingText,
            );

        return String(headingNode?.innerHTML ?? '').trim();
    }, [resolveBlockIdForOutlineHeadingId]);

    const applyOutlineHeadingHtml = useCallback(({ level, oldText, headingHtml, newText, headingId = null }) => {
        const normalizeText = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();
        const targetLevel = Number(level) || 0;
        const target = normalizeText(oldText);
        const replacementHtml = String(headingHtml ?? '').trim();
        const replacementText = normalizeText(newText);
        if (target === '' || replacementHtml === '') {
            return;
        }

        const selector = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2, h3, h4';
        const mappedBlockId = resolveBlockIdForOutlineHeadingId(headingId);
        const headingTag = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2';
        let replacedBlockId = null;
        let nextHtml = '';

        setBlocks((prev) =>
            prev.map((block) => {
                if (replacedBlockId || block.type !== 'text' || !block.content) {
                    return block;
                }

                if (mappedBlockId && block.id !== mappedBlockId) {
                    return block;
                }

                const doc = new DOMParser().parseFromString(block.content, 'text/html');
                let headingNode = Array.from(doc.body.querySelectorAll(selector)).find(
                    (node) => normalizeText(node.textContent) === target,
                );

                if (!headingNode && mappedBlockId === block.id) {
                    headingNode = doc.body.querySelector(selector);
                }

                if (!headingNode && mappedBlockId === block.id) {
                    replacedBlockId = block.id;
                    nextHtml = `<${headingTag}>${replacementHtml}</${headingTag}><p></p>`;

                    return { ...block, content: nextHtml };
                }

                if (!headingNode) {
                    return block;
                }

                headingNode.innerHTML = replacementHtml;
                replacedBlockId = block.id;
                nextHtml = doc.body.innerHTML;

                return { ...block, content: nextHtml };
            }),
        );

        if (replacedBlockId && nextHtml !== '') {
            const activeEditor = blockEditorsRef.current.get(replacedBlockId);
            if (activeEditor && !activeEditor.isDestroyed) {
                activeEditor.commands.setContent(nextHtml, { emitUpdate: false });
            }
        }

        if (replacementText !== '') {
            setOutlineHeadingKeys((prev) => {
                const next = new Set(prev);
                const oldKey = outlineHeadingKey(targetLevel, target);
                const newKey = outlineHeadingKey(targetLevel, replacementText);
                if (next.has(oldKey)) {
                    next.delete(oldKey);
                }
                next.add(newKey);
                return next;
            });
        }
    }, [resolveBlockIdForOutlineHeadingId]);

    const handleOutlineLoaded = useCallback((outline) => {
        const nodes = Array.isArray(outline) ? outline : [];
        const hasOutline = nodes.length > 0;
        setOutlineHasSavedHeadings(hasOutline);
        setOutlineHeadingKeys(flattenOutlineHeadingKeys(nodes));

        const byKey = new Map();
        for (const node of flattenOutlineNodes(nodes)) {
            const level = Number(node?.level ?? 0);
            const text = normalizeOutlineHeadingText(node?.heading_text);
            if (level >= 2 && text !== '' && node?.id != null) {
                byKey.set(outlineHeadingKey(level, text), node.id);
            }
        }
        outlineHeadingIdsByKeyRef.current = byKey;

        for (const block of blocksRef.current) {
            const meta = extractOutlineHeadingFromBlock(block);
            if (!meta) {
                continue;
            }
            const headingId = byKey.get(outlineHeadingKey(meta.level, meta.headingText));
            if (headingId != null) {
                outlineHeadingIdsByBlockIdRef.current.set(block.id, headingId);
            }
        }
    }, []);

    const handleOutlineHeadingAppended = useCallback(({ blockId, headingId, heading }) => {
        if (blockId && headingId != null) {
            outlineHeadingIdsByBlockIdRef.current.set(blockId, headingId);
            outlineAppendDoneRef.current.add(blockId);
        }

        const level = Number(heading?.level ?? 2);
        const text = normalizeOutlineHeadingText(heading?.heading_text);
        if (text !== '') {
            const key = outlineHeadingKey(level, text);
            setOutlineHeadingKeys((prev) => {
                const next = new Set(prev);
                next.add(key);
                return next;
            });
            if (headingId != null) {
                outlineHeadingIdsByKeyRef.current.set(key, headingId);
            }
        }

        setOutlineHasSavedHeadings(true);
    }, []);

    const appendOutlineHeadingForBlock = useCallback(
        async (blockId, meta, options = {}) => {
            const id = String(blockId ?? '').trim();
            if (!id || !meta?.headingText || outlineAppendDoneRef.current.has(id)) {
                return;
            }

            if (outlineAppendInflightRef.current.has(id)) {
                return;
            }

            outlineAppendInflightRef.current.add(id);

            const afterHeadingId = Number(options.afterHeadingId ?? 0) || null;
            const optimisticId = `pending-${id}`;

            setOutlineTreeSync({
                token: Date.now(),
                action: afterHeadingId !== null ? 'insertAfter' : 'append',
                afterHeadingId,
                heading: {
                    id: optimisticId,
                    heading_text: meta.headingText,
                    level: meta.level ?? 2,
                    children: [],
                },
                focusEdit: options.focusEdit === true,
            });

            try {
                const payload = {
                    heading_text: meta.headingText,
                    level: meta.level,
                };
                if (afterHeadingId !== null) {
                    payload.after_heading_id = afterHeadingId;
                }

                const data = await outlineApiRequest(articleId, '', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });

                const heading = data.heading ?? null;
                if (!heading?.id) {
                    throw new Error('Không tạo được heading trong outline.');
                }

                handleOutlineHeadingAppended({
                    blockId: id,
                    headingId: heading.id,
                    heading,
                });
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'confirmHeading',
                    tempHeadingId: optimisticId,
                    afterHeadingId,
                    heading,
                    focusEdit: options.focusEdit === true,
                });
            } catch (error) {
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'remove',
                    headingId: optimisticId,
                });
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Outline',
                            body: error?.message || 'Không thêm được heading vào outline.',
                            status: 'danger',
                        },
                    }),
                );
            } finally {
                outlineAppendInflightRef.current.delete(id);
            }
        },
        [articleId, handleOutlineHeadingAppended],
    );

    const syncOutlineForNewSectionBlock = useCallback(
        (headingBlock, afterHeadingId = null) => {
            if (!articleId || !headingBlock) {
                return;
            }

            const meta = extractOutlineHeadingFromBlock(headingBlock);
            if (!meta) {
                return;
            }

            void appendOutlineHeadingForBlock(headingBlock.id, meta, {
                afterHeadingId,
                focusEdit: false,
            });
        },
        [appendOutlineHeadingForBlock, articleId],
    );

    const resolveOutlineHeadingIdForSection = useCallback((section) => {
        if (!section?.blockIds?.length || section.isIntro) {
            return null;
        }

        const headingBlockId = section.blockIds[0];
        const cached = outlineHeadingIdsByBlockIdRef.current.get(headingBlockId);
        if (cached) {
            return cached;
        }

        const block = blocksRef.current.find((item) => item.id === headingBlockId);
        const meta = block ? extractOutlineHeadingFromBlock(block) : null;
        if (!meta) {
            return null;
        }

        const headingId = outlineHeadingIdsByKeyRef.current.get(
            outlineHeadingKey(meta.level, meta.headingText),
        );
        if (headingId != null) {
            outlineHeadingIdsByBlockIdRef.current.set(headingBlockId, headingId);
        }

        return headingId ?? null;
    }, []);

    const saveSectionTitleFromHeader = useCallback(
        async (section, newText) => {
            if (section?.isIntro) {
                return;
            }

            const trimmed = String(newText ?? '').replace(/\s+/g, ' ').trim();
            const oldText = String(section.title ?? '').replace(/\s+/g, ' ').trim();
            if (trimmed === '' || trimmed === oldText) {
                return;
            }

            const headingBlockId = section.blockIds[0];
            const block = blocksRef.current.find((item) => item.id === headingBlockId);
            const meta = block ? extractOutlineHeadingFromBlock(block) : null;
            const level = meta?.level ?? 2;
            const headingId = resolveOutlineHeadingIdForSection(section);

            applyOutlineHeadingText({
                level,
                oldText,
                newText: trimmed,
                headingId,
            });

            if (headingId == null) {
                if (!articleId) {
                    return;
                }

                const sections = buildEditorSections(blocksRef.current);
                const sectionIndex = sections.findIndex((item) => item.id === section.id);
                let afterHeadingId = null;
                if (sectionIndex > 0) {
                    for (let i = sectionIndex - 1; i >= 0; i--) {
                        const candidate = resolveOutlineHeadingIdForSection(sections[i]);
                        if (candidate != null) {
                            afterHeadingId = candidate;
                            break;
                        }
                    }
                }

                try {
                    await appendOutlineHeadingForBlock(
                        headingBlockId,
                        { level, headingText: trimmed },
                        { afterHeadingId, focusEdit: false },
                    );
                } catch (error) {
                    applyOutlineHeadingText({
                        level,
                        oldText: trimmed,
                        newText: oldText,
                        headingId: null,
                    });
                    window.dispatchEvent(
                        new CustomEvent('seo-article-editor-notify', {
                            detail: {
                                title: 'Outline',
                                body: error?.message || 'Không thêm được tiêu đề section vào outline.',
                                status: 'danger',
                            },
                        }),
                    );
                }

                return;
            }

            setOutlineTreeSync({
                token: Date.now(),
                action: 'patchText',
                headingId,
                newText: trimmed,
            });

            try {
                await outlineApiRequest(articleId, `/${headingId}`, {
                    method: 'PUT',
                    body: JSON.stringify({ heading_text: trimmed }),
                });
            } catch (error) {
                applyOutlineHeadingText({
                    level,
                    oldText: trimmed,
                    newText: oldText,
                    headingId,
                });
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'patchText',
                    headingId,
                    newText: oldText,
                });
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Outline',
                            body: error?.message || 'Không lưu được tiêu đề section.',
                            status: 'danger',
                        },
                    }),
                );
            }
        },
        [appendOutlineHeadingForBlock, applyOutlineHeadingText, articleId, resolveOutlineHeadingIdForSection],
    );

    const switchTab = useCallback(
        (tabId) => {
            if (tabId === 'outline') {
                return;
            }
            if (tabId !== 'editor' && activeBlockId) {
                commitActiveBlock();
                setActiveBlockId(null);
                setGlobalEditor(null);
                blockFlushRef.current = null;
            }
            setActiveTab(tabId);
        },
        [activeBlockId, commitActiveBlock],
    );

    const openOutlineRail = useCallback(() => {
        setActiveTab('editor');
        const rail = outlineRailRef.current;
        if (!rail) {
            return;
        }

        rail.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
        rail.classList.add('is-pulse');
        window.setTimeout(() => rail.classList.remove('is-pulse'), 1200);
    }, []);

    const focusOutlineFromSectionHeader = useCallback(
        (section) => {
            if (section?.isIntro || !section?.blockIds?.length) {
                return;
            }

            const headingBlock = blocksRef.current.find((item) => item.id === section.blockIds[0]);
            if (!headingBlock) {
                return;
            }

            syncOutlineFocusFromBlock(headingBlock, 'focus');
            outlineRailRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
                inline: 'start',
            });
        },
        [syncOutlineFocusFromBlock],
    );

    const handleOutlineHeadingFromEditor = useCallback(
        (action, block) => {
            syncOutlineFocusFromBlock(block, action);
            openOutlineRail();
        },
        [openOutlineRail, syncOutlineFocusFromBlock],
    );

    const jumpToOutlineHeading = useCallback(
        (node) => {
            focusedOutlineHeadingRef.current = {
                level: Number(node?.level ?? 0),
                headingText: String(node?.heading_text ?? ''),
                headingId: node?.id ?? null,
            };

            const blockId = findBlockIdForOutlineHeading(
                blocksRef.current,
                Number(node?.level ?? 0),
                String(node?.heading_text ?? ''),
            );
            if (!blockId) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Outline',
                            body: 'Không tìm thấy heading tương ứng trong editor.',
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            if (sectionHeadingBlockIds.has(blockId)) {
                const sectionId = sectionByBlockId.get(blockId);
                if (sectionId) {
                    setActiveTab('editor');
                    setCollapsedSectionIds((prev) =>
                        prev[sectionId]
                            ? {
                                  ...prev,
                                  [sectionId]: false,
                              }
                            : prev,
                    );
                    window.requestAnimationFrame(() => {
                        const sectionEl = document.querySelector(`[data-seo-section-id="${sectionId}"]`);
                        sectionEl?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        sectionEl?.classList.add('is-outline-jump-highlight');
                        window.setTimeout(() => sectionEl?.classList.remove('is-outline-jump-highlight'), 2400);
                    });
                }

                return;
            }

            focusImageBlock(blockId);
        },
        [focusImageBlock, sectionByBlockId, sectionHeadingBlockIds],
    );

    useEffect(() => {
        const onOpenImagesTab = (event) => {
            const detail = event?.detail ?? {};
            const src = String(detail?.src ?? '').trim();
            const seoMediaId = Number(detail?.seoMediaId ?? detail?.seo_media_id ?? 0);

            switchTab('images');
            setImagesTabJumpTarget({
                token: Date.now(),
                seoMediaId: seoMediaId > 0 ? seoMediaId : null,
                src,
            });
        };

        window.addEventListener('seo-open-images-tab', onOpenImagesTab);

        return () => {
            window.removeEventListener('seo-open-images-tab', onOpenImagesTab);
        };
    }, [switchTab]);

    useEffect(() => {
        if (!activeBlockId) {
            return;
        }

        const sectionId = sectionByBlockId.get(activeBlockId);
        if (!sectionId) {
            return;
        }

        setCollapsedSectionIds((prev) =>
            prev[sectionId]
                ? {
                      ...prev,
                      [sectionId]: false,
                  }
                : prev,
        );
    }, [activeBlockId, sectionByBlockId]);

    const toggleSectionCollapse = useCallback((sectionId) => {
        setCollapsedSectionIds((prev) => ({
            ...prev,
            [sectionId]: !prev[sectionId],
        }));
    }, []);

    const collapseAllSections = useCallback(() => {
        if (editorSections.length === 0) {
            return;
        }

        commitActiveBlock();

        const next = {};
        editorSections.forEach((section) => {
            next[section.id] = true;
        });
        setCollapsedSectionIds(next);
    }, [commitActiveBlock, editorSections]);

    useEffect(() => {
        if (editorSections.length === 0) {
            return;
        }

        setCollapsedSectionIds((prev) => {
            if (Object.keys(prev).length > 0) {
                return prev;
            }

            const next = { ...prev };
            editorSections.forEach((section, index) => {
                if (index > 0) {
                    next[section.id] = true;
                }
            });

            return next;
        });
    }, [editorSections]);

    const focusNewSectionHeader = useCallback((sectionUiId) => {
        setSectionTitleEditRequest({ sectionId: sectionUiId, token: Date.now() });
        window.requestAnimationFrame(() => {
            document.querySelector(`[data-seo-section-id="${sectionUiId}"]`)?.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
            });
        });
    }, []);

    const addSection = useCallback(() => {
        if (tempMergeRef.current) {
            return;
        }

        commitActiveBlock();

        const newSectionBlock = createEmptySectionBlock();
        const sectionId = `section-${newSectionBlock.id}`;
        const sections = buildEditorSections(blocksRef.current);
        const lastSection = [...sections].reverse().find((item) => !item.isIntro) ?? null;
        const afterHeadingId = lastSection ? resolveOutlineHeadingIdForSection(lastSection) : null;

        setBlocks((prev) => normalizeBlocks([...prev, newSectionBlock]));
        setInsertMenu(null);
        setActiveTab('editor');
        setActiveBlockId(null);
        setGlobalEditor(null);
        blockFlushRef.current = null;
        setCollapsedSectionIds((prev) => ({
            ...prev,
            [sectionId]: false,
        }));

        syncOutlineForNewSectionBlock(newSectionBlock, afterHeadingId);
        focusNewSectionHeader(sectionId);
    }, [
        commitActiveBlock,
        focusNewSectionHeader,
        resolveOutlineHeadingIdForSection,
        syncOutlineForNewSectionBlock,
    ]);

    const addSectionAfter = useCallback(
        (section) => {
            if (tempMergeRef.current || !section?.blockIds?.length) {
                return;
            }

            commitActiveBlock();

            const lastBlockId = section.blockIds[section.blockIds.length - 1];
            const newSectionBlock = createEmptySectionBlock();
            const sectionId = `section-${newSectionBlock.id}`;

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === lastBlockId);
                if (index < 0) {
                    return prev;
                }

                const next = [...prev];
                next.splice(index + 1, 0, newSectionBlock);

                return normalizeBlocks(next);
            });
            setInsertMenu(null);
            setActiveTab('editor');
            setActiveBlockId(null);
            setGlobalEditor(null);
            blockFlushRef.current = null;
            setCollapsedSectionIds((prev) => ({
                ...prev,
                [sectionId]: false,
            }));

            const afterHeadingId = resolveOutlineHeadingIdForSection(section);
            syncOutlineForNewSectionBlock(newSectionBlock, afterHeadingId);
            focusNewSectionHeader(sectionId);
        },
        [
            commitActiveBlock,
            focusNewSectionHeader,
            resolveOutlineHeadingIdForSection,
            syncOutlineForNewSectionBlock,
        ],
    );

    const insertFeaturedSnippetAsNewSectionAfter = useCallback(
        async (pending, html) => {
            if (tempMergeRef.current || !pending?.anchorLastBlockId) {
                return;
            }

            commitActiveBlock();

            const keyword = (focusKeyword || articleTitle || '').trim();
            const { headingBlock, contentBlocks } = parseFeaturedSnippetNewSectionBlocks(
                html,
                createEmptyTextBlock,
                keyword,
            );

            if (!headingBlock) {
                return;
            }

            const anchorSection = buildEditorSections(blocksRef.current).find(
                (item) => item.id === pending.anchorSectionId,
            );
            const insertBlocks = [headingBlock, ...contentBlocks];
            const sectionUiId = `section-${headingBlock.id}`;
            const lastBlockId =
                contentBlocks.length > 0 ? contentBlocks[contentBlocks.length - 1].id : headingBlock.id;

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === pending.anchorLastBlockId);
                if (index < 0) {
                    return prev;
                }

                const next = [...prev];
                next.splice(index + 1, 0, ...insertBlocks);

                return next;
            });

            setInsertMenu(null);
            setActiveTab('editor');
            setActiveBlockId(lastBlockId);
            setGlobalEditor(null);
            setCollapsedSectionIds((prev) => ({
                ...prev,
                [sectionUiId]: false,
            }));

            if (outlineHasSavedHeadings && anchorSection) {
                const meta = extractOutlineHeadingFromBlock(headingBlock);
                if (meta) {
                    const afterHeadingId = resolveOutlineHeadingIdForSection(anchorSection);
                    await appendOutlineHeadingForBlock(headingBlock.id, meta, { afterHeadingId });
                }
            }
        },
        [
            appendOutlineHeadingForBlock,
            articleTitle,
            commitActiveBlock,
            focusKeyword,
            outlineHasSavedHeadings,
            resolveOutlineHeadingIdForSection,
        ],
    );

    const requestGenerateFeaturedSnippetAfterSection = useCallback(
        async (section) => {
            if (
                !canGenerateFeaturedSnippet ||
                featuredSnippetGenerating ||
                section?.isIntro ||
                !section?.blockIds?.length
            ) {
                return;
            }

            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_generate_featured_snippet'),
                            body: t('editor_featured_snippet_no_keyword'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            featuredSnippetTargetRef.current = {
                mode: 'new-section-after',
                anchorSectionId: section.id,
                anchorLastBlockId: section.blockIds[section.blockIds.length - 1],
            };
            setFeaturedSnippetGenerating(true);
            setArticleAutosaveLock('generate-featured-snippet', true);

            try {
                await callEditArticleLivewire(
                    'generateFeaturedSnippetFromEditor',
                    featuredSnippetTargetRef.current.anchorLastBlockId,
                    'after',
                );
            } catch (error) {
                featuredSnippetTargetRef.current = null;
                setFeaturedSnippetGenerating(false);
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_generate_featured_snippet'),
                            body: error?.message ?? t('editor_featured_snippet_failed'),
                            status: 'danger',
                        },
                    }),
                );
            } finally {
                setArticleAutosaveLock('generate-featured-snippet', false);
            }
        },
        [articleTitle, canGenerateFeaturedSnippet, featuredSnippetGenerating, focusKeyword],
    );

    useEffect(() => {
        const onFeaturedSnippetGenerated = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const html = String(detail.html ?? '').trim();
            const pending = featuredSnippetTargetRef.current;

            if (!html || pending?.mode !== 'new-section-after') {
                featuredSnippetTargetRef.current = null;
                setFeaturedSnippetGenerating(false);

                return;
            }

            featuredSnippetTargetRef.current = null;

            void insertFeaturedSnippetAsNewSectionAfter(pending, html).finally(() => {
                setFeaturedSnippetGenerating(false);
            });
        };

        window.addEventListener('article-featured-snippet-generated', onFeaturedSnippetGenerated);

        return () => {
            window.removeEventListener('article-featured-snippet-generated', onFeaturedSnippetGenerated);
        };
    }, [insertFeaturedSnippetAsNewSectionAfter]);

    useEffect(() => {
        const normalizeSelectedImage = (payload = {}) => {
            const src = String(payload.url || payload.src || '').trim();
            if (!src) {
                return null;
            }

            const mode = String(payload.mode || '').trim();
            const wpAttachmentId = Number(payload.wpAttachmentId ?? payload.wp_attachment_id ?? 0);
            const seoMediaId = Number(payload.seoMediaId ?? payload.seo_media_id ?? 0);
            const slug = String(payload.slug || '').trim();
            const alt = String(payload.alt || '').trim();
            const isLocal = src.includes('/storage/uploads/seo_media/');

            return {
                key:
                    wpAttachmentId > 0
                        ? `wp_${wpAttachmentId}`
                        : seoMediaId > 0
                          ? `seo_${seoMediaId}`
                          : `src_${src}`,
                block_id: '',
                wp_attachment_id: wpAttachmentId > 0 ? wpAttachmentId : null,
                seo_media_id: seoMediaId > 0 ? seoMediaId : null,
                src,
                wp_url: !isLocal ? src : '',
                local_src: isLocal ? src : '',
                slug,
                alt,
                title: alt,
                caption: '',
                align: 'none',
                origin: mode === 'gallery' ? 'gallery' : 'featured',
                origin_label: mode === 'gallery' ? t('editor_product_album') : t('editor_featured_image'),
            };
        };

        const imageIdentity = (row) => {
            const wpId = Number(row?.wp_attachment_id ?? row?.wpAttachmentId ?? 0);
            if (wpId > 0) {
                return `wp:${wpId}`;
            }

            const seoId = Number(row?.seo_media_id ?? row?.seoMediaId ?? 0);
            if (seoId > 0) {
                return `seo:${seoId}`;
            }

            return `src:${String(row?.src || '').trim()}`;
        };

        const onSelected = (event) => {
            const normalized = normalizeSelectedImage(event.detail ?? {});
            if (!normalized) {
                return;
            }

            setSupplementalImages((prev) => {
                const identity = imageIdentity(normalized);
                const mode = String(normalized.origin || '');
                let next = Array.isArray(prev) ? [...prev] : [];

                if (mode === 'featured') {
                    next = next.filter((row) => String(row?.origin || '') !== 'featured');
                }

                next = next.filter((row) => imageIdentity(row) !== identity);
                next.unshift(normalized);

                return next;
            });
        };

        const onRemoved = (event) => {
            const detail = event.detail ?? {};
            const url = String(detail.url || '').trim();
            if (!url) {
                return;
            }

            setSupplementalImages((prev) =>
                (Array.isArray(prev) ? prev : []).filter(
                    (row) => String(row?.src || '').trim() !== url,
                ),
            );
        };

        window.addEventListener('article-media-selected', onSelected);
        window.addEventListener('article-media-removed', onRemoved);

        return () => {
            window.removeEventListener('article-media-selected', onSelected);
            window.removeEventListener('article-media-removed', onRemoved);
        };
    }, []);

    const applyEditorSectionSearch = useCallback(
        (options = {}) => {
            const { silent = false } = options;
            const needle = String(quickReplaceFind ?? '').trim();

            if (!needle) {
                setCollapsedSectionIds({});
                setEditorSearchMatchCount(null);
                return;
            }

            if (tempMergeRef.current) {
                clearTempMerge();
            }
            commitActiveBlock();

            const nextCollapsed = {};
            let totalMatches = 0;
            let sectionsWithMatches = 0;

            for (const section of editorSections) {
                const sectionCount = countKeywordInSectionBlocks(section, blockById, needle);
                totalMatches += sectionCount;
                if (sectionCount > 0) {
                    sectionsWithMatches += 1;
                } else {
                    nextCollapsed[section.id] = true;
                }
            }

            setCollapsedSectionIds(nextCollapsed);
            setEditorSearchMatchCount(totalMatches);

            if (silent) {
                return;
            }

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title:
                            totalMatches > 0
                                ? t('editor_search_found_title')
                                : t('editor_search_not_found_title'),
                        body:
                            totalMatches > 0
                                ? t('editor_search_found_body', {
                                      count: totalMatches,
                                      sections: sectionsWithMatches,
                                  })
                                : t('editor_search_not_found_body'),
                        status: totalMatches > 0 ? 'success' : 'warning',
                    },
                }),
            );
        },
        [quickReplaceFind, editorSections, blockById, clearTempMerge, commitActiveBlock],
    );

    const applyQuickReplaceAllSections = useCallback(() => {
        const needle = String(quickReplaceFind ?? '').trim();
        if (!needle) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_search_missing_title'),
                        body: t('editor_search_missing_body'),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        if (tempMergeRef.current) {
            clearTempMerge();
        }
        commitActiveBlock();

        let affectedBlocks = 0;
        let totalReplacements = 0;

        setBlocks((prev) =>
            prev.map((block) => {
                if (typeof block.content !== 'string' || block.content === '') {
                    return block;
                }

                const replaced = replaceTextInHtmlContent(block.content, needle, quickReplaceValue);
                if (replaced.replacements <= 0) {
                    return block;
                }

                affectedBlocks += 1;
                totalReplacements += replaced.replacements;

                return {
                    ...block,
                    content: replaced.html,
                };
            }),
        );

        setEditorSearchMatchCount(totalReplacements > 0 ? totalReplacements : 0);

        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: affectedBlocks > 0 ? t('editor_replace_success') : t('editor_replace_not_found'),
                    body:
                        affectedBlocks > 0
                            ? t('editor_replace_success_body', { totalReplacements, affectedBlocks })
                            : t('editor_replace_not_found_body'),
                    status: affectedBlocks > 0 ? 'success' : 'warning',
                },
            }),
        );
    }, [quickReplaceFind, quickReplaceValue, clearTempMerge, commitActiveBlock]);

    const handleEditorSearchAction = useCallback(() => {
        const replaceValue = String(quickReplaceValue ?? '').trim();
        if (replaceValue !== '') {
            applyQuickReplaceAllSections();
            return;
        }

        applyEditorSectionSearch();
    }, [applyEditorSectionSearch, applyQuickReplaceAllSections, quickReplaceValue]);

    const { debounced: debouncedEditorSectionSearch } = useDebouncedCallback(() => {
        if (String(quickReplaceValue ?? '').trim() !== '') {
            return;
        }
        applyEditorSectionSearch({ silent: true });
    }, 350);

    useEffect(() => {
        debouncedEditorSectionSearch();
    }, [quickReplaceFind, quickReplaceValue, debouncedEditorSectionSearch]);

    return (
        <div className="seo-article-editor-root">
            <EditorBusyOverlay
                visible={imageRenameBusy}
                title={t('editor_renaming_wp_images')}
                message={
                    imageRenameBusyCount > 0
                        ? t('editor_renaming_wp_images_body', { count: imageRenameBusyCount })
                        : t('editor_please_wait')
                }
            />
            <div className="seo-article-editor-workspace">
                <div className="seo-article-editor-left-rail">
                    <ArticleGoogleSerpPreview
                        initialPreview={initialSeo?.google_serp_preview ?? null}
                        fallbackUrl={String(initialSeo?.google_serp_preview?.url ?? initialSeo?.site_domain ?? '#')}
                        skipSeoScore={Boolean(initialSeo?.skip_seo_score)}
                        initialFocusKeyword={String(initialSeo?.focus_keyword ?? '')}
                        initialSlug={String(initialSeo?.article_slug ?? '')}
                        permalinkBase={String(initialSeo?.permalink_base ?? '')}
                        permalinkSuffix={String(initialSeo?.permalink_suffix ?? '')}
                    />

                    <aside
                        ref={outlineRailRef}
                        className="seo-article-editor-outline-rail"
                        aria-label="Outline / Dàn ý"
                    >
                        <ArticleOutlineTab
                            articleId={articleId}
                            headingCommand={outlineHeadingCommand}
                            outlineTreeSync={outlineTreeSync}
                            canGenerateOutlineHeading={canGenerateOutlineHeading}
                            resolveHeadingInnerHtml={resolveHeadingInnerHtml}
                            onOutlineLoaded={handleOutlineLoaded}
                            onHeadingTextChange={applyOutlineHeadingText}
                            onHeadingHtmlChange={applyOutlineHeadingHtml}
                            onJumpToEditorHeading={jumpToOutlineHeading}
                            onOutlineMoveHeading={handleOutlineMoveHeading}
                            onOutlineDeleteHeading={handleOutlineDeleteHeading}
                            onOutlineAddSection={addSection}
                            onNotify={(payload) => {
                                window.dispatchEvent(
                                    new CustomEvent('seo-article-editor-notify', { detail: payload }),
                                );
                            }}
                            onRequestEditorHtml={getExportHtml}
                        />
                    </aside>
                </div>

                <div className="seo-article-editor-mainpane">
            <div className="seo-editor-tabs">
                {editorTabs.map((tab) => (
                    <button
                        key={tab.id}
                        type="button"
                        className={`seo-editor-tab ${activeTab === tab.id ? 'is-active' : ''}`}
                        onClick={() => switchTab(tab.id)}
                    >
                        {tab.label}
                        {tab.id === 'images' ? (
                            <span className="seo-editor-tab-badge">
                                {imageTabCount}
                            </span>
                        ) : null}
                        {tab.id === 'reviews' ? (
                            <span className="seo-editor-tab-badge">
                                {virtualReviews.length}
                            </span>
                        ) : null}
                        {tab.id === 'seo' && analysis?.score != null ? (
                            <span className="seo-editor-tab-score">{analysis.score}</span>
                        ) : null}
                    </button>
                ))}
                <div className="seo-editor-tab-actions">
                    <button
                        type="button"
                        className="seo-history-btn"
                        onClick={() => {
                            clearTempMerge();
                            commitActiveBlock();
                            undo();
                        }}
                        disabled={!canUndo}
                        title={t('editor_undo_with_count', { undo: historySteps.undo, max: historySteps.max })}
                    >
                        <Undo2 size={15} />
                    </button>
                    <button
                        type="button"
                        className="seo-history-btn"
                        onClick={() => {
                            clearTempMerge();
                            commitActiveBlock();
                            redo();
                        }}
                        disabled={!canRedo}
                        title={t('editor_redo_with_count', { redo: historySteps.redo })}
                    >
                        <Redo2 size={15} />
                    </button>
                    <span className="seo-autosave-status">{saveLabel}</span>
                </div>
            </div>

            {activeTab === 'editor' ? (
                <div className="editor-container">
                    <div className="max-w-none space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="text-xs font-medium text-gray-500 dark:text-gray-300">
                                {t('editor_total_words')}: {totalWordCount}
                            </div>
                            <div className="ml-auto flex flex-wrap items-center justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={collapseAllSections}
                                    className="seo-editor-search-btn"
                                    title={t('editor_collapse_all_sections')}
                                    aria-label={t('editor_collapse_all_sections')}
                                >
                                    <ListCollapse size={15} />
                                </button>
                                <div className="seo-editor-search-group">
                                    <input
                                        type="text"
                                        value={quickReplaceFind}
                                        onChange={(event) => setQuickReplaceFind(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                handleEditorSearchAction();
                                            }
                                        }}
                                        placeholder={t('editor_find')}
                                        className="seo-editor-search-input"
                                        aria-label={t('editor_find')}
                                    />
                                    {quickReplaceFind.trim() !== '' && editorSearchMatchCount != null ? (
                                        <span
                                            className={
                                                'seo-editor-search-count' +
                                                (editorSearchMatchCount > 0
                                                    ? ' is-found'
                                                    : ' is-empty')
                                            }
                                            title={t('editor_search_count_title')}
                                        >
                                            {editorSearchMatchCount}
                                        </span>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={handleEditorSearchAction}
                                        className="seo-editor-search-btn"
                                        title={
                                            String(quickReplaceValue ?? '').trim() !== ''
                                                ? t('editor_replace_all')
                                                : t('editor_search_sections')
                                        }
                                        aria-label={t('editor_search_sections')}
                                    >
                                        <Search size={15} />
                                    </button>
                                </div>
                                <input
                                    type="text"
                                    value={quickReplaceValue}
                                    onChange={(event) => setQuickReplaceValue(event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            handleEditorSearchAction();
                                        }
                                    }}
                                    placeholder={t('editor_replace')}
                                    className="h-8 w-36 rounded border border-gray-300 bg-white px-2 text-xs text-gray-800 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"
                                />
                            </div>
                        </div>

                        {blocks.length === 0 ? (
                            <p className="text-gray-400 text-center py-10 italic text-sm">
                                {t('editor_loading_content')}
                            </p>
                        ) : (
                            editorSections.map((section, sectionIndex) => {
                                const isCollapsed = collapsedSectionIds[section.id] === true;
                                const sectionNumber = editorSections
                                    .slice(0, sectionIndex + 1)
                                    .filter((item) => !item.isIntro).length;
                                const visibleBlockIds = section.isIntro
                                    ? section.blockIds
                                    : section.blockIds.filter((blockId) => !sectionHeadingBlockIds.has(blockId));
                                const stats =
                                    sectionStats.get(section.id) ?? {
                                        imageCount: 0,
                                        emptyImageSrcCount: 0,
                                        hasEmptyImageSrc: false,
                                        hasTable: false,
                                        tableCount: 0,
                                        linkCount: 0,
                                        wordCount: 0,
                                    };
                                const canQuickDeleteEmptySection =
                                    !section.isIntro && sectionHasOnlyEmptyHeadingBody(section, blockById);

                                return (
                                    <section
                                        key={section.id}
                                        data-seo-section-id={section.id}
                                        className="rounded-lg border border-gray-200 bg-white/80 dark:border-gray-700 dark:bg-slate-900/40"
                                    >
                                        <header className="flex items-center justify-between gap-3 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                                            <div className="flex min-w-0 items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleSectionCollapse(section.id)}
                                                    className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-gray-300 text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-slate-700"
                                                    title={isCollapsed ? t('editor_expand_section') : t('editor_collapse_section')}
                                                >
                                                    {isCollapsed ? <ChevronRight size={15} /> : <ChevronDown size={15} />}
                                                </button>
                                                {section.isIntro ? (
                                                    <h3 className="truncate text-sm font-semibold text-gray-700 dark:text-gray-200">
                                                        {t('editor_intro')}
                                                    </h3>
                                                ) : (
                                                    <SectionHeaderTitle
                                                        sectionNumber={sectionNumber}
                                                        title={section.title}
                                                        onSave={(nextTitle) => saveSectionTitleFromHeader(section, nextTitle)}
                                                        onFocusOutline={() => focusOutlineFromSectionHeader(section)}
                                                        autoEditToken={
                                                            sectionTitleEditRequest?.sectionId === section.id
                                                                ? sectionTitleEditRequest.token
                                                                : 0
                                                        }
                                                    />
                                                )}
                                            </div>
                                            <span className="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                {!section.isIntro ? (
                                                    <span
                                                        className={
                                                            'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] ' +
                                                            (stats.imageCount === 0
                                                                ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-500/60 dark:bg-red-900/40 dark:text-red-200'
                                                                : 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-600 dark:bg-slate-800/60 dark:text-gray-200')
                                                        }
                                                        title={t('editor_section_image_count')}
                                                    >
                                                        <ImageIcon size={11} />
                                                        <span>{stats.imageCount}</span>
                                                    </span>
                                                ) : null}

                                                {stats.hasTable ? (
                                                    <span
                                                        className="ml-1 inline-flex items-center gap-1 rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800 dark:border-amber-500/60 dark:bg-amber-900/40 dark:text-amber-200"
                                                        title={t('editor_section_has_table')}
                                                    >
                                                        <Table size={11} />
                                                        <span>{stats.tableCount}</span>
                                                    </span>
                                                ) : null}

                                                {stats.linkCount > 0 ? (
                                                    <span
                                                        className="ml-1 inline-flex items-center gap-1 rounded border border-emerald-300 bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-800 dark:border-emerald-500/60 dark:bg-emerald-900/40 dark:text-emerald-200"
                                                        title={t('editor_section_link_count')}
                                                    >
                                                        <Link2 size={11} />
                                                        <span>{stats.linkCount}</span>
                                                    </span>
                                                ) : null}

                                                <span
                                                    className="ml-1 inline-flex items-center gap-1 rounded border border-indigo-300 bg-indigo-50 px-1.5 py-0.5 text-[11px] text-indigo-800 dark:border-indigo-500/60 dark:bg-indigo-900/40 dark:text-indigo-200"
                                                    title={t('editor_section_word_count')}
                                                >
                                                    <span>W</span>
                                                    <span>{stats.wordCount}</span>
                                                </span>

                                                {!section.isIntro && stats.imageCount === 0 ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => quickGenerateImageForSection(section)}
                                                        className="seo-section-header-icon-btn ml-1 border-sky-300 bg-sky-50 text-sky-700 hover:bg-sky-100 dark:border-sky-500/70 dark:bg-sky-900/30 dark:text-sky-200"
                                                        title={t('editor_quick_generate_image_section')}
                                                        aria-label={t('editor_quick_generate_image')}
                                                    >
                                                        <Wand2 size={12} />
                                                    </button>
                                                ) : null}

                                                {!section.isIntro ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => addSectionAfter(section)}
                                                        className="seo-section-header-icon-btn ml-1 border-violet-300 bg-violet-50 text-violet-700 hover:bg-violet-100 dark:border-violet-500/70 dark:bg-violet-900/30 dark:text-violet-200"
                                                        title={t('editor_add_section_after')}
                                                        aria-label={t('editor_add_section_after')}
                                                    >
                                                        <ListPlus size={12} />
                                                    </button>
                                                ) : null}

                                                {canQuickDeleteEmptySection ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => deleteSection(section, { skipConfirm: true })}
                                                        className="seo-section-header-icon-btn ml-1 border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-500/70 dark:bg-rose-900/30 dark:text-rose-200"
                                                        title={t('editor_delete_empty_section_hint')}
                                                        aria-label={t('editor_delete_empty_section')}
                                                    >
                                                        <Trash2 size={12} />
                                                    </button>
                                                ) : null}

                                                {!section.isIntro && canGenerateFeaturedSnippet ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => requestGenerateFeaturedSnippetAfterSection(section)}
                                                        disabled={featuredSnippetGenerating}
                                                        className="seo-section-header-icon-btn ml-1 border-fuchsia-300 bg-fuchsia-50 text-fuchsia-700 hover:bg-fuchsia-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-fuchsia-500/70 dark:bg-fuchsia-900/30 dark:text-fuchsia-200"
                                                        title={t('editor_generate_featured_snippet')}
                                                        aria-label={t('editor_generate_featured_snippet')}
                                                    >
                                                        <Sparkles size={12} />
                                                    </button>
                                                ) : null}

                                                {!section.isIntro && stats.hasEmptyImageSrc ? (
                                                    <span
                                                        className="ml-2 inline-flex items-center gap-1 rounded border border-rose-300 bg-rose-50 px-1.5 py-0.5 text-[11px] font-medium text-rose-700 dark:border-rose-500/70 dark:bg-rose-900/30 dark:text-rose-200"
                                                        title={t('editor_section_has_empty_src')}
                                                    >
                                                        <AlertTriangle size={11} />
                                                        <span>{t('editor_empty_src_count', { count: stats.emptyImageSrcCount })}</span>
                                                    </span>
                                                ) : null}

                                                <span className="ml-2 inline-block align-middle">
                                                    {visibleBlockIds.length} block
                                                </span>
                                            </span>
                                        </header>

                                        {!isCollapsed ? (
                                            <div className="space-y-3 p-3">
                                                {visibleBlockIds.map((blockId) => {
                                                    const block = blockById.get(blockId);
                                                    if (!block) {
                                                        return null;
                                                    }

                                                    const isOutlineLockedHeadingBlock =
                                                        outlineHasSavedHeadings &&
                                                        blockHasOutlineHeading(block) &&
                                                        !sectionHeadingBlockIds.has(block.id);
                                                    const isActive = activeBlockId === block.id;
                                                    const showInsert = isActive && !tempMerge;
                                                    const showMoveButtons = showInsert && !isOutlineLockedHeadingBlock;
                                                    const canMovePrevSection = sectionIndex > 0;
                                                    const canMoveNextSection = sectionIndex < editorSections.length - 1;
                                                    const handleMovePrevSection = () => moveBlockToSection(block.id, 'prev');
                                                    const handleMoveNextSection = () => moveBlockToSection(block.id, 'next');

                                                    return (
                                                        <div
                                                            key={block.id}
                                                            data-seo-block-id={block.id}
                                                            className={`seo-editor-block-slot ${isActive ? 'is-active' : ''}`}
                                                        >
                                                            {showInsert ? (
                                                                <>
                                                                    <BlockInsertBar
                                                                        position="before"
                                                                        open={
                                                                            insertMenu?.blockId === block.id &&
                                                                            insertMenu?.position === 'before'
                                                                        }
                                                                        onToggle={() => toggleInsertMenu(block.id, 'before')}
                                                                        showMoveButtons={showMoveButtons}
                                                                        canMovePrevSection={canMovePrevSection}
                                                                        canMoveNextSection={canMoveNextSection}
                                                                        onMovePrevSection={handleMovePrevSection}
                                                                        onMoveNextSection={handleMoveNextSection}
                                                                    />
                                                                    {insertMenu?.blockId === block.id &&
                                                                    insertMenu?.position === 'before' ? (
                                                                        <BlockInsertMenuBar
                                                                            faqShortcodeDisabled={articleHasFaqShortcode(blocks)}
                                                                            onClose={() => setInsertMenu(null)}
                                                                            onInsert={(type) =>
                                                                                insertBlockRelative(block.id, 'before', type)
                                                                            }
                                                                        />
                                                                    ) : null}
                                                                </>
                                                            ) : null}

                                                            <BlockEditor
                                                                block={block}
                                                                articleId={articleId}
                                                                siteId={siteId}
                                                                supportsProductGallery={supportsProductGallery}
                                                                isActive={isActive}
                                                                isHiddenInMerge={
                                                                    Boolean(
                                                                        tempMerge &&
                                                                            tempMerge.rangeIds.includes(block.id) &&
                                                                            block.id !== tempMerge.anchorId,
                                                                    )
                                                                }
                                                                canShiftMerge={Boolean(activeBlockId && activeBlockId !== block.id)}
                                                                onActivate={() => activateBlock(block.id)}
                                                                onShiftMerge={startTempMerge}
                                                                displayContent={
                                                                    tempMerge &&
                                                                    activeBlockId === block.id &&
                                                                    block.id === tempMerge.anchorId
                                                                        ? mergedDisplay
                                                                        : undefined
                                                                }
                                                                suppressBlockUpdate={Boolean(
                                                                    tempMerge &&
                                                                        activeBlockId === block.id &&
                                                                        block.id === tempMerge.anchorId,
                                                                )}
                                                                onUpdate={(newContent, imageData) =>
                                                                    updateBlockContent(block.id, newContent, imageData)
                                                                }
                                                                onRegisterFlush={
                                                                    isActive ? registerBlockFlush : undefined
                                                                }
                                                                onRegisterEditor={
                                                                    isActive
                                                                        ? (editor) => registerBlockEditor(block.id, editor)
                                                                        : undefined
                                                                }
                                                                setGlobalEditor={setGlobalEditor}
                                                                panelFaqs={panelFaqs}
                                                                outlineHeadingsLocked={
                                                                    sectionHeadingBlockIds.has(block.id) ||
                                                                    (outlineHasSavedHeadings &&
                                                                        blockHasOutlineHeading(block))
                                                                }
                                                                isSectionHeadingBlock={sectionHeadingBlockIds.has(block.id)}
                                                                onOutlineHeadingCommand={handleOutlineHeadingFromEditor}
                                                                onDelete={() => deleteBlock(block.id)}
                                                                canDeleteBlock={blocks.length > 1}
                                                            />

                                                            {showInsert ? (
                                                                <>
                                                                    <BlockInsertBar
                                                                        position="after"
                                                                        open={
                                                                            insertMenu?.blockId === block.id &&
                                                                            insertMenu?.position === 'after'
                                                                        }
                                                                        onToggle={() => toggleInsertMenu(block.id, 'after')}
                                                                        showMoveButtons={showMoveButtons}
                                                                        canMovePrevSection={canMovePrevSection}
                                                                        canMoveNextSection={canMoveNextSection}
                                                                        onMovePrevSection={handleMovePrevSection}
                                                                        onMoveNextSection={handleMoveNextSection}
                                                                    />
                                                                    {insertMenu?.blockId === block.id &&
                                                                    insertMenu?.position === 'after' ? (
                                                                        <BlockInsertMenuBar
                                                                            faqShortcodeDisabled={articleHasFaqShortcode(blocks)}
                                                                            imageInsertDisabled={section.isIntro}
                                                                            onClose={() => setInsertMenu(null)}
                                                                            onInsert={(type) =>
                                                                                insertBlockRelative(block.id, 'after', type)
                                                                            }
                                                                        />
                                                                    ) : null}
                                                                </>
                                                            ) : null}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        ) : null}
                                    </section>
                                );
                            })
                        )}
                    </div>
                </div>
            ) : activeTab === 'images' ? (
                <ArticleImagesTab
                    key={imagesReloadKey}
                    blocks={blocks}
                    extraImages={supplementalImages}
                    siteId={siteId}
                    articleId={articleId}
                    jumpTarget={imagesTabJumpTarget}
                    focusKeyword={focusKeyword}
                    articleTitle={articleTitle}
                    onPatchImage={patchImageInBlocks}
                    onSlugChange={handleImageSlugChange}
                    onFocusBlock={focusImageBlock}
                    onQuickFixSlugAll={quickFixSlugAllImages}
                    quickFixSlugAllBusy={quickFixSlugAllBusy}
                    onQuickFixSlugOne={quickFixSlugSingleImage}
                    onQuickFixAltTitleAll={quickFixAltTitleAllImages}
                    onQuickFixAltTitleOne={quickFixAltTitleSingleImage}
                    onRemoveImage={removeImageBlock}
                    onNotify={(payload) => {
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', { detail: payload }),
                        );
                    }}
                />
            ) : activeTab === 'reviews' ? (
                <ArticleReviewsTab
                    initialReviews={virtualReviews}
                    onRefresh={() => callEditArticleLivewire('refreshVirtualReviewsForEditor')}
                />
            ) : (
                <div className="seo-tab-panel">
                    <SeoScorePanel
                        focusKeyword={focusKeyword}
                        analysis={analysis}
                        contentBonus={contentBonus}
                        loading={!analysis}
                        analyzing={analyzing}
                    />
                </div>
            )}
            <GenerateImageModal
                open={generateImageModalOpen}
                onClose={() => setGenerateImageModalOpen(false)}
                onSubmit={submitGenerateImageFromModal}
                initialPrompt={generateImageModalPrompt}
                initialLoaiSanPhamCustom={generateImageModalInitialCustom}
                mode={generateImageModalTarget === 'product-gallery' ? 'product-gallery' : 'editor'}
                productCategoryOptions={productCategoryOptions}
                articleId={articleId}
                productGalleryUrls={productGalleryUrls}
            />
                </div>
            </div>
        </div>
    );
}
