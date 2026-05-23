import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Heading from '@tiptap/extension-heading';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';

/** Giữ class Tailwind / WP trên h1–h6 khi round-trip HTML. */
const PreservedHeading = Heading.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: (element) => element.getAttribute('class'),
                renderHTML: (attributes) => {
                    if (!attributes.class) {
                        return {};
                    }

                    return { class: attributes.class };
                },
            },
        };
    },
});

const SeoEditorImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            'data-seo-media-id': {
                default: null,
                parseHTML: (element) => element.getAttribute('data-seo-media-id'),
                renderHTML: (attributes) => {
                    if (!attributes['data-seo-media-id']) {
                        return {};
                    }

                    return { 'data-seo-media-id': attributes['data-seo-media-id'] };
                },
            },
        };
    },
});

export const articleEditorExtensions = [
    StarterKit.configure({
        heading: false,
        horizontalRule: true,
        link: false,
        underline: false,
    }),
    PreservedHeading.configure({ levels: [1, 2, 3, 4, 5, 6] }),
    Underline,
    Subscript,
    Superscript,
    Highlight.configure({ multicolor: false }),
    TextStyle,
    Color,
    Link.configure({
        openOnClick: false,
        enableClickSelection: true,
        HTMLAttributes: {
            rel: 'noopener noreferrer',
            class: 'seo-editor-link',
        },
    }),
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Table.configure({ resizable: true }),
    TableRow,
    TableHeader,
    TableCell,
    SeoEditorImage.configure({
        inline: false,
        allowBase64: false,
        HTMLAttributes: {
            class: 'seo-editor-inline-image max-w-full h-auto my-4 rounded-lg border border-gray-200 dark:border-slate-800 shadow-sm',
        },
    }),
];
