import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import BlockFormatToolbar from './BlockFormatToolbar';
import { BlockInsertBar, BlockInsertMenuBar } from './BlockInsertMenu';
import BlockEditorResizeHandle, { useBlockEditorHeight } from './BlockEditorResizeHandle';
import { EditorInspectorBubbleHost } from '../editor/host/EditorInspectorBubbleHost';
import ArticleHtmlInspectorModal from './ArticleHtmlInspectorModal';
import { resolveLinkEditorAnchorRect } from '../utils/linkEditorAnchor';
import { normalizeInlineLinks, analyzeInlineLinks } from '../utils/inlineLinkNormalizer';
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
import { scanExistingLinksCompat } from '../utils/existingLinkScanner';
import FeaturedSnippetPromptModal from './FeaturedSnippetPromptModal';
import {
    wrapPlainTextWithLinkInBlocks,
    replaceFirstPlainTextWithLink,
    replaceFirstPlainTextWithText,
} from '../utils/articleLinkInsert';
import { findPlainTextRangeInRoot } from '../utils/articlePlainTextRange';
import { isCtaPlainTextType } from '../utils/ctaLinkFormat';
import {
    captureEditorInsertionContext,
    getEditorInsertionContext,
    clearFrozenEditorInsertionContext,
    freezeEditorInsertionContext,
    getFrozenEditorInsertionContext,
    getInsertionContextForCommand,
    isAssistantFocusStealTarget,
    resolveEditorForInsertion,
    scrollElementIntoViewIfNeeded,
    syncAndFreezeInsertionContext,
    syncInsertionContextFromLiveEditors,
} from '../utils/editorInsertionContext';
import {
    bindEditorCommandHost,
    unbindEditorCommandHost,
    executeEditorCommand,
} from '../utils/editorCommands';
import {
    reorderBlockWithinSection,
    withinSectionMoveAvailability,
} from '../utils/articleEditorBlockReorder';
import { EDITOR_COMMAND_CODES } from '../utils/editorCommands/editorCommandResult';

import { collectContentImagesFromArticle } from '../utils/contentImageCounter';
import {
    buildUnifiedArticleImagesInventory,
    unifiedInventorySlugFixCandidates,
    unifiedInventoryToImageRows,
} from '../utils/unifiedArticleImagesInventory';
import { normalizeOrphanQuoteCharacters } from '../utils/orphanQuoteNormalizer';
import {
    TIPTAP_HTML_PARSE_OPTIONS,
    hasInlineWhitespaceCorruption,
    plainTextFromHtmlLoose,
    INLINE_WHITESPACE_CORRUPTION_CODE,
    countGluedInlineMarkBoundaries,
    repairGluedInlineMarkBoundaryWhitespaceWithReport,
} from '../utils/inlineWhitespaceGuard';
import { SEO_EDITOR_LINK_CLASS } from '../utils/articleEditorTransientMarkup';
import {
    filterSuggestedInternalLinks,
    isInternalHrefForSite,
    isSpecialOrContactHref,
    mergeSuggestionCatalog,
    normalizeHrefForCompare,
    normalizeLinkLabel,
} from '../utils/articleLinkSuggestionFilter';
import { articleShortcutActionFromEvent } from '../utils/articleEditorShortcuts';
import ArticleAssistantWidget from './ArticleAssistantWidget';
import ArticleGoogleSerpPreview from './ArticleGoogleSerpPreview';
import ArticleOutlineTab from './ArticleOutlineTab';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { fetchWordPressProductReviews } from '../utils/articleEditorApi';
import { csrfToken, seoArticleApiHeaders, seoArticleApiFetch } from '../utils/seoArticleApi';
import {
    buildSeoAnalysisPayload,
} from '../utils/seoAnalyzer';
import { composeImmediateArticleAnalysis } from '../utils/composeArticleAnalysis';
import { getAnalysisPolicy, getExternalFacts } from '../utils/articleAnalysisOwnership';
import { documentJsonFromEditorsOrBlocks } from '../utils/editorDocumentBridge';
import { sanitizeViolations, scoreFromViolations, buildFailedViolationItems } from '../utils/seoScoreCalculator';
import { loadFeaturedImage } from '../utils/articleFeaturedImageStorage';
import {
    isAbortError,
    isEditorHostedModule,
    LINKS_RESCAN_REQUEST_EVENT,
    normalizeHeavyModuleId,
} from '../utils/articleEditorModules';
import { normalizeSeoSummary, readCoreBootstrap } from '../utils/articleEditorPayloadAdapters';
import { t } from '../utils/i18n';
import { EditorHostApiProvider } from '../editor/host/EditorHostApiContext';
import { EditorSidebarNavigation } from '../editor/host/EditorSidebarNavigation';
import { EditorSidebarPortalHost } from '../editor/host/EditorSidebarPortalHost';
import { SharedMediaPicker } from '../editor/host/SharedMediaPicker';
import { installEditorShellCompatibilityBridge } from '../editor/runtime/editorShellCompatibilityBridge';
import { installMediaPickerCompatibilityBridge } from '../editor/runtime/mediaPickerCompatibilityBridge';
import {
    getActivePanel,
    openPanel,
    subscribeEditorNavigation,
} from '../editor/runtime/editorRuntimeNavigation';
import { publishPartialRuntimeWidgetHealth } from '../editor/runtime/composeRuntimeWidgetHealth';
import { installRuntimeHealthBadgeBridge } from '../editor/runtime/editorRuntimeHealthStore';
import { SHELL_BOUNDARY_NAV_ITEMS } from '../editor/runtime/editorShellNavItems';
import {
    discardLegacyMediaLocalStorage,
    featuredFromSnapshot,
    fetchMediaSnapshot,
    galleryFromSnapshot,
    setFeaturedViaApi,
    subscribeMediaSnapshot,
    normalizeFeaturedMediaItem,
} from '../utils/articleEditorMediaSnapshot';
import { openMediaPicker } from '../editor/runtime/editorMediaPickerStore';
import { DEFAULT_WIKI_TRUST_DOMAINS } from '../utils/wikiTrustDomains';
import { setArticleAutosaveLock, isArticleAutosaveLocked } from '../utils/articleAutosaveLock';
import {
    appendProductAlbumItems,
    syncProductAlbumToServer,
    loadProductAlbum,
    normalizeProductAlbumList,
    removeProductAlbumItem,
} from '../utils/articleProductAlbumStorage';
import { clearFeaturedImageStorage, saveFeaturedImage } from '../utils/articleFeaturedImageStorage';
import { clearArticleMediaPickerCache } from '../utils/articleMediaPickerCache';
import GenerateImageModal from './GenerateImageModal';
import EditorBusyOverlay from './EditorBusyOverlay';
import {
    applyImagePatchToBlocks,
    applyQuickFixAltTitleToBlock,
    applyQuickFixAltTitleToBlocks,
    applyQuickFixSlugToBlock,
    applyQuickFixSlugToBlocks,
    applyRenameMapToFeaturedImageStorage,
    assignInArticleQuickFixIndices,
    buildAltTitleMetaUpdatePayload,
    buildExactRenameUrlMap,
    buildMergedEditorImagesForPicker,
    buildQuickFixIndexByBlockId,
    collectImagesFromBlocks,
    filterSupplementalDuplicatesOfBlockRows,
    computeQuickFixAltTitleSupplementalOutcome,
    computeQuickFixSlugSupplementalOutcome,
    executeSeoMediaSlugRenamesTwoPhase,
    ensureLocalRenameResultsCoverQueue,
    ensureWpRenameResultsCoverQueue,
    enrichWpRenamedWithRequestMeta,
    enrichBlocksWithPostImages,
    finalizeBlocksAfterWpRename,
    buildLocalSlugRenameErrorNotify,
    mapArticleSlugFixReplacementsToLocalResults,
    omitFailedLocalSlugRenameQueueItems,
    reconcileSupplementalImagesWithBlocks,
    resetSupplementalImagesAfterSlugRename,
    resolveWpRenameOldUrl,
    resolveImageRefIds,
    resolveArticleImageRemoveTarget,
    articleImageRowsShareIdentity,
    shouldRenameSlugOnWordPress,
    syncProductAlbumUrlsFromBlockImages,
    slugFromUrl,
} from '../utils/articleImagesUtils';
import {
    isBulkSlugRenameSafeMedia,
    isWordPressProtectedMedia,
} from '../utils/mediaSourceClassification';
import {
    confirmSlugRename,
} from '../utils/imageSlugRenameConfirm';
import { dispatchWordPressAttachmentMetaUpdate } from '../utils/imageAttachmentMetaUpdate';
import {
    AI_PLACEHOLDER_LOADING_URL,
    createClipboardPasteHandler,
    fetchArticleAiMediaJobs,
    fetchSeoMediaStatus,
    renameSeoMedia,
    renameSeoMediaByUrl,
    fixArticleMediaSlugs,
    updateSeoMediaMeta,
} from '../utils/seoMediaApi';
import {
    showArticleOperationOverlay,
} from '../utils/articleOperationTracker';
import { getDefaultArticleEditorRuntime } from '../editor/runtime/defaultArticleEditorRuntime';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { useArticleEditorHistory } from '../hooks/useArticleEditorHistory';
import {
    clearDraft,
    draftOffersManualChoice,
    hashContent,
    isDraftPersistenceEnabled,
    loadDraft,
    resolveLocalDraftDecision,
    saveDraft,
    setDraftPersistenceEnabled,
    writeSyncedLocalSnapshot,
} from '../utils/articleEditorStorage';
import {
    saveArticleViaApiSingleFlight,
    saveCurrentArticleFromEditor,
    shouldSuppressServerAutosave,
    cancelPendingServerAutosave,
} from '../utils/articleEditorSaveQueue';
import {
    buildArticleEditorApiPayload,
    getEditorConflictTokens,
    setEditorConflictTokens,
    applyEditorDocumentAck,
    logArticleEditorVersionDebug,
    previewSeoScoreViaApi,
} from '../utils/articleEditorApi';
import { buildEditorDocumentEnvelope, blocksFromEditorDocumentEnvelope, isUsableTipTapDocument } from '../utils/articleEditorDocument';
import {
    assertWritableEditorSession,
    canMutateEditor,
    getArticleEditorSessionState,
    runEditorMutation,
} from '../utils/editorSessionState';
import {
    ARTICLE_EDITOR_DRAFT_ALERT_EVENT,
    ARTICLE_EDITOR_OPEN_DRAFT_CHOICE_EVENT,
} from '../utils/articleEditorStickyHeader';
import {
    buildClientOutlineTree,
    extractOutlineHeadingFromBlock,
    flattenClientOutlineNodes,
    normalizeOutlineHeadingText,
    outlineHeadingFingerprint,
} from '../utils/articleEditorClientOutline';
import { createArticleEditorUtilityScheduler } from '../utils/articleEditorUtilityScheduler';
import { countWordsFromHtmlLight } from '../utils/articleEditorMetrics';
import {
    htmlToPlainText,
    isMeaningfulHtml,
    isWordPressImageElement,
    normalizeBlocks,
    parseImageFromBlockContent,
    parseFeaturedSnippetNewSectionBlocks,
    renderImageFigure,
    splitHtmlIntoTextAndImageChunks,
    withDefaultImageInsertAlign,
} from '../utils/blockImageUtils';
import {
    cleanBlockHtmlForEditorDisplay,
    ensureTiptapHeadingCursorParagraph,
    FAQ_SHORTCODE_HTML,
    flattenHtmlBodyNodes,
    isFaqPlaceholderHtml,
    leadingHeadingLevel,
    normalizeSectionHeadingBlockHtml,
    persistBlockHtmlFromEditor,
    resetTipTapEditorHistory,
} from '../utils/editorHtmlUtils';
import { resolveArticleImageSrc, resolveFullWordPressImageUrl, isLocalSeoMediaSrc, supportsWordPressImageSizes } from '../utils/wordpressImageUrl';
import { applyWordPressImageSize } from '../utils/wordpressImageSize';
import {
    SEO_EDITOR_LINK_MARK_CLASS,
    SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS,
    stripEditorTransientMarkup,
} from '../utils/articleEditorTransientMarkup';
import FaqAccordionPreview from './FaqAccordionPreview';
import { Undo2, Redo2, Plus, ChevronDown, ChevronRight, ImageIcon, Table, Link2, Wand2, AlertTriangle, Search, ListPlus, Sparkles, ListCollapse, Trash2, BarChart3, Star } from 'lucide-react';
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

const OUTLINE_HEADING_TEXT_MAX = 255;

/** Khớp server Str::limit(..., 255) / cột heading_text — tránh lệch key DB truncated vs editor full. */
const truncateOutlineHeadingText = (value) =>
    Array.from(normalizeOutlineHeadingText(value)).slice(0, OUTLINE_HEADING_TEXT_MAX).join('');

const extractOutlineApiErrorMessage = (data, response) => {
    if (response.status === 419) {
        return 'Phiên đăng nhập hết hạn — tải lại trang rồi thử lại.';
    }

    const direct = typeof data?.message === 'string' ? data.message.trim() : '';
    if (direct !== '') {
        return direct;
    }

    const errors = data?.errors;
    if (errors && typeof errors === 'object') {
        for (const key of Object.keys(errors)) {
            const first = Array.isArray(errors[key]) ? errors[key][0] : null;
            if (typeof first === 'string' && first.trim() !== '') {
                return first.trim();
            }
        }
    }

    return data?.success === false
        ? 'Yêu cầu outline thất bại.'
        : `Yêu cầu outline thất bại (HTTP ${response.status}).`;
};

const outlineApiCsrfToken = () => csrfToken();

async function outlineApiRequest(articleId, path, options = {}) {
    // Phase 4: client outline ids are not server resources.
    if (/\/(?:client:|pending-)/.test(String(path ?? ''))) {
        return { success: true };
    }

    const response = await fetch(`/api/seo/articles/${articleId}/outline${path}`, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...seoArticleApiHeaders(),
            ...(outlineApiCsrfToken() ? { 'X-CSRF-TOKEN': outlineApiCsrfToken() } : {}),
            ...(options.headers ?? {}),
        },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) {
        throw new Error(extractOutlineApiErrorMessage(data, response));
    }

    return data;
}

const flattenOutlineNodes = (nodes, result = []) => flattenClientOutlineNodes(nodes, result);

const findBlockIdForOutlineHeading = (blocks, level, headingText) => {
    const target = truncateOutlineHeadingText(headingText);
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
            (node) => truncateOutlineHeadingText(node.textContent) === target,
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
            const text = truncateOutlineHeadingText(node?.heading_text);
            if (level >= 2 && text !== '') {
                keys.add(outlineHeadingKey(level, text));
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
    `${Number(level)}|${truncateOutlineHeadingText(headingText)}`;

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

            wordCount += countWordsFromHtmlLight(html);

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

const resolveSupplementalImagesWithGallery = (
    supplementalImages,
    galleryItems,
    articleId,
    supportsProductGallery = false,
) => {
    const album = Array.isArray(galleryItems) ? galleryItems : [];

    if (supportsProductGallery) {
        const nonProductMedia = (Array.isArray(supplementalImages) ? supplementalImages : []).filter(
            (row) => {
                const origin = String(row?.origin ?? '').trim();

                return origin !== 'gallery' && origin !== 'featured';
            },
        );

        if (album.length === 0) {
            return nonProductMedia;
        }

        return [...nonProductMedia, ...buildGallerySupplementalRows([], album, articleId)];
    }

    const nonGallery = (Array.isArray(supplementalImages) ? supplementalImages : []).filter(
        (row) => String(row?.origin ?? '').trim() !== 'gallery',
    );
    const galleryRows = buildGallerySupplementalRows([], galleryItems, articleId);

    return [...nonGallery, ...galleryRows];
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

        const wpOpen = String(match[1] || '');
        const wpInner = String(match[2] || '').trim();
        const isWpImageBlock = /<!--\s*wp:image\b/i.test(wpOpen);
        if (isWpImageBlock) {
            const image = parseImageFromBlockContent(wpInner) ?? parseVideoMediaFromHtml(wpInner);
            if (image && image.mediaType !== 'video' && image.src) {
                blocks.push({
                    id: newBlockId('image'),
                    type: 'image',
                    isWp: true,
                    prefix: '',
                    content: renderImageFigure(image),
                    suffix: '',
                    image,
                });
            } else if (wpInner) {
                blocks.push(...splitClassic(wpInner));
            }
        } else {
            blocks.push({
                id: newBlockId('wp'),
                isWp: true,
                type: 'text',
                prefix: wpOpen,
                content: wpInner,
                suffix: match[3],
            });
        }
        lastIndex = wpRegex.lastIndex;
    }

    if (lastIndex < html.length) {
        const textAfter = html.substring(lastIndex);
        if (textAfter.trim()) blocks.push(...splitClassic(textAfter));
    }

    return hoistInlineImagesFromTextBlocks(regroupParsedBlocksByH2(normalizeBlocks(blocks)));
};

const hoistInlineImagesFromTextBlocks = (blocks) => {
    if (!Array.isArray(blocks) || blocks.length === 0) {
        return blocks;
    }

    const result = [];

    blocks.forEach((block) => {
        if (!block || block.type === 'image' || typeof block.content !== 'string') {
            result.push(block);

            return;
        }

        if (!/<img[\s>]/i.test(block.content)) {
            result.push(block);

            return;
        }

        const chunks = splitHtmlIntoTextAndImageChunks(block.content);
        if (chunks.length <= 1 && chunks[0]?.type === 'text') {
            result.push(block);

            return;
        }

        chunks.forEach((chunk) => {
            if (chunk.type === 'image' && chunk.image?.src) {
                result.push({
                    id: newBlockId('image'),
                    type: 'image',
                    isWp: Boolean(block.isWp),
                    prefix: '',
                    content: chunk.html || renderImageFigure(chunk.image),
                    suffix: '',
                    image: chunk.image,
                });

                return;
            }

            const html = String(chunk.html || '').trim();
            if (!html || !isMeaningfulHtml(html)) {
                return;
            }

            result.push({
                id: newBlockId(block.isWp ? 'wp' : 'classic'),
                type: 'text',
                isWp: false,
                prefix: '',
                content: cleanBlockHtmlForEditorDisplay(html),
                suffix: '',
            });
        });
    });

    return normalizeBlocks(result);
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
    sectionId = null,
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
    editable = true,
}) {
    const [linkAnchor, setLinkAnchor] = useState(null);
    const [htmlInspectorOpen, setHtmlInspectorOpen] = useState(false);
    const [htmlInspectorSnapshot, setHtmlInspectorSnapshot] = useState('');
    const [blockStyleTick, setBlockStyleTick] = useState(0);
    const editorContainerRef = useRef(null);
    const sourceHtml = displayContent ?? block.content;
    const isHydratingRef = useRef(false);
    const acceptUpdatesRef = useRef(false);
    const initialEditorContent = useMemo(() => {
        if (isUsableTipTapDocument(block.editorDocument)) {
            return block.editorDocument;
        }

        return normalizeOrphanQuoteCharacters(ensureTiptapHeadingCursorParagraph(sourceHtml) || '<p></p>');
    }, [block.id]);
    const { minHeight, setMinHeight, persistHeight, minH, maxH } = useBlockEditorHeight(block.id);

    const pushHtml = useCallback(
        (html) => {
            if (suppressBlockUpdate || isHydratingRef.current || !acceptUpdatesRef.current) return;
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

    // Phase 6A — TipTap extensions from internal runtime registry (stable identity).
    const editor = useEditor({
        extensions: getDefaultArticleEditorRuntime().getDocumentExtensions(),
        content: initialEditorContent,
        editable: Boolean(editable),
        parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
        onCreate: () => {
            // Initial content must not dirty / autosave. Enable after first paint.
            acceptUpdatesRef.current = false;
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    acceptUpdatesRef.current = true;
                });
            });
        },
        onUpdate: ({ editor: ed, transaction }) => {
            if (!acceptUpdatesRef.current || isHydratingRef.current) {
                return;
            }
            if (transaction?.getMeta?.('preventUpdate')) {
                return;
            }
            setBlockStyleTick((n) => n + 1);
            pushHtml(ed.getHTML());
            captureEditorInsertionContext({
                sectionId,
                blockId: block.id,
                editor: ed,
            });
        },
        onSelectionUpdate: ({ editor: ed }) => {
            setBlockStyleTick((n) => n + 1);
            captureEditorInsertionContext({
                sectionId,
                blockId: block.id,
                editor: ed,
            });
        },
        onBlur: ({ editor: ed, event }) => {
            const related = event?.relatedTarget ?? document.activeElement;
            if (isAssistantFocusStealTarget(related)) {
                // Freeze last caret; do not let blur rewrite bookmark to doc end.
                freezeEditorInsertionContext(
                    (() => {
                        captureEditorInsertionContext({
                            sectionId,
                            blockId: block.id,
                            editor: ed,
                        });
                        return getEditorInsertionContext();
                    })(),
                );
                return;
            }
            captureEditorInsertionContext({
                sectionId,
                blockId: block.id,
                editor: ed,
            });
        },
        onFocus: ({ editor: ed }) => {
            setGlobalEditor(ed);
            captureEditorInsertionContext({
                sectionId,
                blockId: block.id,
                editor: ed,
            });
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

        resetTipTapEditorHistory(editor);
    }, [editor]);

    useEffect(() => {
        if (!editor) return;
        const nextEditable = Boolean(editable);
        if (editor.isEditable === nextEditable) {
            return;
        }
        editor.setEditable(nextEditable);
    }, [editor, editable]);

    useEffect(() => {
        if (!editor) return;

        if (isUsableTipTapDocument(block.editorDocument)) {
            // JSON hydrate once per block mount; avoid HTML re-parse remount.
            return;
        }

        const nextHtml = normalizeOrphanQuoteCharacters(
            ensureTiptapHeadingCursorParagraph(sourceHtml) || '<p></p>',
        );
        // Khi user đang gõ, parent state đổi theo từng key stroke. Nếu hydrate lại
        // bằng setContent dù HTML tương đương, Tiptap sẽ reset selection/caret về cuối đoạn.
        if (isSameTiptapBlockContent(sourceHtml, editor.getHTML(), nextHtml)) {
            return;
        }

        isHydratingRef.current = true;
        acceptUpdatesRef.current = false;
        editor.commands.setContent(nextHtml, {
            emitUpdate: false,
            parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
        });
        resetTipTapEditorHistory(editor);
        isHydratingRef.current = false;
        requestAnimationFrame(() => {
            acceptUpdatesRef.current = true;
        });
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

    const openLinkEditorAtSelection = useCallback((savedSelection = null) => {
        if (!editor) {
            return;
        }

        if (
            savedSelection &&
            typeof savedSelection.from === 'number' &&
            typeof savedSelection.to === 'number'
        ) {
            const docSize = editor.state.doc.content.size;
            const from = Math.min(Math.max(0, savedSelection.from), docSize);
            const to = Math.min(Math.max(from, savedSelection.to), docSize);
            editor.chain().focus().setTextSelection({ from, to }).run();
        }

        // Chỉ extend khi caret nằm trong link (selection rỗng). Selection có vùng chọn
        // phải giữ nguyên — tránh cắt cụm text+strong thành một phần link cũ.
        if (editor.isActive('link') && editor.state.selection.empty) {
            editor.chain().focus().extendMarkRange('link').run();
        }

        const rect = resolveLinkEditorAnchorRect(editor);
        if (rect) {
            setLinkAnchor(rect);
        }
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
            const rect = resolveLinkEditorAnchorRect(editor);
            if (rect) {
                setLinkAnchor(rect);
            }
        };

        editor.view.dom.addEventListener('click', onLinkClick, true);
        return () => editor.view.dom.removeEventListener('click', onLinkClick, true);
    }, [editor]);

    return (
        <div className="block-editor-active" ref={editorContainerRef}>
            <span className="block-editor-badge" data-style-tick={blockStyleTick}>
                {suppressBlockUpdate
                    ? t('editor_temp_merge')
                    : block.isWp
                        ? 'WP Block'
                        : (() => {
                            if (editor) {
                                for (let level = 1; level <= 6; level += 1) {
                                    if (editor.isActive('heading', { level })) {
                                        return t(`style_heading_${level}`);
                                    }
                                }
                                if (editor.isActive('codeBlock')) {
                                    return t('style_preformatted');
                                }
                            }
                            const level = leadingHeadingLevel(sourceHtml);
                            if (level) {
                                return t(`style_heading_${level}`);
                            }
                            return t('editor_paragraph');
                        })()}
            </span>
            <BlockFormatToolbar
                editor={editor}
                onDelete={onDelete}
                canDelete={canDeleteBlock}
                onEditLink={openLinkEditorAtSelection}
                onViewHtml={() => {
                    if (!editor) {
                        return;
                    }
                    const current = editor.getHTML();
                    const analysis = analyzeInlineLinks(current);
                    if (analysis.duplicateAdjacentCount > 0 || analysis.nestedAnchorCount > 0) {
                        // Dev-only signal — không toast khi đang gõ; chỉ khi mở inspector.
                        if (typeof process !== 'undefined' && process.env?.NODE_ENV === 'development') {
                            // eslint-disable-next-line no-console
                            console.warn('[SeoArticleEditor] split/nested anchors detected', analysis.warnings);
                        }
                    }
                    setHtmlInspectorSnapshot(current);
                    setHtmlInspectorOpen(true);
                }}
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
                <EditorInspectorBubbleHost
                    runtime={getDefaultArticleEditorRuntime()}
                    slot="bubble.link"
                    editor={editor}
                    anchorRect={linkAnchor}
                    containerRef={editorContainerRef}
                    onClose={() => setLinkAnchor(null)}
                    articleId={articleId}
                    siteId={siteId}
                />
            ) : null}
            <ArticleHtmlInspectorModal
                open={htmlInspectorOpen}
                html={htmlInspectorSnapshot}
                onClose={() => setHtmlInspectorOpen(false)}
                onApplyHtml={(nextHtml) => {
                    if (!editor) {
                        return { ok: false, error: t('html_inspector_invalid_html') };
                    }
                    try {
                        const normalized = normalizeInlineLinks(String(nextHtml ?? ''));
                        editor.commands.setContent(normalized || '<p></p>', {
                            emitUpdate: true,
                            parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                        });
                        setHtmlInspectorSnapshot(editor.getHTML());
                        return { ok: true };
                    } catch (error) {
                        return {
                            ok: false,
                            error: error instanceof Error ? error.message : t('html_inspector_invalid_html'),
                        };
                    }
                }}
            />
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
                    maxLength={255}
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
    sectionId = null,
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
    editable = true,
    supportsProductGallery = false,
    panelFaqs,
    faqCount = null,
    canGenerateFaq = false,
    onEditFaq,
    onCreateFaq,
    introImagesLocked = false,
    outlineHeadingsLocked = false,
    isSectionHeadingBlock = false,
    onOutlineHeadingCommand,
    onArmOutsideClickGuard,
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
                onArmOutsideClickGuard={onArmOutsideClickGuard}
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
                    onClick={() => {
                        onActivate();
                        onEditFaq?.();
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            onActivate();
                            onEditFaq?.();
                        }
                    }}
                    role="button"
                    tabIndex={0}
                    title={t('editor_faq_shortcode_hint')}
                >
                    <FaqAccordionPreview
                        faqs={panelFaqs}
                        faqCount={faqCount}
                        canGenerateFaq={canGenerateFaq}
                        onEditFaq={onEditFaq}
                        onCreateFaq={onCreateFaq}
                    />
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
            sectionId={sectionId}
            displayContent={displayContent}
            suppressBlockUpdate={suppressBlockUpdate}
            onUpdate={onUpdate}
            onRegisterFlush={onRegisterFlush}
            onRegisterEditor={onRegisterEditor}
            setGlobalEditor={setGlobalEditor}
            onDelete={onDelete}
            canDeleteBlock={canDeleteBlock}
            articleId={articleId}
            siteId={siteId}
            editable={editable}
        />
    );
}

