import { mergeOwnedPayloads } from './payloadSemantics.js';

/**
 * Global Save Coordinator — each owner flushes its own payload slice.
 * Missing field = untouched; null = clear; value = set.
 *
 * @typedef {{ id: string, flush: () => (Record<string, unknown>|Promise<Record<string, unknown>>), dirty?: () => boolean }} SaveOwner
 */

/** @type {Map<string, SaveOwner>} */
const owners = new Map();

/**
 * @param {SaveOwner} owner
 */
export function registerSaveOwner(owner) {
    const id = String(owner?.id ?? '').trim();
    if (!id || typeof owner.flush !== 'function') {
        throw new Error('Save owner requires id + flush()');
    }
    owners.set(id, owner);
}

/**
 * @param {string} id
 */
export function unregisterSaveOwner(id) {
    owners.delete(String(id ?? ''));
}

export function clearSaveOwners() {
    owners.clear();
}

/**
 * @returns {string[]}
 */
export function listSaveOwnerIds() {
    return [...owners.keys()];
}

/**
 * @param {unknown} err
 * @returns {Error|string}
 */
function normalizeOwnerError(err) {
    if (err instanceof Error) {
        return err;
    }
    if (typeof err === 'string') {
        return err;
    }
    return String(err ?? 'Unknown save owner error');
}

/**
 * Flush registered save owners.
 * Default: only dirty owners. Per-owner flush failures are caught so siblings still merge.
 * Does not fabricate null for missing/skipped owners.
 *
 * @param {{ onlyDirty?: boolean }} [opts]
 * @returns {Promise<{
 *   payload: Record<string, unknown>,
 *   owners: string[],
 *   dirty: string[],
 *   errors: Record<string, Error|string>,
 * }>}
 */
export async function flushAllSaveOwners(opts = {}) {
    const onlyDirty = opts.onlyDirty !== false;
    const merged = {};
    const flushed = [];
    const dirty = [];
    /** @type {Record<string, Error|string>} */
    const errors = {};

    for (const [id, owner] of owners.entries()) {
        const isDirty = typeof owner.dirty === 'function' ? !!owner.dirty() : true;
        if (isDirty) {
            dirty.push(id);
        }
        if (onlyDirty && !isDirty) {
            continue;
        }

        try {
            const slice = await owner.flush();
            Object.assign(merged, mergeOwnedPayloads(slice ?? {}));
            flushed.push(id);
        } catch (err) {
            errors[id] = normalizeOwnerError(err);
        }
    }

    return {
        payload: mergeOwnedPayloads(merged),
        owners: flushed,
        dirty,
        errors,
    };
}
