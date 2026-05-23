/**
 * Tỉ lệ khung hình thông dụng (màn hình + ảnh web).
 * Export: cạnh dài = OVERLAY_EXPORT_MAX px để render không bể.
 */
export const OVERLAY_EXPORT_MAX = 2000;

/** @typedef {{ key: string, label: string, rw: number, rh: number }} OverlayRatioPreset */

/** @type {OverlayRatioPreset[]} */
export const OVERLAY_RATIO_PRESETS = [
    { key: '16x9', label: '16:9 — Desktop / hero', rw: 16, rh: 9 },
    { key: '4x3', label: '4:3 — Ảnh / tablet ngang', rw: 4, rh: 3 },
    { key: '3x2', label: '3:2 — Máy ảnh', rw: 3, rh: 2 },
    { key: '1x1', label: '1:1 — Vuông', rw: 1, rh: 1 },
    { key: '9x16', label: '9:16 — Mobile / Story', rw: 9, rh: 16 },
    { key: '3x4', label: '3:4 — Portrait nhỏ', rw: 3, rh: 4 },
    { key: '2x3', label: '2:3 — Portrait', rw: 2, rh: 3 },
    { key: '21x9', label: '21:9 — Ultrawide', rw: 21, rh: 9 },
];

/**
 * @param {number} rw
 * @param {number} rh
 * @returns {{ width: number, height: number, ratio: number }}
 */
export function exportDimensionsForRatio(rw, rh) {
    const max = OVERLAY_EXPORT_MAX;
    let width;
    let height;

    if (rw >= rh) {
        width = max;
        height = Math.max(1, Math.round((max * rh) / rw));
    } else {
        height = max;
        width = Math.max(1, Math.round((max * rw) / rh));
    }

    return { width, height, ratio: rw / rh };
}

/**
 * @param {OverlayRatioPreset} preset
 */
export function dimensionsForPreset(preset) {
    return exportDimensionsForRatio(preset.rw, preset.rh);
}

/**
 * Chọn overlay có tỉ lệ gần ảnh đích nhất (cùng logic server).
 *
 * @param {number} targetWidth
 * @param {number} targetHeight
 * @param {Array<{ key: string, width: number, height: number }>} variants
 * @returns {string|null}
 */
export function resolveBestVariantKey(targetWidth, targetHeight, variants) {
    if (!variants?.length || targetWidth <= 0 || targetHeight <= 0) {
        return null;
    }

    const targetRatio = targetWidth / targetHeight;
    let bestKey = null;
    let bestDiff = Infinity;

    for (const v of variants) {
        const w = Math.max(1, Number(v.width) || 1);
        const h = Math.max(1, Number(v.height) || 1);
        const ratio = w / h;
        const diff = Math.abs(Math.log(targetRatio) - Math.log(Math.max(0.01, ratio)));

        if (diff < bestDiff) {
            bestDiff = diff;
            bestKey = v.key;
        }
    }

    return bestKey;
}
