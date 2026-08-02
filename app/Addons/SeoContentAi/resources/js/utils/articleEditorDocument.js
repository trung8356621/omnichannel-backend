/**
 * Phase 5A — build / apply canonical article_document envelope on the client.
 */

import { documentJsonFromEditorsOrBlocks } from './editorDocumentBridge';

/**
 * @param {Array<object>} blocks
 * @param {Map<string, import('@tiptap/core').Editor>|null|undefined} blockEditors
 * @returns {{ schema_version: number, type: string, blocks: object[] }}
 */
export function buildEditorDocumentEnvelope(blocks, blockEditors = null) {
    const list = Array.isArray(blocks) ? blocks : [];
    const out = [];

    list.forEach((block) => {
        const id = String(block?.id ?? '').trim();
        if (id === '') {
            return;
        }
        const type = String(block?.type ?? 'text');
        if (type === 'image') {
            const img = block.image && typeof block.image === 'object' ? block.image : {};
            out.push({
                id,
                type: 'image',
                image: {
                    src: String(img.src ?? img.url ?? '').trim(),
                    alt: String(img.alt ?? '').trim(),
                    title: String(img.title ?? '').trim(),
                    caption: String(img.caption ?? '').trim(),
                    align: String(img.align ?? 'none').trim() || 'none',
                },
            });
            return;
        }

        const editor = blockEditors?.get?.(id);
        let document = null;
        if (editor && !editor.isDestroyed && typeof editor.getJSON === 'function') {
            document = editor.getJSON();
        } else {
            const partial = documentJsonFromEditorsOrBlocks(
                new Map(editor ? [[id, editor]] : []),
                [block],
            );
            document = {
                type: 'doc',
                content: Array.isArray(partial?.content) ? partial.content : [],
            };
        }

        out.push({
            id,
            type: 'text',
            document: document && typeof document === 'object'
                ? document
                : { type: 'doc', content: [] },
        });
    });

    return {
        schema_version: 1,
        type: 'article_document',
        blocks: out,
    };
}

/**
 * Convert server envelope → client blocks[] (HTML content left empty; TipTap setContent from JSON).
 *
 * @param {object|null|undefined} envelope
 * @returns {Array<object>|null}
 */
export function blocksFromEditorDocumentEnvelope(envelope) {
    if (!envelope || typeof envelope !== 'object') {
        return null;
    }
    if (String(envelope.type ?? '') !== 'article_document') {
        return null;
    }
    const blocks = Array.isArray(envelope.blocks) ? envelope.blocks : [];
    if (blocks.length === 0) {
        return null;
    }

    return blocks.map((block) => {
        const id = String(block?.id ?? '').trim();
        const type = String(block?.type ?? 'text');
        if (type === 'image') {
            return {
                id,
                type: 'image',
                content: '',
                image: block.image && typeof block.image === 'object' ? block.image : {},
            };
        }

        return {
            id,
            type: 'text',
            content: '',
            editorDocument: block.document && typeof block.document === 'object'
                ? block.document
                : { type: 'doc', content: [] },
        };
    });
}

export default {
    buildEditorDocumentEnvelope,
    blocksFromEditorDocumentEnvelope,
};
