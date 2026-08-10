/**
 * Payload field semantics (cross-addon contract):
 * - missing key  = untouched (do not send)
 * - null         = intentional clear
 * - value        = set/update
 */

/**
 * @param {Record<string, unknown>} parts
 * @returns {Record<string, unknown>}
 */
export function mergeOwnedPayloads(parts) {
    const out = {};
    for (const [key, value] of Object.entries(parts ?? {})) {
        if (value === undefined) {
            continue;
        }
        out[key] = value;
    }
    return out;
}

/**
 * @param {Record<string, unknown>} payload
 * @param {string} key
 * @returns {boolean}
 */
export function payloadTouches(payload, key) {
    return Object.prototype.hasOwnProperty.call(payload ?? {}, key);
}
