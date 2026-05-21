import { DOMSerializer } from '@tiptap/pm/model';

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
