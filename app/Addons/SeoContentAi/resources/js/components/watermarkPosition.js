/**
 * Đặt watermark theo góc neo + offset pixel (tính từ cạnh, không dùng % canvas).
 */

/** @typedef {'top-left'|'top-center'|'top-right'|'center-left'|'center'|'center-right'|'bottom-left'|'bottom-center'|'bottom-right'} WatermarkAnchor */

export const WATERMARK_ANCHORS = [
    { value: 'top-left', label: 'Góc trên — Trái' },
    { value: 'top-center', label: 'Góc trên — Giữa' },
    { value: 'top-right', label: 'Góc trên — Phải' },
    { value: 'center-left', label: 'Giữa — Trái' },
    { value: 'center', label: 'Chính giữa' },
    { value: 'center-right', label: 'Giữa — Phải' },
    { value: 'bottom-left', label: 'Góc dưới — Trái' },
    { value: 'bottom-center', label: 'Góc dưới — Giữa' },
    { value: 'bottom-right', label: 'Góc dưới — Phải' },
];

export const DEFAULT_POSITION_ANCHOR = 'bottom-right';

export const DEFAULT_ANCHOR_OFFSET = { x: 20, y: 20 };

/**
 * Tâm phần tử (watermark) từ góc neo + offset px.
 * offsetX: khoảng cách từ cạnh phải (nếu anchor có "right") hoặc từ cạnh trái.
 * offsetY: khoảng cách từ cạnh dưới (nếu anchor có "bottom") hoặc từ cạnh trên.
 *
 * @param {number} canvasW
 * @param {number} canvasH
 * @param {WatermarkAnchor|string} anchor
 * @param {number} offsetX
 * @param {number} offsetY
 * @param {number} elemW
 * @param {number} elemH
 * @returns {{ x: number, y: number }}
 */
export function resolveAnchorCenter(canvasW, canvasH, anchor, offsetX, offsetY, elemW, elemH) {
    const offX = Math.max(0, Number(offsetX) || 0);
    const offY = Math.max(0, Number(offsetY) || 0);
    const halfW = elemW / 2;
    const halfH = elemH / 2;
    const a = String(anchor || DEFAULT_POSITION_ANCHOR);

    let cx;
    let cy;

    if (a.includes('left')) {
        cx = offX + halfW;
    } else if (a.includes('right')) {
        cx = canvasW - offX - halfW;
    } else {
        cx = canvasW / 2;
    }

    if (a.includes('top')) {
        cy = offY + halfH;
    } else if (a.includes('bottom')) {
        cy = canvasH - offY - halfH;
    } else {
        cy = canvasH / 2;
    }

    return {
        x: Math.max(halfW, Math.min(canvasW - halfW, cx)),
        y: Math.max(halfH, Math.min(canvasH - halfH, cy)),
    };
}

/**
 * @param {Record<string, unknown>} position
 * @returns {{ anchor: string, offsetX: number, offsetY: number }}
 */
export function normalizeAnchorPosition(position) {
    return {
        anchor: String(position?.positionAnchor ?? position?.anchor ?? DEFAULT_POSITION_ANCHOR),
        offsetX: Math.max(0, Number(position?.anchorOffset?.x ?? position?.offsetX ?? DEFAULT_ANCHOR_OFFSET.x)),
        offsetY: Math.max(0, Number(position?.anchorOffset?.y ?? position?.offsetY ?? DEFAULT_ANCHOR_OFFSET.y)),
    };
}

/**
 * Từ tâm phần tử → offset px (góc dưới-phải làm mặc định khi kéo).
 *
 * @param {number} canvasW
 * @param {number} canvasH
 * @param {number} centerX
 * @param {number} centerY
 * @param {number} elemW
 * @param {number} elemH
 * @param {WatermarkAnchor|string} anchor
 */
export function centerToAnchorOffset(canvasW, canvasH, centerX, centerY, elemW, elemH, anchor) {
    const halfW = elemW / 2;
    const halfH = elemH / 2;
    const a = String(anchor || DEFAULT_POSITION_ANCHOR);

    let offsetX = 0;
    let offsetY = 0;

    if (a.includes('left')) {
        offsetX = Math.round(centerX - halfW);
    } else if (a.includes('right')) {
        offsetX = Math.round(canvasW - centerX - halfW);
    }

    if (a.includes('top')) {
        offsetY = Math.round(centerY - halfH);
    } else if (a.includes('bottom')) {
        offsetY = Math.round(canvasH - centerY - halfH);
    }

    return {
        x: Math.max(0, offsetX),
        y: Math.max(0, offsetY),
    };
}

/**
 * @param {Record<string, unknown>} config
 */
export function migratePositionFromLegacy(config) {
    if (config.positionType === 'anchor' && config.positionAnchor) {
        return config;
    }

    if (config.positionType === 'custom' && config.customCoords) {
        return {
            ...config,
            positionType: 'anchor',
            positionAnchor: DEFAULT_POSITION_ANCHOR,
            anchorOffset: { ...DEFAULT_ANCHOR_OFFSET },
        };
    }

    return {
        ...config,
        positionType: config.positionType === 'preset' ? 'preset' : 'anchor',
        positionAnchor: config.positionAnchor ?? DEFAULT_POSITION_ANCHOR,
        anchorOffset: config.anchorOffset ?? { ...DEFAULT_ANCHOR_OFFSET },
    };
}
