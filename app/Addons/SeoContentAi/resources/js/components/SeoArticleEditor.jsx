import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import BlockFormatToolbar from './BlockFormatToolbar';
import { BlockInsertBar, BlockInsertMenuBar } from './BlockInsertMenu';
import BlockEditorResizeHandle, { useBlockEditorHeight } from './BlockEditorResizeHandle';
import LinkEditBubble from './LinkEditBubble';
import ImageBlockEditor from './ImageBlockEditor';
import {
    countMatchingAnchorsInHtml,
    countPlainTextInHtml,
    findBlockIdForExportOffset,
    scrollToFaqByIndex,
    scrollToFaqKeyword,
    scrollToKeywordAnchor,
    scrollToPlainTextInBlock,
} from '../utils/articleLinkScroll';
import { wrapFirstPlainTextWithLink } from '../utils/articleLinkInsert';
import SeoScorePanel from './SeoScorePanel';
import OutlineMarkdownPanel from './OutlineMarkdownPanel';
import ArticleImagesTab from './ArticleImagesTab';
import EditorBusyOverlay from './EditorBusyOverlay';
import {
    applyImagePatchToBlocks,
    applyQuickFixMetaToBlocks,
    finalizeBlocksAfterWpRename,
    enrichBlocksWithPostImages,
} from '../utils/articleImagesUtils';
import {
    confirmSlugRename,
    dispatchWordPressSlugRename,
} from '../utils/imageSlugRenameConfirm';
import { renameSeoMedia } from '../utils/seoMediaApi';
import { articleEditorExtensions } from '../utils/editorExtensions';
import { createClipboardPasteHandler } from '../utils/seoMediaApi';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { useArticleEditorHistory } from '../hooks/useArticleEditorHistory';
import { loadDraft, saveDraft } from '../utils/articleEditorStorage';
import {
    htmlToPlainText,
    isWordPressImageElement,
    normalizeBlocks,
    parseImageFromBlockContent,
    renderImageFigure,
} from '../utils/blockImageUtils';
import { coalesceTiptapExportHtml, FAQ_SHORTCODE_HTML, flattenHtmlBodyNodes, isFaqPlaceholderHtml } from '../utils/editorHtmlUtils';
import {
    SEO_EDITOR_LINK_MARK_CLASS,
    SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS,
    stripEditorTransientMarkup,
} from '../utils/articleEditorTransientMarkup';
import FaqAccordionPreview from './FaqAccordionPreview';
import { Undo2, Redo2 } from 'lucide-react';
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

