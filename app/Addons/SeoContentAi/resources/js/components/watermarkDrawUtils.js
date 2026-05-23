/**
 * Canvas drawing helpers for watermark stamp patterns.
 */

import { applyColorStyle } from './watermarkColorUtils';
import { normalizeAnchorPosition, resolveAnchorCenter } from './watermarkPosition';

function fillFromOpts(ctx, rect, opts) {
    if (opts.textColorConfig) {
        return applyColorStyle(ctx, rect, opts.textColorConfig);
    }

    return opts.textColor ?? '#ffffff';
}

export function drawTextArc(ctx, str, x, y, radius, startAngle, endAngle, isReverse = false) {
    const chars = String(str).split('');
    const angleRange = endAngle - startAngle;

    ctx.save();
    ctx.translate(x, y);

    chars.forEach((char, i) => {
        const percent = chars.length > 1 ? i / (chars.length - 1) : 0.5;
        const angle = startAngle + angleRange * percent;

        ctx.save();
        ctx.rotate(angle);
        ctx.translate(0, isReverse ? radius : -radius);
        if (isReverse) {
            ctx.rotate(Math.PI);
        }
        ctx.fillText(char, 0, 0);
        ctx.restore();
    });

    ctx.restore();
}

export function resolveStampCenter(canvasW, canvasH, positionType, customCoords, presetPos, margin, position = {}) {
    if (positionType === 'anchor') {
        const { anchor, offsetX, offsetY } = normalizeAnchorPosition(position);
        const stampW = 280;
        const stampH = 120;

        return resolveAnchorCenter(canvasW, canvasH, anchor, offsetX, offsetY, stampW, stampH);
    }

    const pad = margin;
    switch (presetPos) {
        case 'top-left':
            return { x: pad + 140, y: pad + 140 };
        case 'top-center':
            return { x: canvasW / 2, y: pad + 140 };
        case 'top-right':
            return { x: canvasW - pad - 140, y: pad + 140 };
        case 'center-left':
            return { x: pad + 140, y: canvasH / 2 };
        case 'center-right':
            return { x: canvasW - pad - 140, y: canvasH / 2 };
        case 'bottom-left':
            return { x: pad + 140, y: canvasH - pad - 140 };
        case 'bottom-center':
            return { x: canvasW / 2, y: canvasH - pad - 140 };
        case 'bottom-right':
        default:
            return { x: canvasW - pad - 140, y: canvasH - pad - 140 };
    }
}

export function drawClassicGrid(ctx, w, h, opts) {
    const { text1, textSize, selectedFont, textColor, rotation, gridSpacing } = opts;

    ctx.save();
    ctx.translate(w / 2, h / 2);
    ctx.rotate((rotation * Math.PI) / 180);
    ctx.font = `bold ${textSize}px "${selectedFont}", ${selectedFont}, sans-serif`;
    ctx.fillStyle = fillFromOpts(ctx, { x: -w / 2, y: -h / 2, w, h }, opts);
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    const step = gridSpacing + textSize;
    for (let x = -w * 1.5; x < w * 1.5; x += step * 2) {
        for (let y = -h * 1.5; y < h * 1.5; y += step) {
            ctx.fillText(text1, x, y);
        }
    }
    ctx.restore();
}

export function drawCircularBadge(ctx, cx, cy, opts) {
    const {
        text1,
        text2,
        textSize,
        selectedFont,
        textColor,
        borderColor,
        borderWidth,
        backgroundColor,
        bgOpacity,
        rotation,
    } = opts;

    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate((rotation * Math.PI) / 180);

    const radius = Math.max(100, textSize * 4);

    if (bgOpacity > 0) {
        ctx.save();
        ctx.globalAlpha = bgOpacity;
        ctx.fillStyle = backgroundColor;
        ctx.beginPath();
        ctx.arc(0, 0, radius + 20, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
    }

    ctx.strokeStyle = borderColor;
    ctx.lineWidth = borderWidth;
    ctx.beginPath();
    ctx.arc(0, 0, radius, 0, Math.PI * 2);
    ctx.stroke();

    ctx.lineWidth = Math.max(1, borderWidth / 2);
    ctx.beginPath();
    ctx.arc(0, 0, radius - 8, 0, Math.PI * 2);
    ctx.stroke();

    ctx.font = `bold ${textSize * 0.75}px ${selectedFont}`;
    ctx.fillStyle = textColor;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    drawTextArc(ctx, String(text1).toUpperCase(), 0, 0, radius - 22, -Math.PI / 1.45, Math.PI / 1.45);
    drawTextArc(
        ctx,
        String(text2).toUpperCase(),
        0,
        0,
        radius - 22,
        Math.PI / 1.45,
        -Math.PI / 1.45,
        true,
    );

    ctx.font = `bold ${textSize}px ${selectedFont}`;
    ctx.fillText('★', 0, 2);

    ctx.restore();
}

export function drawSecurityRect(ctx, cx, cy, opts) {
    const {
        text1,
        textSize,
        selectedFont,
        textColor,
        borderColor,
        borderWidth,
        backgroundColor,
        bgOpacity,
        rotation,
    } = opts;

    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate((rotation * Math.PI) / 180);

    ctx.font = `bold ${textSize}px ${selectedFont}`;
    const label = String(text1).toUpperCase();
    const textWidth = ctx.measureText(label).width;
    const rectW = textWidth + 60;
    const rectH = textSize * 2.5;

    if (bgOpacity > 0) {
        ctx.save();
        ctx.globalAlpha = bgOpacity;
        ctx.fillStyle = backgroundColor;
        ctx.fillRect(-rectW / 2, -rectH / 2, rectW, rectH);
        ctx.restore();
    }

    ctx.strokeStyle = borderColor;
    ctx.lineWidth = borderWidth;
    ctx.setLineDash([6, 4]);
    ctx.strokeRect(-rectW / 2, -rectH / 2, rectW, rectH);
    ctx.setLineDash([]);

    ctx.lineWidth = Math.max(1, borderWidth / 3);
    ctx.strokeRect(-rectW / 2 + 5, -rectH / 2 + 5, rectW - 10, rectH - 10);

    ctx.save();
    ctx.rotate((-15 * Math.PI) / 180);
    ctx.fillStyle = textColor;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, 0, 0);
    ctx.restore();

    ctx.restore();
}

