import React, { useEffect, useRef, useState } from 'react';
import {
    Bold,
    Italic,
    Underline,
    Strikethrough,
    List,
    ListOrdered,
    Quote,
    Code,
    AlignLeft,
    AlignCenter,
    AlignRight,
    AlignJustify,
    Link2,
    Unlink,
    Minus,
    Undo2,
    Redo2,
    RemoveFormatting,
    Highlighter,
    Subscript,
    Superscript,
    Table,
    Trash2,
    ListTree,
    Smile,
    ChevronDown,
} from 'lucide-react';
import ParagraphStyleDropdown from './ParagraphStyleDropdown';
import EmojiPickerModal from './EmojiPickerModal';
import { t } from '../utils/i18n';

const ICON_SIZE = 16;

function ToolbarButton({ onClick, onMouseDown, isActive = false, disabled = false, children, title }) {
    return (
        <button
            type="button"
            onClick={onClick}
            onMouseDown={onMouseDown}
            disabled={disabled}
            title={title}
            className={`seo-toolbar-btn${isActive ? ' is-active' : ''}${disabled ? ' is-disabled' : ''}`}
        >
            {children}
        </button>
    );
}

function ToolbarGroup({ children, className = '' }) {
    return <div className={`seo-toolbar-group${className ? ` ${className}` : ''}`}>{children}</div>;
}

function InsertActionButton({ onClick, onMouseDown, title, children, label }) {
    return (
        <button
            type="button"
            onClick={onClick}
            onMouseDown={onMouseDown}
            title={title}
            className="seo-insert-toolbar-btn"
        >
            {children}
            {label ? <span className="seo-insert-toolbar-btn__label">{label}</span> : null}
        </button>
    );
}