const articleHasFaqShortcode = (blocks) =>
    blocks.some((block) => isFaqPlaceholderHtml(block.content || ''));

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
                const image = parseImageFromBlockContent(content);
                chunks.push({
                    id: newBlockId('image'),
                    type: 'image',
                    isWp: false,
                    prefix: '',
                    content: image ? renderImageFigure(image) : content,
                    suffix: '',
                    image: image ?? undefined,
                });
                return;
            }

            const tempDiv = document.createElement('div');
            tempDiv.appendChild(node.cloneNode(true));
            chunks.push({
                id: newBlockId('classic'),
                type: 'text',
                isWp: false,
                prefix: '',
                content: tempDiv.innerHTML.trim(),
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

    return normalizeBlocks(blocks);
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
            return typeof part === 'string' ? stripEditorTransientMarkup(part) : part;
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

function ActiveBlockEditor({
    block,
    displayContent,
    suppressBlockUpdate,
    onUpdate,
    onRegisterFlush,
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
            onUpdate(coalesceTiptapExportHtml(sourceHtml, html));
        },
        [suppressBlockUpdate, onUpdate, sourceHtml],
    );

    const clipboardPasteHandler = useCallback(
        createClipboardPasteHandler({ articleId, siteId }),
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
                'data-placeholder': 'Nhập nội dung…',
            },
            handlePaste: clipboardPasteHandler,
        },
    });

    useEffect(() => {
        if (!editor) return;

        isHydratingRef.current = true;
        editor.commands.setContent(sourceHtml || '<p></p>', { emitUpdate: false });
        isHydratingRef.current = false;
    }, [editor, block.id, sourceHtml]);

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
                {suppressBlockUpdate ? 'Gộp tạm (Shift+Click)' : block.isWp ? 'WP Block' : 'Đoạn văn'}
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
    setGlobalEditor,
    onDelete,
    canDeleteBlock,
    articleId,
    siteId,
    panelFaqs,
}) {
    const blockHtml = displayContent ?? block.content;
    const isFaqShortcodeBlock = block.type === 'text' && isFaqPlaceholderHtml(blockHtml);

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
            <div
                className={`seo-faq-shortcode-block${isActive ? ' is-active' : ''}`}
                onClick={onActivate}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        onActivate();
                    }
                }}
                role="button"
                tabIndex={0}
                title="Khối shortcode FAQ — chỉnh nội dung tại panel FAQ bên dưới"
            >
                <FaqAccordionPreview faqs={panelFaqs} />
            </div>
        );
    }

    if (!isActive) {
        return (
            <div
                className="seo-block-preview seo-wp-content p-3 -mx-1 rounded border border-transparent hover:border-gray-200 dark:hover:border-slate-600 hover:bg-gray-50/80 dark:hover:bg-slate-800/40 transition-all cursor-text prose prose-slate max-w-none dark:prose-invert"
                dangerouslySetInnerHTML={{
                    __html: block.content || '<p class="text-gray-400 italic">Click để chỉnh sửa…</p>',
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
                        ? 'Click để sửa · Shift+Click để gộp tạm các đoạn trong khoảng'
                        : 'Click để chỉnh sửa đoạn văn này'
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
            setGlobalEditor={setGlobalEditor}
            onDelete={onDelete}
            canDeleteBlock={canDeleteBlock}
        />
    );
}

const TABS = [
    { id: 'editor', label: 'Editor' },
    { id: 'images', label: 'Hình ảnh' },
    { id: 'outline', label: 'Dàn ý' },
    { id: 'seo', label: 'SEO point' },
];

export default function SeoArticleEditor({
    articleId,
    siteId = null,
    initialHtml,
    initialOutline = '',
    initialSeo,
    initialPostImages = [],
    initialFaqs = [],
    articleTitle = '',
    editorSettings = {},
}) {
    const historyStep = editorSettings?.history_step ?? 20;

    const [blocks, setBlocks] = useState([]);
    const [activeBlockId, setActiveBlockId] = useState(null);
    const [tempMerge, setTempMerge] = useState(null);
    const [globalEditor, setGlobalEditor] = useState(null);
    const [activeTab, setActiveTab] = useState('editor');
    const [saveStatus, setSaveStatus] = useState('saved');
    const [analyzing, setAnalyzing] = useState(false);
    const [imageRenameBusy, setImageRenameBusy] = useState(false);
    const [imageRenameBusyCount, setImageRenameBusyCount] = useState(0);
    const [imagesReloadKey, setImagesReloadKey] = useState(0);
    const [insertMenu, setInsertMenu] = useState(null);
    const [panelFaqs, setPanelFaqs] = useState(Array.isArray(initialFaqs) ? initialFaqs : []);
    const pendingQuickFixKeywordRef = useRef('');

    const [focusKeyword, setFocusKeyword] = useState(initialSeo?.focus_keyword ?? null);
    const [analysis, setAnalysis] = useState(initialSeo?.analysis ?? null);
    const [contentBonus, setContentBonus] = useState(
        initialSeo?.content_bonus ?? initialSeo?.analysis?.content_bonus ?? null,
    );
    const [extractedLinks, setExtractedLinks] = useState(
        initialSeo?.extracted_links ?? { internal: [], external: [] },
    );
    const [suggestedInternalLinks, setSuggestedInternalLinks] = useState(
        initialSeo?.suggested_internal_links ?? [],
    );

    const enrichLinksWithOccurrences = useCallback((links) => {
        const source = links && typeof links === 'object' ? links : { internal: [], external: [] };
        const currentBlocks = blocksRef.current;

        const buildKey = (item) =>
            `${String(item?.href ?? '').trim()}\u0000${String(item?.text ?? '').trim()}`;

        const countCache = new Map();
        const withCounts = (items) =>
            (Array.isArray(items) ? items : []).map((item) => {
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
                    countCache.set(key, Math.max(1, count));
                }

                return {
                    ...item,
                    occurrence_count: countCache.get(key) ?? 1,
                };
            });

        return {
            internal: withCounts(source.internal),
            external: withCounts(source.external),
        };
    }, []);

    const publishExtractedLinks = useCallback((links, suggestedInternal = suggestedInternalLinks) => {
        const enrichedLinks = enrichLinksWithOccurrences(links);
        window.dispatchEvent(
            new CustomEvent('seo-editor-links-updated', {
                detail: {
                    links: enrichedLinks,
                    suggested_internal: Array.isArray(suggestedInternal) ? suggestedInternal : [],
                },
            }),
        );
    }, [suggestedInternalLinks, enrichLinksWithOccurrences]);

    const blocksRef = useRef(blocks);
    blocksRef.current = blocks;
    const tempMergeRef = useRef(tempMerge);
    tempMergeRef.current = tempMerge;
    const blockFlushRef = useRef(null);
    const activeBlockIdRef = useRef(null);
    const linkScrollTokenRef = useRef(0);
    const intraSelectionRef = useRef({ text: '', html: '' });

    useEffect(() => {
        activeBlockIdRef.current = activeBlockId;
    }, [activeBlockId]);

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
    }, 700);

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
        if (!articleId) return;
        if (loadedArticleIdRef.current === articleId) return;

        loadedArticleIdRef.current = articleId;
        skipNextAutosave.current = true;
        clearTempMerge();

        const draft = loadDraft(articleId);
        let parsed = [];
        if (draft?.blocks?.length) {
            parsed = normalizeBlocks(draft.blocks);
        } else if (draft?.html) {
            parsed = parseHtmlToBlocks(draft.html);
        } else {
            parsed = parseHtmlToBlocks(initialHtml);
        }
        setBlocks(enrichBlocksWithPostImages(parsed, initialPostImages));

        setActiveBlockId(null);
        setGlobalEditor(null);
    }, [articleId, initialHtml, initialPostImages, clearTempMerge]);

    useEffect(() => {
        if (initialSeo) {
            setFocusKeyword(initialSeo.focus_keyword ?? null);
            setAnalysis(initialSeo.analysis ?? null);
            setContentBonus(initialSeo.content_bonus ?? initialSeo.analysis?.content_bonus ?? null);
            setExtractedLinks(initialSeo.extracted_links ?? { internal: [], external: [] });
            setSuggestedInternalLinks(initialSeo.suggested_internal_links ?? []);
        }
    }, [initialSeo]);

    const updateBlockContent = useCallback((id, newContent, imageData) => {
        setBlocks((prev) =>
            prev.map((b) =>
                b.id === id
                    ? {
                          ...b,
                          content: newContent,
                          ...(imageData ? { image: imageData } : {}),
                      }
                    : b,
            ),
        );
    }, []);

    const registerBlockFlush = useCallback((fn) => {
        blockFlushRef.current = fn;
    }, []);

    const commitActiveBlock = useCallback(() => {
        if (tempMergeRef.current) return;
        blockFlushRef.current?.();
    }, []);

    const patchImageInBlocks = useCallback((blockId, patch) => {
        setBlocks((prev) => applyImagePatchToBlocks(prev, blockId, patch));
    }, []);

    const requestWordPressRenames = useCallback((items) => {
        dispatchWordPressSlugRename(items);
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
                                    title: 'Không đổi được slug ảnh',
                                    body: error?.message ?? 'Thử lại sau.',
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
        [requestWordPressRenames],
    );

    const quickFixAllImages = useCallback(() => {
        const keyword = (focusKeyword || articleTitle || '').trim();
        if (!keyword) {
            return;
        }

        const current = blocksRef.current;
        const preview = applyQuickFixMetaToBlocks(current, keyword);
        const renameCount = preview.renameQueue.length;

        if (renameCount > 0 && !confirmSlugRename({ count: renameCount, isQuickFix: true })) {
            return;
        }

        if (renameCount === 0 && !window.confirm('Áp dụng alt/title từ khóa cho tất cả ảnh trong bài?')) {
            return;
        }

        setBlocks(preview.blocks);
        pendingQuickFixKeywordRef.current = keyword;

        if (renameCount > 0) {
            requestWordPressRenames(preview.renameQueue);
        } else {
            setImagesReloadKey((k) => k + 1);
        }
    }, [focusKeyword, articleTitle, requestWordPressRenames]);

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
                        title: 'Không tìm thấy bảng',
                        body: 'Nội dung hiện tại chưa có bảng để nhảy tới.',
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

            if (needsBlockSwitch) {
                window.setTimeout(runScroll, 60);
            } else {
                runScroll();
            }
        },
        [clearTempMerge, commitActiveBlock],
    );

    const insertSuggestedLinkIntoContent = useCallback(
        (detail) => {
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            if (!text || !href) {
                return;
            }

            commitActiveBlock();
            setActiveTab('editor');

            const currentBlocks = blocksRef.current;
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
                    new CustomEvent('seo-editor-suggested-link-inserted', {
                        detail: { text, href },
                    }),
                );
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Đã chèn link nội bộ',
                            body: `«${text}»`,
                            status: 'success',
                        },
                    }),
                );

                return;
            }

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Không tìm thấy từ khóa',
                        body: `Không tìm thấy «${text}» (chưa có link) trong nội dung.`,
                        status: 'warning',
                    },
                }),
            );
        },
        [commitActiveBlock, updateBlockContent, requestAnalyze],
    );

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

        const onScrollToFeaturedSnippetTable = () => {
            scrollToFeaturedSnippetTable();
        };

        window.addEventListener('seo-editor-scroll-to-link', onScrollToLink);
        window.addEventListener('seo-editor-insert-suggested-link', onInsertSuggestedLink);
        window.addEventListener('seo-editor-scroll-to-featured-snippet-table', onScrollToFeaturedSnippetTable);

        return () => {
            window.removeEventListener('seo-editor-scroll-to-link', onScrollToLink);
            window.removeEventListener('seo-editor-insert-suggested-link', onInsertSuggestedLink);
            window.removeEventListener('seo-editor-scroll-to-featured-snippet-table', onScrollToFeaturedSnippetTable);
        };
    }, [scrollToExtractedLink, insertSuggestedLinkIntoContent, scrollToFeaturedSnippetTable]);

    const deleteBlock = useCallback(
        (id) => {
            if (blocksRef.current.length <= 1) return;

            const block = blocksRef.current.find((b) => b.id === id);
            if (!block) return;

            if (block.isWp && !window.confirm('Xóa khối WordPress này khỏi bài viết?')) {
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

            dispatchActiveBlockContext(articleId, text, html, true, activeBlockId);
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

    const activateBlock = useCallback(
        (id) => {
            setInsertMenu(null);
            if (tempMergeRef.current) {
                clearTempMerge();
                setGlobalEditor(null);
                setActiveBlockId(id);
                return;
            }
            if (id === activeBlockId) return;
            commitActiveBlock();
            setActiveBlockId(id);
            setGlobalEditor(null);
        },
        [activeBlockId, commitActiveBlock, clearTempMerge],
    );

    const insertBlockRelative = useCallback(
        (refBlockId, position, type) => {
            if (tempMergeRef.current) return;

            if (type === 'faq' && articleHasFaqShortcode(blocksRef.current)) {
                setInsertMenu(null);
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Đã có shortcode FAQ',
                            body: 'Bài viết chỉ nên có một [omi_faq].',
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
                return normalizeBlocks(next);
            });

            setInsertMenu(null);
            setActiveBlockId(newId);
            setGlobalEditor(null);
        },
        [commitActiveBlock],
    );

    const insertImageAfterBlock = useCallback(
        (refBlockId, imageUrl) => {
            const url = (imageUrl ?? '').trim();
            if (!refBlockId || !url) {
                return;
            }
            if (tempMergeRef.current) {
                return;
            }

            commitActiveBlock();

            const image = {
                src: url,
                alt: '',
                title: '',
            };
            const html = renderImageFigure(image);
            const newBlock = {
                ...createEmptyImageBlock(),
                content: html,
                image,
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

    const moveBlock = useCallback(
        (blockId, direction) => {
            if (tempMergeRef.current) {
                return;
            }

            commitActiveBlock();
            setInsertMenu(null);

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === blockId);
                if (index < 0) {
                    return prev;
                }

                const targetIndex = direction === 'up' ? index - 1 : index + 1;
                if (targetIndex < 0 || targetIndex >= prev.length) {
                    return prev;
                }

                const next = [...prev];
                [next[index], next[targetIndex]] = [next[targetIndex], next[index]];
                return normalizeBlocks(next);
            });
        },
        [commitActiveBlock],
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
            const html = (event.detail?.editorHtml ?? '').trim();
            if (!html) {
                return;
            }

            skipNextAutosave.current = true;
            clearTempMerge();
            blockFlushRef.current = null;
            setActiveBlockId(null);
            setGlobalEditor(null);
            setBlocks(enrichBlocksWithPostImages(parseHtmlToBlocks(html), initialPostImages));
            saveDraft(articleId, { blocks: parseHtmlToBlocks(html), html });
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
                setExtractedLinks(result.extracted_links);
                setSuggestedInternalLinks(suggested);
                publishExtractedLinks(result.extracted_links, suggested);
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
                e.target.closest('.seo-fmt-dropdown-menu') ||
                e.target.closest('.seo-block-insert-bar') ||
                e.target.closest('.seo-block-insert-trigger') ||
                e.target.closest('.seo-block-insert-menu') ||
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

        window.addEventListener('seo-editor-analyze-result', handleAnalyzeResult);
        document.addEventListener('mousedown', handleClickOutside);

        const onImageGenerateRequest = (event) => {
            const blockId = event.detail?.blockId;
            const prompt = (event.detail?.prompt ?? '').trim();
            if (!blockId || !prompt) return;

            setBlocks((prev) =>
                prev.map((b) =>
                    b.id === blockId
                        ? {
                              ...b,
                              pendingImagePrompt: prompt,
                          }
                        : b,
                ),
            );

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Đã lưu mô tả ảnh',
                        body: 'Mô tả đã lưu trên bài. Tạo ảnh AI sẽ kết nối workflow sau.',
                        status: 'success',
                    },
                }),
            );
        };

        window.addEventListener('seo-editor-image-generate-request', onImageGenerateRequest);

        const onEditorBlockImageSelected = (event) => {
            const blockId = event.detail?.blockId;
            const url = (event.detail?.url ?? '').trim();
            const attachmentId = Number(event.detail?.attachmentId ?? 0);
            if (!blockId || !url) return;

            const alt = (event.detail?.alt ?? '').trim();
            const slug = (event.detail?.slug ?? '').trim();
            const seoMediaId = Number(event.detail?.seoMediaId ?? event.detail?.id ?? 0);
            const image = {
                src: url,
                alt,
                title: alt,
                wpAttachmentId: attachmentId > 0 ? attachmentId : undefined,
                seoMediaId: seoMediaId > 0 ? seoMediaId : undefined,
                slug: slug || undefined,
            };
            const html = renderImageFigure(image);
            updateBlockContent(blockId, html, image);
        };

        window.addEventListener('editor-block-image-selected', onEditorBlockImageSelected);

        const onArticleAiImageGenerated = (event) => {
            const blockId = (event.detail?.activeBlockId ?? '').trim();
            const url = (event.detail?.url ?? '').trim();
            if (!blockId || !url) {
                return;
            }
            insertImageAfterBlock(blockId, url);
        };

        const onArticleAiVideoGenerated = (event) => {
            const blockId = (event.detail?.activeBlockId ?? '').trim();
            const url = (event.detail?.url ?? '').trim();
            if (!blockId || !url) {
                return;
            }
            insertVideoAfterBlock(blockId, url);
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
            window.removeEventListener('article-faqs-extracted', syncPanelFaqs);
            window.removeEventListener('seo-editor-faqs-updated', syncPanelFaqsFromEditor);
            window.removeEventListener('seo-editor-analyze-result', handleAnalyzeResult);
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, [
        activeBlockId,
        globalEditor,
        updateBlockContent,
        clearTempMerge,
        articleId,
        getExportHtml,
        initialPostImages,
        insertImageAfterBlock,
        insertVideoAfterBlock,
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

    const saveLabel =
        saveStatus === 'saving'
            ? 'Đang lưu nháp…'
            : saveStatus === 'pending'
              ? 'Sẽ lưu nháp…'
              : 'Đã lưu nháp (cục bộ)';

    const mergedDisplay =
        tempMerge && activeBlockId === tempMerge.anchorId ? tempMerge.mergedHtml : undefined;

    const switchTab = useCallback(
        (tabId) => {
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

    return (
        <div className="seo-article-editor-root">
            <EditorBusyOverlay
                visible={imageRenameBusy}
                title="Đang đổi tên ảnh trên WordPress"
                message={
                    imageRenameBusyCount > 0
                        ? `Đang xử lý ${imageRenameBusyCount} ảnh và cập nhật URL trong các bài viết…`
                        : 'Vui lòng đợi, không đóng trang.'
                }
            />
            <div className="seo-editor-tabs">
                {TABS.map((tab) => (
                    <button
                        key={tab.id}
                        type="button"
                        className={`seo-editor-tab ${activeTab === tab.id ? 'is-active' : ''}`}
                        onClick={() => switchTab(tab.id)}
                    >
                        {tab.label}
                        {tab.id === 'images' ? (
                            <span className="seo-editor-tab-badge">
                                {blocks.filter((b) => b.type === 'image').length}
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
                        title={`Hoàn tác (${historySteps.undo}/${historySteps.max})`}
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
                        title={`Làm lại (${historySteps.redo})`}
                    >
                        <Redo2 size={15} />
                    </button>
                    <span className="seo-autosave-status">{saveLabel}</span>
                </div>
            </div>

            {activeTab === 'editor' ? (
                <div className="editor-container">
                    <div className="max-w-none space-y-3">
                        {blocks.length === 0 ? (
                            <p className="text-gray-400 text-center py-10 italic text-sm">
                                Đang tải nội dung bài viết…
                            </p>
                        ) : (
                            blocks.map((block, blockIndex) => {
                                const isActive = activeBlockId === block.id;
                                const showInsert = isActive && !tempMerge;
                                const canMoveUp = blockIndex > 0;
                                const canMoveDown = blockIndex < blocks.length - 1;
                                const handleMoveUp = () => moveBlock(block.id, 'up');
                                const handleMoveDown = () => moveBlock(block.id, 'down');
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
                                                    canMoveUp={canMoveUp}
                                                    canMoveDown={canMoveDown}
                                                    onMoveUp={handleMoveUp}
                                                    onMoveDown={handleMoveDown}
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
                                            setGlobalEditor={setGlobalEditor}
                                            panelFaqs={panelFaqs}
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
                                                    canMoveUp={canMoveUp}
                                                    canMoveDown={canMoveDown}
                                                    onMoveUp={handleMoveUp}
                                                    onMoveDown={handleMoveDown}
                                                />
                                                {insertMenu?.blockId === block.id &&
                                                insertMenu?.position === 'after' ? (
                                                    <BlockInsertMenuBar
                                                        faqShortcodeDisabled={articleHasFaqShortcode(blocks)}
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
                            })
                        )}
                    </div>
                </div>
            ) : activeTab === 'images' ? (
                <ArticleImagesTab
                    key={imagesReloadKey}
                    blocks={blocks}
                    siteId={siteId}
                    articleId={articleId}
                    focusKeyword={focusKeyword}
                    articleTitle={articleTitle}
                    onPatchImage={patchImageInBlocks}
                    onSlugChange={handleImageSlugChange}
                    onFocusBlock={focusImageBlock}
                    onQuickFixAll={quickFixAllImages}
                    onNotify={(payload) => {
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', { detail: payload }),
                        );
                    }}
                />
            ) : activeTab === 'outline' ? (
                <div className="seo-tab-panel seo-outline-tab">
                    <OutlineMarkdownPanel articleId={articleId} initialOutline={initialOutline} />
                </div>
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
        </div>
    );
}
