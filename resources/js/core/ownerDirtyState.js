/**
 * Per-domain dirty flags — ONE business state = ONE owner.
 * Content / Media / SEO / Publishing never share a mega dirty bit.
 */

const dirty = {
    content: false,
    media: false,
    seo: false,
    publishing: false,
    wordpress: false,
};

/**
 * @param {'content'|'media'|'seo'|'publishing'|'wordpress'} owner
 * @param {boolean} value
 */
export function setOwnerDirty(owner, value = true) {
    if (!Object.prototype.hasOwnProperty.call(dirty, owner)) {
        return;
    }
    dirty[owner] = !!value;
}

/**
 * @param {'content'|'media'|'seo'|'publishing'|'wordpress'} owner
 */
export function isOwnerDirty(owner) {
    return !!dirty[owner];
}

/**
 * @returns {Record<string, boolean>}
 */
export function getDirtyMap() {
    return { ...dirty };
}

export function clearAllDirty() {
    for (const key of Object.keys(dirty)) {
        dirty[key] = false;
    }
}

export function anyOwnerDirty() {
    return Object.values(dirty).some(Boolean);
}
