import { DOMSerializer } from '@tiptap/pm/model';
import { isCtaPlainTextType } from './ctaLinkFormat';

/**
 * HTML của vùng đang bôi đen trong TipTap (dùng tách FAQ thủ công).
 */
export function getSelectionHtmlFromEditor(editor) {
    if (!editor?.state) {
        return '';
    }

    const { from, to, empty } = editor.state.selection;
    if (empty || to <= from) {
        return '';
    }

    const slice = editor.state.doc.slice(from, to);
    const serializer = DOMSerializer.fromSchema(editor.schema);
    const fragment = serializer.serializeFragment(slice.content);
    const wrap = document.createElement('div');
    wrap.appendChild(fragment);

    return wrap.innerHTML.trim();
}

export function getSelectionTextFromEditor(editor) {
    if (!editor?.state) {
        return '';
    }

    const { from, to, empty } = editor.state.selection;
    if (empty || to <= from) {
        return '';
    }

    return editor.state.doc.textBetween(from, to, '\n\n').trim();
}

/**
 * Chèn text thuần tại vùng chọn hoặc con trỏ.
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {string} text
 * @returns {boolean}
 */
export function insertTextInEditor(editor, text) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const value = String(text ?? '').trim();
    if (!value) {
        return false;
    }

    const { from, to, empty } = editor.state.selection;
    const chain = editor.chain().focus();

    if (!empty && to > from) {
        chain.deleteRange({ from, to });
    }

    return chain.insertContent({ type: 'text', text: value }).run();
}

/**
 * Chèn CTA: link hoặc text thuần (address, working_hours).
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {string} label
 * @param {string} href
 * @param {string} [type]
 * @returns {boolean}
 */
export function insertCtaInEditor(editor, label, href, type = '') {
    if (isCtaPlainTextType(type)) {
        return insertTextInEditor(editor, label);
    }

    return insertLinkInEditor(editor, label, href);
}

/**
 * Chèn link tại vùng chọn hoặc con trỏ hiện tại trong TipTap.
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {string} label
 * @param {string} href
 * @returns {boolean}
 */
export function insertLinkInEditor(editor, label, href) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const linkLabel = String(label ?? '').trim();
    const linkHref = String(href ?? '').trim();
    if (!linkLabel || !linkHref) {
        return false;
    }

    const { from, to, empty } = editor.state.selection;
    const chain = editor.chain().focus();

    if (!empty && to > from) {
        chain.deleteRange({ from, to });
    }

    return chain
        .insertContent({
            type: 'text',
            text: linkLabel,
            marks: [
                {
                    type: 'link',
                    attrs: {
                        href: linkHref,
                        target: null,
                        rel: 'noopener noreferrer',
                    },
                },
            ],
        })
        .run();
}

/** @deprecated Use insertLinkInEditor */
export function insertLinkReplacingEditorSelection(editor, label, href) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const { empty, to, from } = editor.state.selection;
    if (empty || to <= from) {
        return false;
    }

    return insertLinkInEditor(editor, label, href);
}