export default function SeoArticleEditor({
    articleId,
    siteId = null,
    initialHtml,
    initialEditorDocument = null,
    initialEditorDocumentHash = null,
    initialSeo,
    initialPostImages = [],
    initialSupplementalImages = [],
    initialPostType = '',
    contentRevision = '',
    connectionHash = '',
    expectedUpdatedAt = '',
    expectedContentHash = '',
    documentVersion = 1,
    sessionReadOnly = false,
    supportsProductGallery: supportsProductGalleryProp = false,
    isCanaryProduct: isCanaryProductProp = false,
    productCategoryOptions = [],
    initialProductGallery = [],
    initialFaqs = [],
    initialVirtualReviews = [],
    articleTitle = '',
    editorSettings = {},
    mediaPickerUrl = '',
    initialLoaiSanPham = '',
    initialGalleryDescription = '',
    perfDebug = false,
}) {
    const [supportsProductGallery, setSupportsProductGallery] = useState(() => Boolean(supportsProductGalleryProp));
    const isCanaryProduct = Boolean(isCanaryProductProp);
    const historyStep = editorSettings?.history_step ?? 20;
    const connectionHashRef = useRef(connectionHash);
    connectionHashRef.current = connectionHash;
    const siteIdRef = useRef(siteId);
    siteIdRef.current = siteId;
    const draftScope = useCallback(() => ({
        siteId: Number(siteIdRef.current ?? 0) || 0,
    }), []);
    const withDraftSite = useCallback((payload = {}) => ({
        ...payload,
        site_id: Number(siteIdRef.current ?? 0) || 0,
    }), []);
    const perfDebugEnabled = Boolean(perfDebug || editorSettings?.perf_debug);

    useEffect(() => {
        window.__SEO_ARTICLE_MEDIA_PICKER_ENDPOINT__ = mediaPickerUrl;

        return () => {
            delete window.__SEO_ARTICLE_MEDIA_PICKER_ENDPOINT__;
        };
    }, [mediaPickerUrl]);

    useEffect(() => {
        if (!perfDebugEnabled || typeof performance === 'undefined' || typeof performance.mark !== 'function') {
            return;
        }
        performance.mark('seo-article-editor-react-ready');
        try {
            performance.measure(
                'seo-article-editor-mount-to-react-ready',
                'seo-article-editor-mount-start',
                'seo-article-editor-react-ready',
            );
        } catch {
            // Marks có thể thiếu nếu component remount qua livewire:navigated — bỏ qua.
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const [blocks, setBlocks] = useState([]);
    const [activeBlockId, setActiveBlockId] = useState(null);
    const [tempMerge, setTempMerge] = useState(null);
    const [globalEditor, setGlobalEditor] = useState(null);
    const outlineRailRef = useRef(null);
    const [assistantPortalRoots, setAssistantPortalRoots] = useState({
        seo: null,
        image: null,
        reviews: null,
        links: null,
        faq: null,
        featured: null,
        aiChat: null,
    });
    // Phase 6C.2–6C.4: editor-hosted includes seo/images/reviews/links/faq/featured/ai-chat.
    // Default SEO so right-rail SEO Assistant is not stuck on inactive placeholder.
    const [activeHeavyModule, setActiveHeavyModule] = useState('seo');
    const editorHostActionsRef = useRef({});
    const activeHeavyModuleRef = useRef(null);
    activeHeavyModuleRef.current = activeHeavyModule;
    const imagesAbortRef = useRef(null);
    const reviewsAbortRef = useRef(null);
    const seoSummaryAbortRef = useRef(null);
    const [seoSummaryLoading, setSeoSummaryLoading] = useState(false);
    const [seoSummaryError, setSeoSummaryError] = useState(null);
    const seoSummaryLoadedRef = useRef(false);

    const [sidebarNavRoot, setSidebarNavRoot] = useState(null);
    const [mediaPickerRoot, setMediaPickerRoot] = useState(null);
    const [runtimeContextRevision, setRuntimeContextRevision] = useState(0);

    // Phase 6B/6C.1 — shell bridge + runtime navigation (no dual CustomEvent listeners in host).
    useEffect(() => {
        const uninstallBridge = installEditorShellCompatibilityBridge();
        const uninstallMediaPickerBridge = installMediaPickerCompatibilityBridge();
        const uninstallBadgeBridge = installRuntimeHealthBadgeBridge();
        setSidebarNavRoot(document.getElementById('article-editor-sidebar-navigation-root'));
        setMediaPickerRoot(document.getElementById('article-editor-media-picker-root'));
        discardLegacyMediaLocalStorage(articleId);
        if (Number(articleId) > 0) {
            void fetchMediaSnapshot(articleId).catch(() => {});
        }
        const unsubNav = subscribeEditorNavigation((panelId) => {
            const normalized = normalizeHeavyModuleId(panelId);
            if (normalized && isEditorHostedModule(normalized)) {
                setActiveHeavyModule(normalized);
                return;
            }
            // External / Alpine-only / closed → unmount editor-hosted heavy body.
            setActiveHeavyModule(null);
        });
        // Align initial chip with runtime default.
        openPanel(getActivePanel() || 'seo', { source: 'host_mount' });

        return () => {
            unsubNav();
            uninstallMediaPickerBridge();
            uninstallBridge();
            uninstallBadgeBridge();
            imagesAbortRef.current?.abort();
            reviewsAbortRef.current?.abort();
        };
    }, []);

    // Phase 3: fetch images only while Images is the active heavy module; abort on leave.
    useEffect(() => {
        if (activeHeavyModule !== 'images' || !articleId) {
            imagesAbortRef.current?.abort();
            return undefined;
        }

        const controller = new AbortController();
        imagesAbortRef.current = controller;
        let cancelled = false;
        const imagesUrl =
            window.__SEO_EDITOR_LAZY_ENDPOINTS__?.images
            || `/api/seo/articles/${articleId}/editor/images`;
        const metaUrl =
            window.__SEO_EDITOR_LAZY_ENDPOINTS__?.meta
            || `/api/seo/articles/${articleId}/editor/meta`;

        void (async () => {
            try {
                const headers = {
                    Accept: 'application/json',
                    ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
                };
                const [imagesRes, metaRes] = await Promise.all([
                    seoArticleApiFetch(imagesUrl, { headers, signal: controller.signal }),
                    seoArticleApiFetch(metaUrl, { headers, signal: controller.signal }),
                ]);
                if (cancelled || controller.signal.aborted || activeHeavyModuleRef.current !== 'images') {
                    return;
                }
                if (imagesRes.response.ok && imagesRes.data?.success !== false) {
                    const images = Array.isArray(imagesRes.data?.data) ? imagesRes.data.data : [];
                    window.dispatchEvent(
                        new CustomEvent('article-post-images-synced', {
                            detail: { images },
                        }),
                    );
                }
                if (metaRes.response.ok && metaRes.data?.success !== false) {
                    const meta = metaRes.data?.data ?? {};
                    if (Array.isArray(meta.product_gallery) && meta.product_gallery.length > 0) {
                        window.dispatchEvent(
                            new CustomEvent('seo-product-gallery-updated', {
                                detail: {
                                    gallery: meta.product_gallery,
                                    article_id: articleId,
                                },
                            }),
                        );
                    }

                }
            } catch (error) {
                if (isAbortError(error) || controller.signal.aborted) {
                    return;
                }
            }
        })();

        return () => {
            cancelled = true;
            controller.abort();
            if (imagesAbortRef.current === controller) {
                imagesAbortRef.current = null;
            }
        };
    }, [activeHeavyModule, articleId]);

    const [virtualReviews, setVirtualReviews] = useState(() =>
        Array.isArray(initialVirtualReviews) ? initialVirtualReviews : [],
    );
    const isProductPost = supportsProductGallery;
    const showReviewsTab = editorSettings?.show_reviews_tab !== false;
    const canQuickCreateReviews = editorSettings?.can_quick_create_reviews === true;
    const showConfigureReviewsLink = editorSettings?.show_configure_reviews_link === true;
    const quickCreateReviewsConfigUrl = String(editorSettings?.quick_create_reviews_config_url ?? '').trim();
    const canGenerateFeaturedSnippet = editorSettings?.can_generate_featured_snippet === true;
    const canGenerateOutlineHeading = editorSettings?.can_generate_outline_heading === true;
    useEffect(() => {
        const refreshAssistantPortalRoots = () => {
            setAssistantPortalRoots({
                seo: document.getElementById('seo-article-seo-assistant-root'),
                image: document.getElementById('seo-article-image-assistant-root'),
                reviews: document.getElementById('seo-article-reviews-assistant-root'),
                links: document.getElementById('seo-article-links-root'),
                faq: document.getElementById('seo-article-faq-root'),
                featured: document.getElementById('seo-article-featured-root'),
                aiChat: document.getElementById('seo-article-ai-chat-root'),
            });
            setMediaPickerRoot(document.getElementById('article-editor-media-picker-root'));
        };

        refreshAssistantPortalRoots();
        window.addEventListener('load', refreshAssistantPortalRoots);

        return () => window.removeEventListener('load', refreshAssistantPortalRoots);
    }, []);

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

    const [reviewsLoadWarning, setReviewsLoadWarning] = useState(null);
    const [reviewsLoading, setReviewsLoading] = useState(false);
    const reviewsPanelActive = activeHeavyModule === 'reviews';
    const imagesPanelActive = activeHeavyModule === 'images';
    const seoPanelActive = activeHeavyModule === 'seo';
    useEffect(() => {
        // Phase 3: fetch reviews only while Reviews is active; abort + drop heavy list on leave.
        if (!showReviewsTab || !isProductPost || !articleId || !reviewsPanelActive) {
            reviewsAbortRef.current?.abort();
            if (!reviewsPanelActive) {
                setVirtualReviews([]);
                setReviewsLoading(false);
            }
            return undefined;
        }

        const controller = new AbortController();
        reviewsAbortRef.current = controller;
        let cancelled = false;

        (async () => {
            setReviewsLoading(true);
            setReviewsLoadWarning(null);
            try {
                const result = await fetchWordPressProductReviews(articleId);
                if (cancelled || controller.signal.aborted || activeHeavyModuleRef.current !== 'reviews') {
                    return;
                }
                if (!result.success) {
                    setReviewsLoadWarning(String(result.message ?? 'Không thể tải đánh giá từ WordPress.'));
                    return;
                }

                const data = result.data ?? {};
                const remote = Array.isArray(data.reviews) ? data.reviews : [];
                const pending = Array.isArray(data.pending_local_reviews) ? data.pending_local_reviews : [];
                setVirtualReviews([...remote, ...pending]);
                if (data.warning) {
                    setReviewsLoadWarning(String(data.warning));
                }
            } catch (error) {
                if (isAbortError(error) || cancelled || controller.signal.aborted) {
                    return;
                }
                setReviewsLoadWarning(String(error?.message ?? 'Không thể tải đánh giá từ WordPress.'));
            } finally {
                if (!cancelled && !controller.signal.aborted) {
                    setReviewsLoading(false);
                }
            }
        })();

        return () => {
            cancelled = true;
            controller.abort();
            if (reviewsAbortRef.current === controller) {
                reviewsAbortRef.current = null;
            }
        };
    }, [articleId, isProductPost, showReviewsTab, reviewsPanelActive]);

    const refreshVirtualReviews = useCallback(async () => {
        if (!articleId || !isProductPost) {
            return callEditArticleLivewire('refreshVirtualReviewsForEditor');
        }
        setReviewsLoading(true);
        try {
            const result = await fetchWordPressProductReviews(articleId);
            if (!result.success) {
                setReviewsLoadWarning(String(result.message ?? 'Không thể tải đánh giá từ WordPress.'));
                return [];
            }
            const data = result.data ?? {};
            const remote = Array.isArray(data.reviews) ? data.reviews : [];
            const pending = Array.isArray(data.pending_local_reviews) ? data.pending_local_reviews : [];
            const merged = [...remote, ...pending];
            setVirtualReviews(merged);
            setReviewsLoadWarning(data.warning ? String(data.warning) : null);
            return merged;
        } catch (error) {
            setReviewsLoadWarning(String(error?.message ?? 'Không thể tải đánh giá từ WordPress.'));
            return [];
        } finally {
            setReviewsLoading(false);
        }
    }, [articleId, isProductPost]);

    const generateQuickPostReviews = useCallback(
        () => callEditArticleLivewire('generateQuickPostReviews'),
        [],
    );

    const [saveStatus, setSaveStatus] = useState('saved');
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
    const [clientOutline, setClientOutline] = useState(() => []);
    const outlineFingerprintRef = useRef('');
    const utilitySchedulerRef = useRef(null);
    if (utilitySchedulerRef.current == null) {
        utilitySchedulerRef.current = createArticleEditorUtilityScheduler({
            perfDebug: Boolean(perfDebug || editorSettings?.perf_debug),
        });
    }
    const outlineHeadingIdsByBlockIdRef = useRef(new Map());
    const outlineHeadingIdsByKeyRef = useRef(new Map());
    const outlineAppendInflightRef = useRef(new Set());
    const outlineAppendDoneRef = useRef(new Set());
    const [insertMenu, setInsertMenu] = useState(null);
    const [collapsedSectionIds, setCollapsedSectionIds] = useState({});
    const [supplementalImages, setSupplementalImages] = useState(() => {
        const initial = Array.isArray(initialSupplementalImages) ? initialSupplementalImages : [];
        if (!supportsProductGallery || !articleId) {
            return initial;
        }

        const album = loadProductAlbum(articleId);

        return resolveSupplementalImagesWithGallery(initial, album, articleId, supportsProductGallery);
    });
    const supplementalImagesRef = useRef(supplementalImages);
    supplementalImagesRef.current = supplementalImages;
    const publishEditorImagesCatalogRef = useRef(() => {});
    const [postImages, setPostImages] = useState(() =>
        Array.isArray(initialPostImages) ? initialPostImages : [],
    );
    const postImagesRef = useRef(postImages);
    postImagesRef.current = postImages;
    const [quickReplaceFind, setQuickReplaceFind] = useState('');
    const [quickReplaceValue, setQuickReplaceValue] = useState('');
    const [editorSearchMatchCount, setEditorSearchMatchCount] = useState(null);
    const panelFaqsRef = useRef(Array.isArray(initialFaqs) ? initialFaqs : []);
    const [panelFaqs, setPanelFaqs] = useState(Array.isArray(initialFaqs) ? initialFaqs : []);
    panelFaqsRef.current = panelFaqs;
    const [faqCount, setFaqCount] = useState(() => {
        const core = readCoreBootstrap();
        const fromCore = Number(core?.faqCount ?? core?.faq_count ?? 0);
        return Number.isFinite(fromCore) ? fromCore : 0;
    });
    const canGenerateFaq = editorSettings?.can_generate_faq === true;
    const [featuredSnippetPromptOpen, setFeaturedSnippetPromptOpen] = useState(false);
    const [featuredSnippetPreviewHtml, setFeaturedSnippetPreviewHtml] = useState('');
    const [featuredSnippetPromptContext, setFeaturedSnippetPromptContext] = useState(null);
    const [seoAnalyzeError, setSeoAnalyzeError] = useState(null);
    const pendingFaqGenerateRef = useRef(false);
    const pendingQuickFixKeywordRef = useRef('');
    const pendingLocalRenameResultsRef = useRef([]);
    const pendingLocalRenameQueueRef = useRef([]);
    const pendingWpRenameRequestRef = useRef([]);
    const slugRenameManagedByBatchRef = useRef(false);
    const generateImageTargetRef = useRef('editor');
    const [generateImageModalOpen, setGenerateImageModalOpen] = useState(false);
    const [generateImageModalPrompt, setGenerateImageModalPrompt] = useState('');
    const [generateImageModalTarget, setGenerateImageModalTarget] = useState('editor');
    const [generateImageModalInitialCustom, setGenerateImageModalInitialCustom] = useState('');
    const [featuredSnippetGenerating, setFeaturedSnippetGenerating] = useState(false);
    const featuredSnippetTargetRef = useRef(null);

    // Phase 4: outline status derived from client blocks — no GET /outline on open/interact.
    useEffect(() => {
        const scheduler = utilitySchedulerRef.current;
        return () => {
            scheduler?.destroy();
            utilitySchedulerRef.current = null;
        };
    }, []);

    useEffect(() => {
        const scheduler = utilitySchedulerRef.current;
        if (!scheduler) {
            return undefined;
        }

        scheduler.bumpVersion();
        scheduler.schedule({
            id: 'outline',
            debounceMs: 400,
            priority: 'idle',
            run: ({ version, signal }) => {
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
                const nextBlocks = blocksRef.current;
                const fingerprint = outlineHeadingFingerprint(nextBlocks);
                if (fingerprint === outlineFingerprintRef.current) {
                    return;
                }
                outlineFingerprintRef.current = fingerprint;
                const tree = buildClientOutlineTree(nextBlocks);
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
                setClientOutline(tree);
                const flat = flattenClientOutlineNodes(tree);
                setOutlineHasSavedHeadings(flat.length > 0);
                setOutlineHeadingKeys(
                    new Set(
                        flat.map((node) =>
                            outlineHeadingKey(Number(node.level), normalizeOutlineHeadingText(node.heading_text)),
                        ),
                    ),
                );
                const byKey = new Map();
                for (const node of flat) {
                    const level = Number(node?.level ?? 0);
                    const text = normalizeOutlineHeadingText(node?.heading_text);
                    if (level >= 2 && text !== '' && node?.id != null) {
                        byKey.set(outlineHeadingKey(level, text), node.id);
                    }
                    if (node?.block_id) {
                        outlineHeadingIdsByBlockIdRef.current.set(String(node.block_id), node.id);
                    }
                }
                outlineHeadingIdsByKeyRef.current = byKey;
            },
        });

        return undefined;
    }, [blocks]);

    const parseGalleryItems = useCallback((items) => normalizeProductAlbumList(items), []);

    const [productGalleryItems, setProductGalleryItems] = useState(() =>
        parseGalleryItems(initialProductGallery.length > 0 ? initialProductGallery : loadProductAlbum(articleId)),
    );

    useEffect(() => {
        const onGalleryUpdated = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const gallery = detail.gallery;
            if (!Array.isArray(gallery)) {
                return;
            }

            const items = parseGalleryItems(gallery);
            setProductGalleryItems(items);
            setSupplementalImages((prev) =>
                resolveSupplementalImagesWithGallery(prev, items, articleId, supportsProductGallery),
            );

            if (supportsProductGallery && articleId) {
                if (items.length === 0) {
                    clearFeaturedImageStorage(articleId);
                } else {
                    const first = items[0];
                    saveFeaturedImage(articleId, {
                        url: first.url,
                        wpAttachmentId: first.id,
                        seoMediaId: first.id,
                    });
                }
            }

            setImagesReloadKey((key) => key + 1);
            queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
        };

        window.addEventListener('seo-product-gallery-updated', onGalleryUpdated);

        return () => window.removeEventListener('seo-product-gallery-updated', onGalleryUpdated);
    }, [articleId, parseGalleryItems, supportsProductGallery]);

    const [siteDomain] = useState(() => String(initialSeo?.site_domain ?? '').trim());
    const [articleType, setArticleType] = useState(
        () => String(initialSeo?.article_type ?? initialPostType ?? 'post').trim(),
    );
    const wikiTrustDomains = Array.isArray(editorSettings?.wiki_trust_domains)
        ? editorSettings.wiki_trust_domains
        : DEFAULT_WIKI_TRUST_DOMAINS;
    const scoringMessages =
        editorSettings?.seo_rule_messages && typeof editorSettings.seo_rule_messages === 'object'
            ? editorSettings.seo_rule_messages
            : editorSettings?.seo_scoring_messages && typeof editorSettings.seo_scoring_messages === 'object'
              ? editorSettings.seo_scoring_messages
              : {};
    const seoScoringRules = Array.isArray(editorSettings?.seo_scoring_rules)
        ? editorSettings.seo_scoring_rules
        : Array.isArray(initialSeo?.seo_scoring_rules)
          ? initialSeo.seo_scoring_rules
          : [];
    const seoMetaRef = useRef({
        seoTitle: String(articleTitle ?? initialSeo?.google_serp_preview?.title ?? '').trim(),
        metaDescription: String(
            initialSeo?.google_serp_preview?.description
            ?? initialSeo?.meta_description
            ?? '',
        ).trim(),
        slug: String(initialSeo?.article_slug ?? '').trim(),
    });
    const lastSeoAnalysisRef = useRef(null);
    const hasHydratedSeoFromServerRef = useRef(false);
    const [focusKeyword, setFocusKeyword] = useState(initialSeo?.focus_keyword ?? null);
    const [analysis, setAnalysis] = useState(initialSeo?.analysis ?? null);
    const [savedSeoScore, setSavedSeoScore] = useState(() => (
        initialSeo?.score === null || initialSeo?.score === undefined
            ? null
            : Number(initialSeo.score)
    ));
    const [seoScoreSource, setSeoScoreSource] = useState('saved');
    const seoPreviewAbortRef = useRef(null);
    const [mediaHealthTick, setMediaHealthTick] = useState(0);
    const [featuredHealthSnapshot, setFeaturedHealthSnapshot] = useState(() => loadFeaturedImage(articleId));

    useEffect(() => {
        const onSeoSummary = (event) => {
            const detail = event?.detail ?? {};
            if (!detail || typeof detail !== 'object') {
                return;
            }
            const summary = normalizeSeoSummary(detail);
            seoSummaryLoadedRef.current = true;
            setSeoSummaryError(null);
            setSeoSummaryLoading(false);
            if (summary.focusKeyword != null) {
                setFocusKeyword(summary.focusKeyword);
            }
            setAnalysis({
                score: summary.score,
                violations: summary.violations,
            });
            if (summary.seoTitle || summary.metaDescription || summary.articleSlug) {
                seoMetaRef.current = {
                    ...seoMetaRef.current,
                    seoTitle: summary.seoTitle || seoMetaRef.current.seoTitle,
                    metaDescription: summary.metaDescription || seoMetaRef.current.metaDescription,
                    slug: summary.articleSlug || seoMetaRef.current.slug,
                };
            }
            if (summary.siteDomain) {
                siteDomainRef.current = summary.siteDomain;
                // Domain arrived after first scan — republish classification for Links panel.
                window.dispatchEvent(new CustomEvent(LINKS_RESCAN_REQUEST_EVENT));
            }
        };
        window.addEventListener('seo-editor-seo-summary-loaded', onSeoSummary);
        return () => window.removeEventListener('seo-editor-seo-summary-loaded', onSeoSummary);
    }, []);

    // SEO Assistant: fetch summary when panel active if not yet loaded; always terminate loading.
    useEffect(() => {
        if (!seoPanelActive || !articleId) {
            seoSummaryAbortRef.current?.abort();
            setSeoSummaryLoading(false);
            return undefined;
        }

        if (seoSummaryLoadedRef.current || analysis != null) {
            setSeoSummaryLoading(false);
            return undefined;
        }

        const controller = new AbortController();
        seoSummaryAbortRef.current = controller;
        setSeoSummaryLoading(true);
        setSeoSummaryError(null);

        void (async () => {
            let settled = false;
            try {
                const url =
                    window.__SEO_EDITOR_LAZY_ENDPOINTS__?.seoSummary
                    || `/api/seo/articles/${articleId}/editor/seo-summary`;
                const settingsUrl =
                    window.__SEO_EDITOR_LAZY_ENDPOINTS__?.settings
                    || `/api/seo/articles/${articleId}/editor/settings`;
                const [seoRes, settingsRes] = await Promise.all([
                    seoArticleApiFetch(url, { signal: controller.signal }),
                    seoArticleApiFetch(settingsUrl, { signal: controller.signal }),
                ]);
                if (controller.signal.aborted || activeHeavyModuleRef.current !== 'seo') {
                    return;
                }
                if (settingsRes.response.ok && settingsRes.data?.success !== false) {
                    const settingsData = settingsRes.data?.data ?? {};

                }
                if (!seoRes.response.ok || seoRes.data?.success === false) {
                    settled = true;
                    setSeoSummaryError(t('editor_seo_load_error'));
                    return;
                }
                const summary = normalizeSeoSummary(seoRes.data);
                settled = true;
                seoSummaryLoadedRef.current = true;
                window.dispatchEvent(
                    new CustomEvent('seo-editor-seo-summary-loaded', { detail: summary.raw }),
                );
            } catch (error) {
                if (isAbortError(error) || controller.signal.aborted) {
                    return;
                }
                if (activeHeavyModuleRef.current === 'seo') {
                    settled = true;
                    setSeoSummaryError(t('editor_seo_load_error'));
                }
            } finally {
                if (!controller.signal.aborted && activeHeavyModuleRef.current === 'seo') {
                    setSeoSummaryLoading(false);
                    if (!settled && !seoSummaryLoadedRef.current) {
                        setSeoSummaryError(t('editor_seo_load_error'));
                    }
                }
            }
        })();

        return () => {
            controller.abort();
            if (seoSummaryAbortRef.current === controller) {
                seoSummaryAbortRef.current = null;
            }
        };
    }, [seoPanelActive, articleId, analysis]);

    const [extractedLinks, setExtractedLinks] = useState(() => {
        const source = initialSeo?.extracted_links ?? { internal: [], external: [] };

        return {
            internal: Array.isArray(source.internal) ? source.internal : [],
            external: (Array.isArray(source.external) ? source.external : []).filter(
                (item) => !isSpecialOrContactHref(item?.href),
            ),
        };
    });
    const [suggestedInternalLinks, setSuggestedInternalLinks] = useState(() =>
        filterSuggestedInternalLinks(
            initialSeo?.suggested_internal_links ?? [],
            initialSeo?.extracted_links?.internal ?? [],
            initialSeo?.extracted_links?.external ?? [],
        ),
    );
    const [suggestedExternalLinks, setSuggestedExternalLinks] = useState(() =>
        filterSuggestedInternalLinks(
            initialSeo?.suggested_external_links ?? [],
            initialSeo?.extracted_links?.internal ?? [],
            initialSeo?.extracted_links?.external ?? [],
        ),
    );
    const domainLinkCatalogRef = useRef(
        Array.isArray(initialSeo?.domain_link_list_catalog) ? initialSeo.domain_link_list_catalog : [],
    );
    const suggestionKeywordCatalogRef = useRef(
        Array.isArray(initialSeo?.suggested_internal_links_catalog)
            ? initialSeo.suggested_internal_links_catalog
            : [],
    );
    const suggestionExternalCatalogRef = useRef(
        mergeSuggestionCatalog(
            initialSeo?.suggested_external_links_catalog ?? [],
            initialSeo?.suggested_external_links ?? [],
        ),
    );
    const siteDomainRef = useRef(String(initialSeo?.site_domain ?? '').trim());

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

    useEffect(() => {
        setArticleAutosaveLock('quick-fix-slug-all', quickFixSlugAllBusy);

        return () => setArticleAutosaveLock('quick-fix-slug-all', false);
    }, [quickFixSlugAllBusy]);

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
                        galleryGenerationMode: String(normalized.galleryGenerationMode ?? 'sprite').trim() || 'sprite',
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
            external: withCounts(source.external).filter((item) => !isSpecialOrContactHref(item?.href)),
        };
    }, []);

    const publishExtractedLinks = useCallback((links, suggestedInternal = suggestedInternalLinks, suggestedExternal = suggestedExternalLinks) => {
        const enrichedLinks = enrichLinksWithOccurrences(links);
        const filteredSuggested = filterSuggestedInternalLinks(
            suggestedInternal,
            enrichedLinks.internal ?? [],
            enrichedLinks.external ?? [],
        );
        const filteredExternalSuggested = filterSuggestedInternalLinks(
            suggestedExternal,
            enrichedLinks.internal ?? [],
            enrichedLinks.external ?? [],
        ).filter((item) => {
            const href = String(item?.href ?? item?.target_url ?? '').trim();

            return href !== '' && !isSpecialOrContactHref(href);
        });
        const articlePlainText = htmlToPlainText(exportBlocksToHtml(blocksRef.current));
        window.dispatchEvent(
            new CustomEvent('seo-editor-links-updated', {
                detail: {
                    source: 'client-document',
                    links: enrichedLinks,
                    suggested_internal: filteredSuggested,
                    suggested_external: filteredExternalSuggested,
                    article_plain_text: articlePlainText,
                    site_domain: siteDomainRef.current,
                    domain_link_list_catalog: domainLinkCatalogRef.current,
                    suggested_internal_links_catalog: suggestionKeywordCatalogRef.current,
                    suggested_external_links_catalog: suggestionExternalCatalogRef.current,
                },
            }),
        );
    }, [suggestedInternalLinks, suggestedExternalLinks, enrichLinksWithOccurrences]);

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
    // Content body images only (image blocks + inline <img>). Never featured/gallery/supplemental library.
    const contentImageCensus = useMemo(
        () => collectContentImagesFromArticle(blocks),
        [blocks],
    );
    const unifiedImagesInventory = useMemo(
        () => buildUnifiedArticleImagesInventory({
            contentImages: contentImageCensus.rows,
            featuredImage: featuredHealthSnapshot ?? featuredFromSnapshot(articleId),
            galleryImages: productGalleryItems,
            supplementalImages,
        }),
        [
            contentImageCensus.rows,
            featuredHealthSnapshot,
            articleId,
            productGalleryItems,
            supplementalImages,
        ],
    );
    const unifiedImageRows = useMemo(
        () => unifiedInventoryToImageRows(unifiedImagesInventory),
        [unifiedImagesInventory],
    );
    // Images chip count = unique inventory assets (not content-only, not issue count).
    const imageTabCount = unifiedImageRows.length;

    const tempMergeRef = useRef(tempMerge);
    tempMergeRef.current = tempMerge;
    const blockFlushRef = useRef(null);
    const activeBlockIdRef = useRef(null);
    const structureMutationRef = useRef(null);
    const scheduleAutosaveRef = useRef(() => {});
    const requestAnalyzeRef = useRef(() => {});
    const blockOutsideClickGuardUntilRef = useRef(0);
    const linkScrollTokenRef = useRef(0);
    const intraSelectionRef = useRef({ text: '', html: '' });
    const focusedOutlineHeadingRef = useRef(null);
    const globalEditorRef = useRef(null);
    const blockEditorsRef = useRef(new Map());
    const pendingAiMediaRef = useRef(new Map());
    /** Media ID user đã xóa khỏi editor — không tự chèn lại từ poll/event AI. */
    const dismissedEditorImageMediaIdsRef = useRef(new Set());
    const resumedArticleAiJobsRef = useRef(null);
    const mediaPollTimersRef = useRef(new Map());
    const generateImageInFlightRef = useRef(false);
    /** Stable bridge — host-actions effect runs above requestGenerateArticleImage declaration (avoid TDZ). */
    const requestGenerateArticleImageRef = useRef(null);

    useEffect(() => {
        activeBlockIdRef.current = activeBlockId;
    }, [activeBlockId]);

    useEffect(() => {
        globalEditorRef.current = globalEditor;
    }, [globalEditor]);

    const getExportHtml = useCallback(() => exportBlocksToHtml(blocksRef.current), []);

    useEffect(() => {
        window.__seoExportEditorHtml = () => getExportHtml();
        return () => {
            delete window.__seoExportEditorHtml;
        };
    }, [getExportHtml]);

    useEffect(() => {
        if (seoScoringRules.length > 0) {
            window.__SEO_SCORING_RULES__ = seoScoringRules;
        }
    }, [seoScoringRules]);

    useEffect(() => {
        if (Object.keys(scoringMessages).length > 0) {
            window.__SEO_RULE_MESSAGES__ = scoringMessages;
        }
    }, [scoringMessages]);

    const liveSeoScore = useMemo(() => {
        const violations = sanitizeViolations(
            Array.isArray(analysis?.violations) ? analysis.violations : [],
            seoScoringRules,
        );

        return scoreFromViolations(violations, seoScoringRules);
    }, [analysis, seoScoringRules]);

    const seoFailedItems = useMemo(() => {
        const violations = sanitizeViolations(
            Array.isArray(analysis?.violations) ? analysis.violations : [],
            seoScoringRules,
        );

        return buildFailedViolationItems(
            violations,
            seoScoringRules,
            scoringMessages,
            analysis?.metrics ?? {},
        );
    }, [analysis, seoScoringRules, scoringMessages]);

    const seoFailedCount = seoFailedItems.length;

    useEffect(() => {
        // Content widgets only — typing (blocks) must not rebuild featured/gallery health.
        const locale = String(document?.documentElement?.lang ?? 'vi').startsWith('en') ? 'en' : 'vi';
        const contentImages = collectContentImagesFromArticle(blocks);
        const imageRows = unifiedImageRows;
        const keyword = String(focusKeyword ?? '').trim();
        const fromAnalysis = analysis?.metrics?.image_ratio ?? {};
        const useSnap = fromAnalysis.count_source === 'media_snapshot'
            && Number.isFinite(Number(fromAnalysis.valid_image_count));
        // Ratio current = body content occurrences only — never unified inventory total.
        const validCount = useSnap
            ? Math.max(0, Number(fromAnalysis.valid_image_count) || 0)
            : contentImages.valid_content_image_count;
        const wordCount = Math.max(
            0,
            Number(fromAnalysis.current_word_count)
            || Number(analysis?.metrics?.word_count)
            || Number(analysis?.metrics?.eligible_word_count)
            || 0,
        );
        const wordsPerImage = Math.max(
            1,
            Number(fromAnalysis.target_words_per_image)
            || Number(fromAnalysis.words_per_image)
            || 200,
        );
        const recommendedFromWords = wordCount > 0
            ? Math.max(1, Math.ceil(wordCount / wordsPerImage))
            : 0;
        const recommended = Number(fromAnalysis.recommended_image_count) > 0
            ? Number(fromAnalysis.recommended_image_count)
            : recommendedFromWords;
        const imageRatioMetrics = {
            ...fromAnalysis,
            current_word_count: wordCount || Number(fromAnalysis.current_word_count) || 0,
            current_image_count: validCount,
            valid_image_count: validCount,
            recommended_image_count: recommended,
            missing_image_count: Math.max(0, recommended - validCount),
            target_words_per_image: wordsPerImage,
            words_per_image: wordsPerImage,
        };
        const runtime = getDefaultArticleEditorRuntime();
        publishPartialRuntimeWidgetHealth(runtime, {
            seo: {
                focusKeyword: keyword,
                violations: analysis?.violations ?? [],
                failedItems: seoFailedItems,
                locale,
            },
            images: {
                rows: imageRows,
                keyword,
                imageRatioMetrics,
                locale,
                messages: scoringMessages,
            },
            links: {
                extractedLinks: analysis?.extracted_links ?? extractedLinks,
                locale,
            },
        }, {
            reviewsBadge: showReviewsTab && isProductPost ? virtualReviews.length : null,
        });
    }, [
        analysis,
        blocks,
        unifiedImageRows,
        supplementalImages,
        focusKeyword,
        seoFailedItems,
        scoringMessages,
        extractedLinks,
        showReviewsTab,
        isProductPost,
        virtualReviews.length,
        imageTabCount,
        mediaHealthTick,
    ]);

    useEffect(() => {
        // Media snapshot widgets — independent of TipTap typing / blocks.
        const locale = String(document?.documentElement?.lang ?? 'vi').startsWith('en') ? 'en' : 'vi';
        const keyword = String(focusKeyword ?? '').trim();
        const runtime = getDefaultArticleEditorRuntime();
        publishPartialRuntimeWidgetHealth(runtime, {
            featured: {
                articleId,
                featuredImage: featuredHealthSnapshot ?? featuredFromSnapshot(articleId),
                keyword,
                altMandatory: Boolean(getAnalysisPolicy()?.featured?.alt_required),
                locale,
            },
            gallery: {
                required: Boolean(
                    getAnalysisPolicy()?.gallery?.required
                    ?? (supportsProductGallery || isProductPost),
                ),
                items: productGalleryItems,
                keyword,
                locale,
            },
        });
    }, [
        articleId,
        focusKeyword,
        supportsProductGallery,
        isProductPost,
        productGalleryItems,
        featuredHealthSnapshot,
        mediaHealthTick,
    ]);

    // Phase 6C.3 — Featured/Gallery health from media snapshot (no Alpine tick / LS).
    useEffect(() => {
        const syncFromSnapshot = () => {
            setFeaturedHealthSnapshot(featuredFromSnapshot(articleId));
            setProductGalleryItems(parseGalleryItems(galleryFromSnapshot(articleId)));
            setMediaHealthTick((tick) => tick + 1);
        };
        syncFromSnapshot();
        const unsub = subscribeMediaSnapshot(({ articleId: aid }) => {
            if (Number(aid) === Number(articleId)) {
                syncFromSnapshot();
            }
        });
        const onHealthRefresh = () => syncFromSnapshot();
        window.addEventListener('seo-assistant-widget-health-refresh', onHealthRefresh);
        return () => {
            unsub();
            window.removeEventListener('seo-assistant-widget-health-refresh', onHealthRefresh);
        };
    }, [articleId, parseGalleryItems]);

    const applySeoAnalysisResult = useCallback((result, source = 'live') => {
        if (!result || typeof result !== 'object') {
            setAnalyzing(false);
            return;
        }

        const payload = buildSeoAnalysisPayload(result);
        lastSeoAnalysisRef.current = payload;

        const violations = result.violations ?? payload?.violations ?? [];
        const score = Number.isFinite(Number(result.total_score ?? result.score ?? result.seo_score))
            ? Number(result.total_score ?? result.score ?? result.seo_score)
            : scoreFromViolations(
                sanitizeViolations(violations, seoScoringRules),
                seoScoringRules,
            );

        setAnalysis({
            violations,
            score,
            seo_score: score,
            errors: result.errors ?? [],
            good: result.good ?? [],
            warnings: result.warnings ?? [],
            score_version: result.score_version ?? null,
            content_hash: result.content_hash ?? null,
            calculated_at: result.calculated_at ?? null,
        });
        setSeoScoreSource(source);
        setAnalyzing(false);


        if (payload.extracted_links) {
            setSuggestedInternalLinks((prevSuggested) => {
                const filteredSuggested = filterSuggestedInternalLinks(
                    prevSuggested,
                    payload.extracted_links.internal ?? [],
                    payload.extracted_links.external ?? [],
                );
                setSuggestedExternalLinks((prevExternalSuggested) => {
                    const filteredExternalSuggested = filterSuggestedInternalLinks(
                        prevExternalSuggested,
                        payload.extracted_links.internal ?? [],
                        payload.extracted_links.external ?? [],
                    ).filter((item) => {
                        const href = String(item?.href ?? item?.target_url ?? '').trim();

                        return href !== '' && !isSpecialOrContactHref(href);
                    });
                    setExtractedLinks({
                        internal: payload.extracted_links.internal ?? [],
                        external: (payload.extracted_links.external ?? []).filter(
                            (item) => !isSpecialOrContactHref(item?.href),
                        ),
                    });
                    publishExtractedLinks(
                        payload.extracted_links,
                        filteredSuggested,
                        filteredExternalSuggested,
                    );

                    return filteredExternalSuggested;
                });

                return filteredSuggested;
            });
        }
    }, [publishExtractedLinks, seoScoringRules]);

    const resolveArticleFaqsSnapshot = useCallback(() => {
        const fromFaqEditor = window.__seoCollectArticleFaqs?.();
        if (Array.isArray(fromFaqEditor)) {
            return fromFaqEditor;
        }

        return panelFaqsRef.current;
    }, []);

    // Perf Phase 2B: immediate local analysis debounce 250ms (policy-driven).
    // Không gọi server. Không remount TipTap.
    const analyzedBlocksRef = useRef(null);
    const [seoStale, setSeoStale] = useState(false);

    const runLocalSeoAnalysis = useCallback(() => {
        if (!tempMergeRef.current) {
            blockFlushRef.current?.();
        }
        const meta = seoMetaRef.current;
        const policy = getAnalysisPolicy() || editorSettings?.analysis_policy || null;
        const result = composeImmediateArticleAnalysis({
            documentHtml: getExportHtml(),
            document: documentJsonFromEditorsOrBlocks(blockEditorsRef.current, blocksRef.current),
            blocks: blocksRef.current,
            focusKeyword,
            seoTitle: meta.seoTitle || articleTitle,
            metaDescription: meta.metaDescription,
            slug: meta.slug,
            siteDomain,
            faqs: resolveArticleFaqsSnapshot(),
            wikiTrustDomains,
            scoringMessages,
            seoScoringRules,
            articleType: articleType,
            articleLengthSettings: {
                article_length_product: policy?.content?.article_length_product
                    ?? editorSettings?.article_length_product,
                article_length_default: policy?.content?.article_length_default
                    ?? editorSettings?.article_length_default,
            },
            featuredSnippetThresholds: policy?.featured_snippet_thresholds
                ?? editorSettings?.featured_snippet_thresholds
                ?? {},
            policy,
            articleId,
            externalFacts: getExternalFacts() || editorSettings?.external_facts || null,
        });

        applySeoAnalysisResult(result);
    }, [
        applySeoAnalysisResult,
        articleId,
        articleTitle,
        articleType,
        editorSettings?.analysis_policy,
        editorSettings?.article_length_default,
        editorSettings?.article_length_product,
        editorSettings?.external_facts,
        editorSettings?.featured_snippet_thresholds,
        focusKeyword,
        getExportHtml,
        resolveArticleFaqsSnapshot,
        scoringMessages,
        seoScoringRules,
        siteDomain,
        wikiTrustDomains,
    ]);

    const runPhpSeoPreview = useCallback(async () => {
        if (!articleId) {
            return;
        }
        if (seoPreviewAbortRef.current) {
            seoPreviewAbortRef.current.abort();
        }
        const controller = new AbortController();
        seoPreviewAbortRef.current = controller;
        const meta = seoMetaRef.current;
        try {
            setAnalyzing(true);
            setSeoAnalyzeError(null);
            const data = await previewSeoScoreViaApi(articleId, {
                title: meta.seoTitle || articleTitle,
                slug: meta.slug,
                meta_description: meta.metaDescription,
                focus_keyword: focusKeyword,
                content: getExportHtml(),
            }, { signal: controller.signal });
            if (controller.signal.aborted) {
                return;
            }
            applySeoAnalysisResult(data, 'live');
            setSeoStale(false);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            // Keep local JS score; surface soft error.
            setSeoAnalyzeError(error?.message ?? 'seo_preview_failed');
            setAnalyzing(false);
        }
    }, [applySeoAnalysisResult, articleId, articleTitle, focusKeyword, getExportHtml]);

    const requestAnalyze = useCallback(() => {
        try {
            setAnalyzing(true);
            setSeoAnalyzeError(null);
            runLocalSeoAnalysis();
            analyzedBlocksRef.current = blocksRef.current;
            setSeoStale(false);
            void runPhpSeoPreview();
        } catch (error) {
            setAnalyzing(false);
            setSeoAnalyzeError(error?.message ?? 'seo_analyze_failed');
        }
    }, [runLocalSeoAnalysis, runPhpSeoPreview]);

    requestAnalyzeRef.current = requestAnalyze;

    const scheduleIdleSeoAnalysis = useCallback(() => {
        const scheduler = utilitySchedulerRef.current;
        if (!scheduler) {
            return;
        }
        setSeoStale(true);
        setSeoAnalyzeError(null);
        scheduler.schedule({
            id: 'seo-idle-analyze',
            debounceMs: 600,
            priority: 'normal',
            run: ({ version, signal }) => {
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
                if (blocksRef.current === analyzedBlocksRef.current) {
                    setSeoStale(false);
                    return;
                }
                try {
                    setAnalyzing(true);
                    setSeoAnalyzeError(null);
                    runLocalSeoAnalysis();
                    if (signal.aborted || version !== scheduler.getVersion()) {
                        return;
                    }
                    analyzedBlocksRef.current = blocksRef.current;
                    setSeoStale(false);
                    void runPhpSeoPreview();
                } catch (error) {
                    setAnalyzing(false);
                    setSeoAnalyzeError(error?.message ?? 'seo_analyze_failed');
                }
            },
        });
    }, [runLocalSeoAnalysis, runPhpSeoPreview]);

    useEffect(() => {
        const onMediaSnapshotAnalyze = (event) => {
            const detail = event?.detail ?? {};
            const aid = Number(detail.article_id ?? 0);
            if (aid > 0 && aid !== Number(articleId)) {
                return;
            }
            scheduleIdleSeoAnalysis();
        };
        window.addEventListener('article-editor-media-snapshot-changed', onMediaSnapshotAnalyze);
        return () => {
            window.removeEventListener('article-editor-media-snapshot-changed', onMediaSnapshotAnalyze);
        };
    }, [articleId, scheduleIdleSeoAnalysis]);

    const openFaqModule = useCallback((options = {}) => {
        if (options?.autoGenerate) {
            pendingFaqGenerateRef.current = true;
        }
        // Defer past TipTap activate / shortcode mousedown — tránh race mount FAQ lần đầu.
        window.setTimeout(() => {
            openPanel('faq', {
                source: options?.source ?? 'faq-shortcode',
                autoGenerate: Boolean(options?.autoGenerate),
            });
        }, 0);
    }, []);

    const createFaqFromShortcode = useCallback(() => {
        openFaqModule({ autoGenerate: canGenerateFaq });
        if (canGenerateFaq) {
            window.setTimeout(() => {
                window.dispatchEvent(new CustomEvent('generate-article-faqs'));
            }, 400);
        }
    }, [canGenerateFaq, openFaqModule]);

    const openFeaturedSnippetPrompt = useCallback(() => {
        const outline = flattenClientOutlineNodes(clientOutline ?? [])
            .map((node) => `${'#'.repeat(Math.max(1, Number(node.level) || 2))} ${String(node.heading_text ?? '').trim()}`)
            .filter((line) => line.replace(/#/g, '').trim() !== '')
            .join('\n');
        const sectionContent = String(getExportHtml?.() ?? '').slice(0, 8000);
        setFeaturedSnippetPreviewHtml('');
        setFeaturedSnippetPromptContext({
            title: articleTitle,
            focusKeyword,
            outline,
            sectionContent,
            language: window.__SEO_I18N_LOCALE__ ?? 'vi',
            domain: siteDomainRef.current,
        });
        setFeaturedSnippetPromptOpen(true);
    }, [articleTitle, clientOutline, focusKeyword, getExportHtml]);

    const handleSeoViolationAction = useCallback((action) => {
        if (!action?.action) {
            return;
        }
        if (action.action === 'open-faq-generator') {
            openFaqModule({ autoGenerate: canGenerateFaq });
            if (canGenerateFaq) {
                window.setTimeout(() => {
                    window.dispatchEvent(new CustomEvent('generate-article-faqs'));
                }, 500);
            }
            return;
        }
        if (action.action === 'open-featured-snippet-prompt') {
            openFeaturedSnippetPrompt();
        }
    }, [canGenerateFaq, openFaqModule, openFeaturedSnippetPrompt]);

    const autosaveIntervalSecondsRaw = Number(editorSettings?.autosave_interval_seconds);
    const autosaveIntervalSeconds = Number.isFinite(autosaveIntervalSecondsRaw)
        ? Math.max(0, Math.min(30, autosaveIntervalSecondsRaw))
        : 2;
    const draftSaveDisabled = autosaveIntervalSeconds === 0
        || Boolean(sessionReadOnly)
        || Boolean(window.__SEO_EDITOR_READ_ONLY__);
    const draftSaveDelayMs = Math.max(1, autosaveIntervalSeconds || 2) * 1000;
    const serverAutosaveDebounceMs = Math.max(
        1000,
        Number(editorSettings?.server_autosave_debounce_ms)
            || Number(window.__SEO_EDITOR_SERVER_AUTOSAVE_DEBOUNCE_MS__)
            || 4000,
    );
    const serverAutosaveInFlightRef = useRef(false);
    const serverAutosaveDirtyRef = useRef(false);
    const serverAutosaveSeqRef = useRef(0);
    const serverAutosaveNeedsRetryRef = useRef(false);
    const lastAutosaveHashRef = useRef('');
    /** Canonical body plain text from bootstrap — used to block hydrate-origin space corruption saves. */
    const bootstrapBodyPlainRef = useRef(plainTextFromHtmlLoose(initialHtml));
    const whitespaceCorruptionLockedRef = useRef(false);

    const assertWritableDocumentNotWhitespaceCorrupted = useCallback((html) => {
        const base = String(bootstrapBodyPlainRef.current ?? '').trim();
        if (base === '') {
            return true;
        }
        const candidate = plainTextFromHtmlLoose(html);
        if (!hasInlineWhitespaceCorruption(base, candidate)) {
            whitespaceCorruptionLockedRef.current = false;
            return true;
        }
        if (!whitespaceCorruptionLockedRef.current) {
            whitespaceCorruptionLockedRef.current = true;
            window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('editor_inline_whitespace_corruption_title'),
                    body: t('editor_inline_whitespace_corruption_body'),
                    status: 'danger',
                    code: INLINE_WHITESPACE_CORRUPTION_CODE,
                },
            }));
        }
        return false;
    }, []);

    const scheduleServerAutosave = useCallback(() => {
        if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__) {
            return;
        }
        if (!assertWritableDocumentNotWhitespaceCorrupted(getExportHtml())) {
            return;
        }
        const client = window.__seoEditorSessionClient;
        if (!client || client.readOnly || !client.sessionId) {
            return;
        }
        serverAutosaveDirtyRef.current = true;
        if (serverAutosaveInFlightRef.current) {
            return;
        }

        cancelPendingServerAutosave();
        window.__seoServerAutosaveTimer = window.setTimeout(async () => {
            if (!serverAutosaveDirtyRef.current) {
                return;
            }
            if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ || window.__SEO_EDITOR_EXITING__) {
                return;
            }
            if (shouldSuppressServerAutosave()) {
                // Explicit Save owns write queue — re-check after suppress window.
                serverAutosaveDirtyRef.current = true;
                window.__seoServerAutosaveTimer = window.setTimeout(() => {
                    if (serverAutosaveDirtyRef.current) {
                        scheduleServerAutosave();
                    }
                }, 1000);
                return;
            }
            const activeClient = window.__seoEditorSessionClient;
            if (!activeClient || activeClient.readOnly || !activeClient.sessionId) {
                return;
            }

            const htmlAtSend = getExportHtml();
            const currentBodyHash = hashContent(htmlAtSend);
            const tokens = getEditorConflictTokens();
            const ackBodyHash = String(tokens.expected_content_hash || '').trim();
            const ackDocHash = String(window.__SEO_EDITOR_DOCUMENT_HASH__ || '').trim();
            // Client unchanged-skip: same ACK body hash, no failed write pending retry.
            // Do not clear editor dirty UI — only skip network PUT.
            if (
                !serverAutosaveNeedsRetryRef.current
                && currentBodyHash !== ''
                && ackBodyHash !== ''
                && currentBodyHash === ackBodyHash
                && lastAutosaveHashRef.current === currentBodyHash
            ) {
                serverAutosaveDirtyRef.current = false;
                return;
            }
            // First ACK after load: lastAutosaveHash empty — still skip if body matches ACK
            // and we already have document hash from bootstrap (idle open).
            if (
                !serverAutosaveNeedsRetryRef.current
                && currentBodyHash !== ''
                && ackBodyHash !== ''
                && currentBodyHash === ackBodyHash
                && ackDocHash !== ''
                && lastAutosaveHashRef.current === ''
            ) {
                lastAutosaveHashRef.current = currentBodyHash;
                serverAutosaveDirtyRef.current = false;
                return;
            }

            serverAutosaveDirtyRef.current = false;
            serverAutosaveInFlightRef.current = true;
            const seq = ++serverAutosaveSeqRef.current;
            try {
                const result = await saveArticleViaApiSingleFlight(articleId, async () => {
                    const editorDocument = buildEditorDocumentEnvelope(blocksRef.current, blockEditorsRef.current);
                    const payload = buildArticleEditorApiPayload({
                        articleId,
                        html: getExportHtml() || htmlAtSend,
                        editor_document: editorDocument,
                        expected_editor_document_hash: window.__SEO_EDITOR_DOCUMENT_HASH__ || null,
                        client_document_hash: currentBodyHash,
                        seoAnalysis: null,
                    }, null);
                    payload.save_mode = 'autosave';
                    payload.client_document_hash = currentBodyHash;
                    return payload;
                }, { priority: 'autosave' });
                if (result?.suppressed_autosave) {
                    serverAutosaveDirtyRef.current = true;
                    return;
                }
                // Stale ACK must not overwrite newer local edits.
                if (seq !== serverAutosaveSeqRef.current) {
                    return;
                }
                logArticleEditorVersionDebug('autosave_ack', {
                    noop: Boolean(result?.noop),
                    reconciled: Boolean(result?.reconciled),
                    document_version: result?.document_version ?? null,
                    content_hash: String(result?.content_hash || '').slice(0, 12) || null,
                });
                applyEditorDocumentAck(result);
                if (result?.content_hash) {
                    lastAutosaveHashRef.current = String(result.content_hash);
                } else if (currentBodyHash) {
                    lastAutosaveHashRef.current = currentBodyHash;
                }
                serverAutosaveNeedsRetryRef.current = false;
            } catch {
                serverAutosaveDirtyRef.current = true;
                serverAutosaveNeedsRetryRef.current = true;
            } finally {
                serverAutosaveInFlightRef.current = false;
                if (serverAutosaveDirtyRef.current) {
                    scheduleServerAutosave();
                }
            }
        }, serverAutosaveDebounceMs);
    }, [articleId, assertWritableDocumentNotWhitespaceCorrupted, getExportHtml, serverAutosaveDebounceMs, sessionReadOnly]);

    const { debounced: debouncedLocalSave, cancel: cancelLocalDraftSave } = useDebouncedCallback(() => {
        if (!articleId || draftSaveDisabled) return;
        if (!isDraftPersistenceEnabled() || window.__SEO_EDITOR_EXITING__) return;
        if (isArticleAutosaveLocked()) return;
        const html = getExportHtml();
        if (!assertWritableDocumentNotWhitespaceCorrupted(html)) return;
        setSaveStatus('saving');
        const tokens = getEditorConflictTokens();
        saveDraft(articleId, connectionHashRef.current, withDraftSite({
            content: html,
            editor_document: buildEditorDocumentEnvelope(blocksRef.current, blockEditorsRef.current),
            editor_document_schema_version: 1,
            base_editor_document_hash: window.__SEO_EDITOR_DOCUMENT_HASH__ || null,
            base_updated_at: tokens.expected_updated_at || null,
            base_content_hash: tokens.expected_content_hash || null,
            base_document_version: window.__SEO_EDITOR_DOCUMENT_VERSION__ || documentVersion || null,
            user_id: window.__SEO_EDITOR_CURRENT_USER_ID__ || null,
        }));
        setSaveStatus('saved');
        scheduleServerAutosave();
    }, draftSaveDelayMs);

    // scheduleAutosave chỉ lo lưu nháp local — KHÔNG còn gọi SEO analyze (đó là nguồn lag khi gõ).
    // Analyze giờ chỉ chạy khi requestAnalyze() được gọi rõ ràng (nút Phân tích / sau hành động cụ thể).
    const scheduleAutosave = useCallback(() => {
        if (draftSaveDisabled || window.__SEO_EDITOR_EXITING__) {
            return;
        }
        if (!isDraftPersistenceEnabled() || isArticleAutosaveLocked()) {
            return;
        }
        if (!assertWritableDocumentNotWhitespaceCorrupted(getExportHtml())) {
            return;
        }
        setSaveStatus('pending');
        debouncedLocalSave();
    }, [assertWritableDocumentNotWhitespaceCorrupted, debouncedLocalSave, draftSaveDisabled, getExportHtml]);

    scheduleAutosaveRef.current = scheduleAutosave;

    useEffect(() => {
        window.__SEO_EDITOR_EXITING__ = false;
        setDraftPersistenceEnabled(true);
        window.__seoCancelArticleDraftAutosave = cancelLocalDraftSave;
        window.__seoDisableArticleDraftPersistence = () => {
            setDraftPersistenceEnabled(false);
            cancelLocalDraftSave();
        };

        return () => {
            if (window.__seoCancelArticleDraftAutosave === cancelLocalDraftSave) {
                delete window.__seoCancelArticleDraftAutosave;
            }
            delete window.__seoDisableArticleDraftPersistence;
        };
    }, [cancelLocalDraftSave]);

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
                    'input, textarea, [contenteditable="true"], [contenteditable=""], .ProseMirror, .tiptap-editor-content, .block-editor-active',
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
            if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ || !canMutateEditor()) {
                // Copy (C) stays; mutation shortcuts blocked in hard read-only.
                if (key === 'c') {
                    return;
                }
                if (['z', 'y', 'b', 'i', 'u', 'k'].includes(key)) {
                    event.preventDefault();
                }
                return;
            }

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
    }, [undo, redo, canUndo, canRedo, requestAnalyze, sessionReadOnly]);

    // Hydrate: tự chọn bản gần nhất; nếu local≠server thì hiện nút ! trên sticky header để mở modal chọn lại.
    const [draftRestoreOffer, setDraftRestoreOffer] = useState(null);
    const [draftChoiceModalOpen, setDraftChoiceModalOpen] = useState(false);

    useEffect(() => {
        if (!articleId) return;
        if (loadedArticleIdRef.current === articleId) return;

        loadedArticleIdRef.current = articleId;
        dismissedEditorImageMediaIdsRef.current = new Set();
        skipNextAutosave.current = true;
        whitespaceCorruptionLockedRef.current = false;
        clearTempMerge();

        const connHash = connectionHashRef.current;
        const scope = draftScope();
        const draft = loadDraft(articleId, connHash, scope);

        // Absolute recovery: DB may already have glued mark boundaries (saved after old bug).
        const serverHtmlRepair = repairGluedInlineMarkBoundaryWhitespaceWithReport(initialHtml);
        let effectiveInitialHtml = serverHtmlRepair.html;
        if (serverHtmlRepair.repaired) {
            window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('editor_inline_whitespace_repaired_title'),
                    body: t('editor_inline_whitespace_repaired_body'),
                    status: 'warning',
                },
            }));
        }
        bootstrapBodyPlainRef.current = plainTextFromHtmlLoose(effectiveInitialHtml);

        const serverBodyHash = hashContent(effectiveInitialHtml);
        const serverContentHash = String(expectedContentHash ?? '').trim() || serverBodyHash;
        // Prefer HTML when server HTML was repaired — corrupted JSON must not win.
        const serverBlocksFromJson = serverHtmlRepair.repaired
            ? null
            : blocksFromEditorDocumentEnvelope(initialEditorDocument, effectiveInitialHtml);
        const serverBlocks = enrichBlocksWithPostImages(
            serverBlocksFromJson
                ?? parseHtmlToBlocks(stripLeadingH1FromHtml(effectiveInitialHtml)),
            postImagesRef.current,
        );
        if (initialEditorDocumentHash && !serverHtmlRepair.repaired) {
            window.__SEO_EDITOR_DOCUMENT_HASH__ = String(initialEditorDocumentHash);
        }

        analyzedBlocksRef.current = null;

        const serverState = {
            content_hash: serverBodyHash || serverContentHash,
            expected_content_hash: serverContentHash,
            site_id: scope.siteId,
            updated_at: expectedUpdatedAt || null,
            content: effectiveInitialHtml,
            version: serverContentHash,
        };
        const decision = resolveLocalDraftDecision(draft, serverState);
        const canManualChoose = draftOffersManualChoice(draft, serverState);
        const hardReadonly = Boolean(sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__);

        // Locked session: keep local draft, never auto-apply / clear / overwrite canonical.
        if (hardReadonly) {
            setBlocks(serverBlocks);
            const analyzedContentHash = String(initialSeo?.analyzed_content_hash ?? '').trim();
            setSeoStale(
                analyzedContentHash !== ''
                && serverBodyHash !== ''
                && analyzedContentHash !== serverBodyHash,
            );
            setDraftRestoreOffer(null);
            setDraftChoiceModalOpen(false);
            setActiveBlockId(null);
            setGlobalEditor(null);
            return;
        }

        const draftContentRaw = String(draft?.content ?? '');
        const draftGlue = draft ? countGluedInlineMarkBoundaries(draftContentRaw) : 0;
        const serverGlue = countGluedInlineMarkBoundaries(effectiveInitialHtml);
        const preferServerOverGluedDraft = Boolean(draft)
            && decision === 'restore_local'
            && draftGlue > serverGlue;

        if (decision === 'restore_local' && draft && !preferServerOverGluedDraft) {
            const draftRepair = repairGluedInlineMarkBoundaryWhitespaceWithReport(draftContentRaw);
            if (draftRepair.repaired) {
                window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_inline_whitespace_repaired_title'),
                        body: t('editor_inline_whitespace_repaired_body'),
                        status: 'warning',
                    },
                }));
            }
            bootstrapBodyPlainRef.current = plainTextFromHtmlLoose(draftRepair.html);
            const restoredBlocks = enrichBlocksWithPostImages(
                parseHtmlToBlocks(stripLeadingH1FromHtml(draftRepair.html)),
                postImagesRef.current,
            );
            setBlocks(restoredBlocks);
            setSeoStale(true);
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_draft_auto_restored_title'),
                        body: t('editor_draft_auto_restored_body'),
                        status: 'info',
                    },
                }),
            );
        } else {
            setBlocks(serverBlocks);
            cancelLocalDraftSave();
            window.__seoCancelArticleDraftAutosave?.();
            // Keep a glued draft only when it is healthier than server; otherwise clear so F5 recovers.
            if (!draft || draftGlue >= serverGlue) {
                clearDraft(articleId, connHash, scope);
            }
            writeSyncedLocalSnapshot(articleId, connHash, withDraftSite({
                content: exportBlocksToHtml(serverBlocks),
                base_updated_at: expectedUpdatedAt || null,
                base_content_hash: serverContentHash,
                version: serverContentHash,
            }));

            const analyzedContentHash = String(initialSeo?.analyzed_content_hash ?? '').trim();
            setSeoStale(
                analyzedContentHash !== ''
                && serverBodyHash !== ''
                && analyzedContentHash !== serverBodyHash,
            );
        }

        if (canManualChoose && draft && !preferServerOverGluedDraft) {
            setDraftRestoreOffer({ draft, serverBlocks });
            setDraftChoiceModalOpen(false);
        } else {
            setDraftRestoreOffer(null);
            setDraftChoiceModalOpen(false);
        }

        setActiveBlockId(null);
        setGlobalEditor(null);
    }, [articleId, initialHtml, initialPostImages, expectedUpdatedAt, expectedContentHash, clearTempMerge, draftScope, withDraftSite, cancelLocalDraftSave, initialSeo, sessionReadOnly, initialEditorDocument, initialEditorDocumentHash]);

    // After lock → writable (retry/takeover): offer kept local draft, never auto-apply.
    useEffect(() => {
        if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ || !articleId) {
            return;
        }
        if (draftRestoreOffer) {
            return;
        }
        const connHash = connectionHashRef.current;
        const scope = draftScope();
        const draft = loadDraft(articleId, connHash, scope);
        if (!draft) {
            return;
        }
        const serverBodyHash = hashContent(initialHtml);
        const serverContentHash = String(expectedContentHash ?? '').trim() || serverBodyHash;
        const serverBlocksFromJson = blocksFromEditorDocumentEnvelope(initialEditorDocument, initialHtml);
        const serverBlocks = enrichBlocksWithPostImages(
            serverBlocksFromJson
                ?? parseHtmlToBlocks(stripLeadingH1FromHtml(initialHtml)),
            postImagesRef.current,
        );
        const serverState = {
            content_hash: serverBodyHash || serverContentHash,
            expected_content_hash: serverContentHash,
            site_id: scope.siteId,
            updated_at: expectedUpdatedAt || null,
            content: initialHtml,
            version: serverContentHash,
        };
        if (!draftOffersManualChoice(draft, serverState)) {
            return;
        }
        setDraftRestoreOffer({ draft, serverBlocks });
        setDraftChoiceModalOpen(false);
    }, [
        sessionReadOnly,
        articleId,
        draftRestoreOffer,
        draftScope,
        initialHtml,
        initialEditorDocument,
        expectedContentHash,
        expectedUpdatedAt,
    ]);

    useEffect(() => {
        window.dispatchEvent(
            new CustomEvent(ARTICLE_EDITOR_DRAFT_ALERT_EVENT, {
                detail: {
                    visible: Boolean(draftRestoreOffer),
                    title: t('editor_draft_choice_button_hint'),
                },
            }),
        );

        return () => {
            window.dispatchEvent(
                new CustomEvent(ARTICLE_EDITOR_DRAFT_ALERT_EVENT, {
                    detail: { visible: false },
                }),
            );
        };
    }, [draftRestoreOffer]);

    useEffect(() => {
        const onOpenDraftChoice = () => {
            if (!draftRestoreOffer) {
                return;
            }
            setDraftChoiceModalOpen(true);
        };

        window.addEventListener(ARTICLE_EDITOR_OPEN_DRAFT_CHOICE_EVENT, onOpenDraftChoice);

        return () => {
            window.removeEventListener(ARTICLE_EDITOR_OPEN_DRAFT_CHOICE_EVENT, onOpenDraftChoice);
        };
    }, [draftRestoreOffer]);

    const applyDraftRestore = useCallback(() => {
        if (!draftRestoreOffer) return;
        if (!canMutateEditor()) {
            assertWritableEditorSession('editor_read_only');
            return;
        }
        const restoredBlocks = enrichBlocksWithPostImages(
            parseHtmlToBlocks(stripLeadingH1FromHtml(String(draftRestoreOffer.draft?.content ?? ''))),
            postImagesRef.current,
        );
        setBlocks(restoredBlocks);
        setDraftChoiceModalOpen(false);
        setDraftRestoreOffer(null);
        setSeoStale(true);
    }, [draftRestoreOffer]);

    const discardDraftRestore = useCallback(() => {
        clearDraft(articleId, connectionHashRef.current, draftScope());
        if (draftRestoreOffer?.serverBlocks) {
            setBlocks(draftRestoreOffer.serverBlocks);
            writeSyncedLocalSnapshot(articleId, connectionHashRef.current, withDraftSite({
                content: exportBlocksToHtml(draftRestoreOffer.serverBlocks),
                base_updated_at: expectedUpdatedAt || null,
                base_content_hash: String(expectedContentHash ?? '').trim(),
                version: String(expectedContentHash ?? '').trim(),
            }));
        }
        setDraftChoiceModalOpen(false);
        setDraftRestoreOffer(null);
    }, [articleId, draftRestoreOffer, draftScope, expectedUpdatedAt, expectedContentHash, withDraftSite]);

    const keepServerOverDraft = useCallback(() => {
        clearDraft(articleId, connectionHashRef.current, draftScope());
        if (draftRestoreOffer?.serverBlocks) {
            setBlocks(draftRestoreOffer.serverBlocks);
            writeSyncedLocalSnapshot(articleId, connectionHashRef.current, withDraftSite({
                content: exportBlocksToHtml(draftRestoreOffer.serverBlocks),
                base_updated_at: expectedUpdatedAt || null,
                base_content_hash: String(expectedContentHash ?? '').trim(),
                version: String(expectedContentHash ?? '').trim(),
            }));
        }
        setDraftChoiceModalOpen(false);
        setDraftRestoreOffer(null);
    }, [articleId, draftRestoreOffer, draftScope, expectedUpdatedAt, expectedContentHash, withDraftSite]);

    useEffect(() => {
        if (!initialSeo || hasHydratedSeoFromServerRef.current) {
            return;
        }

        hasHydratedSeoFromServerRef.current = true;
        setFocusKeyword(initialSeo.focus_keyword ?? null);
        setAnalysis(initialSeo.analysis ?? null);
        setExtractedLinks({
            internal: initialSeo.extracted_links?.internal ?? [],
            external: (initialSeo.extracted_links?.external ?? []).filter(
                (item) => !isSpecialOrContactHref(item?.href),
            ),
        });
        setSuggestedInternalLinks(
            filterSuggestedInternalLinks(
                initialSeo.suggested_internal_links ?? [],
                initialSeo.extracted_links?.internal ?? [],
                initialSeo.extracted_links?.external ?? [],
            ),
        );
        setSuggestedExternalLinks(
            filterSuggestedInternalLinks(
                initialSeo.suggested_external_links ?? [],
                initialSeo.extracted_links?.internal ?? [],
                initialSeo.extracted_links?.external ?? [],
            ).filter((item) => {
                const href = String(item?.href ?? item?.target_url ?? '').trim();

                return href !== '' && !isSpecialOrContactHref(href);
            }),
        );
        if (String(initialSeo.site_domain ?? '').trim() !== '') {
            siteDomainRef.current = String(initialSeo.site_domain).trim();
        }
        if (Array.isArray(initialSeo.suggested_external_links_catalog)) {
            suggestionExternalCatalogRef.current = mergeSuggestionCatalog(
                initialSeo.suggested_external_links_catalog,
                initialSeo.suggested_external_links ?? [],
            );
        }
    }, [initialSeo]);

    const reconcileImagesTabWithBlocks = useCallback((nextBlocks) => {
        setSupplementalImages((prev) => reconcileSupplementalImagesWithBlocks(prev, nextBlocks));
        setImagesReloadKey((key) => key + 1);
    }, []);

    const updateBlockContent = useCallback((id, newContent, imageData) => {
        if (!canMutateEditor()) {
            return;
        }
        setBlocks((prev) => {
            const nextBlocks = prev.map((b) =>
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
            );

            if (imageData !== undefined) {
                queueMicrotask(() => reconcileImagesTabWithBlocks(nextBlocks));
            }

            return nextBlocks;
        });
    }, [reconcileImagesTabWithBlocks]);

    const registerBlockFlush = useCallback((fn) => {
        blockFlushRef.current = fn;
    }, []);

    const registerBlockEditor = useCallback((blockId, editor) => {
        if (!blockId) {
            return;
        }

        if (editor) {
            try {
                editor.setEditable(!sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__);
            } catch {
                // ignore
            }
            blockEditorsRef.current.set(blockId, editor);
            return;
        }

        blockEditorsRef.current.delete(blockId);
    }, [sessionReadOnly]);

    useEffect(() => {
        const writable = !sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__;
        blockEditorsRef.current.forEach((editor) => {
            try {
                if (editor && typeof editor.setEditable === 'function' && editor.isEditable !== writable) {
                    editor.setEditable(writable);
                }
            } catch {
                // ignore destroyed editors
            }
        });
    }, [sessionReadOnly]);

    const resolveActiveEditor = useCallback(() => {
        return resolveEditorForInsertion({
            blockEditors: blockEditorsRef.current,
            activeBlockId: activeBlockIdRef.current,
            globalEditor: globalEditorRef.current,
        });
    }, []);

    const commitActiveBlock = useCallback(() => {
        if (tempMergeRef.current) return;
        blockFlushRef.current?.();
    }, []);

    // Phase 4/6C.2 — bind command host + module actions (no window event bus for Links/CTA/FAQ insert).
    useEffect(() => {
        bindEditorCommandHost({
            articleId,
            getEditorRegistry: () => blockEditorsRef.current,
            getActiveEditorId: () => activeBlockIdRef.current,
            getGlobalEditor: () => globalEditorRef.current,
            getDocumentModel: () => null,
            getMediaSnapshot: () => null,
            getAnalysisPolicy: () => getAnalysisPolicy() || editorSettings?.analysis_policy || null,
            getDocumentVersion: () => window.__SEO_EDITOR_DOCUMENT_VERSION__ || documentVersion || null,
            getLocalRevision: () => Number(window.__SEO_EDITOR_LOCAL_REVISION__ || 0),
            isArchived: () => Boolean(window.__SEO_EDITOR_ARCHIVED__),
            hasConflict: () => Boolean(window.__SEO_EDITOR_DOCUMENT_CONFLICT__),
            dispatchDocumentChanged: (payload) => {
                window.__SEO_EDITOR_LOCAL_REVISION__ = Number(payload?.local_revision) || 0;
            },
            notify: (detail) => {
                window.dispatchEvent(new CustomEvent('seo-article-editor-notify', { detail }));
            },
            scheduleAutosave: () => scheduleAutosaveRef.current?.(),
            requestAnalyze: () => requestAnalyzeRef.current?.(),
            commitActiveBlock: () => commitActiveBlock(),
            onStructureMutation: (name, payload) => structureMutationRef.current?.(name, payload) ?? false,
            actions: {
                insertSuggestedLink: (detail) => editorHostActionsRef.current.insertSuggestedLink?.(detail),
                insertCtaLink: (detail) => editorHostActionsRef.current.insertCtaLink?.(detail),
                removeInternalLink: (detail) => editorHostActionsRef.current.removeInternalLink?.(detail),
                scrollToLink: (detail) => editorHostActionsRef.current.scrollToLink?.(detail),
                applyExtractedFaqs: (detail) => editorHostActionsRef.current.applyExtractedFaqs?.(detail),
                applyEditorBlockImage: (detail) => editorHostActionsRef.current.applyEditorBlockImage?.(detail),
                generateArticleImage: (detail) => editorHostActionsRef.current.generateArticleImage?.(detail),
                generateArticleVideo: (detail) => editorHostActionsRef.current.generateArticleVideo?.(detail),
                getActiveBlockId: () => activeBlockIdRef.current,
                getExportHtml: () => editorHostActionsRef.current.getExportHtml?.() ?? '',
                getSelectionHtml: () => editorHostActionsRef.current.getSelectionHtml?.() ?? '',
            },
        });

        return () => {
            unbindEditorCommandHost();
        };
    }, [articleId, commitActiveBlock, documentVersion, editorSettings?.analysis_policy]);

    // Phase 6A — sync internal runtime context (host bridge; modules must not read globals).
    useEffect(() => {
        const runtime = getDefaultArticleEditorRuntime({
            article: {
                id: articleId,
                type: initialPostType || null,
                documentVersion,
                editorDocumentHash: initialEditorDocumentHash,
            },
            workflow: {
                archived: Boolean(window.__SEO_EDITOR_ARCHIVED__),
                belongsToContentProject: Boolean(window.__SEO_EDITOR_CONTENT_PROJECT_ID__),
                manualWpSyncAllowed: !Boolean(window.__SEO_EDITOR_CONTENT_PROJECT_ID__),
            },
            session: (() => {
                const sessionState = getArticleEditorSessionState();
                const writable = !sessionReadOnly
                    && !window.__SEO_EDITOR_READ_ONLY__
                    && Boolean(sessionState?.writable);
                const status = writable
                    ? 'active'
                    : String(sessionState?.status || (sessionReadOnly ? 'locked' : 'read_only'));
                return {
                    id: sessionState?.session_id ?? null,
                    writable,
                    read_only: !writable,
                    status,
                    conflict: Boolean(window.__SEO_EDITOR_DOCUMENT_CONFLICT__)
                        || status === 'conflict',
                };
            })(),
            policy: {
                analysis: editorSettings?.analysis_policy || null,
            },
            document: {
                editorRegistry: blockEditorsRef.current,
                commandExecutor: null,
            },
            snapshots: {
                media: null,
                faq: null,
                analysis: null,
            },
        });
        setRuntimeContextRevision(runtime.getCreateGeneration());
        if (perfDebug && typeof window !== 'undefined') {
            window.__SEO_EDITOR_RUNTIME_DIAGNOSTICS__ = runtime.getDiagnostics();
        }
    }, [
        articleId,
        documentVersion,
        sessionReadOnly,
        initialPostType,
        initialEditorDocumentHash,
        editorSettings?.analysis_policy,
        perfDebug,
    ]);

    const armBlockOutsideClickGuard = useCallback((ms = 220) => {
        blockOutsideClickGuardUntilRef.current = Date.now() + ms;
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
                saveDraft(articleId, connectionHashRef.current, {
                    content: exportBlocksToHtml(nextBlocks),
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


    const patchImageInBlocks = useCallback(
        (blockId, patch, withoutHistory = false) => {
            const updater = (prev) => applyImagePatchToBlocks(prev, blockId, patch);
            if (withoutHistory) {
                updateBlocksWithoutHistory(updater);
            } else {
                setBlocks(updater);
            }

            if (patch && Object.prototype.hasOwnProperty.call(patch, 'excludeQuickFix')) {
                setImagesReloadKey((key) => key + 1);
                scheduleAutosave();
            }
        },
        [scheduleAutosave, updateBlocksWithoutHistory],
    );

    const requestWordPressRenames = useCallback((items, options = {}) => {
        if (!items?.length) {
            return;
        }

        const silent = options.silent === true;
        pendingWpRenameRequestRef.current = Array.isArray(items) ? [...items] : [];

        window.dispatchEvent(
            new CustomEvent('seo-rename-attachment-slugs-loading', {
                detail: { count: items.length },
            }),
        );

        callEditArticleLivewire('renameAttachmentSlugsOnWordPress', items, silent).catch((error) => {
            pendingWpRenameRequestRef.current = [];
            window.dispatchEvent(
                new CustomEvent('seo-attachment-slugs-rename-finished', {
                    detail: {
                        success: false,
                        renamed: [],
                        message: error?.message ?? t('editor_try_again_later'),
                    },
                }),
            );
        });
    }, []);

    const renameLocalMediaByUrl = useCallback(
        (mediaUrl, newSlug, options = {}) =>
            renameSeoMediaByUrl(mediaUrl, newSlug, {
                siteId,
                articleId,
                seoMediaId: options?.seoMediaId ?? null,
            }),
        [siteId, articleId],
    );

    const requestWordPressAttachmentMetaUpdate = useCallback((items, options = {}) => {
        dispatchWordPressAttachmentMetaUpdate(items, { silent: options.silent === true });
    }, []);

    const notifyEditor = useCallback((title, body, status = 'success') => {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: { title, body, status },
            }),
        );
    }, []);

    const notifyLocalSlugRenameErrors = useCallback((errors, attemptedCount) => {
        const detail = buildLocalSlugRenameErrorNotify(errors, attemptedCount);
        if (!detail) {
            return;
        }
        const body = detail.body
            || (detail.bodyKey ? t(detail.bodyKey, detail.bodyParams) : t('editor_try_again_later'));
        notifyEditor(t(detail.titleKey), body, detail.status);
    }, [notifyEditor]);

    const pushAltTitleMetaToStores = useCallback(
        (row, altTitle) => {
            const trimmed = String(altTitle ?? '').trim();
            if (!trimmed || !row) {
                return;
            }

            const { seoMediaId, wpAttachmentId } = buildAltTitleMetaUpdatePayload(row, trimmed);

            if (seoMediaId > 0) {
                updateSeoMediaMeta([
                    {
                        id: seoMediaId,
                        alt_text: trimmed,
                        title: trimmed,
                    },
                ]).catch((error) => {
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

            if (wpAttachmentId > 0) {
                requestWordPressAttachmentMetaUpdate([
                    {
                        attachment_id: wpAttachmentId,
                        alt_text: trimmed,
                        title: trimmed,
                    },
                ]);
            }
        },
        [requestWordPressAttachmentMetaUpdate],
    );

    const handleImageSlugChange = useCallback(
        (row, newSlug, applyPatch) => {
            const trimmed = newSlug.trim();
            if (!trimmed || trimmed === (row.slug || '').trim()) {
                return true;
            }

            const { wpAttachmentId, seoMediaId, isLocal, src: localSrc } = resolveImageRefIds(row);
            const renameSrc = String(localSrc || row.src || '').trim();

            if (shouldRenameSlugOnWordPress(row)) {
                if (!confirmSlugRename({ count: 1 })) {
                    return false;
                }

                pendingQuickFixKeywordRef.current = '';
                requestWordPressRenames([
                    {
                        attachment_id: wpAttachmentId,
                        new_slug: trimmed,
                        old_url: resolveWpRenameOldUrl(row),
                        old_slug: (row.slug || '').trim(),
                        block_id: String(row?.blockId ?? row?.block_id ?? '').trim(),
                    },
                ]);

                return true;
            }

            // Ưu tiên rename-by-url khi local /storage — tránh ID WP stale gọi /media/{id}/rename.
            if ((isLocal || renameSrc.includes('/storage/uploads/seo_media/')) && renameSrc) {
                const oldSlug = (row.slug || '').trim();
                renameLocalMediaByUrl(renameSrc, trimmed, { seoMediaId: seoMediaId > 0 ? seoMediaId : null })
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

            if (seoMediaId > 0) {
                renameSeoMedia(seoMediaId, trimmed, { articleId })
                    .then((data) => {
                        applyPatch({
                            slug: data.slug,
                            src: data.url,
                            seoMediaId: data.id ?? seoMediaId,
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

    const handleImageAltTitleChange = useCallback(
        (row, altTitle) => {
            const trimmed = String(altTitle ?? '').trim();
            if (!row || trimmed === String(row?.alt || '').trim()) {
                return;
            }

            const patch = { alt: trimmed, title: trimmed };
            const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();

            if (blockId) {
                patchImageInBlocks(blockId, patch);
            } else {
                patchSupplementalImageRow(row, patch);
            }

            pushAltTitleMetaToStores(row, trimmed);
            setImagesReloadKey((k) => k + 1);
        },
        [patchImageInBlocks, patchSupplementalImageRow, pushAltTitleMetaToStores],
    );

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
                try {
                    const results = await executeSeoMediaSlugRenamesTwoPhase(uniqueLocalRenames, {
                        renameById: (id, slug) => renameSeoMedia(id, slug, { articleId }),
                        renameByUrl: (src, slug, opts) => renameLocalMediaByUrl(src, slug, opts),
                    });

                    pendingLocalRenameResultsRef.current = [
                        ...pendingLocalRenameResultsRef.current,
                        ...results,
                    ];

                    const skipped = results.errors?.length ?? 0;
                    if (skipped > 0) {
                        pendingLocalRenameQueueRef.current = omitFailedLocalSlugRenameQueueItems(
                            pendingLocalRenameQueueRef.current,
                            results.errors,
                        );
                        notifyLocalSlugRenameErrors(results.errors, uniqueLocalRenames.length);
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

                setImagesReloadKey((k) => k + 1);
            })();
        },
        [notifyLocalSlugRenameErrors, renameLocalMediaByUrl],
    );

    const buildQuickFixContext = useCallback(
        (imageRows = null) => {
            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword) {
                return null;
            }

            const inventoryRows = Array.isArray(imageRows) && imageRows.length > 0
                ? imageRows
                : unifiedImageRows;
            const baseRows = (Array.isArray(inventoryRows) ? inventoryRows : []).filter(
                (row) => !row?.excludeQuickFix,
            );
            const sourceRows = [];
            const seenRows = new Set();
            const appendSourceRow = (row) => {
                if (!row || row?.excludeQuickFix) {
                    return;
                }

                const key =
                    String(row?.identity_key ?? '').trim()
                    || normalizeImageSrcKey(row?.src)
                    || String(row?.blockId ?? row?.block_id ?? '').trim()
                    || `row:${sourceRows.length}`;
                if (!key || seenRows.has(key)) {
                    return;
                }

                seenRows.add(key);
                sourceRows.push(row);
            };

            baseRows.forEach(appendSourceRow);

            // Ensure local Featured/Gallery slug-fix candidates are present even if panel filter omitted them.
            unifiedInventorySlugFixCandidates(unifiedImagesInventory).forEach(appendSourceRow);

            const indexedRows = assignInArticleQuickFixIndices(
                filterSupplementalDuplicatesOfBlockRows(sourceRows),
            );
            const indexByBlockId = buildQuickFixIndexByBlockId(indexedRows);

            const supplementalOnlyRows = indexedRows.filter(
                (row) => String(row?.blockId ?? row?.block_id ?? '').trim() === '',
            );

            return { keyword, sourceRows: indexedRows, indexByBlockId, supplementalOnlyRows };
        },
        [focusKeyword, articleTitle, unifiedImageRows, unifiedImagesInventory],
    );

    const applyQuickFixSlugPreview = useCallback(
        (preview, keyword, options = {}) => {
            const renameCount = preview.renameQueue.length;
            const localRenameCount = (preview.localRenameQueue ?? []).length;
            const silent = options.silent === true;

            pendingQuickFixKeywordRef.current = keyword;
            pendingLocalRenameResultsRef.current = [];
            pendingLocalRenameQueueRef.current = Array.isArray(preview.localRenameQueue)
                ? [...preview.localRenameQueue]
                : [];

            const tasks = [];

            if (renameCount > 0) {
                requestWordPressRenames(preview.renameQueue, { silent });
            } else if (localRenameCount === 0) {
                setImagesReloadKey((k) => k + 1);
            }

            if (localRenameCount > 0) {
                const blockRenames = (preview.localRenameQueue ?? []).filter(
                    (item) => String(item?.block_id ?? '').trim() !== '',
                );
                const supplementalRenames = (preview.localRenameQueue ?? []).filter(
                    (item) => String(item?.block_id ?? '').trim() === '',
                );

                const runLocalRenames = async (items) => {
                    if (!items.length) {
                        return [];
                    }

                    return executeSeoMediaSlugRenamesTwoPhase(items, {
                        renameById: (id, slug) => renameSeoMedia(id, slug, { articleId }),
                        renameByUrl: (src, slug, opts) => renameLocalMediaByUrl(src, slug, opts),
                    });
                };

                // Chạy tuần tự + gộp 1 lần — tránh race ghi đè pendingLocalRenameResultsRef.
                tasks.push(
                    (async () => {
                        const merged = [];
                        const allErrors = [];
                        try {
                            if (blockRenames.length > 0) {
                                const blockResults = await runLocalRenames(blockRenames);
                                merged.push(...blockResults);
                                if (Array.isArray(blockResults.errors)) {
                                    allErrors.push(...blockResults.errors);
                                }
                            }
                            if (supplementalRenames.length > 0) {
                                const supplementalResults = await runLocalRenames(supplementalRenames);
                                merged.push(...supplementalResults);
                                if (Array.isArray(supplementalResults.errors)) {
                                    allErrors.push(...supplementalResults.errors);
                                }
                            }
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

                        if (allErrors.length > 0) {
                            pendingLocalRenameQueueRef.current = omitFailedLocalSlugRenameQueueItems(
                                pendingLocalRenameQueueRef.current,
                                allErrors,
                            );
                            notifyLocalSlugRenameErrors(
                                allErrors,
                                blockRenames.length + supplementalRenames.length,
                            );
                        }

                        pendingLocalRenameResultsRef.current = [
                            ...pendingLocalRenameResultsRef.current,
                            ...merged,
                        ];
                    })(),
                );
            }

            return tasks.length > 0 ? Promise.all(tasks) : Promise.resolve();
        },
        [notifyLocalSlugRenameErrors, renameLocalMediaByUrl, requestWordPressRenames],
    );

    const applySlugRenameFinished = useCallback((detail) => {
        const rawWpRenamed = enrichWpRenamedWithRequestMeta(
            Array.isArray(detail?.renamed) ? detail.renamed : [],
            pendingWpRenameRequestRef.current,
        );
        // success === false: chỉ dùng renamed thật. Còn lại: fill queue thiếu (file đã rename sẵn).
        const wpRenamed = detail?.success === false
            ? rawWpRenamed
            : ensureWpRenameResultsCoverQueue(pendingWpRenameRequestRef.current, rawWpRenamed);
        pendingWpRenameRequestRef.current = [];
        const localResults = ensureLocalRenameResultsCoverQueue(
            pendingLocalRenameQueueRef.current,
            pendingLocalRenameResultsRef.current ?? [],
        );
        pendingLocalRenameResultsRef.current = [];
        pendingLocalRenameQueueRef.current = [];
        pendingQuickFixKeywordRef.current = '';

        // Deactivate TipTap trước khi patch — tránh flush HTML cũ đè URL mới.
        if (!tempMergeRef.current) {
            blockFlushRef.current = null;
        }
        setActiveBlockId(null);
        setGlobalEditor(null);

        const nextBlocks = finalizeBlocksAfterWpRename(blocksRef.current, wpRenamed, localResults);
        blocksRef.current = nextBlocks;
        setBlocks(nextBlocks);

        // Sync TipTap document thật (không chỉ DOM) cho mọi editor còn sống.
        nextBlocks.forEach((block) => {
            if (String(block?.type ?? '') === 'image') {
                return;
            }
            const editor = blockEditorsRef.current.get(block.id);
            if (!editor || editor.isDestroyed) {
                return;
            }
            const nextHtml = String(block.content ?? '').trim() || '<p></p>';
            try {
                editor.commands.setContent(nextHtml, {
                    emitUpdate: false,
                    parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                });
            } catch {
                // ignore destroyed/race
            }
        });

        setSupplementalImages((prev) =>
            resetSupplementalImagesAfterSlugRename(prev, nextBlocks, wpRenamed, localResults),
        );

        const urlMap = buildExactRenameUrlMap(wpRenamed, localResults);
        if (articleId && Object.keys(urlMap).length > 0) {
            syncProductAlbumUrlsFromBlockImages(articleId, nextBlocks, wpRenamed, localResults);
            applyRenameMapToFeaturedImageStorage(articleId, wpRenamed, localResults);
        }

        const siteIdForCache = Number(siteIdRef.current ?? 0) || 0;
        if (siteIdForCache > 0) {
            clearArticleMediaPickerCache(siteIdForCache);
        }

        setImagesReloadKey((k) => k + 1);
        queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
        scheduleAutosave();

        return { nextBlocks, wpRenamed, localResults, urlMap };
    }, [articleId, scheduleAutosave, setGlobalEditor]);

    const waitForWordPressSlugRenameFinished = useCallback((batchCount = 1) => {
        const total = Number(batchCount);
        if (total <= 0) {
            return Promise.resolve(null);
        }

        return new Promise((resolve) => {
            let remaining = total;
            let lastDetail = null;

            const onFinished = (event) => {
                lastDetail = event?.detail ?? null;
                remaining -= 1;
                if (remaining > 0) {
                    return;
                }

                window.removeEventListener('seo-attachment-slugs-rename-finished', onFinished);
                resolve(lastDetail);
            };

            window.addEventListener('seo-attachment-slugs-rename-finished', onFinished);
        });
    }, []);

    const finalizeSlugRenameSideEffects = useCallback((wpRenamed = [], localResults = []) => {
        const currentBlocks = blocksRef.current;

        if (supportsProductGallery && articleId) {
            syncProductAlbumUrlsFromBlockImages(articleId, currentBlocks, wpRenamed, localResults);
            const album = loadProductAlbum(articleId);
            if (album.length > 0) {
                saveFeaturedImage(articleId, {
                    url: album[0].url,
                    wpAttachmentId: album[0].id,
                    seoMediaId: album[0].id,
                });
            } else {
                clearFeaturedImageStorage(articleId);
            }
        }

        const siteIdForCache = Number(siteIdRef.current ?? 0) || 0;
        if (siteIdForCache > 0) {
            clearArticleMediaPickerCache(siteIdForCache);
        }

        setImagesReloadKey((key) => key + 1);
        queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
        // Recompute Media Health immediately — do not wait for SEO re-analyze.
        setFeaturedHealthSnapshot(loadFeaturedImage(articleId));
        setMediaHealthTick((tick) => tick + 1);
        window.dispatchEvent(new CustomEvent('seo-assistant-widget-health-refresh'));
        scheduleAutosave();
    }, [articleId, scheduleAutosave, supportsProductGallery]);

    const prepareImageSlugsBeforeWpSync = useCallback(async () => {
        if (quickFixSlugAllBusy) {
            throw new Error(t('editor_try_again_later'));
        }

        const context = buildQuickFixContext();
        if (!context) {
            return false;
        }

        const { keyword, indexByBlockId, supplementalOnlyRows, sourceRows } = context;
        const enrichmentByBlockId = {};
        (sourceRows ?? []).forEach((row) => {
            const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();
            if (blockId) {
                enrichmentByBlockId[blockId] = row;
            }
        });

        const preview = applyQuickFixSlugToBlocks(
            blocksRef.current,
            keyword,
            indexByBlockId,
            enrichmentByBlockId,
            { wpOnly: false, includeWordPressRenames: false },
        );
        const localRenameQueue = [...(preview.localRenameQueue ?? [])];
        const blockEligibleCount = collectImagesFromBlocks(blocksRef.current).filter(
            (row) => !row?.excludeQuickFix,
        ).length;

        let supplementalOrdinal = blockEligibleCount;
        supplementalOnlyRows.forEach((row) => {
            if (row?.excludeQuickFix) {
                return;
            }

            supplementalOrdinal += 1;
            const enriched = { ...row, quickFixIndex: supplementalOrdinal };
            const outcome = computeQuickFixSlugSupplementalOutcome(enriched, keyword, {
                wpOnly: false,
            });
            if (outcome.localRename) {
                localRenameQueue.push(outcome.localRename);
            }
        });

        const uniqueLocalRenames = [];
        const seenLocalRenames = new Set();
        localRenameQueue.forEach((item) => {
            const id = Number(item?.seo_media_id ?? 0);
            const key = id > 0 ? `id:${id}` : `src:${normalizeImageSrcKey(item?.src)}`;
            if (!key || seenLocalRenames.has(key)) {
                return;
            }

            seenLocalRenames.add(key);
            uniqueLocalRenames.push(item);
        });

        if (uniqueLocalRenames.length === 0) {
            return false;
        }

        setQuickFixSlugAllBusy(true);
        window.__seoArticleHeavyActionOverlay?.setStatusMessage?.('Đang chuẩn hóa tên ảnh…');

        try {
            const localResults = await executeSeoMediaSlugRenamesTwoPhase(uniqueLocalRenames, {
                renameById: (id, slug) => renameSeoMedia(id, slug, { articleId }),
                renameByUrl: (src, slug, opts) => renameLocalMediaByUrl(src, slug, opts),
            });
            const nextBlocks = finalizeBlocksAfterWpRename(blocksRef.current, [], localResults);
            const nextSupplemental = resetSupplementalImagesAfterSlugRename(
                supplementalImagesRef.current,
                nextBlocks,
                [],
                localResults,
            );

            blocksRef.current = nextBlocks;
            supplementalImagesRef.current = nextSupplemental;
            setBlocks(nextBlocks);
            setSupplementalImages(nextSupplemental);
            finalizeSlugRenameSideEffects();

            const skipped = localResults.errors?.length ?? 0;
            if (skipped > 0) {
                notifyLocalSlugRenameErrors(localResults.errors, uniqueLocalRenames.length);
            }

            return localResults.length > 0 || skipped > 0;
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
            throw error;
        } finally {
            setQuickFixSlugAllBusy(false);
        }
    }, [
        buildQuickFixContext,
        finalizeSlugRenameSideEffects,
        notifyLocalSlugRenameErrors,
        quickFixSlugAllBusy,
        renameLocalMediaByUrl,
    ]);

    const quickFixSlugAllImages = useCallback(
        async (imageRows = null) => {
            if (quickFixSlugAllBusy) {
                return;
            }

            const context = buildQuickFixContext(imageRows);
            if (!context) {
                return;
            }

            const { keyword, indexByBlockId, supplementalOnlyRows, sourceRows } = context;

            const enrichmentByBlockId = {};
            (sourceRows ?? []).forEach((row) => {
                const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();
                if (blockId) {
                    enrichmentByBlockId[blockId] = row;
                }
            });

            // Fix Slug All: local/safe media only — never WordPress attachments.
            const preview = applyQuickFixSlugToBlocks(
                blocksRef.current,
                keyword,
                indexByBlockId,
                enrichmentByBlockId,
                { wpOnly: false, includeWordPressRenames: false },
            );

            const allBodyRows = collectImagesFromBlocks(blocksRef.current);
            let skippedWordPress = Number(preview.skippedWordPress ?? 0)
                || allBodyRows.filter((row) => isWordPressProtectedMedia(row)).length;

            const blockEligibleCount = allBodyRows.filter((row) => isBulkSlugRenameSafeMedia(row)).length;
            const extraLocalRenames = [...(preview.localRenameQueue ?? [])];
            const localRenameSeen = new Set(
                extraLocalRenames.map((item) => {
                    const id = Number(item?.seo_media_id ?? 0);
                    if (id > 0) {
                        return `id:${id}`;
                    }

                    return `src:${normalizeImageSrcKey(item?.src)}`;
                }).filter(Boolean),
            );

            let supplementalOrdinal = blockEligibleCount;
            supplementalOnlyRows.forEach((row) => {
                if (isWordPressProtectedMedia(row)) {
                    skippedWordPress += 1;
                    return;
                }
                if (!isBulkSlugRenameSafeMedia(row)) {
                    return;
                }

                supplementalOrdinal += 1;
                const enriched = { ...row, quickFixIndex: supplementalOrdinal };
                const outcome = computeQuickFixSlugSupplementalOutcome(enriched, keyword, {
                    wpOnly: false,
                });

                if (Object.keys(outcome.patch ?? {}).length > 0 && !outcome.wpRename) {
                    patchSupplementalImageRow(enriched, outcome.patch);
                }

                if (outcome.localRename && !outcome.wpRename) {
                    const localId = Number(outcome.localRename.seo_media_id ?? 0);
                    const localKey =
                        localId > 0
                            ? `id:${localId}`
                            : `src:${normalizeImageSrcKey(outcome.localRename.src)}`;
                    if (localKey && !localRenameSeen.has(localKey)) {
                        localRenameSeen.add(localKey);
                        extraLocalRenames.push(outcome.localRename);
                    }
                }
            });

            const mergedPreview = {
                ...preview,
                renameQueue: [],
                localRenameQueue: extraLocalRenames,
            };

            const totalWpRenames = 0;
            const totalLocalRenames = (mergedPreview.localRenameQueue ?? []).length;
            const skippedAlreadyValid = Number(preview.skippedAlreadyValid ?? 0) || 0;
            const eligibleCount = Number(preview.eligibleCount ?? 0) || blockEligibleCount;

            if (totalLocalRenames === 0) {
                let body = t('editor_quick_fix_slug_all_noop_body');
                if (skippedWordPress > 0 && skippedAlreadyValid > 0) {
                    body = t('editor_quick_fix_slug_all_noop_mixed', {
                        wp: skippedWordPress,
                        valid: skippedAlreadyValid,
                    });
                } else if (skippedWordPress > 0) {
                    body = t('editor_quick_fix_slug_all_wp_skipped_only', { count: skippedWordPress });
                } else if (skippedAlreadyValid > 0 || eligibleCount > 0) {
                    body = t('editor_quick_fix_slug_all_noop_already_valid', {
                        count: skippedAlreadyValid || eligibleCount,
                    });
                } else {
                    body = t('editor_quick_fix_slug_all_noop_no_local');
                }
                notifyEditor(
                    t('editor_quick_fix_slug_all_noop_title'),
                    body,
                    'warning',
                );
                return;
            }

            setQuickFixSlugAllBusy(true);
            showArticleOperationOverlay('processing', 'media_slug_fix');
            window.__seoArticleHeavyActionOverlay?.show('sync', {
                persistUntilUnload: true,
                title: 'Đang sửa slug ảnh',
                message: 'Vui lòng không chỉnh sửa bài viết trong lúc đổi slug.',
            });
            setArticleAutosaveLock('quick-fix-slug-all', true);

            await new Promise((resolve) => {
                window.requestAnimationFrame(() => resolve());
            });

            try {
                slugRenameManagedByBatchRef.current = true;

                // Always save trước rename — tránh rename song song với editor dirty / body stale.
                // Docs: docs/article-editor/image-slug-rename.md
                window.__seoArticleHeavyActionOverlay?.setStatusMessage?.('Đang lưu bài viết trước khi sửa slug…');
                try {
                    await saveCurrentArticleFromEditor({
                        reason: 'before_fix_slug_all',
                        siteId: Number(siteIdRef.current ?? 0) || 0,
                        keepOverlay: true,
                        silentNotification: true,
                    });
                } catch (saveError) {
                    throw new Error(
                        String(saveError?.message ?? t('editor_try_again_later')),
                    );
                }

                window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
                    'Đang sửa slug ảnh local…',
                );

                const wpDetail = null;

                let localFixResult = null;
                let localSkipped = 0;
                let localFixed = 0;
                if (totalLocalRenames > 0) {
                    localFixResult = await fixArticleMediaSlugs(
                        articleId,
                        (mergedPreview.localRenameQueue ?? []).map((item) => ({
                            seo_media_id: Number(item?.seo_media_id ?? 0) || null,
                            url: String(item?.src ?? item?.url ?? '').trim(),
                            new_slug: String(item?.new_slug ?? '').trim(),
                            old_slug: String(item?.old_slug ?? '').trim(),
                        })),
                    );
                    localSkipped = Number(localFixResult?.skipped_count ?? 0) || 0;
                    const renamedList = Array.isArray(localFixResult?.renamed)
                        ? localFixResult.renamed
                        : (Array.isArray(localFixResult?.replacements) ? localFixResult.replacements : []);
                    localFixed = renamedList.length > 0
                        ? renamedList.length
                        : Math.max(0, totalLocalRenames - localSkipped);
                }

                // Sync session version after server body rewrite — prevents false Version conflict
                // when after_fix_slug_all save runs against bumped document_version.
                const syncVersionAfterSlugFix = (payload) => {
                    const nextVersion = Number(payload?.document_version ?? 0) || 0;
                    if (nextVersion > 0) {
                        window.__SEO_EDITOR_DOCUMENT_VERSION__ = nextVersion;
                        window.__seoEditorSessionClient?.setDocumentVersion?.(nextVersion);
                        try {
                            const livewireId = String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '');
                            const component = livewireId && window.Livewire?.find?.(livewireId);
                            component?.set?.('expectedDocumentVersion', nextVersion);
                        } catch {
                            // ignore
                        }
                    }
                    if (payload?.editor_document_hash) {
                        window.__SEO_EDITOR_DOCUMENT_HASH__ = String(payload.editor_document_hash);
                    }
                    if (payload?.updated_at || payload?.content_hash) {
                        const tokens = getEditorConflictTokens();
                        setEditorConflictTokens({
                            expected_updated_at: payload.updated_at || tokens.expected_updated_at,
                            expected_content_hash: payload.content_hash
                                ? String(payload.content_hash)
                                : tokens.expected_content_hash,
                        });
                    }
                };
                syncVersionAfterSlugFix(localFixResult);

                // Patch editor document/state từ exact rename map — không đoán URL / không chỉ sửa DOM.
                skipNextAutosave.current = true;
                pendingLocalRenameQueueRef.current = [...(mergedPreview.localRenameQueue ?? [])];
                const apiReplacements = Array.isArray(localFixResult?.renamed) && localFixResult.renamed.length > 0
                    ? localFixResult.renamed.map((row) => ({
                        media_id: Number(row?.image_id ?? row?.media_id ?? 0) || null,
                        old_url: String(row?.old_url ?? '').trim(),
                        new_url: String(row?.new_url ?? '').trim(),
                        old_slug: String(row?.old_filename ?? row?.old_slug ?? '').replace(/\.[^.]+$/, ''),
                        new_slug: String(row?.new_slug ?? row?.new_filename ?? '').replace(/\.[^.]+$/, ''),
                    }))
                    : (localFixResult?.replacements ?? []);
                pendingLocalRenameResultsRef.current = mapArticleSlugFixReplacementsToLocalResults(
                    apiReplacements,
                    mergedPreview.localRenameQueue ?? [],
                );
                const applied = applySlugRenameFinished(wpDetail ?? { success: true, renamed: [] });
                finalizeSlugRenameSideEffects(applied?.wpRenamed ?? [], applied?.localResults ?? []);

                cancelLocalDraftSave();
                window.__seoCancelArticleDraftAutosave?.();
                const htmlAfterFix = getExportHtml();
                const tokensAfterFix = getEditorConflictTokens();
                // Keep server content_hash from slug-fix ACK — TipTap export hash must not
                // poison expected_content_hash before the following persist.
                clearDraft(articleId, connectionHashRef.current, draftScope());
                writeSyncedLocalSnapshot(articleId, connectionHashRef.current, withDraftSite({
                    content: htmlAfterFix,
                    base_updated_at: tokensAfterFix.expected_updated_at || null,
                    base_content_hash: tokensAfterFix.expected_content_hash || null,
                    version: tokensAfterFix.expected_content_hash || hashContent(htmlAfterFix),
                }));

                // Persist URL mới lần nữa — tránh save sau đó ghi đè body server bằng state cũ.
                window.__seoArticleHeavyActionOverlay?.setStatusMessage?.('Đang lưu URL ảnh mới…');
                try {
                    await saveCurrentArticleFromEditor({
                        reason: 'after_fix_slug_all',
                        siteId: Number(siteIdRef.current ?? 0) || 0,
                        keepOverlay: true,
                        silentNotification: true,
                    });
                } catch (afterSaveError) {
                    const conflictPayload = afterSaveError?.data ?? afterSaveError?.sessionError?.data ?? null;
                    const isVersionConflict = afterSaveError?.conflict === true
                        || String(afterSaveError?.code ?? '').includes('document_version')
                        || String(afterSaveError?.code ?? '').includes('content_hash');
                    if (isVersionConflict && conflictPayload) {
                        syncVersionAfterSlugFix({
                            document_version: conflictPayload?.conflict?.actual_document_version
                                ?? conflictPayload?.document_version,
                            content_hash: conflictPayload?.conflict?.actual_content_hash
                                ?? conflictPayload?.content_hash,
                            updated_at: conflictPayload?.conflict?.actual_updated_at
                                ?? conflictPayload?.updated_at,
                            editor_document_hash: conflictPayload?.editor_document_hash,
                        });
                        // Do not replace ACK content_hash with TipTap export hash.
                        try {
                            await saveCurrentArticleFromEditor({
                                reason: 'after_fix_slug_all_retry',
                                siteId: Number(siteIdRef.current ?? 0) || 0,
                                keepOverlay: true,
                                silentNotification: true,
                            });
                        } catch (retryError) {
                            notifyEditor(
                                t('editor_quick_fix_slug_all_failed_title'),
                                String(retryError?.message ?? t('editor_try_again_later')),
                                'warning',
                            );
                        }
                    } else {
                        notifyEditor(
                            t('editor_quick_fix_slug_all_failed_title'),
                            String(afterSaveError?.message ?? t('editor_try_again_later')),
                            'warning',
                        );
                    }
                }

                const totalDone = localFixed;

                if (localSkipped > 0) {
                    notifyLocalSlugRenameErrors(
                        Array.from({ length: localSkipped }, () => ({
                            message: '',
                        })),
                        Math.max(localSkipped, totalLocalRenames || localSkipped),
                    );
                }

                window.dispatchEvent(new CustomEvent('seo-assistant-widget-health-refresh'));
                notifyEditor(
                    t('editor_quick_fix_slug_all_done_title'),
                    skippedWordPress > 0
                        ? t('editor_quick_fix_slug_all_done_with_wp_skip', {
                            local: totalDone,
                            skipped: skippedWordPress,
                        })
                        : t('editor_quick_fix_slug_all_done_body', {
                            count: totalDone,
                        }),
                    'success',
                );

                showArticleOperationOverlay('success', 'media_slug_fix');
            } catch (error) {
                notifyEditor(
                    t('editor_quick_fix_slug_all_failed_title'),
                    String(error?.message ?? t('editor_try_again_later')),
                    'danger',
                );
            } finally {
                slugRenameManagedByBatchRef.current = false;
                setArticleAutosaveLock('quick-fix-slug-all', false);
                if (window.__seoArticleHeavyActionOverlay) {
                    window.__seoArticleHeavyActionOverlay.persistUntilUnload = false;
                }
                window.__seoEndArticleHeavyActionClient?.();
                setQuickFixSlugAllBusy(false);
            }
        },
        [
            applySlugRenameFinished,
            articleId,
            buildQuickFixContext,
            cancelLocalDraftSave,
            draftScope,
            finalizeSlugRenameSideEffects,
            getExportHtml,
            notifyEditor,
            notifyLocalSlugRenameErrors,
            patchSupplementalImageRow,
            quickFixSlugAllBusy,
            requestWordPressRenames,
            waitForWordPressSlugRenameFinished,
            withDraftSite,
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

            if (preview.applied === 0 && supplementalOutcomes.length === 0) {
                return;
            }

            if (!window.confirm(t('editor_quick_fix_alt_title_all_confirm'))) {
                return;
            }

            setBlocks(preview.blocks);

            sourceRows
                .filter((row) => !row?.excludeQuickFix)
                .forEach((row) => {
                    patchSupplementalImageRow(row, { alt: keyword, title: keyword });
                });

            supplementalOutcomes
                .filter(({ row }) => !row?.excludeQuickFix)
                .forEach(({ row, outcome }) => {
                    patchSupplementalImageRow(row, outcome.patch);
                });

            const seoMetaItems = [];
            const wpMetaItems = [];
            const pushedSeo = new Set();
            const pushedWp = new Set();
            const wpIdsSyncedViaSeo = new Set();

            const enqueueRowMeta = (row, phrase) => {
                const trimmed = String(phrase ?? '').trim();
                if (!trimmed || !row) {
                    return;
                }

                const { seoMediaId, wpAttachmentId } = buildAltTitleMetaUpdatePayload(row, trimmed);

                if (seoMediaId > 0 && !pushedSeo.has(seoMediaId)) {
                    pushedSeo.add(seoMediaId);
                    seoMetaItems.push({
                        id: seoMediaId,
                        alt_text: trimmed,
                        title: trimmed,
                    });
                    if (wpAttachmentId > 0) {
                        wpIdsSyncedViaSeo.add(wpAttachmentId);
                    }
                }

                if (wpAttachmentId > 0 && !pushedWp.has(wpAttachmentId)) {
                    pushedWp.add(wpAttachmentId);
                    wpMetaItems.push({
                        attachment_id: wpAttachmentId,
                        alt_text: trimmed,
                        title: trimmed,
                    });
                }
            };

            sourceRows
                .filter((row) => !row?.excludeQuickFix)
                .forEach((row) => enqueueRowMeta(row, keyword));

            supplementalOutcomes
                .filter(({ row }) => !row?.excludeQuickFix)
                .forEach(({ row, outcome }) => {
                    const phrase = String(outcome?.patch?.alt ?? keyword).trim() || keyword;
                    enqueueRowMeta(row, phrase);
                });

            (preview.wpMetaQueue ?? []).forEach((item) => {
                const attachmentId = Number(item?.attachment_id ?? 0);
                if (attachmentId <= 0 || pushedWp.has(attachmentId)) {
                    return;
                }
                pushedWp.add(attachmentId);
                wpMetaItems.push(item);
            });

            const wpOnlyItems = wpMetaItems.filter(
                (item) => !wpIdsSyncedViaSeo.has(Number(item.attachment_id ?? 0)),
            );

            const finishNotify = (wpCount, localCount, errorMessage = null) => {
                if (errorMessage) {
                    notifyEditor(
                        t('editor_cannot_update_image_meta'),
                        errorMessage,
                        'danger',
                    );
                    return;
                }

                const total = Math.max(localCount, wpCount, preview.applied);
                notifyEditor(
                    t('editor_quick_fix_alt_title_all_done_title'),
                    wpCount > 0
                        ? t('editor_quick_fix_alt_title_all_done_body_wp', {
                              count: total,
                              wp: wpCount,
                          })
                        : t('editor_quick_fix_alt_title_all_done_body', { count: total }),
                    'success',
                );
            };

            const seoPromise =
                seoMetaItems.length > 0
                    ? updateSeoMediaMeta(seoMetaItems)
                    : Promise.resolve({ updated_count: 0, wp_updated_count: 0 });

            seoPromise
                .then((data) => {
                    const localCount = Number(data?.updated_count ?? seoMetaItems.length);
                    const wpFromSeo = Number(data?.wp_updated_count ?? 0);

                    if (wpOnlyItems.length > 0) {
                        // Một lần Livewire → một toast Filament (không spam từng ảnh).
                        requestWordPressAttachmentMetaUpdate(wpOnlyItems);
                        return;
                    }

                    finishNotify(wpFromSeo, localCount || preview.applied);
                })
                .catch((error) => {
                    if (wpOnlyItems.length > 0) {
                        // Vẫn đẩy WP batch; toast Filament báo kết quả WP.
                        requestWordPressAttachmentMetaUpdate(wpOnlyItems);
                        return;
                    }
                    finishNotify(0, 0, error?.message ?? t('editor_try_again_later'));
                });

            if (seoMetaItems.length === 0 && wpOnlyItems.length === 0 && preview.applied > 0) {
                finishNotify(0, preview.applied);
            }

            setImagesReloadKey((k) => k + 1);
        },
        [
            buildQuickFixContext,
            notifyEditor,
            patchSupplementalImageRow,
            requestWordPressAttachmentMetaUpdate,
        ],
    );

    const quickFixSlugSingleImage = useCallback(
        async (target) => {
            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword || !target) {
                return;
            }

            const rowHint = typeof target === 'object' ? target : null;
            const blockId =
                typeof target === 'string'
                    ? target
                    : String(target?.blockId ?? target?.block_id ?? '').trim();

            const resolveRow = () => {
                if (rowHint && typeof rowHint === 'object') {
                    return rowHint;
                }
                if (blockId) {
                    return collectImagesFromBlocks(blocksRef.current).find(
                        (entry) => entry.blockId === blockId,
                    ) ?? null;
                }

                return null;
            };
            const maybeWpRow = resolveRow();
            if (maybeWpRow && isWordPressProtectedMedia(maybeWpRow)) {
                window.dispatchEvent(new CustomEvent('seo-wordpress-media-rename-open', {
                    detail: {
                        siteId: Number(siteIdRef.current ?? 0) || 0,
                        articleId,
                        attachmentId: Number(
                            maybeWpRow.wpAttachmentId ?? maybeWpRow.wp_attachment_id ?? 0,
                        ),
                        oldUrl: resolveWpRenameOldUrl(maybeWpRow),
                        previewUrl: String(maybeWpRow.src ?? maybeWpRow.url ?? '').trim(),
                        currentSlug: String(maybeWpRow.slug ?? '').trim(),
                        sourceAction: 'article_editor',
                    },
                }));

                return;
            }

            if (blockId) {
                const enrichmentRow = rowHint ?? collectImagesFromBlocks(blocksRef.current).find(
                    (entry) => entry.blockId === blockId,
                );
                const preview = applyQuickFixSlugToBlock(
                    blocksRef.current,
                    keyword,
                    blockId,
                    enrichmentRow,
                    { wpOnly: false, includeWordPressRenames: false },
                );
                if (preview.applied === 0) {
                    return;
                }

                const renameCount = preview.renameQueue.length;
                const localRenameCount = (preview.localRenameQueue ?? []).length;

                if (renameCount > 0 && !confirmSlugRename({ count: 1, isQuickFix: true })) {
                    return;
                }

                if (renameCount === 0 && localRenameCount === 0) {
                    notifyEditor(
                        t('editor_quick_fix_slug_all_noop_title'),
                        t('editor_quick_fix_slug_all_noop_body'),
                        'warning',
                    );
                    return;
                }

                await applyQuickFixSlugPreview(preview, keyword);

                if (renameCount === 0 && (pendingLocalRenameResultsRef.current?.length ?? 0) > 0) {
                    applySlugRenameFinished({ renamed: [] });
                    finalizeSlugRenameSideEffects();
                }

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
            const outcome = computeQuickFixSlugSupplementalOutcome(enrichedRow, keyword, {
                wpOnly: false,
            });

            if (Object.keys(outcome.patch ?? {}).length > 0) {
                patchSupplementalImageRow(enrichedRow, outcome.patch);
            }

            if (!outcome?.wpRename && !outcome?.localRename) {
                notifyEditor(
                    t('editor_quick_fix_slug_all_noop_title'),
                    t('editor_quick_fix_slug_all_noop_body'),
                    'warning',
                );
                return;
            }

            if (outcome?.wpRename) {
                if (!confirmSlugRename({ count: 1, isQuickFix: true })) {
                    return;
                }
            }

            await applyQuickFixSlugPreview(
                {
                    applied: 1,
                    renameQueue: outcome?.wpRename ? [outcome.wpRename] : [],
                    localRenameQueue: outcome?.localRename ? [outcome.localRename] : [],
                },
                keyword,
            );

            if (!outcome?.wpRename && (pendingLocalRenameResultsRef.current?.length ?? 0) > 0) {
                applySlugRenameFinished({ renamed: [] });
                finalizeSlugRenameSideEffects();
            }
        },
        [
            applyQuickFixSlugPreview,
            applySlugRenameFinished,
            articleTitle,
            enrichSupplementalRow,
            finalizeSlugRenameSideEffects,
            focusKeyword,
            notifyEditor,
            patchSupplementalImageRow,
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
                const row = collectImagesFromBlocks(blocksRef.current).find(
                    (entry) => entry.blockId === blockId,
                );
                if (row) {
                    pushAltTitleMetaToStores(row, keyword);
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
            pushAltTitleMetaToStores(row, keyword);
            setImagesReloadKey((k) => k + 1);
        },
        [
            focusKeyword,
            articleTitle,
            patchSupplementalImageRow,
            pushAltTitleMetaToStores,
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

            if (slugRenameManagedByBatchRef.current) {
                return;
            }

            applySlugRenameFinished(e?.detail ?? {});
        };

        window.addEventListener('seo-rename-attachment-slugs-loading', onLoading);
        window.addEventListener('seo-attachment-slugs-rename-finished', onFinished);

        return () => {
            window.removeEventListener('seo-rename-attachment-slugs-loading', onLoading);
            window.removeEventListener('seo-attachment-slugs-rename-finished', onFinished);
        };
    }, [applySlugRenameFinished]);

    const collapseSectionsExcept = useCallback(
        (targetSectionId) => {
            if (!targetSectionId || editorSections.length === 0) {
                return;
            }

            const next = {};
            for (const section of editorSections) {
                next[section.id] = section.id !== targetSectionId;
            }

            setCollapsedSectionIds(next);
        },
        [editorSections],
    );

    const focusImageBlock = useCallback(
        (blockId) => {
            if (!blockId) {
                return;
            }

            const targetSectionId = sectionByBlockId.get(blockId);
            // Expand target only — do NOT collapse other sections (media/image UX).
            if (targetSectionId) {
                setCollapsedSectionIds((prev) =>
                    prev[targetSectionId]
                        ? { ...prev, [targetSectionId]: false }
                        : prev,
                );
            }

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

            captureEditorInsertionContext({
                sectionId: targetSectionId,
                blockId,
            });

            const jump = () => {
                const slot = document.querySelector(`[data-seo-block-id="${blockId}"]`);
                if (!slot) {
                    return;
                }

                scrollElementIntoViewIfNeeded(slot, { behavior: 'smooth', block: 'nearest' });
                slot.classList.add(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS);
                window.setTimeout(
                    () =>
                        slot.classList.remove(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS),
                    2400,
                );
            };

            window.setTimeout(jump, needsSwitch || targetSectionId ? 90 : 0);
        },
        [clearTempMerge, commitActiveBlock, sectionByBlockId],
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

        const targetSectionId = sectionByBlockId.get(targetBlockId);
        if (targetSectionId) {
            collapseSectionsExcept(targetSectionId);
        }

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

        window.setTimeout(jump, needsSwitch || targetSectionId ? 90 : 0);
    }, [clearTempMerge, collapseSectionsExcept, commitActiveBlock, sectionByBlockId]);

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
            if (targetSectionId) {
                collapseSectionsExcept(targetSectionId);
            }

            const currentActive = activeBlockIdRef.current;
            const needsDeactivate = currentActive != null && currentActive !== targetBlockId;

            if (needsDeactivate) {
                commitActiveBlock();
                setActiveBlockId(null);
                setGlobalEditor(null);
                blockFlushRef.current = null;
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

            const scrollDelay = needsDeactivate || targetSectionId ? 90 : 0;

            if (scrollDelay > 0) {
                window.setTimeout(runScroll, scrollDelay);
            } else {
                runScroll();
            }
        },
        [clearTempMerge, collapseSectionsExcept, commitActiveBlock, sectionByBlockId],
    );

    const insertSuggestedLinkIntoContent = useCallback(
        (detail) => {
            if (!assertWritableEditorSession('link_insert_blocked')) {
                return;
            }
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            const occurrenceIndex = Math.max(0, Number(detail?.occurrence_index) || 0);
            if (!text || !href) {
                return;
            }

            const insertMode = String(detail?.insert_mode ?? detail?.insertMode ?? 'wrap').toLowerCase();
            if (insertMode === 'caret') {
                syncInsertionContextFromLiveEditors({
                    blockEditors: blockEditorsRef.current,
                    activeBlockId: activeBlockIdRef.current,
                    sectionByBlockId,
                });
                const insertionCtx = getEditorInsertionContext();
                const preferredBlockId = String(
                    detail?.target?.blockId
                    ?? insertionCtx.activeBlockId
                    ?? activeBlockIdRef.current
                    ?? '',
                ).trim();
                if (preferredBlockId) {
                    const sectionId = sectionByBlockId.get(preferredBlockId);
                    if (sectionId) {
                        setCollapsedSectionIds((prev) =>
                            prev[sectionId] ? { ...prev, [sectionId]: false } : prev,
                        );
                    }
                }
                const bookmark = detail?.target?.selectionBookmark ?? insertionCtx.selection;
                const tryCaretInsert = () => {
                    const result = executeEditorCommand('insert_link', {
                        editorId: preferredBlockId || undefined,
                        label: text,
                        text,
                        href,
                        bookmark,
                    }, { notifyOnFailure: true });
                    if (result && result.ok === false && (
                        result.code === 'editor_read_only'
                        || result.code === 'editor_session_not_owned'
                        || result.code === 'content_replace_conflict'
                        || result.code === 'permission_denied'
                    )) {
                        return 'blocked';
                    }
                    return Boolean(result?.ok && result.transaction_applied);
                };
                const afterCaretInsert = () => {
                    if (preferredBlockId) {
                        scrollElementIntoViewIfNeeded(
                            document.querySelector(`[data-seo-block-id="${preferredBlockId}"]`),
                            { behavior: 'smooth', block: 'nearest' },
                        );
                    }
                    window.dispatchEvent(
                        new CustomEvent('seo-editor-suggested-link-inserted', {
                            detail: { text, href, blockId: preferredBlockId },
                        }),
                    );
                };
                const caretStatus = tryCaretInsert();
                if (caretStatus === 'blocked') {
                    return;
                }
                if (caretStatus) {
                    afterCaretInsert();
                    return;
                }
                // Section may still be mounting TipTap after expand — one frame retry.
                requestAnimationFrame(() => {
                    syncInsertionContextFromLiveEditors({
                        blockEditors: blockEditorsRef.current,
                        activeBlockId: preferredBlockId || activeBlockIdRef.current,
                        sectionByBlockId,
                    });
                    const retryStatus = tryCaretInsert();
                    if (retryStatus === true) {
                        afterCaretInsert();
                    }
                });
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
                        editor.commands.setContent(nextHtml, {
                            emitUpdate: false,
                            parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                        });
                    }
                }

                if (articleId) {
                    saveDraft(articleId, connectionHashRef.current, {
                        content: exportBlocksToHtml(nextBlocks),
                    });
                    setSaveStatus('saved');
                }

                setExtractedLinks((prev) => {
                    const current = prev && typeof prev === 'object'
                        ? prev
                        : { internal: [], external: [] };
                    const isInternal = isInternalHrefForSite(href, siteDomainRef.current);
                    const bucketKey = isInternal ? 'internal' : 'external';
                    const bucket = Array.isArray(current[bucketKey]) ? current[bucketKey] : [];
                    const alreadyAdded = bucket.some(
                        (item) =>
                            normalizeLinkLabel(item?.text) === normalizeLinkLabel(text) ||
                            normalizeHrefForCompare(item?.href) === normalizeHrefForCompare(href),
                    );

                    return alreadyAdded
                        ? current
                        : {
                              ...current,
                              [bucketKey]: [...bucket, { text, href, occurrence_count: 1 }],
                          };
                });
                setSuggestedInternalLinks((prev) =>
                    filterSuggestedInternalLinks(prev, [{ text, href }]),
                );
                setSuggestedExternalLinks((prev) =>
                    filterSuggestedInternalLinks(prev, [{ text, href }]),
                );
                window.dispatchEvent(
                    new CustomEvent('seo-editor-suggested-link-inserted', {
                        detail: { text, href },
                    }),
                );
            };

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
                const result = executeEditorCommand('insert_link', {
                    editor,
                    editorId: targetBlockId,
                    label: text,
                    text,
                    href,
                }, { notifyOnFailure: true });
                if (!(result?.ok && result.transaction_applied)) {
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
            requestAnalyze,
            sectionByBlockId,
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
                    activeEditor.commands.setContent(activeBlock.content, {
                        emitUpdate: false,
                        parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                    });
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
            if (!assertWritableEditorSession('editor_read_only')) {
                return;
            }
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            const type = String(detail?.type ?? '').toLowerCase();
            const plainText = isCtaPlainTextType(type) || detail?.is_placeholder === true;

            if (!text || (!href && !plainText)) {
                return;
            }

            // Do NOT re-sync from live editors after sidebar stole focus — that overwrites frozen caret.
            const frozen = getFrozenEditorInsertionContext();
            if (!frozen?.selection) {
                syncInsertionContextFromLiveEditors({
                    blockEditors: blockEditorsRef.current,
                    activeBlockId: activeBlockIdRef.current,
                    sectionByBlockId,
                });
            }

            const insertionCtx = getInsertionContextForCommand();
            const preferredBlockId = String(
                detail?.target?.blockId
                ?? insertionCtx.activeBlockId
                ?? activeBlockIdRef.current
                ?? '',
            ).trim();

            if (preferredBlockId) {
                const sectionId = sectionByBlockId.get(preferredBlockId);
                if (sectionId) {
                    setCollapsedSectionIds((prev) =>
                        prev[sectionId] ? { ...prev, [sectionId]: false } : prev,
                    );
                }
                if (activeBlockIdRef.current !== preferredBlockId) {
                    activeBlockIdRef.current = preferredBlockId;
                    setActiveBlockId(preferredBlockId);
                }
            }

            const bookmark = detail?.target?.selectionBookmark
                ?? insertionCtx.selection
                ?? frozen?.selection
                ?? null;
            // Contact sidebar always inserts CTA sentence via one command.
            const isCtaSentence = detail?.is_sentence === true
                || detail?.is_cta_sentence === true
                || detail?.is_cta_block === true
                || Boolean(String(detail?.sentence ?? '').trim());
            const tryCtaInsert = () => {
                const commandName = isCtaSentence ? 'insert_contact_cta' : 'insert_contact_value';
                const result = executeEditorCommand(commandName, {
                    editorId: preferredBlockId || undefined,
                    type,
                    contactType: type,
                    label: text,
                    text,
                    href,
                    sentence: String(detail?.sentence ?? detail?.text ?? '').trim(),
                    value_label: String(detail?.value_label ?? '').trim() || undefined,
                    bookmark,
                }, { notifyOnFailure: true });
                if (result && result.ok === false && (
                    result.code === 'editor_read_only'
                    || result.code === 'editor_session_not_owned'
                    || result.code === 'content_replace_conflict'
                    || result.code === 'permission_denied'
                )) {
                    return 'blocked';
                }
                return Boolean(result?.ok && result.transaction_applied);
            };
            const afterCtaInsert = () => {
                clearFrozenEditorInsertionContext();
                if (preferredBlockId) {
                    const sectionId = sectionByBlockId.get(preferredBlockId);
                    if (sectionId) {
                        setCollapsedSectionIds((prev) =>
                            prev[sectionId] ? { ...prev, [sectionId]: false } : prev,
                        );
                    }
                    const slot = document.querySelector(`[data-seo-block-id="${preferredBlockId}"]`);
                    scrollElementIntoViewIfNeeded(slot, { behavior: 'smooth', block: 'nearest' });
                }
                // Dirty/analyze/autosave emitted once by command layer document-changed.

                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: isCtaSentence
                                ? t('editor_cta_block_inserted')
                                : t('editor_contact_value_inserted'),
                            body: `«${text}»`,
                            status: 'success',
                        },
                    }),
                );
            };

            const ctaInsertStatus = tryCtaInsert();
            if (ctaInsertStatus === 'blocked') {
                return;
            }
            if (ctaInsertStatus) {
                afterCtaInsert();
                return;
            }

            const selectedText = intraSelectionRef.current.text;
            const activeId = preferredBlockId || activeBlockIdRef.current;
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
                        clearFrozenEditorInsertionContext();
                        updateBlockContent(activeBlock.id, replaceResult.html);
                        requestAnalyze();
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: isCtaSentence
                                        ? t('editor_cta_block_inserted')
                                        : t('editor_contact_value_inserted'),
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
            // Fallback only to active block end — never silently insert into first section.
            const targetBlock = currentBlocks.find(
                (block) => block.id === activeId && block.type !== 'image',
            );

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
            const valueLink = plainText
                ? safeText
                : `<a href="${href.replace(/"/g, '&quot;')}" class="${SEO_EDITOR_LINK_CLASS}">${safeText}</a>`;
            // Fallback also stays inline — never wrap in article-cta / new paragraph block.
            const insertion = placeholderHtml !== '' ? placeholderHtml : valueLink;
            const base = String(targetBlock.content ?? '').trim();
            const nextHtml = base !== '' ? `${base} ${insertion}` : `<p>${insertion}</p>`;

            clearFrozenEditorInsertionContext();
            updateBlockContent(targetBlock.id, nextHtml);
            requestAnalyze();

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: isCtaSentence
                            ? t('editor_cta_block_inserted')
                            : t('editor_contact_value_inserted'),
                        body: `«${text}»`,
                        status: 'success',
                    },
                }),
            );
        },
        [commitActiveBlock, updateBlockContent, requestAnalyze, sectionByBlockId],
    );

    useEffect(() => {
        if (blocks.length === 0) {
            return undefined;
        }

        const domain = siteDomainRef.current || siteDomain;
        const scheduler = utilitySchedulerRef.current;
        if (!scheduler) {
            const freshLinks = scanExistingLinksCompat(blocks, domain);
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
            return undefined;
        }

        scheduler.schedule({
            id: 'existing-links-scan',
            debounceMs: 750,
            priority: 'normal',
            run: ({ version, signal }) => {
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
                const freshLinks = scanExistingLinksCompat(
                    blocksRef.current,
                    siteDomainRef.current || siteDomain,
                );
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
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
            },
        });

        return undefined;
    }, [blocks, siteDomain]);

    useEffect(() => {
        publishExtractedLinks(extractedLinks, suggestedInternalLinks, suggestedExternalLinks);
    }, [extractedLinks, suggestedInternalLinks, suggestedExternalLinks, publishExtractedLinks]);

    useEffect(() => {
        const republishExistingLinks = () => {
            const freshLinks = scanExistingLinksCompat(
                blocksRef.current,
                siteDomainRef.current || siteDomain,
            );
            setExtractedLinks(freshLinks);
            publishExtractedLinks(freshLinks, suggestedInternalLinks, suggestedExternalLinks);
        };

        window.addEventListener(LINKS_RESCAN_REQUEST_EVENT, republishExistingLinks);

        return () => {
            window.removeEventListener(LINKS_RESCAN_REQUEST_EVENT, republishExistingLinks);
        };
    }, [publishExtractedLinks, siteDomain, suggestedExternalLinks, suggestedInternalLinks]);

    useEffect(() => {
        // Host actions accept plain detail payloads (not Event). Window listeners unwrap once.
        const scrollToLinkAction = (detail) => {
            scrollToExtractedLink(detail && typeof detail === 'object' ? detail : {});
        };
        const insertSuggestedLinkAction = (detail) => {
            insertSuggestedLinkIntoContent(detail && typeof detail === 'object' ? detail : {});
        };
        const insertCtaLinkAction = (detail) => {
            insertCtaLinkIntoContent(detail && typeof detail === 'object' ? detail : {});
        };
        const removeInternalLinkAction = (detail) => {
            removeInternalLinkFromContent(detail && typeof detail === 'object' ? detail : {});
        };

        const onScrollToLink = (event) => {
            scrollToLinkAction(event?.detail ?? {});
        };

        const onFocusAssistantReason = (event) => {
            const detail = event?.detail ?? {};
            const code = String(detail.code ?? detail.reason?.code ?? '').trim();
            const targetId = String(detail.target_id ?? detail.reason?.target_id ?? '').trim();
            const panel = String(detail.panel ?? '').trim();

            if (code === 'focus_keyword_missing' || targetId === 'focus-keyword') {
                window.dispatchEvent(new CustomEvent('seo-assistant-switch-panel', { detail: { panel: 'seo' } }));
                requestAnimationFrame(() => {
                    const input = document.getElementById('seo-google-preview-focus-keyword');
                    if (input instanceof HTMLElement) {
                        input.focus();
                        scrollElementIntoViewIfNeeded(input, { behavior: 'smooth', block: 'nearest' });
                    }
                });
                return;
            }

            if (code === 'featured_missing' || code === 'featured_slug_not_fixed' || panel === 'featured') {
                openPanel('featured', { source: 'reason_featured' });
                openMediaPicker({
                    mode: 'featured',
                    selection: 'single',
                    onConfirm: async (items) => {
                        const item = normalizeFeaturedMediaItem(items?.[0]);
                        if (!item?.url) return;
                        try {
                            await setFeaturedViaApi(articleId, item);
                            window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('make_featured_image_success'),
                                    body: t('make_featured_image_success_body'),
                                    status: 'success',
                                },
                            }));
                        } catch (error) {
                            window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('make_featured_image_failed'),
                                    body: String(error?.message || 'error'),
                                    status: 'warning',
                                },
                            }));
                        }
                    },
                });
                return;
            }

            if (code === 'gallery_missing' || code.startsWith('gallery_') || panel === 'gallery') {
                openPanel('featured', { source: 'reason_gallery' });
                return;
            }

            if (
                code === 'image_slug_not_fixed'
                || code === 'image_slug_unresolved'
                || code === 'image_alt_missing'
                || code === 'image_ratio_low'
                || code === 'image_reference_invalid'
                || panel === 'images'
            ) {
                window.dispatchEvent(new CustomEvent('seo-assistant-switch-panel', { detail: { panel: 'images' } }));
                if (targetId) {
                    focusImageBlock(targetId);
                }
                return;
            }

            if (code === 'links_below_minimum' || panel === 'links' || panel === 'cta') {
                window.dispatchEvent(new CustomEvent('seo-assistant-switch-panel', { detail: { panel: panel || 'links' } }));
            }
        };

        const onRemoveInternalLink = (event) => {
            removeInternalLinkAction(event?.detail ?? {});
        };

        const onScrollToFeaturedSnippetTable = () => {
            scrollToFeaturedSnippetTable();
        };

        const onDocumentHtmlRequest = () => {
            const html = typeof getExportHtml === 'function'
                ? getExportHtml()
                : exportBlocksToHtml(blocksRef.current);
            window.dispatchEvent(
                new CustomEvent('seo-editor-document-html', {
                    detail: { html: String(html ?? ''), articleId },
                }),
            );
        };

        // Phase 6C.2 — module actions accept plain detail (FAQ pattern). Do not expect Event.
        editorHostActionsRef.current.insertSuggestedLink = insertSuggestedLinkAction;
        editorHostActionsRef.current.insertCtaLink = insertCtaLinkAction;
        editorHostActionsRef.current.removeInternalLink = removeInternalLinkAction;
        editorHostActionsRef.current.scrollToLink = scrollToLinkAction;
        editorHostActionsRef.current.applyEditorBlockImage = (detail) => {
            window.dispatchEvent(new CustomEvent('editor-block-image-selected', {
                detail: detail && typeof detail === 'object' ? detail : {},
            }));
        };
        editorHostActionsRef.current.generateArticleImage = (detail) => {
            requestGenerateArticleImageRef.current?.(detail);
        };
        editorHostActionsRef.current.generateArticleVideo = (detail) => {
            // Video generation remains Livewire shell endpoint (Alpine listens).
            window.dispatchEvent(new CustomEvent('generate-article-video', {
                detail: detail && typeof detail === 'object' ? detail : {},
            }));
        };
        editorHostActionsRef.current.getExportHtml = () => (
            typeof getExportHtml === 'function'
                ? getExportHtml()
                : exportBlocksToHtml(blocksRef.current)
        );
        editorHostActionsRef.current.getSelectionHtml = () => String(intraSelectionRef.current?.html ?? '');

        window.addEventListener('seo-editor-scroll-to-link', onScrollToLink);
        window.addEventListener('seo-assistant-focus-reason', onFocusAssistantReason);
        window.addEventListener('seo-editor-remove-internal-link', onRemoveInternalLink);
        window.addEventListener('seo-editor-scroll-to-featured-snippet-table', onScrollToFeaturedSnippetTable);
        window.addEventListener('seo-editor-document-html-request', onDocumentHtmlRequest);

        return () => {
            window.removeEventListener('seo-editor-scroll-to-link', onScrollToLink);
            window.removeEventListener('seo-assistant-focus-reason', onFocusAssistantReason);
            window.removeEventListener('seo-editor-remove-internal-link', onRemoveInternalLink);
            window.removeEventListener('seo-editor-scroll-to-featured-snippet-table', onScrollToFeaturedSnippetTable);
            window.removeEventListener('seo-editor-document-html-request', onDocumentHtmlRequest);
        };
    }, [scrollToExtractedLink, insertSuggestedLinkIntoContent, insertCtaLinkIntoContent, removeInternalLinkFromContent, scrollToFeaturedSnippetTable, getExportHtml, articleId, focusImageBlock]);

    const clearMediaPolling = useCallback((mediaId) => {
        const timer = mediaPollTimersRef.current.get(mediaId);
        if (timer) {
            window.clearTimeout(timer);
        }
        mediaPollTimersRef.current.delete(mediaId);
    }, []);

    const releaseImageBlockMediaTracking = useCallback(
        (block) => {
            if (!block || block.type !== 'image') {
                return;
            }

            const image = block.image ?? parseImageFromBlockContent(String(block.content ?? ''));
            const mediaId = Number(image?.seoMediaId ?? image?.seo_media_id ?? 0);
            const blockId = String(block.id ?? '').trim();

            for (const [key, pending] of [...pendingAiMediaRef.current.entries()]) {
                const pendingBlockId = String(pending?.blockId ?? '').trim();
                const keyAsMediaId = Number(key);

                if (
                    (blockId !== '' && pendingBlockId === blockId) ||
                    (mediaId > 0 && keyAsMediaId === mediaId)
                ) {
                    pendingAiMediaRef.current.delete(key);
                }
            }

            if (mediaId > 0) {
                dismissedEditorImageMediaIdsRef.current.add(mediaId);
                clearMediaPolling(mediaId);
            }
        },
        [clearMediaPolling],
    );

    const isDismissedEditorImageMedia = useCallback((mediaId) => {
        const id = Number(mediaId ?? 0);

        return id > 0 && dismissedEditorImageMediaIdsRef.current.has(id);
    }, []);

    const deleteBlock = useCallback(
        (id, { skipConfirm = false } = {}) => {
            if (!assertWritableEditorSession('block_delete_blocked')) {
                return;
            }
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

            if (block.type === 'image') {
                releaseImageBlockMediaTracking(block);
            }

            setBlocks((prev) => prev.filter((b) => b.id !== id));
        },
        [activeBlockId, articleId, commitActiveBlock, clearTempMerge, releaseImageBlockMediaTracking],
    );

    const removeImageBlock = useCallback(
        (row) => {
            const target = resolveArticleImageRemoveTarget(
                row,
                blocksRef.current,
                supplementalImagesRef.current,
            );
            if (!target || target.kind !== 'block') {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('image_tab_remove_no_block'),
                            body: t('image_tab_remove_unmatched_404'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            const blockId = target.blockId;
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

            // Dọn supplemental orphan cùng identity — tránh hàng 404 còn lại trên tab Images.
            setSupplementalImages((prev) =>
                (Array.isArray(prev) ? prev : []).filter((item) => {
                    if (String(item?.blockId ?? item?.block_id ?? '').trim() === blockId) {
                        return false;
                    }

                    return !articleImageRowsShareIdentity(row, item);
                }),
            );
            setImagesReloadKey((key) => key + 1);
            queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
        },
        [deleteBlock],
    );

    const removeSupplementalImage = useCallback(
        (row) => {
            const target = resolveArticleImageRemoveTarget(
                row,
                blocksRef.current,
                supplementalImagesRef.current,
            );
            if (!target) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('image_tab_remove_no_block'),
                            body: t('image_tab_remove_unmatched_404'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            if (target.kind === 'block') {
                removeImageBlock(row);

                return;
            }

            const src = String(target.src ?? row?.src ?? '').trim();
            const origin = String(target.origin ?? row?.origin ?? '').trim();
            if (!src) {
                return;
            }

            setSupplementalImages((prev) =>
                (Array.isArray(prev) ? prev : []).filter((item) => {
                    const itemBlockId = String(item?.blockId || item?.block_id || '').trim();
                    if (itemBlockId) {
                        return true;
                    }

                    return !articleImageRowsShareIdentity(
                        { ...row, src, origin },
                        item,
                    );
                }),
            );

            if (supportsProductGallery && (origin === 'gallery' || origin === 'featured')) {
                removeProductAlbumItem(articleId, src);
                if (loadProductAlbum(articleId).length === 0) {
                    clearFeaturedImageStorage(articleId);
                }
            } else if (origin === 'featured') {
                clearFeaturedImageStorage(articleId);
            }

            setImagesReloadKey((key) => key + 1);
            queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
            scheduleAutosave();
        },
        [articleId, removeImageBlock, scheduleAutosave, supportsProductGallery],
    );

    const makeImageFeatured = useCallback(
        async (row) => {
            if (supportsProductGallery) {
                throw new Error(t('make_featured_image_product_hint'));
            }

            const item = saveFeaturedImage(articleId, {
                url: String(row?.localSrc || row?.src || '').trim(),
                wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0),
                seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0),
                alt: String(row?.alt ?? '').trim(),
                slug: String(row?.slug ?? '').trim(),
            });

            if (!item) {
                throw new Error(t('make_featured_image_missing_source'));
            }

            await callEditArticleLivewire('persistFeaturedImageFromClient', item);

            window.dispatchEvent(
                new CustomEvent('article-media-selected', {
                    detail: {
                        mode: 'featured',
                        url: item.url,
                        wpAttachmentId: item.wp_attachment_id,
                        seoMediaId: item.seo_media_id,
                        alt: item.alt,
                        slug: item.slug,
                    },
                }),
            );
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('make_featured_image_success'),
                        body: t('make_featured_image_success_body'),
                        status: 'success',
                    },
                }),
            );
            setImagesReloadKey((key) => key + 1);
            scheduleAutosave();
        },
        [articleId, scheduleAutosave, supportsProductGallery],
    );

    useEffect(() => {
        const publishSelectionContext = () => {
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
    }, [activeBlockId, blocks, tempMerge, articleId]);

    const clearOutlineFocus = useCallback(() => {
        focusedOutlineHeadingRef.current = null;
        setOutlineHeadingCommand({
            token: Date.now(),
            action: 'clear',
        });
    }, []);

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
            armBlockOutsideClickGuard();
            const sectionId = sectionByBlockId.get(id);
            if (sectionId && collapsedSectionIds[sectionId]) {
                setCollapsedSectionIds((prev) => ({ ...prev, [sectionId]: false }));
            }
            captureEditorInsertionContext({
                sectionId: sectionId ?? null,
                blockId: id,
            });
            if (tempMergeRef.current) {
                clearTempMerge();
                setGlobalEditor(null);
                activeBlockIdRef.current = id;
                setActiveBlockId(id);
                if (outlineHasSavedHeadings) {
                    const block = blocksRef.current.find((item) => item.id === id);
                    if (block && (blockHasOutlineHeading(block) || isBlockOutlineSynced(block))) {
                        syncOutlineFocusFromBlock(block);
                    } else {
                        clearOutlineFocus();
                    }
                }
                return;
            }
            if (id === activeBlockId) {
                return;
            }
            commitActiveBlock();
            activeBlockIdRef.current = id;
            setActiveBlockId(id);
            setGlobalEditor(null);
            if (outlineHasSavedHeadings) {
                const block = blocksRef.current.find((item) => item.id === id);
                if (block && (blockHasOutlineHeading(block) || isBlockOutlineSynced(block))) {
                    syncOutlineFocusFromBlock(block);
                } else {
                    clearOutlineFocus();
                }
            }
        },
        [
            activeBlockId,
            armBlockOutsideClickGuard,
            clearOutlineFocus,
            collapsedSectionIds,
            commitActiveBlock,
            clearTempMerge,
            isBlockOutlineSynced,
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
            activeBlockIdRef.current = newId;
            setActiveBlockId(newId);
            setGlobalEditor(null);
            if (type === 'image') {
                armBlockOutsideClickGuard(360);
            } else {
                armBlockOutsideClickGuard();
            }
            if (outlineHasSavedHeadings) {
                clearOutlineFocus();
            }
        },
        [
            armBlockOutsideClickGuard,
            clearOutlineFocus,
            commitActiveBlock,
            isIntroBlockId,
            notifyIntroNoImages,
            outlineHasSavedHeadings,
        ],
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
        if (isDismissedEditorImageMedia(mediaId)) {
            pendingAiMediaRef.current.delete(mediaId);

            return false;
        }

        const trimmedUrl = String(finalUrl ?? '').trim();
        if (!trimmedUrl || trimmedUrl.includes('placeholder-loading')) {
            return false;
        }

        const pending = pendingAiMediaRef.current.get(mediaId);
        let targetBlockId = String(pending?.blockId ?? '').trim();

        if (!targetBlockId && mediaId > 0) {
            const byMediaId = blocksRef.current.find((block) => {
                const image = block?.image ?? null;
                const seoId = Number(image?.seoMediaId ?? image?.seo_media_id ?? 0);
                return seoId === mediaId && Boolean(image?.isProcessing);
            });
            targetBlockId = byMediaId?.id ?? '';
        }

        // Client placeholder chưa gắn seoMediaId — lấy entry awaitingServer.
        if (!targetBlockId) {
            const awaitingEntry = [...pendingAiMediaRef.current.entries()].find(
                ([, value]) => value?.awaitingServer && value?.blockId,
            );
            if (awaitingEntry) {
                const [clientKey, awaiting] = awaitingEntry;
                pendingAiMediaRef.current.delete(clientKey);
                targetBlockId = String(awaiting.blockId ?? '').trim();
            }
        }

        // Còn một block đang isProcessing — thay thế.
        if (!targetBlockId) {
            const processingBlocks = blocksRef.current.filter(
                (block) => block?.type === 'image' && Boolean(block?.image?.isProcessing),
            );
            if (processingBlocks.length === 1) {
                targetBlockId = processingBlocks[0].id;
            }
        }

        if (!targetBlockId) {
            return false;
        }

        if (mediaType === 'video') {
            const safeUrl = trimmedUrl
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
                    src: trimmedUrl,
                    title: '',
                    alt: '',
                    isProcessing: false,
                    seoMediaId: mediaId > 0 ? mediaId : undefined,
                },
                true,
            );
        }

        pendingAiMediaRef.current.delete(mediaId);
        window.dispatchEvent(new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }));
        setImagesReloadKey((k) => k + 1);
        scheduleAutosave();
        return true;
    }, [isDismissedEditorImageMedia, patchImageInBlocks, scheduleAutosave, updateBlocksWithoutHistory]);

    const applyCompletedMediaToProductGallery = useCallback((mediaId, finalUrl, galleryItems = null) => {
        if (!articleId) {
            return false;
        }

        const trimmedUrl = String(finalUrl ?? '').trim();
        if (mediaId <= 0 || trimmedUrl === '') {
            return false;
        }

        // Luôn gắn ảnh gốc (chưa split) vào album — không dùng gallery_urls từ auto-split.
        const rawItems = [{ id: mediaId, url: trimmedUrl }];

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

        syncProductAlbumToServer(articleId);

        return true;
    }, [articleId]);

    const AI_JOBS_POLL_MS = 5_000;
    const AI_JOBS_INITIAL_POLL_MS = 3_000;

    const startMediaStatusPolling = useCallback((mediaId, mediaType) => {
        if (!mediaId || mediaPollTimersRef.current.has(mediaId)) {
            return;
        }

        let attempt = 0;
        const maxAttempts = 72;

        const poll = async () => {
            attempt += 1;

            try {
                const payload = await fetchSeoMediaStatus(mediaId);
                const status = String(payload?.status ?? '').toLowerCase();
                const url = String(payload?.url ?? '').trim();

                if (status === 'completed' && url) {
                    if (url.includes('placeholder-loading')) {
                        // Job ghi completed nhưng URL vẫn placeholder — poll tiếp.
                    } else if (isDismissedEditorImageMedia(mediaId)) {
                        clearMediaPolling(mediaId);
                        pendingAiMediaRef.current.delete(mediaId);

                        return;
                    } else {
                        const pending = pendingAiMediaRef.current.get(mediaId);
                        if (pending?.target === 'product-gallery' && mediaType === 'image') {
                            const galleryItems = Array.isArray(payload?.gallery_urls) && payload.gallery_urls.length > 0
                                ? payload.gallery_urls
                                : null;
                            if (applyCompletedMediaToProductGallery(mediaId, url, galleryItems)) {
                                clearMediaPolling(mediaId);
                                return;
                            }
                        } else if (applyCompletedMediaToPlaceholder(mediaId, mediaType, url)) {
                            clearMediaPolling(mediaId);
                            return;
                        }
                        // completed nhưng chưa gắn được block — giữ poll, thử lại.
                    }
                }

                if (status === 'failed') {
                    clearMediaPolling(mediaId);
                    pendingAiMediaRef.current.delete(mediaId);
                    window.dispatchEvent(
                        new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                    );
                    window.dispatchEvent(
                        new CustomEvent('article-ai-media-failed', {
                            detail: {
                                type: mediaType,
                                message: payload?.error_message || t('editor_ai_failed'),
                                seoMediaId: mediaId,
                            },
                        }),
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
    }, [
        applyCompletedMediaToPlaceholder,
        applyCompletedMediaToProductGallery,
        clearMediaPolling,
        isDismissedEditorImageMedia,
    ]);

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
            if (!assertWritableEditorSession('image_insert_blocked')) {
                return '';
            }
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

            const image = withDefaultImageInsertAlign({
                src: url,
                alt: '',
                title: '',
                ...imagePatch,
            });
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
                const nextImage = withDefaultImageInsertAlign({
                    ...baseImage,
                    ...imagePatch,
                    src: url,
                    alt: kw || baseImage.alt || '',
                    title: kw || baseImage.title || '',
                });
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

            const image = withDefaultImageInsertAlign({
                src: url,
                alt: kw,
                title: kw,
                ...imagePatch,
            });
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

            if (generateImageInFlightRef.current) {
                return;
            }
            generateImageInFlightRef.current = true;

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
                        saveDraft(articleId, connectionHashRef.current, {
                            content: getExportHtml(),
                        });
                        setSaveStatus('saved');
                    }
                }
            }

            setArticleAutosaveLock('generate-image-request', true);

            try {
                const result = await callEditArticleLivewire(
                    'generateArticleImageFromEditor',
                    selectionText,
                    String(payload.selectionHtml ?? ''),
                    userBrief,
                    activeBlockIdFromPayload,
                    target,
                    Number.parseInt(String(payload.loaiSanPhamCategoryArticleId ?? 0), 10) || 0,
                    String(payload.loaiSanPhamCustom ?? '').trim(),
                    String(payload.galleryGenerationMode ?? 'sprite').trim() || 'sprite',
                );

                if (result && typeof result === 'object' && result.ok === false) {
                    clearAwaitingClientImagePlaceholders();
                    const message = String(result.message ?? t('editor_generate_image_failed'));
                    const technical = String(result.technical_details ?? result.technicalDetails ?? '');
                    window.dispatchEvent(
                        new CustomEvent('article-ai-media-failed', {
                            detail: {
                                type: 'image',
                                message,
                                technicalDetails: technical,
                                classification: result.classification ?? null,
                                retryable: Boolean(result.retryable),
                            },
                        }),
                    );
                    // Không dùng window.alert — Filament Notification + modal/event đã hiển thị.
                } else if (target !== 'product-gallery') {
                    // Không phụ thuộc Livewire event — gắn seoMediaId + poll từ return value / ai-jobs.
                    let mediaId = Number(result?.seo_media_id ?? result?.seoMediaId ?? 0);
                    let status = String(result?.status ?? 'processing').toLowerCase();
                    let resultUrl = String(result?.url ?? '').trim();

                    if (mediaId <= 0 && articleId) {
                        try {
                            const jobs = await fetchArticleAiMediaJobs(articleId);
                            const newest = (Array.isArray(jobs) ? jobs : []).find((job) => {
                                const jobStatus = String(job?.status ?? '').toLowerCase();
                                const jobType = String(job?.media_type ?? 'image').toLowerCase();
                                return jobType === 'image' && (jobStatus === 'processing' || jobStatus === 'completed');
                            });
                            if (newest) {
                                mediaId = Number(newest.id ?? 0);
                                status = String(newest.status ?? status).toLowerCase();
                                resultUrl = String(newest.url ?? resultUrl).trim();
                            }
                        } catch {
                            // ignore — event/poll path vẫn thử
                        }
                    }

                    if (mediaId > 0) {
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
                                    isProcessing: status !== 'completed',
                                    src:
                                        status === 'completed' && resultUrl && !resultUrl.includes('placeholder-loading')
                                            ? resultUrl
                                            : AI_PLACEHOLDER_LOADING_URL,
                                },
                                true,
                            );
                            pendingAiMediaRef.current.set(mediaId, {
                                blockId: pending.blockId,
                                mediaType: 'image',
                            });
                        } else {
                            const processingBlocks = blocksRef.current.filter(
                                (block) => block?.type === 'image' && Boolean(block?.image?.isProcessing),
                            );
                            const unbound = processingBlocks.find((block) => {
                                const seoId = Number(block?.image?.seoMediaId ?? block?.image?.seo_media_id ?? 0);
                                return seoId <= 0;
                            });
                            const targetBlock = unbound ?? (processingBlocks.length === 1 ? processingBlocks[0] : null);
                            if (targetBlock) {
                                patchImageInBlocks(
                                    targetBlock.id,
                                    {
                                        seoMediaId: mediaId,
                                        isProcessing: status !== 'completed',
                                        src:
                                            status === 'completed' && resultUrl && !resultUrl.includes('placeholder-loading')
                                                ? resultUrl
                                                : AI_PLACEHOLDER_LOADING_URL,
                                    },
                                    true,
                                );
                                pendingAiMediaRef.current.set(mediaId, {
                                    blockId: targetBlock.id,
                                    mediaType: 'image',
                                });
                            }
                        }

                        if (status === 'completed' && resultUrl && !resultUrl.includes('placeholder-loading')) {
                            applyCompletedMediaToPlaceholder(mediaId, 'image', resultUrl);
                        } else {
                            startMediaStatusPolling(mediaId, 'image');
                        }
                    }
                }
            } catch (error) {
                clearAwaitingClientImagePlaceholders();
                const message = error?.message ?? t('editor_generate_image_failed');
                window.dispatchEvent(
                    new CustomEvent('article-ai-media-failed', {
                        detail: {
                            type: 'image',
                            message,
                        },
                    }),
                );
                // Không dùng window.alert — tránh raw provider error; Notification/event đã đủ.
            } finally {
                generateImageInFlightRef.current = false;
                setArticleAutosaveLock('generate-image-request', false);
            }
        },
        [
            applyCompletedMediaToPlaceholder,
            articleId,
            clearAwaitingClientImagePlaceholders,
            commitActiveBlock,
            getExportHtml,
            patchImageInBlocks,
            placeProcessingImagePlaceholder,
            resolveAiRefBlockId,
            startMediaStatusPolling,
        ],
    );
    requestGenerateArticleImageRef.current = requestGenerateArticleImage;

    useEffect(() => {
        if (!articleId || resumedArticleAiJobsRef.current === articleId) {
            return undefined;
        }

        resumedArticleAiJobsRef.current = articleId;
        let cancelled = false;

        void (async () => {
            try {
                const jobs = await fetchArticleAiMediaJobs(articleId);
                if (cancelled) {
                    return;
                }

                for (const job of jobs) {
                    const mediaId = Number(job?.id ?? 0);
                    const status = String(job?.status ?? '').toLowerCase();
                    const mediaType = String(job?.media_type ?? 'image').toLowerCase();
                    const jobUrl = String(job?.url ?? '').trim();

                    if (mediaId <= 0) {
                        continue;
                    }

                    if (dismissedEditorImageMediaIdsRef.current.has(mediaId)) {
                        continue;
                    }

                    // Completed gần đây — thay placeholder đang spin (kể cả mất pending map).
                    if (
                        status === 'completed' &&
                        jobUrl &&
                        !jobUrl.includes('placeholder-loading') &&
                        mediaType !== 'video'
                    ) {
                        if (applyCompletedMediaToPlaceholder(mediaId, 'image', jobUrl)) {
                            continue;
                        }
                    }

                    if (status !== 'processing') {
                        continue;
                    }

                    if (pendingAiMediaRef.current.has(mediaId) || mediaPollTimersRef.current.has(mediaId)) {
                        startMediaStatusPolling(mediaId, mediaType === 'video' ? 'video' : 'image');
                        continue;
                    }

                    const existingBlock = findImageBlockByMediaId(mediaId);
                    if (existingBlock) {
                        pendingAiMediaRef.current.set(mediaId, {
                            blockId: existingBlock.id,
                            mediaType: mediaType === 'video' ? 'video' : 'image',
                        });
                        startMediaStatusPolling(mediaId, mediaType === 'video' ? 'video' : 'image');
                        continue;
                    }

                    // Placeholder client chưa gắn seoMediaId — bind job processing mới nhất.
                    const unboundProcessing = blocksRef.current.find((block) => {
                        if (block?.type !== 'image' || !block?.image?.isProcessing) {
                            return false;
                        }
                        const seoId = Number(block.image?.seoMediaId ?? block.image?.seo_media_id ?? 0);
                        return seoId <= 0;
                    });
                    if (unboundProcessing && mediaType !== 'video') {
                        patchImageInBlocks(
                            unboundProcessing.id,
                            {
                                seoMediaId: mediaId,
                                isProcessing: true,
                                src: jobUrl || AI_PLACEHOLDER_LOADING_URL,
                            },
                            true,
                        );
                        pendingAiMediaRef.current.set(mediaId, {
                            blockId: unboundProcessing.id,
                            mediaType: 'image',
                        });
                        startMediaStatusPolling(mediaId, 'image');
                        continue;
                    }

                    const editorBlockId = String(job?.editor_block_id ?? '').trim();
                    const refBlockId = resolveAiRefBlockId(editorBlockId);
                    if (!refBlockId) {
                        continue;
                    }

                    const placeholderUrl = jobUrl || AI_PLACEHOLDER_LOADING_URL;
                    const placeholderId =
                        mediaType === 'video'
                            ? insertImageAfterBlock(refBlockId, placeholderUrl, {
                                  seoMediaId: mediaId,
                                  isProcessing: true,
                              })
                            : placeProcessingImagePlaceholder(refBlockId, placeholderUrl, {
                                  seoMediaId: mediaId,
                                  isProcessing: true,
                              });

                    if (placeholderId) {
                        pendingAiMediaRef.current.set(mediaId, {
                            blockId: placeholderId,
                            mediaType: mediaType === 'video' ? 'video' : 'image',
                        });
                        startMediaStatusPolling(mediaId, mediaType === 'video' ? 'video' : 'image');
                    }
                }
            } catch {
                // Không chặn editor nếu API job tạm lỗi.
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [
        articleId,
        applyCompletedMediaToPlaceholder,
        findImageBlockByMediaId,
        insertImageAfterBlock,
        patchImageInBlocks,
        placeProcessingImagePlaceholder,
        resolveAiRefBlockId,
        startMediaStatusPolling,
    ]);

    // Đang còn placeholder spin — reconcile định kỳ (poll miss / mất pending map).
    useEffect(() => {
        if (!articleId) {
            return undefined;
        }

        const hasProcessingPlaceholder = blocks.some(
            (block) => block?.type === 'image' && Boolean(block?.image?.isProcessing),
        );
        if (!hasProcessingPlaceholder) {
            return undefined;
        }

        let cancelled = false;

        const reconcile = async () => {
            try {
                const jobs = await fetchArticleAiMediaJobs(articleId);
                if (cancelled) {
                    return;
                }

                for (const job of jobs) {
                    const mediaId = Number(job?.id ?? 0);
                    const status = String(job?.status ?? '').toLowerCase();
                    const mediaType = String(job?.media_type ?? 'image').toLowerCase();
                    const jobUrl = String(job?.url ?? '').trim();
                    if (mediaId <= 0 || mediaType === 'video') {
                        continue;
                    }
                    if (dismissedEditorImageMediaIdsRef.current.has(mediaId)) {
                        continue;
                    }

                    if (status === 'completed' && jobUrl && !jobUrl.includes('placeholder-loading')) {
                        if (applyCompletedMediaToPlaceholder(mediaId, 'image', jobUrl)) {
                            return;
                        }
                    }

                    if (status === 'processing') {
                        const unbound = blocksRef.current.find((block) => {
                            if (block?.type !== 'image' || !block?.image?.isProcessing) {
                                return false;
                            }
                            const seoId = Number(block.image?.seoMediaId ?? block.image?.seo_media_id ?? 0);
                            return seoId <= 0 || seoId === mediaId;
                        });
                        if (unbound) {
                            const seoId = Number(unbound.image?.seoMediaId ?? unbound.image?.seo_media_id ?? 0);
                            if (seoId !== mediaId) {
                                patchImageInBlocks(
                                    unbound.id,
                                    {
                                        seoMediaId: mediaId,
                                        isProcessing: true,
                                        src: jobUrl || AI_PLACEHOLDER_LOADING_URL,
                                    },
                                    true,
                                );
                            }
                            pendingAiMediaRef.current.set(mediaId, {
                                blockId: unbound.id,
                                mediaType: 'image',
                            });
                            startMediaStatusPolling(mediaId, 'image');
                            return;
                        }
                    }
                }
            } catch {
                // ignore transient API errors
            }
        };

        void reconcile();
        const timer = window.setInterval(reconcile, 8_000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [
        articleId,
        applyCompletedMediaToPlaceholder,
        blocks,
        patchImageInBlocks,
        startMediaStatusPolling,
    ]);

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

    const applyMoveBlockWithinSectionMutation = (payload = {}) => {
        const command = 'move_block_within_section';
        const blockId = String(payload.blockId ?? '').trim();
        const direction = String(payload.direction ?? '').trim().toLowerCase();
        let sectionId = String(payload.sectionId ?? payload.section_id ?? '').trim();

        const fail = (code, meta = {}) => ({
            ok: false,
            code,
            command,
            editor_id: blockId || null,
            transaction_applied: false,
            document_changed: false,
            selection_changed: false,
            new_selection: null,
            history_step: false,
            error: { code, message_key: `editor_command.${code}` },
            meta,
        });

        if (tempMergeRef.current) {
            return fail(EDITOR_COMMAND_CODES.NO_CHANGE);
        }
        if (!blockId) {
            return fail(EDITOR_COMMAND_CODES.TARGET_MISSING);
        }
        if (direction !== 'up' && direction !== 'down') {
            return fail(EDITOR_COMMAND_CODES.SELECTION_INVALID, { direction });
        }

        const sections = buildEditorSections(blocksRef.current);
        const section = sectionId
            ? sections.find((row) => row.id === sectionId)
            : sections.find((row) => (row.blockIds ?? []).includes(blockId));
        if (!section) {
            return fail('section_missing', { blockId, sectionId: sectionId || null });
        }
        if (sectionId && section.id !== sectionId) {
            return fail(EDITOR_COMMAND_CODES.SECTION_MISMATCH, { blockId, sectionId });
        }
        if (!(section.blockIds ?? []).includes(blockId)) {
            return fail(EDITOR_COMMAND_CODES.SECTION_MISMATCH, { blockId, sectionId: section.id });
        }
        sectionId = section.id;

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

        let working = blocksRef.current;
        if (flushedHtml != null && activeId) {
            working = working.map((block) =>
                block.id === activeId ? { ...block, content: flushedHtml } : block,
            );
        }

        // Re-resolve section ids against flushed working set (same ids, same ownership).
        const liveSections = buildEditorSections(working);
        const liveSection = liveSections.find((row) => row.id === sectionId)
            ?? liveSections.find((row) => (row.blockIds ?? []).includes(blockId));
        if (!liveSection) {
            return fail('section_missing', { blockId, sectionId });
        }

        const result = reorderBlockWithinSection(working, {
            blockId,
            direction,
            sectionBlockIds: liveSection.blockIds,
            sectionId: liveSection.id,
        });

        if (!result.ok) {
            return fail(result.code, {
                sectionId: liveSection.id,
                fromIndex: result.fromIndex,
                toIndex: result.toIndex,
            });
        }

        // One setBlocks → one history step. Do not normalizeBlocks (avoids new image ids).
        blocksRef.current = result.blocks;
        setBlocks(result.blocks);
        setActiveBlockId(blockId);
        setGlobalEditor(null);

        return {
            ok: true,
            code: EDITOR_COMMAND_CODES.MOVED,
            command,
            editor_id: blockId,
            transaction_applied: true,
            document_changed: true,
            selection_changed: false,
            new_selection: null,
            history_step: true,
            error: null,
            meta: {
                sectionId: liveSection.id,
                fromIndex: result.fromIndex,
                toIndex: result.toIndex,
            },
        };
    };

    structureMutationRef.current = (name, payload) => {
        if (name === 'delete_block') {
            deleteBlock(payload.blockId, { skipConfirm: Boolean(payload.skipConfirm) });
            return true;
        }
        if (name === 'replace_article_document' && Array.isArray(payload.blocks)) {
            setBlocks(payload.blocks);
            return true;
        }
        if (name === 'move_block_within_section') {
            return applyMoveBlockWithinSectionMutation(payload);
        }
        if (name === 'move_block_to_adjacent_section') {
            const direction = payload.direction === 'prev' || payload.direction === 'next'
                ? payload.direction
                : null;
            if (!direction) {
                return {
                    ok: false,
                    code: EDITOR_COMMAND_CODES.SELECTION_INVALID,
                    command: name,
                    editor_id: payload.blockId ?? null,
                    transaction_applied: false,
                    document_changed: false,
                    selection_changed: false,
                    new_selection: null,
                    history_step: false,
                    error: {
                        code: EDITOR_COMMAND_CODES.SELECTION_INVALID,
                        message_key: 'editor_command.selection_invalid',
                    },
                    meta: {},
                };
            }
            moveBlockToSection(payload.blockId, direction);
            return true;
        }
        return false;
    };

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
        const applyExtractedFaqsToEditor = (detail = {}) => {
            const html = stripLeadingH1FromHtml(detail?.editorHtml ?? detail?.editor_html ?? '');
            if (!html) {
                return;
            }

            skipNextAutosave.current = true;
            clearTempMerge();
            blockFlushRef.current = null;
            setActiveBlockId(null);
            setGlobalEditor(null);
            setBlocks(enrichBlocksWithPostImages(parseHtmlToBlocks(html), postImagesRef.current));
            saveDraft(articleId, connectionHashRef.current, { content: html });
            setSaveStatus('saved');
            setSeoStale(true);
        };
        editorHostActionsRef.current.applyExtractedFaqs = applyExtractedFaqsToEditor;

        const applyEditorHtml = (event) => {
            applyExtractedFaqsToEditor(event.detail ?? {});
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
            saveDraft(articleId, connectionHashRef.current, { content: html });
            setSaveStatus('saved');
            setSeoStale(true);
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
            runLocalSeoAnalysis();
            window.dispatchEvent(
                new CustomEvent('editor-html-collected', {
                    detail: {
                        html: getExportHtml(),
                        target,
                        seoAnalysis: lastSeoAnalysisRef.current,
                    },
                }),
            );
        };

        window.addEventListener('collect-editor-html', onCollectEditorHtml);
        // Legacy extract-article-faqs → Livewire path removed for editor; use runFaqExtractFromToolbar.
        window.addEventListener('article-faqs-extracted', applyEditorHtml);
        window.addEventListener('seo-article-revision-restore', onRevisionRestore);

        const onPostImagesSynced = (event) => {
            const images = event.detail?.images;
            if (!Array.isArray(images)) {
                return;
            }
            setPostImages(images);
            postImagesRef.current = images;
            setBlocks((prev) => enrichBlocksWithPostImages(prev, images));
            setImagesReloadKey((key) => key + 1);
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

        const publishEditorImagesCatalog = ({ autoSync = false } = {}) => {
            const images = buildMergedEditorImagesForPicker(
                blocksRef.current,
                supplementalImagesRef.current,
            );
            window.dispatchEvent(
                new CustomEvent('seo-editor-images-catalog', {
                    detail: { images, tab: 'article', autoSync },
                }),
            );
        };

        publishEditorImagesCatalogRef.current = (autoSync = true) =>
            publishEditorImagesCatalog({ autoSync });

        const onRequestEditorImagesCatalog = () => publishEditorImagesCatalog();

        window.addEventListener('seo-request-editor-images-catalog', onRequestEditorImagesCatalog);

        const syncPanelFaqs = (event) => {
            const fromExtract = event.detail?.faqs;
            if (Array.isArray(fromExtract)) {
                panelFaqsRef.current = fromExtract;
                setPanelFaqs(fromExtract);
                setFaqCount(fromExtract.length);
                scheduleIdleSeoAnalysis();
            }
        };

        const syncPanelFaqsFromFaqEditor = (event) => {
            const rows = event.detail?.faqs;
            if (!Array.isArray(rows)) {
                return;
            }

            panelFaqsRef.current = rows;
            setPanelFaqs(rows);
            setFaqCount(rows.length);
            scheduleIdleSeoAnalysis();
        };

        window.addEventListener('article-faqs-extracted', syncPanelFaqs);
        window.addEventListener('article-faq-rows-changed', syncPanelFaqsFromFaqEditor);

        const handleFocusKeywordUpdated = (e) => {
            const keyword = e.detail?.focus_keyword ?? null;
            setFocusKeyword(keyword);
            requestAnalyze();
        };

        const handleGoogleSerpPreviewUpdated = (event) => {
            const preview = event.detail?.preview ?? event.detail ?? {};
            seoMetaRef.current = {
                ...seoMetaRef.current,
                seoTitle: String(preview?.title ?? seoMetaRef.current.seoTitle ?? articleTitle ?? '').trim(),
                metaDescription: String(
                    preview?.description ?? seoMetaRef.current.metaDescription ?? '',
                ).trim(),
            };
            requestAnalyze();
        };

        const handleEditorSlugUpdated = (event) => {
            const slug = String(event.detail?.slug ?? event.detail?.article_slug ?? '').trim();
            if (slug === '') {
                return;
            }

            seoMetaRef.current = {
                ...seoMetaRef.current,
                slug,
            };
            requestAnalyze();
        };

        const handlePublishPostTypeChanged = (event) => {
            const nextType = String(event.detail?.postType ?? event.detail?.post_type ?? '').trim();
            if (nextType === '') {
                return;
            }

            const normalized = nextType.toLowerCase();
            const nextSupportsGallery = normalized === 'product' || normalized === 'e-commerce';
            setArticleType(nextType);
            setSupportsProductGallery(nextSupportsGallery);
            requestAnalyze();
        };

        const handleServerAnalyzeResult = (event) => {
            const result = event?.detail?.result;
            if (result && typeof result === 'object') {
                applySeoAnalysisResult(result, 'saved');
                const score = Number(result.total_score ?? result.score ?? result.seo_score);
                if (Number.isFinite(score)) {
                    setSavedSeoScore(score);
                }
                setSeoStale(false);
                return;
            }
            requestAnalyze();
        };

        window.addEventListener('seo-focus-keyword-updated', handleFocusKeywordUpdated);
        window.addEventListener('google-serp-preview-updated', handleGoogleSerpPreviewUpdated);
        window.addEventListener('seo-editor-slug-updated', handleEditorSlugUpdated);
        window.addEventListener('seo-publish-post-type-changed', handlePublishPostTypeChanged);
        window.addEventListener('seo-editor-analyze-result', handleServerAnalyzeResult);

        const handleClickOutside = (e) => {
            if (Date.now() < blockOutsideClickGuardUntilRef.current) {
                return;
            }

            const activeId = String(activeBlockIdRef.current ?? '').trim();
            if (activeId !== '') {
                const activeSlot = e.target.closest(`[data-seo-block-id="${activeId}"]`);
                if (activeSlot) {
                    return;
                }
            }

            // Không đóng block nếu đang focus vào input/textarea bên trong block image hoặc picker
            const activeEl = document.activeElement;
            if (
                activeEl &&
                ['INPUT', 'TEXTAREA'].includes(activeEl.tagName) &&
                !activeEl.readOnly &&
                (activeEl.closest('.block-image-active') || activeEl.closest('.seo-image-block-picker'))
            ) {
                if (!e.target.closest('.block-image-active') && !e.target.closest('.seo-image-block-picker')) {
                    return;
                }
            }

            // Assistant sidebar / dock: never clear active editor context on click.
            if (isAssistantFocusStealTarget(e.target)) {
                return;
            }

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
                e.target.closest('.seo-image-block-picker') ||
                e.target.closest('.seo-image-block-picker__choice') ||
                e.target.closest('.seo-image-block-picker__input') ||
                e.target.closest('.seo-image-block-picker__textarea') ||
                e.target.closest('.seo-image-block-picker__btn') ||
                e.target.closest('.seo-image-block-picker__back') ||
                e.target.closest('.seo-image-meta-panel') ||
                e.target.closest('.block-editor-text-block') ||
                e.target.closest('.seo-image-toolbar') ||
                e.target.closest('.block-image-active') ||
                e.target.closest('.seo-block-image-empty-preview') ||
                e.target.closest('.seo-outline-panel') ||
                e.target.closest('.seo-article-editor-outline-rail') ||
                e.target.closest('.seo-article-media-modal') ||
                e.target.closest('.seo-generate-image-modal') ||
                e.target.closest('.seo-generate-image-modal-backdrop') ||
                e.target.tagName === 'INPUT' ||
                e.target.tagName === 'TEXTAREA' ||
                e.target.closest('[contenteditable]')
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
            activeBlockIdRef.current = null;
            setActiveBlockId(null);
            setGlobalEditor(null);
            if (outlineHasSavedHeadings) {
                clearOutlineFocus();
            }
        };

        const captureInsertionBeforeAssistantFocus = (event) => {
            if (!isAssistantFocusStealTarget(event.target)) {
                return;
            }
            syncAndFreezeInsertionContext({
                blockEditors: blockEditorsRef.current,
                activeBlockId: activeBlockIdRef.current,
                sectionByBlockId,
            });
        };

        const onFreezeInsertionContext = () => {
            syncAndFreezeInsertionContext({
                blockEditors: blockEditorsRef.current,
                activeBlockId: activeBlockIdRef.current,
                sectionByBlockId,
            });
        };

        document.addEventListener('mousedown', handleClickOutside);
        document.addEventListener('pointerdown', captureInsertionBeforeAssistantFocus, true);
        window.addEventListener('seo-assistant-freeze-insertion-context', onFreezeInsertionContext);

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

        const persistSelectedMediaBlock = (blockId, content, image, blockType = 'image') => {
            const nextBlocks = blocksRef.current.map((block) =>
                block.id === blockId
                    ? {
                          ...block,
                          type: blockType,
                          content,
                          image,
                      }
                    : block,
            );

            blocksRef.current = nextBlocks;
            setBlocks(nextBlocks);
            reconcileImagesTabWithBlocks(nextBlocks);

            if (articleId) {
                saveDraft(articleId, connectionHashRef.current, {
                    content: exportBlocksToHtml(nextBlocks),
                });
                setSaveStatus('saved');
            }
        };

        const syncWpPickerSelectionToImagesTab = (pickerTab) => {
            if (String(pickerTab ?? '').trim() !== 'original') {
                return;
            }

            setImagesReloadKey((key) => key + 1);
            publishEditorImagesCatalog({ autoSync: true });
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
                    'video',
                );
                syncWpPickerSelectionToImagesTab(event.detail?.pickerTab);
                return;
            }

            const slug = (event.detail?.slug ?? '').trim() || slugFromUrl(url);
            const kw = String(window.__SEO_MAIN_KEYWORD__ ?? '').trim();
            const alt = kw || (event.detail?.alt ?? '').trim();
            const seoMediaId = Number(event.detail?.seoMediaId ?? event.detail?.id ?? 0);
            if (seoMediaId > 0) {
                dismissedEditorImageMediaIdsRef.current.delete(seoMediaId);
            }
            const image = withDefaultImageInsertAlign({
                src: url,
                alt,
                title: alt,
                wpAttachmentId: attachmentId > 0 ? attachmentId : undefined,
                seoMediaId: seoMediaId > 0 ? seoMediaId : undefined,
                slug: slug || undefined,
                wpSrc: url,
            });
            const html = renderImageFigure(image);
            persistSelectedMediaBlock(blockId, html, image, 'image');
            syncWpPickerSelectionToImagesTab(event.detail?.pickerTab);
        };

        window.addEventListener('editor-block-image-selected', onEditorBlockImageSelected);

        const onArticleAiImageGenerated = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const requestedBlockId = String(detail.activeBlockId ?? detail.active_block_id ?? '').trim();
            const url = String(detail.url ?? '').trim();
            const status = String(detail.status ?? '').toLowerCase();
            const mediaId = Number(detail.seoMediaId ?? detail.seo_media_id ?? 0);
            const target = String(detail.target ?? generateImageTargetRef.current ?? 'editor').trim() || 'editor';
            if (!url && status !== 'processing' && status !== 'pending') {
                return;
            }

            if (mediaId > 0 && isDismissedEditorImageMedia(mediaId)) {
                pendingAiMediaRef.current.delete(mediaId);
                clearMediaPolling(mediaId);

                return;
            }

            if (target === 'product-gallery') {
                if ((status === 'processing' || status === 'pending') && mediaId > 0) {
                    pendingAiMediaRef.current.set(mediaId, {
                        target: 'product-gallery',
                        mediaType: 'image',
                    });
                    startMediaStatusPolling(mediaId, 'image');
                    generateImageTargetRef.current = 'editor';
                    return;
                }

                if (status === 'completed' && mediaId > 0 && url && !url.includes('placeholder-loading')) {
                    const galleryItems = Array.isArray(detail.gallery_urls) && detail.gallery_urls.length > 0
                        ? detail.gallery_urls
                        : (Array.isArray(detail.galleryUrls) && detail.galleryUrls.length > 0
                            ? detail.galleryUrls
                            : null);
                    applyCompletedMediaToProductGallery(mediaId, url, galleryItems);
                    generateImageTargetRef.current = 'editor';
                }

                return;
            }

            const isProcessingStatus = status === 'processing' || status === 'pending';

            // Completed trước — không phụ thuộc refBlockId.
            if (status === 'completed' && mediaId > 0 && url && !url.includes('placeholder-loading')) {
                if (applyCompletedMediaToPlaceholder(mediaId, 'image', url)) {
                    return;
                }

                const existingCompleted = findImageBlockByMediaId(mediaId);
                if (existingCompleted) {
                    patchImageInBlocks(
                        existingCompleted.id,
                        {
                            src: url,
                            title: '',
                            alt: '',
                            isProcessing: false,
                            seoMediaId: mediaId,
                        },
                        true,
                    );
                    pendingAiMediaRef.current.delete(mediaId);
                    clearMediaPolling(mediaId);
                    setImagesReloadKey((k) => k + 1);
                    scheduleAutosave();
                }

                return;
            }

            // Processing: gắn awaiting client placeholder — không early-return vì thiếu refBlockId.
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

                if (pendingAiMediaRef.current.has(mediaId)) {
                    const pending = pendingAiMediaRef.current.get(mediaId);
                    const pendingBlockId = String(pending?.blockId ?? '').trim();
                    const hasPendingBlock = pendingBlockId
                        ? blocksRef.current.some((block) => block.id === pendingBlockId)
                        : false;

                    if (!hasPendingBlock) {
                        pendingAiMediaRef.current.delete(mediaId);
                        clearMediaPolling(mediaId);

                        return;
                    }

                    startMediaStatusPolling(mediaId, 'image');
                    return;
                }
            }

            const fallbackActiveBlockId = String(activeBlockIdRef.current ?? '').trim();
            const refBlockId = resolveAiRefBlockId(requestedBlockId || fallbackActiveBlockId);
            if (!refBlockId) {
                return;
            }

            if (isProcessingStatus && mediaId > 0) {
                const placeholderId = placeProcessingImagePlaceholder(refBlockId, url || AI_PLACEHOLDER_LOADING_URL, {
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

            if (mediaId > 0 && isDismissedEditorImageMedia(mediaId)) {
                pendingAiMediaRef.current.delete(mediaId);
                clearMediaPolling(mediaId);

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
                        pendingAiMediaRef.current.delete(mediaId);
                        clearMediaPolling(mediaId);

                        return;
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
            window.removeEventListener('article-faqs-extracted', applyEditorHtml);
            window.removeEventListener('seo-article-revision-restore', onRevisionRestore);
            window.removeEventListener('article-post-images-synced', onPostImagesSynced);
            window.removeEventListener('article-supplemental-images-synced', onSupplementalImagesSynced);
            window.removeEventListener('seo-request-editor-images-catalog', onRequestEditorImagesCatalog);
            window.removeEventListener('article-faqs-extracted', syncPanelFaqs);
            window.removeEventListener('article-faq-rows-changed', syncPanelFaqsFromFaqEditor);
            window.removeEventListener('seo-focus-keyword-updated', handleFocusKeywordUpdated);
            window.removeEventListener('google-serp-preview-updated', handleGoogleSerpPreviewUpdated);
            window.removeEventListener('seo-editor-slug-updated', handleEditorSlugUpdated);
            window.removeEventListener('seo-publish-post-type-changed', handlePublishPostTypeChanged);
            window.removeEventListener('seo-editor-analyze-result', handleServerAnalyzeResult);
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('pointerdown', captureInsertionBeforeAssistantFocus, true);
            window.removeEventListener('seo-assistant-freeze-insertion-context', onFreezeInsertionContext);
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
        reconcileImagesTabWithBlocks,
        clearTempMerge,
        articleId,
        articleTitle,
        getExportHtml,
        initialPostImages,
        insertImageAfterBlock,
        insertVideoAfterBlock,
        requestAnalyze,
        scheduleIdleSeoAnalysis,
        runLocalSeoAnalysis,
        scheduleAutosave,
        startMediaStatusPolling,
        clearMediaPolling,
        isDismissedEditorImageMedia,
        clearOutlineFocus,
        outlineHasSavedHeadings,
    ]);

    useEffect(() => {
        window.__seoCollectEditorHeavyBundle = async ({
            renameImagesBeforeWpSync = false,
        } = {}) => {
            blockFlushRef.current?.();

            if (renameImagesBeforeWpSync) {
                await prepareImageSlugsBeforeWpSync();
                window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
                    'Đang đồng bộ WordPress…',
                );
            }

            clearTempMerge();
            setActiveBlockId(null);
            setGlobalEditor(null);
            runLocalSeoAnalysis();

            const faqRows = resolveArticleFaqsSnapshot();
            const faqCollectorOpen = typeof window.__seoCollectArticleFaqs === 'function';
            // Phase 2: đừng nhét faqs:[] khi module FAQ chưa hydrate — sync sẽ wipe DB/WP.
            const faqsForBundle =
                faqCollectorOpen || (Array.isArray(faqRows) && faqRows.length > 0)
                    ? faqRows
                    : null;

            const exportHtml = getExportHtml();
            if (!assertWritableDocumentNotWhitespaceCorrupted(exportHtml)) {
                const err = new Error(t('editor_inline_whitespace_corruption_body'));
                err.code = INLINE_WHITESPACE_CORRUPTION_CODE;
                throw err;
            }
            const editorDocument = buildEditorDocumentEnvelope(
                blocksRef.current,
                blockEditorsRef.current,
            );

            return {
                articleId,
                html: exportHtml,
                client_rendered_html: exportHtml,
                editor_document: editorDocument,
                expected_editor_document_hash: window.__SEO_EDITOR_DOCUMENT_HASH__ || null,
                seoAnalysis: lastSeoAnalysisRef.current,
                faqs: faqsForBundle,
            };
        };

        window.__seoAssertEditorWhitespaceSafe = (html) => (
            assertWritableDocumentNotWhitespaceCorrupted(html)
        );

        return () => {
            delete window.__seoCollectEditorHeavyBundle;
            delete window.__seoAssertEditorWhitespaceSafe;
        };
    }, [
        articleId,
        assertWritableDocumentNotWhitespaceCorrupted,
        clearTempMerge,
        getExportHtml,
        prepareImageSlugsBeforeWpSync,
        resolveArticleFaqsSnapshot,
        runLocalSeoAnalysis,
    ]);

    useEffect(() => {
        // Idle SEO auto-analysis (3–5s) — not 150ms loop. Typing cancels via bumpVersion.
        if (blocks.length === 0) return;
        if (skipNextAutosave.current) {
            skipNextAutosave.current = false;
            return;
        }
        if (blocks !== analyzedBlocksRef.current) {
            scheduleIdleSeoAnalysis();
        }
        scheduleAutosave();
    }, [blocks, scheduleAutosave, scheduleIdleSeoAnalysis]);

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

    const saveLabel =
        saveStatus === 'saving'
            ? t('editor_saving_draft')
            : saveStatus === 'pending'
              ? t('editor_draft_pending')
              : t('editor_draft_saved_local');

    useEffect(() => {
        window.dispatchEvent(
            new CustomEvent('article-editor:save-status', {
                detail: { status: saveStatus, label: saveLabel },
            }),
        );
    }, [saveStatus, saveLabel]);

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
        const targetLevel = Number(level) || 0;
        const target = truncateOutlineHeadingText(oldText);
        const replacement = truncateOutlineHeadingText(newText);
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
                    (node) => truncateOutlineHeadingText(node.textContent) === target,
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

            const mappedHeadingId = outlineHeadingIdsByKeyRef.current.get(oldKey);
            if (mappedHeadingId != null) {
                outlineHeadingIdsByKeyRef.current.delete(oldKey);
                outlineHeadingIdsByKeyRef.current.set(newKey, mappedHeadingId);
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
        const target = truncateOutlineHeadingText(headingText);
        const headingNode =
            Array.from(doc.body.querySelectorAll(selector)).find(
                (item) => truncateOutlineHeadingText(item.textContent) === target,
            ) ?? doc.body.querySelector(selector);

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
                activeEditor.commands.setContent(nextHtml, {
                    emitUpdate: false,
                    parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                });
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

            // Phase 4: outline is client-derived — no POST /outline on section add.
            const clientHeadingId = `client:${id}`;
            const heading = {
                id: clientHeadingId,
                heading_text: meta.headingText,
                level: meta.level ?? 2,
                block_id: id,
                children: [],
            };

            try {
                handleOutlineHeadingAppended({
                    blockId: id,
                    headingId: clientHeadingId,
                    heading,
                });
                outlineFingerprintRef.current = '';
                const tree = buildClientOutlineTree(blocksRef.current);
                outlineFingerprintRef.current = outlineHeadingFingerprint(blocksRef.current);
                setClientOutline(tree);
                if (options.focusEdit === true) {
                    setOutlineTreeSync({
                        token: Date.now(),
                        action: 'focus',
                        headingId: clientHeadingId,
                        focusEdit: true,
                    });
                }
            } finally {
                outlineAppendInflightRef.current.delete(id);
            }
        },
        [handleOutlineHeadingAppended],
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

            const trimmed = truncateOutlineHeadingText(newText);
            const oldText = truncateOutlineHeadingText(section.title);
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

    const scrollPageToTop = useCallback(() => {
        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
        document.documentElement.scrollTop = 0;
        document.body.scrollTop = 0;

        document.querySelector('.seo-article-edit-page .fi-main-ctn')?.scrollTo?.({ top: 0, left: 0, behavior: 'auto' });
        document.querySelector('.seo-article-edit-page .fi-main')?.scrollTo?.({ top: 0, left: 0, behavior: 'auto' });
    }, []);

    const openImageAssistantPanel = useCallback(() => {
        window.dispatchEvent(
            new CustomEvent('seo-assistant-switch-panel', {
                detail: { panel: 'images' },
            }),
        );
    }, []);

    const openOutlineRail = useCallback(() => {
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

            const fromBlockId = String(node?.block_id ?? '').trim();
            const clientId = String(node?.id ?? '');
            const blockId =
                fromBlockId
                || (clientId.startsWith('client:') ? clientId.slice('client:'.length) : '')
                || findBlockIdForOutlineHeading(
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
                    collapseSectionsExcept(sectionId);
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
        [collapseSectionsExcept, focusImageBlock, sectionByBlockId, sectionHeadingBlockIds],
    );

    useEffect(() => {
        const onOpenImagesTab = (event) => {
            const detail = event?.detail ?? {};
            const src = String(detail?.src ?? '').trim();
            const seoMediaId = Number(detail?.seoMediaId ?? detail?.seo_media_id ?? 0);

            openImageAssistantPanel();
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
    }, [openImageAssistantPanel]);

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

    const collapsedSectionsInitializedRef = useRef(false);

    useEffect(() => {
        if (editorSections.length === 0) {
            return;
        }

        if (collapsedSectionsInitializedRef.current) {
            return;
        }

        collapsedSectionsInitializedRef.current = true;
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

    const runFeaturedSnippetPromptGenerate = useCallback(async () => {
        if (!canGenerateFeaturedSnippet || featuredSnippetGenerating) {
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

        const sections = buildEditorSections(blocksRef.current);
        const anchorSection = [...sections].reverse().find((item) => !item.isIntro) ?? sections[0] ?? null;
        const anchorLastBlockId = anchorSection?.blockIds?.[anchorSection.blockIds.length - 1]
            ?? blocksRef.current[blocksRef.current.length - 1]?.id
            ?? null;
        if (!anchorLastBlockId) {
            return;
        }

        featuredSnippetTargetRef.current = {
            mode: 'prompt-preview',
            anchorSectionId: anchorSection?.id ?? null,
            anchorLastBlockId,
        };
        setFeaturedSnippetGenerating(true);
        setArticleAutosaveLock('generate-featured-snippet', true);

        try {
            await callEditArticleLivewire(
                'generateFeaturedSnippetFromEditor',
                anchorLastBlockId,
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
    }, [articleTitle, canGenerateFeaturedSnippet, featuredSnippetGenerating, focusKeyword]);

    const confirmFeaturedSnippetPromptInsert = useCallback(() => {
        const pending = featuredSnippetTargetRef.current;
        const html = String(featuredSnippetPreviewHtml || pending?.previewHtml || '').trim();
        if (!html || !pending?.anchorLastBlockId) {
            return;
        }
        featuredSnippetTargetRef.current = null;
        setFeaturedSnippetPromptOpen(false);
        setFeaturedSnippetGenerating(true);
        void insertFeaturedSnippetAsNewSectionAfter(
            {
                mode: 'new-section-after',
                anchorSectionId: pending.anchorSectionId,
                anchorLastBlockId: pending.anchorLastBlockId,
            },
            html,
        ).finally(() => {
            setFeaturedSnippetGenerating(false);
            setFeaturedSnippetPreviewHtml('');
            scheduleIdleSeoAnalysis();
        });
    }, [featuredSnippetPreviewHtml, insertFeaturedSnippetAsNewSectionAfter, scheduleIdleSeoAnalysis]);

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

            if (!html) {
                featuredSnippetTargetRef.current = null;
                setFeaturedSnippetGenerating(false);
                return;
            }

            if (pending?.mode === 'prompt-preview') {
                featuredSnippetTargetRef.current = {
                    ...pending,
                    mode: 'prompt-insert',
                    previewHtml: html,
                };
                setFeaturedSnippetPreviewHtml(html);
                setFeaturedSnippetGenerating(false);
                return;
            }

            if (pending?.mode !== 'new-section-after') {
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

            if (String(event.detail?.pickerTab ?? '').trim() === 'original') {
                queueMicrotask(() => {
                    publishEditorImagesCatalogRef.current?.();
                    setImagesReloadKey((key) => key + 1);
                });
            }
        };

        const onRemoved = (event) => {
            const detail = event.detail ?? {};
            const url = String(detail.url || '').trim();
            const urlKey = url ? normalizeImageSrcKey(url) : '';
            const seoId = Number(detail.seo_media_id ?? detail.seoMediaId ?? 0);
            const wpId = Number(detail.wp_attachment_id ?? detail.wpAttachmentId ?? 0);
            if (!urlKey && seoId <= 0 && wpId <= 0) {
                return;
            }

            setSupplementalImages((prev) =>
                (Array.isArray(prev) ? prev : []).filter((row) => {
                    const rowSeo = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
                    if (seoId > 0 && rowSeo > 0 && seoId === rowSeo) {
                        return false;
                    }
                    const rowWp = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
                    if (wpId > 0 && rowWp > 0 && wpId === rowWp) {
                        return false;
                    }
                    if (!urlKey) {
                        return true;
                    }
                    const candidates = [
                        row?.src,
                        row?.localSrc,
                        row?.local_src,
                        row?.wpSrc,
                        row?.wp_url,
                    ];
                    return !candidates.some(
                        (candidate) => normalizeImageSrcKey(String(candidate || '').trim()) === urlKey,
                    );
                }),
            );
            setImagesReloadKey((key) => key + 1);
            queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
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

    const editorHostApi = useMemo(() => ({
        contractVersion: 1,
        article: {
            id: articleId,
            type: articleType,
            supportsProductGallery: Boolean(supportsProductGallery),
        },
        seo: {
            focusKeyword,
            analysis,
            seoScoringRules,
            seoRuleMessages: scoringMessages,
            loading: seoSummaryLoading,
            analyzing,
            stale: seoStale,
            analyzeError: seoAnalyzeError,
            error: seoSummaryError,
            savedScore: savedSeoScore,
            scoreSource: seoScoreSource,
            onRetry: () => {
                seoSummaryLoadedRef.current = false;
                setSeoSummaryError(null);
                setAnalysis(null);
            },
            onAnalyzeClick: requestAnalyze,
            onViolationAction: handleSeoViolationAction,
            canGenerateFaq,
            canGenerateFeaturedSnippet,
        },
        ai: {
            debug: editorSettings?.ai_debug ?? null,
            canGenerateImage: !sessionReadOnly
                && canMutateEditor()
                && editorSettings?.can_generate_image !== false,
            canGenerateVideo: !sessionReadOnly
                && canMutateEditor()
                && editorSettings?.can_generate_video === true,
        },
        images: {
            reloadKey: imagesReloadKey,
            blocks,
            extraImages: unifiedImageRows,
            featuredImage: featuredHealthSnapshot ?? featuredFromSnapshot(articleId),
            galleryImages: productGalleryItems,
            useUnifiedInventory: true,
            siteId,
            articleId,
            jumpTarget: imagesTabJumpTarget,
            focusKeyword,
            articleTitle,
            onPatchImage: patchImageInBlocks,
            onFocusBlock: focusImageBlock,
            onQuickFixSlugAll: quickFixSlugAllImages,
            quickFixSlugAllBusy,
            onQuickFixSlugOne: quickFixSlugSingleImage,
            onQuickFixAltTitleAll: quickFixAltTitleAllImages,
            onQuickFixAltTitleOne: quickFixAltTitleSingleImage,
            onRemoveImage: removeImageBlock,
            onRemoveSupplementalImage: removeSupplementalImage,
            onAltTitleChange: handleImageAltTitleChange,
            onMakeFeatured: makeImageFeatured,
            onNotify: (payload) => {
                window.dispatchEvent(new CustomEvent('seo-article-editor-notify', { detail: payload }));
            },
        },
        reviews: {
            articleId,
            initialReviews: virtualReviews,
            onRefresh: refreshVirtualReviews,
            loading: reviewsLoading,
            warning: reviewsLoadWarning,
            canQuickCreate: canQuickCreateReviews,
            showConfigureReviews: showConfigureReviewsLink,
            quickCreateConfigUrl: quickCreateReviewsConfigUrl,
            onQuickCreate: canQuickCreateReviews ? generateQuickPostReviews : undefined,
        },
    }), [
        articleType,
        supportsProductGallery,
        focusKeyword,
        analysis,
        seoScoringRules,
        scoringMessages,
        seoSummaryLoading,
        analyzing,
        seoStale,
        seoAnalyzeError,
        savedSeoScore,
        seoScoreSource,
        seoSummaryError,
        requestAnalyze,
        handleSeoViolationAction,
        canGenerateFaq,
        canGenerateFeaturedSnippet,
        imagesReloadKey,
        blocks,
        unifiedImageRows,
        featuredHealthSnapshot,
        productGalleryItems,
        supplementalImages,
        siteId,
        articleId,
        imagesTabJumpTarget,
        articleTitle,
        patchImageInBlocks,
        focusImageBlock,
        quickFixSlugAllImages,
        quickFixSlugAllBusy,
        quickFixSlugSingleImage,
        quickFixAltTitleAllImages,
        quickFixAltTitleSingleImage,
        removeImageBlock,
        removeSupplementalImage,
        handleImageAltTitleChange,
        makeImageFeatured,
        virtualReviews,
        refreshVirtualReviews,
        reviewsLoading,
        reviewsLoadWarning,
        canQuickCreateReviews,
        showConfigureReviewsLink,
        quickCreateReviewsConfigUrl,
        generateQuickPostReviews,
        editorSettings?.ai_debug,
        editorSettings?.can_generate_image,
        editorSettings?.can_generate_video,
        sessionReadOnly,
    ]);

    const editorPanelShells = useMemo(() => ({
        seo: ({ children }) => (
            <ArticleAssistantWidget
                widgetId="seo"
                title="SEO Assistant"
                icon={BarChart3}
                badge={analyzing ? '…' : liveSeoScore}
                defaultCollapsed={false}
                className="seo-assistant-widget--seo"
            >
                {children}
            </ArticleAssistantWidget>
        ),
        images: ({ children }) => (
            <ArticleAssistantWidget
                widgetId="images"
                title="Image Assistant"
                icon={ImageIcon}
                badge={imageTabCount > 0 ? imageTabCount : null}
                defaultCollapsed={false}
                className="seo-assistant-widget--images"
            >
                {children}
            </ArticleAssistantWidget>
        ),
        reviews: ({ children }) => (
            <ArticleAssistantWidget
                widgetId="reviews"
                title={t('reviews_tab_label')}
                icon={Star}
                badge={virtualReviews.length}
                defaultCollapsed
                className="seo-assistant-widget--reviews"
            >
                {children}
            </ArticleAssistantWidget>
        ),
    }), [analyzing, liveSeoScore, imageTabCount, virtualReviews.length]);

    return (
        <div
            className={`seo-article-editor-root${sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ ? ' seo-article-editor-root--hard-readonly' : ''}`}
            data-seo-editor-hard-readonly={sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ ? '1' : '0'}
        >
            <EditorBusyOverlay
                visible={imageRenameBusy || quickFixSlugAllBusy}
                title={
                    quickFixSlugAllBusy
                        ? t('editor_quick_fix_slug_all_busy')
                        : t('editor_renaming_wp_images')
                }
                message={
                    quickFixSlugAllBusy
                        ? t('editor_please_wait')
                        : imageRenameBusyCount > 0
                          ? t('editor_renaming_wp_images_body', { count: imageRenameBusyCount })
                          : t('editor_please_wait')
                }
            />
            <div className="seo-article-editor-workspace">
                <div className="seo-article-editor-left-rail">
                    <ArticleGoogleSerpPreview
                        articleId={articleId}
                        initialPreview={initialSeo?.google_serp_preview ?? {
                            title: String(articleTitle ?? '').trim(),
                            description: String(initialSeo?.meta_description ?? '').trim(),
                            url: '#',
                            display_url: '#',
                        }}
                        fallbackUrl={String(initialSeo?.google_serp_preview?.url ?? initialSeo?.site_domain ?? '#')}
                        skipSeoScore={Boolean(initialSeo?.skip_seo_score)}
                        initialFocusKeyword={String(initialSeo?.focus_keyword ?? '')}
                        initialSlug={String(initialSeo?.article_slug ?? '')}
                        permalinkBase={String(initialSeo?.permalink_base ?? '')}
                        permalinkSuffix={String(initialSeo?.permalink_suffix ?? '')}
                        promptHooks={editorSettings?.prompt_hooks ?? null}
                        articleTitle={articleTitle}
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
                            preferClientSource
                            clientOutline={clientOutline}
                            onClientRefresh={() => {
                                outlineFingerprintRef.current = '';
                                const tree = buildClientOutlineTree(blocksRef.current);
                                outlineFingerprintRef.current = outlineHeadingFingerprint(blocksRef.current);
                                setClientOutline(tree);
                                return tree;
                            }}
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
            <div className="seo-editor-sticky-boundary">
            <div className="seo-editor-toolbar seo-editor-toolbar--document">
                <div className="seo-editor-toolbar__actions">
                    <button
                        type="button"
                        className="seo-history-btn"
                        onClick={() => {
                            if (sessionReadOnly || !canMutateEditor()) {
                                return;
                            }
                            clearTempMerge();
                            commitActiveBlock();
                            undo();
                        }}
                        disabled={sessionReadOnly || !canUndo || !canMutateEditor()}
                        title={sessionReadOnly
                            ? t('editor_locked_mutation_tooltip')
                            : t('editor_undo_with_count', { undo: historySteps.undo, max: historySteps.max })}
                    >
                        <Undo2 size={15} />
                    </button>
                    <button
                        type="button"
                        className="seo-history-btn"
                        onClick={() => {
                            if (sessionReadOnly || !canMutateEditor()) {
                                return;
                            }
                            clearTempMerge();
                            commitActiveBlock();
                            redo();
                        }}
                        disabled={sessionReadOnly || !canRedo || !canMutateEditor()}
                        title={sessionReadOnly
                            ? t('editor_locked_mutation_tooltip')
                            : t('editor_redo_with_count', { redo: historySteps.redo })}
                    >
                        <Redo2 size={15} />
                    </button>
                    <span className="seo-autosave-status seo-autosave-status--toolbar-hidden" aria-hidden="true">
                        {saveLabel}
                    </span>
                    {analyzing ? (
                        <span className="seo-analyze-stale-hint">{t('editor_seo_analyzing')}</span>
                    ) : seoAnalyzeError ? (
                        <button
                            type="button"
                            className="seo-analyze-stale-hint"
                            onClick={requestAnalyze}
                            title={t('editor_seo_analyze_failed')}
                        >
                            {t('editor_seo_analyze_failed')} — {t('editor_seo_analyze_retry')}
                        </button>
                    ) : seoStale ? (
                        <button
                            type="button"
                            className="seo-analyze-stale-hint"
                            onClick={requestAnalyze}
                            title={t('editor_seo_stale')}
                        >
                            {t('editor_seo_stale')}
                        </button>
                    ) : null}
                </div>
            </div>

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
                                                    const editorWritable = !sessionReadOnly
                                                        && !window.__SEO_EDITOR_READ_ONLY__
                                                        && canMutateEditor();
                                                    const withinMove = withinSectionMoveAvailability(visibleBlockIds, block.id);
                                                    const canMoveUpWithinSection = editorWritable && withinMove.canMoveUp;
                                                    const canMoveDownWithinSection = editorWritable && withinMove.canMoveDown;
                                                    const handleMovePrevSection = () => moveBlockToSection(block.id, 'prev');
                                                    const handleMoveNextSection = () => moveBlockToSection(block.id, 'next');
                                                    const handleMoveUpWithinSection = () => {
                                                        executeEditorCommand('move_block_within_section', {
                                                            sectionId: section.id,
                                                            blockId: block.id,
                                                            direction: 'up',
                                                        }, { notifyOnFailure: false });
                                                    };
                                                    const handleMoveDownWithinSection = () => {
                                                        executeEditorCommand('move_block_within_section', {
                                                            sectionId: section.id,
                                                            blockId: block.id,
                                                            direction: 'down',
                                                        }, { notifyOnFailure: false });
                                                    };

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
                                                                        canMoveUpWithinSection={canMoveUpWithinSection}
                                                                        canMoveDownWithinSection={canMoveDownWithinSection}
                                                                        onMovePrevSection={handleMovePrevSection}
                                                                        onMoveNextSection={handleMoveNextSection}
                                                                        onMoveUpWithinSection={handleMoveUpWithinSection}
                                                                        onMoveDownWithinSection={handleMoveDownWithinSection}
                                                                    />
                                                                    {insertMenu?.blockId === block.id &&
                                                                    insertMenu?.position === 'before' ? (
                                                                        <BlockInsertMenuBar
                                                                            faqShortcodeDisabled={articleHasFaqShortcode(blocks)}
                                                                            imageInsertDisabled={section.isIntro}
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
                                                                sectionId={section.id}
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
                                                                faqCount={faqCount}
                                                                canGenerateFaq={canGenerateFaq}
                                                                onEditFaq={openFaqModule}
                                                                onCreateFaq={createFaqFromShortcode}
                                                                outlineHeadingsLocked={
                                                                    sectionHeadingBlockIds.has(block.id) ||
                                                                    (outlineHasSavedHeadings &&
                                                                        blockHasOutlineHeading(block))
                                                                }
                                                                isSectionHeadingBlock={sectionHeadingBlockIds.has(block.id)}
                                                                onOutlineHeadingCommand={handleOutlineHeadingFromEditor}
                                                                onArmOutsideClickGuard={armBlockOutsideClickGuard}
                                                                onDelete={() => deleteBlock(block.id)}
                                                                canDeleteBlock={blocks.length > 1}
                                                                editable={!sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__}
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
                                                                        canMoveUpWithinSection={canMoveUpWithinSection}
                                                                        canMoveDownWithinSection={canMoveDownWithinSection}
                                                                        onMovePrevSection={handleMovePrevSection}
                                                                        onMoveNextSection={handleMoveNextSection}
                                                                        onMoveUpWithinSection={handleMoveUpWithinSection}
                                                                        onMoveDownWithinSection={handleMoveDownWithinSection}
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
            </div>
            <GenerateImageModal
                open={generateImageModalOpen}
                onClose={() => setGenerateImageModalOpen(false)}
                onSubmit={submitGenerateImageFromModal}
                initialPrompt={generateImageModalPrompt}
                initialLoaiSanPhamCustom={generateImageModalInitialCustom}
                mode={generateImageModalTarget === 'product-gallery' ? 'product-gallery' : 'editor'}
                productCategoryOptions={productCategoryOptions}
                articleId={articleId}
                siteId={siteId}
                productGalleryItems={productGalleryItems}
                canaryProduct={isCanaryProduct}
            />
            <FeaturedSnippetPromptModal
                open={featuredSnippetPromptOpen}
                canGenerate={canGenerateFeaturedSnippet}
                generating={featuredSnippetGenerating}
                previewHtml={featuredSnippetPreviewHtml}
                context={featuredSnippetPromptContext}
                onClose={() => {
                    setFeaturedSnippetPromptOpen(false);
                    if (featuredSnippetTargetRef.current?.mode === 'prompt-preview'
                        || featuredSnippetTargetRef.current?.mode === 'prompt-insert') {
                        featuredSnippetTargetRef.current = null;
                    }
                }}
                onGenerate={() => {
                    void runFeaturedSnippetPromptGenerate();
                }}
                onConfirmInsert={confirmFeaturedSnippetPromptInsert}
            />
                </div>
            </div>

            <EditorHostApiProvider value={editorHostApi}>
                {sidebarNavRoot ? (
                    <EditorSidebarNavigation
                        runtime={getDefaultArticleEditorRuntime()}
                        rootEl={sidebarNavRoot}
                        contextRevision={runtimeContextRevision}
                        shellItems={SHELL_BOUNDARY_NAV_ITEMS}
                    />
                ) : null}
                <EditorSidebarPortalHost
                    runtime={getDefaultArticleEditorRuntime()}
                    activePanelId={activeHeavyModule}
                    portalRoots={assistantPortalRoots}
                    shells={editorPanelShells}
                    articleId={articleId}
                    siteId={siteId}
                    isPanelAllowed={(panelId) => {
                        if (panelId === 'reviews') {
                            return Boolean(isProductPost && showReviewsTab);
                        }
                        return true;
                    }}
                />
                {mediaPickerRoot ? (
                    <SharedMediaPicker
                        articleId={articleId}
                        rootEl={mediaPickerRoot}
                        wordpressAvailable={Boolean(editorSettings?.wordpress_connected ?? true)}
                        articleDomain={siteDomainRef.current || siteDomain}
                    />
                ) : null}
            </EditorHostApiProvider>

            {draftChoiceModalOpen && draftRestoreOffer ? (
                <div className="seo-draft-restore-overlay" role="dialog" aria-modal="true">
                    <div className="seo-draft-restore-modal">
                        <h3 className="seo-draft-restore-modal__title">
                            {t('editor_draft_restore_title')}
                        </h3>
                        <p className="seo-draft-restore-modal__body">
                            {t('editor_draft_restore_body')}
                        </p>
                        <div className="seo-draft-restore-modal__actions">
                            <button
                                type="button"
                                className="seo-draft-restore-modal__btn seo-draft-restore-modal__btn--primary"
                                onClick={applyDraftRestore}
                            >
                                {t('editor_draft_restore_action_restore')}
                            </button>
                            <button
                                type="button"
                                className="seo-draft-restore-modal__btn"
                                onClick={keepServerOverDraft}
                            >
                                {t('editor_draft_restore_action_keep_server')}
                            </button>
                            <button
                                type="button"
                                className="seo-draft-restore-modal__btn seo-draft-restore-modal__btn--danger"
                                onClick={discardDraftRestore}
                            >
                                {t('editor_draft_restore_action_discard')}
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
