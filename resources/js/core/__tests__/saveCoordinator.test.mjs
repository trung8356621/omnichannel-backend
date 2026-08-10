import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    registerSaveOwner,
    clearSaveOwners,
    flushAllSaveOwners,
} from '../saveCoordinator.js';

beforeEach(() => {
    clearSaveOwners();
});

/**
 * @param {string} id
 * @param {Record<string, unknown>} slice
 * @param {{ dirty?: boolean, fail?: Error|string }} [opts]
 */
function mockOwner(id, slice, opts = {}) {
    const dirty = opts.dirty !== false;
    registerSaveOwner({
        id,
        dirty: () => dirty,
        flush: async () => {
            if (opts.fail !== undefined) {
                throw opts.fail;
            }
            return slice;
        },
    });
}

describe('flushAllSaveOwners', () => {
    it('1. content only dirty', async () => {
        mockOwner('content', { title: 'A', content_html: '<p>x</p>' }, { dirty: true });
        mockOwner('media', { media_snapshot: { featured: null } }, { dirty: false });
        mockOwner('seo', { seo_title: 'S' }, { dirty: false });

        const result = await flushAllSaveOwners({ onlyDirty: true });

        assert.deepEqual(result.dirty, ['content']);
        assert.deepEqual(result.owners, ['content']);
        assert.equal(result.payload.title, 'A');
        assert.equal(result.payload.content_html, '<p>x</p>');
        assert.equal(Object.prototype.hasOwnProperty.call(result.payload, 'media_snapshot'), false);
        assert.equal(Object.prototype.hasOwnProperty.call(result.payload, 'seo_title'), false);
        assert.deepEqual(result.errors, {});
    });

    it('2. media only dirty', async () => {
        mockOwner('content', { title: 'A' }, { dirty: false });
        mockOwner('media', { media_snapshot: { featured: { url: 'https://x/a.jpg' } } }, { dirty: true });
        mockOwner('seo', { seo_title: 'S' }, { dirty: false });

        const result = await flushAllSaveOwners({ onlyDirty: true });

        assert.deepEqual(result.dirty, ['media']);
        assert.deepEqual(result.owners, ['media']);
        assert.deepEqual(result.payload.media_snapshot, { featured: { url: 'https://x/a.jpg' } });
        assert.equal(Object.prototype.hasOwnProperty.call(result.payload, 'title'), false);
        assert.deepEqual(result.errors, {});
    });

    it('3. seo only dirty', async () => {
        mockOwner('content', { title: 'A' }, { dirty: false });
        mockOwner('media', { media_snapshot: {} }, { dirty: false });
        mockOwner('seo', { seo_title: 'SEO', seo_description: null }, { dirty: true });

        const result = await flushAllSaveOwners({ onlyDirty: true });

        assert.deepEqual(result.dirty, ['seo']);
        assert.deepEqual(result.owners, ['seo']);
        assert.equal(result.payload.seo_title, 'SEO');
        assert.equal(result.payload.seo_description, null);
        assert.equal(Object.prototype.hasOwnProperty.call(result.payload, 'title'), false);
        assert.deepEqual(result.errors, {});
    });

    it('4. content + media both dirty', async () => {
        mockOwner('content', { title: 'Both', content_html: '<p>y</p>' }, { dirty: true });
        mockOwner('media', { media_snapshot: { featured: null } }, { dirty: true });
        mockOwner('seo', { seo_title: 'S' }, { dirty: false });

        const result = await flushAllSaveOwners({ onlyDirty: true });

        assert.deepEqual(result.dirty, ['content', 'media']);
        assert.deepEqual(result.owners, ['content', 'media']);
        assert.equal(result.payload.title, 'Both');
        assert.deepEqual(result.payload.media_snapshot, { featured: null });
        assert.equal(Object.prototype.hasOwnProperty.call(result.payload, 'seo_title'), false);
        assert.deepEqual(result.errors, {});
    });

    it('5. media fail + content success — content payload kept, errors.media set', async () => {
        mockOwner('content', { title: 'OK', content_html: '<p>ok</p>' }, { dirty: true });
        mockOwner('media', { media_snapshot: {} }, {
            dirty: true,
            fail: new Error('media flush failed'),
        });

        const result = await flushAllSaveOwners({ onlyDirty: true });

        assert.deepEqual(result.dirty, ['content', 'media']);
        assert.deepEqual(result.owners, ['content']);
        assert.equal(result.payload.title, 'OK');
        assert.equal(result.payload.content_html, '<p>ok</p>');
        assert.equal(Object.prototype.hasOwnProperty.call(result.payload, 'media_snapshot'), false);
        assert.ok(result.errors.media instanceof Error);
        assert.equal(result.errors.media.message, 'media flush failed');
        assert.equal(Object.prototype.hasOwnProperty.call(result.errors, 'content'), false);
    });

    it('6. seo fail + content success', async () => {
        mockOwner('content', { title: 'Content wins' }, { dirty: true });
        mockOwner('seo', { seo_title: 'Nope' }, {
            dirty: true,
            fail: 'seo validation failed',
        });

        const result = await flushAllSaveOwners({ onlyDirty: true });

        assert.deepEqual(result.dirty, ['content', 'seo']);
        assert.deepEqual(result.owners, ['content']);
        assert.equal(result.payload.title, 'Content wins');
        assert.equal(Object.prototype.hasOwnProperty.call(result.payload, 'seo_title'), false);
        assert.equal(result.errors.seo, 'seo validation failed');
        assert.equal(Object.prototype.hasOwnProperty.call(result.errors, 'content'), false);
    });
});
