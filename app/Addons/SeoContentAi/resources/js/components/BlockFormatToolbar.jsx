import React from 'react';
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
} from 'lucide-react';
import ParagraphStyleDropdown from './ParagraphStyleDropdown';

const ICON_SIZE = 16;

function ToolbarButton({ onClick, isActive = false, disabled = false, children, title }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={title}
            className={`seo-toolbar-btn${isActive ? ' is-active' : ''}${disabled ? ' is-disabled' : ''}`}
        >
            {children}
        </button>
    );
}

function ToolbarGroup({ children }) {
    return <div className="seo-toolbar-group">{children}</div>;
}

export default function BlockFormatToolbar({ editor, onDelete, canDelete = true, onEditLink }) {
    if (!editor) return null;

    const openLinkEditor = () => {
        if (onEditLink) {
            onEditLink();
            return;
        }
        const previous = editor.getAttributes('link').href;
        const url = window.prompt('URL liên kết:', previous || 'https://');
        if (url === null) return;
        if (url === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }
        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    };

    return (
        <div className="seo-block-toolbar seo-block-toolbar-rich" onMouseDown={(e) => e.preventDefault()}>
            <div className="seo-toolbar-row">
                <ToolbarGroup>
                    <ParagraphStyleDropdown editor={editor} />
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleBold().run()}
                        isActive={editor.isActive('bold')}
                        title="In đậm"
                    >
                        <Bold size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleItalic().run()}
                        isActive={editor.isActive('italic')}
                        title="In nghiêng"
                    >
                        <Italic size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleUnderline().run()}
                        isActive={editor.isActive('underline')}
                        title="Gạch chân"
                    >
                        <Underline size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleStrike().run()}
                        isActive={editor.isActive('strike')}
                        title="Gạch ngang"
                    >
                        <Strikethrough size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleBlockquote().run()}
                        isActive={editor.isActive('blockquote')}
                        title="Trích dẫn"
                    >
                        <Quote size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleCode().run()}
                        isActive={editor.isActive('code')}
                        title="Mã inline"
                    >
                        <Code size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleBulletList().run()}
                        isActive={editor.isActive('bulletList')}
                        title="Danh sách chấm"
                    >
                        <List size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleOrderedList().run()}
                        isActive={editor.isActive('orderedList')}
                        title="Danh sách số"
                    >
                        <ListOrdered size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().setTextAlign('left').run()}
                        isActive={editor.isActive({ textAlign: 'left' })}
                        title="Căn trái"
                    >
                        <AlignLeft size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().setTextAlign('center').run()}
                        isActive={editor.isActive({ textAlign: 'center' })}
                        title="Căn giữa"
                    >
                        <AlignCenter size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().setTextAlign('right').run()}
                        isActive={editor.isActive({ textAlign: 'right' })}
                        title="Căn phải"
                    >
                        <AlignRight size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().setTextAlign('justify').run()}
                        isActive={editor.isActive({ textAlign: 'justify' })}
                        title="Căn đều"
                    >
                        <AlignJustify size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={openLinkEditor}
                        isActive={editor.isActive('link')}
                        title="Chèn / sửa link"
                    >
                        <Link2 size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().unsetLink().run()}
                        disabled={!editor.isActive('link')}
                        title="Gỡ link"
                    >
                        <Unlink size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().undo().run()}
                        disabled={!editor.can().undo()}
                        title="Hoàn tác"
                    >
                        <Undo2 size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().redo().run()}
                        disabled={!editor.can().redo()}
                        title="Làm lại"
                    >
                        <Redo2 size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().clearNodes().unsetAllMarks().run()}
                        title="Xóa định dạng"
                    >
                        <RemoveFormatting size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>
            </div>

            <div className="seo-toolbar-row">
                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleHighlight().run()}
                        isActive={editor.isActive('highlight')}
                        title="Đánh dấu"
                    >
                        <Highlighter size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleSubscript().run()}
                        isActive={editor.isActive('subscript')}
                        title="Chỉ số dưới"
                    >
                        <Subscript size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().toggleSuperscript().run()}
                        isActive={editor.isActive('superscript')}
                        title="Chỉ số trên"
                    >
                        <Superscript size={ICON_SIZE} />
                    </ToolbarButton>
                    <input
                        type="color"
                        title="Màu chữ"
                        className="seo-toolbar-color"
                        onChange={(e) => editor.chain().focus().setColor(e.target.value).run()}
                    />
                </ToolbarGroup>

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={() => editor.chain().focus().setHorizontalRule().run()}
                        title="Đường kẻ ngang"
                    >
                        <Minus size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() =>
                            editor
                                .chain()
                                .focus()
                                .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                                .run()
                        }
                        title="Chèn bảng"
                    >
                        <Table size={ICON_SIZE} />
                    </ToolbarButton>
                </ToolbarGroup>

                <span className="seo-toolbar-spacer" />

                <ToolbarButton
                    onClick={() => {
                        window.dispatchEvent(new CustomEvent('extract-article-faqs-from-toolbar'));
                    }}
                    title="Tách FAQ từ đoạn đang bôi đen (hoặc cả block)"
                >
                    <ListTree size={ICON_SIZE} />
                </ToolbarButton>

                <button
                    type="button"
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={() => canDelete && onDelete?.()}
                    disabled={!canDelete}
                    title={canDelete ? 'Xóa đoạn văn' : 'Không thể xóa đoạn cuối cùng'}
                    className={`seo-toolbar-btn seo-toolbar-delete${!canDelete ? ' is-disabled' : ''}`}
                >
                    <Trash2 size={ICON_SIZE} />
                </button>
            </div>
        </div>
    );
}