export function drawElegantSignature(ctx, x, y, opts) {
    const { text1, textSize, selectedFont, textColor, borderColor, rotation } = opts;

    ctx.save();
    ctx.translate(x, y);
    ctx.rotate((rotation * Math.PI) / 180);

    ctx.font = `italic ${textSize}px ${selectedFont}`;
    ctx.fillStyle = textColor;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(text1, 0, 0);

    const w = ctx.measureText(text1).width;
    ctx.strokeStyle = borderColor;
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(-10, 12);
    ctx.quadraticCurveTo(w / 2, 22, w + 30, 6);
    ctx.stroke();

    ctx.restore();
}

export function drawMinimalFrame(ctx, w, h, opts) {
    const { text1, textSize, selectedFont, textColor, borderColor, borderWidth } = opts;
    const padding = 30;

    ctx.save();
    ctx.strokeStyle = borderColor;
    ctx.lineWidth = borderWidth;
    ctx.strokeRect(padding, padding, w - padding * 2, h - padding * 2);

    ctx.lineWidth = Math.max(1, borderWidth / 2);
    ctx.strokeRect(padding + 8, padding + 8, w - (padding + 8) * 2, h - (padding + 8) * 2);

    ctx.font = `bold ${textSize * 0.7}px ${selectedFont}`;
    ctx.fillStyle = textColor;
    ctx.textAlign = 'right';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(text1, w - padding - 20, h - padding - 20);

    ctx.restore();
}

function fontCss(selectedFont, size, style = 'bold') {
    return `${style} ${size}px "${selectedFont}", ${selectedFont}, sans-serif`;
}

function drawVerticalBrand(ctx, text, x, startY, letterSpacing, direction = 1) {
    const letters = String(text).replace(/\s+/g, ' ').trim().toUpperCase().split('');
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    letters.forEach((char, i) => {
        if (char === ' ') {
            return;
        }
        const y = startY + i * letterSpacing * direction;
        ctx.fillText(char, x, y);
    });
}

/**
 * Khung góc thẩm mỹ: L-shape 4 góc, chấm tròn, chữ góc + chữ dọc hai cạnh.
 */
export function drawAestheticCorners(ctx, w, h, opts) {
    const { text1, text2, textSize, selectedFont, textColor, borderColor, borderWidth, gridSpacing } =
        opts;

    const padding = Math.max(15, gridSpacing / 4);
    const len = Math.max(28, textSize * 1.6);
    const sideText = String(text2 || text1).trim();
    const cornerLabel = String(text1).trim();
    const letterSpacing = Math.max(14, textSize * 0.82);
    const smallSize = Math.max(11, textSize * 0.55);

    ctx.save();
    ctx.strokeStyle = borderColor;
    ctx.lineWidth = borderWidth;
    ctx.lineCap = 'square';
    ctx.lineJoin = 'miter';

    const drawL = (x1, y1, x2, y2, x3, y3) => {
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.lineTo(x3, y3);
        ctx.stroke();
    };

    drawL(padding + len, padding, padding, padding, padding, padding + len);
    drawL(w - padding - len, padding, w - padding, padding, w - padding, padding + len);
    drawL(padding + len, h - padding, padding, h - padding, padding, h - padding - len);
    drawL(w - padding - len, h - padding, w - padding, h - padding, w - padding, h - padding - len);

    ctx.fillStyle = borderColor;
    const dotR = Math.max(2.5, borderWidth * 0.9);
    const dots = [
        [padding + len + 14, padding + 14],
        [w - padding - len - 14, padding + 14],
        [padding + len + 14, h - padding - 14],
        [w - padding - len - 14, h - padding - 14],
    ];
    dots.forEach(([dx, dy]) => {
        ctx.beginPath();
        ctx.arc(dx, dy, dotR, 0, Math.PI * 2);
        ctx.fill();
    });

    ctx.fillStyle = textColor;
    ctx.font = fontCss(selectedFont, Math.max(12, textSize * 0.72));
    ctx.textBaseline = 'middle';

    ctx.textAlign = 'left';
    ctx.fillText(cornerLabel, padding + 12, padding + len + 18);
    ctx.textAlign = 'right';
    ctx.fillText(cornerLabel, w - padding - 12, padding + len + 18);
    ctx.textAlign = 'left';
    ctx.fillText(cornerLabel, padding + 12, h - padding - len - 18);
    ctx.textAlign = 'right';
    ctx.fillText(cornerLabel, w - padding - 12, h - padding - len - 18);

    ctx.font = fontCss(selectedFont, smallSize, 'normal');

    const sideStartY = padding + len + 36;
    const sideEndY = h - padding - len - 36;
    const leftX = padding + 10;
    const rightX = w - padding - 10;

    drawVerticalBrand(ctx, sideText, leftX, sideStartY, letterSpacing, 1);

    ctx.save();
    ctx.translate(rightX, sideEndY);
    ctx.rotate(Math.PI);
    drawVerticalBrand(ctx, sideText, 0, 0, letterSpacing, 1);
    ctx.restore();

    ctx.restore();
}
