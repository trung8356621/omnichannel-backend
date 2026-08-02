/**
 * Soft content-image recommendation.
 * target_words_per_image = 200 → ~6 ảnh cho bài ~1.150 từ (không dùng ~120–140).
 */

export const TARGET_WORDS_PER_IMAGE = 200;

/**
 * @param {string} html
 * @returns {{
 *   current_image_count: number,
 *   valid_image_count: number,
 *   block_image_count: number,
 *   current_word_count: number,
 *   recommended_image_count: number,
 *   missing_image_count: number,
 *   target_words_per_image: number,
 *   baseScore: number,
 *   missingAlt: number,
 * }}
 */
export function computeImageRatioMetrics(html, {
    countWordsForImageRatio,
    queryImages,
} = {}) {
    const source = String(html ?? '');
    let images = [];
    let missingAlt = 0;

    if (typeof queryImages === 'function') {
        images = queryImages(source);
    } else if (typeof document !== 'undefined') {
        const container = document.createElement('div');
        container.innerHTML = source;
        images = Array.from(container.querySelectorAll('img')).filter((img) => {
            const src = String(img.getAttribute('src') ?? '').trim();
            return src !== '' && !/placeholder/i.test(src);
        });
    }

    images.forEach((img) => {
        const alt = typeof img?.getAttribute === 'function'
            ? String(img.getAttribute('alt') ?? '').trim()
            : String(img?.alt ?? '').trim();
        if (alt === '') {
            missingAlt += 1;
        }
    });

    const validImageCount = images.length;
    const wordCount = typeof countWordsForImageRatio === 'function'
        ? Number(countWordsForImageRatio(source)) || 0
        : 0;

    const recommended = wordCount > 0
        ? Math.max(1, Math.ceil(wordCount / TARGET_WORDS_PER_IMAGE))
        : 0;
    const missing = Math.max(0, recommended - validImageCount);

    // Soft score aligned to target 200 (ideal ~150–300 từ/ảnh). Missing count drives warnings.
    let baseScore = 0;
    if (wordCount >= 10 && validImageCount > 0) {
        const wordsPerImage = Math.round(wordCount / validImageCount);
        if (missing === 0 && wordsPerImage >= 150 && wordsPerImage <= 300) {
            baseScore = 15;
        } else if (missing === 0) {
            baseScore = 15;
        } else if (missing === 1) {
            baseScore = 12;
        } else if (missing === 2) {
            baseScore = 8;
        } else if (missing >= 3) {
            baseScore = 3;
        } else if (wordsPerImage > 300 && wordsPerImage <= 500) {
            baseScore = 10;
        } else {
            baseScore = 3;
        }
    }

    return {
        current_image_count: validImageCount,
        valid_image_count: validImageCount,
        block_image_count: validImageCount,
        current_word_count: wordCount,
        recommended_image_count: recommended,
        missing_image_count: missing,
        target_words_per_image: TARGET_WORDS_PER_IMAGE,
        baseScore,
        missingAlt,
    };
}

/**
 * @param {number} currentWordCount
 * @param {number} recommendedWordCount
 */
export function computeContentLengthMetrics(currentWordCount, recommendedWordCount) {
    const current = Math.max(0, Number(currentWordCount) || 0);
    const recommended = Math.max(1, Number(recommendedWordCount) || 2000);

    return {
        current_word_count: current,
        recommended_word_count: recommended,
        missing_word_count: Math.max(0, recommended - current),
    };
}

/**
 * Interpolate `:key` placeholders. Never leave snake_case codes as UI text.
 *
 * @param {string} template
 * @param {Record<string, string|number>} vars
 */
export function interpolateSeoReasonTemplate(template, vars = {}) {
    return String(template ?? '').replace(/:([a-zA-Z0-9_]+)/g, (_, key) => {
        if (Object.prototype.hasOwnProperty.call(vars, key)) {
            return String(vars[key]);
        }
        return '';
    });
}

/**
 * @param {string} locale
 * @param {number} value
 */
export function formatSeoCount(value, locale = 'vi') {
    const number = Number(value) || 0;
    try {
        return new Intl.NumberFormat(locale === 'en' ? 'en-US' : 'vi-VN').format(number);
    } catch {
        return String(number);
    }
}