export default function BlockFormatToolbar({ editor, onDelete, canDelete = true, onEditLink }) {
    const [emojiPickerOpen, setEmojiPickerOpen] = useState(false);
    const [overflowOpen, setOverflowOpen] = useState(false);
    /** Giữ vị trí con trỏ trước khi modal (portal) lấy focus. */
    const savedSelectionRef = useRef(null);
    const overflowRef = useRef(null);

    useEffect(() => {
        if (!overflowOpen) {
            return undefined;
        }

        const onDocMouseDown = (event) => {
            if (overflowRef.current?.contains(event.target)) {
                return;
            }
            setOverflowOpen(false);
        };

        document.addEventListener('mousedown', onDocMouseDown);
        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, [overflowOpen]);

    if (!editor) return null;

    const openLinkEditor = () => {
        const { from, to } = editor.state.selection;
        const savedSelection = { from, to };
        if (onEditLink) {
            onEditLink(savedSelection);
        }
    };

    const openEmojiPicker = () => {
        const { from, to } = editor.state.selection;
        savedSelectionRef.current = { from, to };
        setEmojiPickerOpen(true);
    };

    const closeEmojiPicker = () => {
        setEmojiPickerOpen(false);
        savedSelectionRef.current = null;
    };

    const insertEmoji = (emoji) => {
        const saved = savedSelectionRef.current;
        const docSize = editor.state.doc.content.size;

        let chain = editor.chain().focus();

        if (saved && typeof saved.from === 'number') {
            const from = Math.min(Math.max(0, saved.from), docSize);
            const to = Math.min(Math.max(from, saved.to), docSize);
            chain = chain.setTextSelection({ from, to });
        }

        chain.insertContent(emoji).run();
        savedSelectionRef.current = null;
        setEmojiPickerOpen(false);
    };

    return (
        <div className="seo-block-toolbar seo-block-toolbar-rich" onMouseDown={(e) => e.preventDefault()}>
            {/* Format toolbar — adjacent to editable content */}
            <div className="seo-toolbar-row seo-toolbar-row--format" role="toolbar" aria-label={t('toolbar_format_aria')}>
                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().undo().run()}
                        disabled={!editor.can().undo()}
                        title={t('toolbar_undo')}
                    >
                        <Undo2 size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().redo().run()}
                        disabled={!editor.can().redo()}
                        title={t('toolbar_redo')}
                    >
                        <Redo2 size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <ToolbarGroup>
                    <ParagraphStyleDropdown editor={editor} />
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleBold().run()}
                        isActive={editor.isActive('bold')}
                        title={t('toolbar_bold')}
                    >
                        <Bold size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleItalic().run()}
                        isActive={editor.isActive('italic')}
                        title={t('toolbar_italic')}
                    >
                        <Italic size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleUnderline().run()}
                        isActive={editor.isActive('underline')}
                        title={t('toolbar_underline')}
                    >
                        <Underline size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleStrike().run()}
                        isActive={editor.isActive('strike')}
                        title={t('toolbar_strikethrough')}
                    >
                        <Strikethrough size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleBulletList().run()}
                        isActive={editor.isActive('bulletList')}
                        title={t('toolbar_bullet_list')}
                    >
                        <List size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleOrderedList().run()}
                        isActive={editor.isActive('orderedList')}
                        title={t('toolbar_ordered_list')}
                    >
                        <ListOrdered size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().setTextAlign('left').run()}
                        isActive={editor.isActive({ textAlign: 'left' })}
                        title={t('toolbar_align_left')}
                    >
                        <AlignLeft size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().setTextAlign('center').run()}
                        isActive={editor.isActive({ textAlign: 'center' })}
                        title={t('toolbar_align_center')}
                    >
                        <AlignCenter size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().setTextAlign('right').run()}
                        isActive={editor.isActive({ textAlign: 'right' })}
                        title={t('toolbar_align_right')}
                    >
                        <AlignRight size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().setTextAlign('justify').run()}
                        isActive={editor.isActive({ textAlign: 'justify' })}
                        title={t('toolbar_align_justify')}
                    >
                        <AlignJustify size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={openLinkEditor}
                        isActive={editor.isActive('link')}
                        title={t('toolbar_insert_edit_link')}
                    >
                        <Link2 size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().unsetLink().run()}
                        disabled={!editor.isActive('link')}
                        title={t('toolbar_unlink')}
                    >
                        <Unlink size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <ToolbarGroup className="seo-toolbar-group--overflow">
                    <div className="seo-toolbar-overflow" ref={overflowRef}>
                        <ToolbarButton
                            onClick={() => setOverflowOpen((v) => !v)}
                            isActive={overflowOpen}
                            title={t('toolbar_more_format')}
                        >
                            <ChevronDown size={ICON_SIZE} />
                        </ToolbarButton>
                        {overflowOpen ? (
                            <div
                                className="seo-toolbar-overflow__menu"
                                onMouseDown={(e) => e.stopPropagation()}
                            >
                                <ToolbarButton
                                    onClick={() => {
                                        editor.chain().focus().toggleHighlight().run();
                                        setOverflowOpen(false);
                                    }}
                                    isActive={editor.isActive('highlight')}
                                    title={t('toolbar_highlight')}
                                >
                                    <Highlighter size={ICON_SIZE} />
                                </ToolbarButton>
                                <ToolbarButton
                                    onClick={() => {
                                        editor.chain().focus().toggleSubscript().run();
                                        setOverflowOpen(false);
                                    }}
                                    isActive={editor.isActive('subscript')}
                                    title={t('toolbar_subscript')}
                                >
                                    <Subscript size={ICON_SIZE} />
                                </ToolbarButton>
                                <ToolbarButton
                                    onClick={() => {
                                        editor.chain().focus().toggleSuperscript().run();
                                        setOverflowOpen(false);
                                    }}
                                    isActive={editor.isActive('superscript')}
                                    title={t('toolbar_superscript')}
                                >
                                    <Superscript size={ICON_SIZE} />
                                </ToolbarButton>
                                <label className="seo-toolbar-color-wrap" title={t('toolbar_text_color')}>
                                    <input
                                        type="color"
                                        className="seo-toolbar-color"
                                        onChange={(e) => {
                                            editor.chain().focus().setColor(e.target.value).run();
                                            setOverflowOpen(false);
                                        }}
                                    />
                                </label>
                                <ToolbarButton
                                    onClick={() => {
                                        editor.chain().focus().toggleCode().run();
                                        setOverflowOpen(false);
                                    }}
                                    isActive={editor.isActive('code')}
                                    title={t('toolbar_inline_code')}
                                >
                                    <Code size={ICON_SIZE} />
                                </ToolbarButton>
                                <ToolbarButton
                                    onClick={() => {
                                        editor.chain().focus().clearNodes().unsetAllMarks().run();
                                        setOverflowOpen(false);
                                    }}
                                    title={t('toolbar_clear_format')}
                                >
                                    <RemoveFormatting size={ICON_SIZE} />
                                </ToolbarButton>
                            </div>
                        ) : null}
                    </div>
                </ToolbarGroup>

                <span className="seo-toolbar-end-actions">
                    <ToolbarButton
                        onClick={() => {
                            window.dispatchEvent(new CustomEvent('extract-article-faqs-from-toolbar'));
                        }}
                        title={t('toolbar_extract_faq')}
                    >
                        <ListTree size={ICON_SIZE} />
                    </ToolbarButton>

                    <button
                        type="button"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => canDelete && onDelete?.()}
                        disabled={!canDelete}
                        title={canDelete ? t('toolbar_delete_paragraph') : t('toolbar_cannot_delete_last')}
                        className={`seo-toolbar-btn seo-toolbar-delete${!canDelete ? ' is-disabled' : ''}`}
                    >
                        <Trash2 size={ICON_SIZE} />
                    </button>
                </span>
            </div>

            {/* Insert-content toolbar — actions that already existed on BlockFormatToolbar */}
            <div className="seo-toolbar-row seo-toolbar-row--insert" role="toolbar" aria-label={t('toolbar_insert_aria')}>
                <InsertActionButton
                    onClick={() => editor.chain().focus().toggleBlockquote().run()}
                    title={t('toolbar_quote')}
                    label={t('toolbar_insert_quote_short')}
                >
                    <Quote size={ICON_SIZE} />
                </InsertActionButton>
                <InsertActionButton
                    onClick={() => editor.chain().focus().setHorizontalRule().run()}
                    title={t('toolbar_horizontal_rule')}
                    label={t('toolbar_insert_divider_short')}
                >
                    <Minus size={ICON_SIZE} />
                </InsertActionButton>
                <InsertActionButton
                    onClick={() =>
                        editor
                            .chain()
                            .focus()
                            .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                            .run()
                    }
                    title={t('toolbar_insert_table')}
                    label={t('toolbar_insert_table_short')}
                >
                    <Table size={ICON_SIZE} />
                </InsertActionButton>
                <InsertActionButton
                    onMouseDown={(e) => {
                        e.preventDefault();
                        openEmojiPicker();
                    }}
                    title={t('toolbar_insert_emoji')}
                    label={t('toolbar_insert_emoji_short')}
                >
                    <Smile size={ICON_SIZE} />
                </InsertActionButton>
            </div>

            <EmojiPickerModal
                open={emojiPickerOpen}
                onClose={closeEmojiPicker}
                onSelect={insertEmoji}
            />
        </div>
    );
}
