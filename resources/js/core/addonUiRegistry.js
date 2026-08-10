/**
 * Client Core — addon UI registry (React).
 * Business modules register into editor/shell slots; Core never hard-imports them.
 */

/** @type {Map<string, { addon: string, slot: string, render: Function }>} */
const entries = new Map();

/**
 * @param {string} key
 * @param {{ addon: string, slot: string, render: Function }} entry
 */
export function registerAddonUi(key, entry) {
    const id = String(key ?? '').trim();
    if (!id) {
        throw new Error('Addon UI key required');
    }
    if (entries.has(id)) {
        throw new Error(`Addon UI [${id}] already registered`);
    }
    entries.set(id, {
        addon: String(entry.addon ?? ''),
        slot: String(entry.slot ?? ''),
        render: entry.render,
    });
}

/**
 * @param {string} slot
 * @returns {Array<{ key: string, addon: string, slot: string, render: Function }>}
 */
export function listAddonUiForSlot(slot) {
    const want = String(slot ?? '');
    const out = [];
    for (const [key, meta] of entries.entries()) {
        if (meta.slot === want) {
            out.push({ key, ...meta });
        }
    }
    return out;
}

export function clearAddonUiRegistry() {
    entries.clear();
}