const SAFE_FALLBACKS = {
    vi: {
        content_length_low: 'Nội dung chưa đạt độ dài đề xuất',
        image_ratio_low: 'Tỷ lệ hình ảnh chưa đạt đề xuất',
        image_ratio_poor: 'Tỷ lệ hình ảnh chưa đạt đề xuất',
        image_ratio_missing: 'Tỷ lệ hình ảnh chưa đạt đề xuất',
        image_ratio_suboptimal: 'Tỷ lệ hình ảnh chưa đạt đề xuất',
        missing_focus_keyword: 'Thiếu từ khóa chính',
    },
    en: {
        content_length_low: 'Content is below the recommended length',
        image_ratio_low: 'Image ratio is below recommendation',
        image_ratio_poor: 'Image ratio is below recommendation',
        image_ratio_missing: 'Image ratio is below recommendation',
        image_ratio_suboptimal: 'Image ratio is below recommendation',
        missing_focus_keyword: 'Focus keyword is missing',
    },
};

/**
 * @param {string} key
 * @param {string} locale
 */
export function safeSeoReasonFallback(key, locale = 'vi') {
    const normalized = String(key ?? '').replace(/^seo_rules\./, '').trim();
    const pack = SAFE_FALLBACKS[locale] || SAFE_FALLBACKS.vi;
    if (pack[normalized]) {
        return pack[normalized];
    }

    return locale === 'en'
        ? 'SEO check needs attention'
        : 'Cần kiểm tra tiêu chí SEO';
}

/**
 * Build display label/summary/detail for a violation key.
 *
 * @param {string} key
 * @param {{
 *   messages?: Record<string, string>,
 *   metrics?: Record<string, number>,
 *   locale?: string,
 * }} options
 */
export function presentSeoReason(key, options = {}) {
    const normalized = String(key ?? '').replace(/^seo_rules\./, '').trim();
    const locale = options.locale === 'en' ? 'en' : 'vi';
    const messages = options.messages && typeof options.messages === 'object' ? options.messages : {};
    const metrics = options.metrics && typeof options.metrics === 'object' ? options.metrics : {};

    const summaryKey = `seo_rules.${normalized}`;
    const detailKey = `seo_rules.${normalized}_detail`;
    const labelKey = `seo_rules.${normalized}_label`;

    const vars = {};
    Object.entries(metrics).forEach(([name, value]) => {
        vars[name] = typeof value === 'number' ? formatSeoCount(value, locale) : String(value ?? '');
    });
    // short aliases used in lang files
    if (metrics.missing_image_count != null) {
        vars.missing = formatSeoCount(metrics.missing_image_count, locale);
    }
    if (metrics.current_image_count != null) {
        vars.current = formatSeoCount(metrics.current_image_count, locale);
    }
    if (metrics.recommended_image_count != null) {
        vars.recommended = formatSeoCount(metrics.recommended_image_count, locale);
    }
    if (metrics.current_word_count != null) {
        vars.words = formatSeoCount(metrics.current_word_count, locale);
        if (vars.current == null) {
            vars.current = vars.words;
        }
    }
    if (metrics.recommended_word_count != null && vars.recommended == null) {
        vars.recommended = formatSeoCount(metrics.recommended_word_count, locale);
    }
    if (metrics.missing_word_count != null && vars.missing == null) {
        vars.missing = formatSeoCount(metrics.missing_word_count, locale);
    }

    const looksLikeCode = (value) => /^[a-z0-9]+(?:_[a-z0-9]+)+$/i.test(String(value ?? '').trim());
    const isImageRatioKey = normalized.startsWith('image_ratio_');
    const missingImages = Number(metrics.missing_image_count);
    const useGenericImageRatioCopy = isImageRatioKey
        && Number.isFinite(missingImages)
        && missingImages <= 0;

    const rawSummary = useGenericImageRatioCopy
        ? ''
        : (messages[summaryKey] || messages[normalized] || '');
    const rawDetail = messages[detailKey] || '';
    const rawLabel = messages[labelKey] || '';

    let summary = rawSummary ? interpolateSeoReasonTemplate(rawSummary, vars) : '';
    let detail = rawDetail ? interpolateSeoReasonTemplate(rawDetail, vars) : '';
    let label = rawLabel ? interpolateSeoReasonTemplate(rawLabel, vars) : '';

    if (useGenericImageRatioCopy) {
        summary = safeSeoReasonFallback(normalized, locale);
        if (!detail || looksLikeCode(detail)) {
            detail = summary;
        }
    }

    if (!summary || looksLikeCode(summary) || summary === normalized) {
        summary = safeSeoReasonFallback(normalized, locale);
        if (typeof console !== 'undefined' && typeof console.warn === 'function') {
            console.warn('[seo-reason] missing translation', normalized);
        }
    }
    if (!label || looksLikeCode(label)) {
        label = summary;
    }
    if (!detail || looksLikeCode(detail)) {
        detail = summary;
    }

    return {
        code: normalized,
        label,
        summary,
        detail,
        metrics,
    };
}
