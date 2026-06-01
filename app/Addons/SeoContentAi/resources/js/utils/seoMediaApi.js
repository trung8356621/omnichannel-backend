const UPLOAD_URL = '/api/seo/media/upload';
const IMPORT_URL = '/api/seo/media/import-url';
const PREPARE_EDITOR_URL = '/api/seo/media/prepare-editor';
const APPLY_WATERMARK_URL = '/api/seo/media/apply-watermark';
const RENAME_URL_TEMPLATE = '/api/seo/media/{id}/rename';
const RENAME_BY_URL = '/api/seo/media/rename-by-url';
const SAVE_EDITED_URL_TEMPLATE = '/api/seo/media/{id}/save-edited';
const MEDIA_IMAGE_EDITOR_PATH = '/seo/media-image-editor';
const SPLITTER_SOURCE_URL = '/api/seo/media/splitter-source';
const SAVE_SPLIT_URL = '/api/seo/media/save-split';
const ARTICLE_AI_JOBS_URL_TEMPLATE = '/api/seo/media/article/{articleId}/ai-jobs';
const MEDIA_STATUS_URL_TEMPLATE = '/api/seo/media/{id}/status';
const MEDIA_RETRY_URL_TEMPLATE = '/api/seo/media/{id}/retry-generation';
const MEDIA_DELETE_AI_JOB_URL_TEMPLATE = '/api/seo/media/{id}/ai-job';

export const AI_PLACEHOLDER_LOADING_URL = '/assets/images/placeholder-loading.svg';

/** URL tương đối /storage/... — tránh lệch host/port khi APP_URL khác origin trình duyệt. */
export function normalizeSeoMediaUrl(url) {
    if (!url || typeof url !== 'string') {
        return '';
    }
    const trimmed = url.trim();
    try {
        const parsed = new URL(trimmed, window.location.origin);
        if (parsed.pathname.startsWith('/storage/')) {
            return parsed.pathname;
        }
    } catch {
        /* relative path */
    }
    if (trimmed.startsWith('/storage/')) {
        return trimmed;
    }

    return trimmed;
}

export async function fetchSplitterSource({
    siteId = null,
    seoMediaId = null,
    wpAttachmentId = null,
    slug = '',
} = {}) {
    const params = new URLSearchParams();
    if (siteId) params.set('site_id', String(siteId));
    if (seoMediaId) params.set('seo_media_id', String(seoMediaId));
    if (wpAttachmentId) params.set('wp_attachment_id', String(wpAttachmentId));
    if (slug) params.set('slug', String(slug));

    const response = await fetch(`${SPLITTER_SOURCE_URL}?${params.toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        throw new Error(data.message ?? 'Không tải được ảnh nguồn.');
    }

    if (data.url) {
        data.url = normalizeSeoMediaUrl(data.url);
    }

    return data;
}

/**
 * @param {{ siteId: number, articleId?: number|null, originalSeoMediaId?: number|null, originalUrl?: string, pieces: { blob: Blob, filename: string }[] }} params
 */
export async function saveSplitPiecesToLibrary({
    siteId,
    articleId = null,
    originalSeoMediaId = null,
    pieces = [],
}) {
    const formData = new FormData();
    formData.append('site_id', String(siteId));
    const resolvedArticleId = Number.parseInt(String(articleId ?? ''), 10);
    if (Number.isFinite(resolvedArticleId) && resolvedArticleId > 0) {
        formData.append('article_id', String(resolvedArticleId));
    }
    if (originalSeoMediaId != null && Number(originalSeoMediaId) > 0) {
        formData.append('original_seo_media_id', String(originalSeoMediaId));
    }
    pieces.forEach((piece, index) => {
        formData.append(`pieces[${index}]`, piece.blob, piece.filename || `piece-${index + 1}.png`);
    });

    const response = await fetch(SAVE_SPLIT_URL, {
        method: 'POST',
        body: formData,
        headers: {
            ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        const message =
            response.status === 419
                ? 'Phiên đăng nhập hết hạn — tải lại trang rồi thử lại.'
                : (data.message ?? 'Không lưu được ảnh đã tách.');
        throw new Error(message);
    }

    if (Array.isArray(data.saved)) {
        data.saved = data.saved.map((row) => ({
            ...row,
            url: row.url ? normalizeSeoMediaUrl(row.url) : row.url,
        }));
    }

    return data;
}

/** Mở trình chỉnh sửa ảnh (Magic Eraser / Image Splitter trong cùng một app). */
export function buildMediaImageEditorUrl({ seoMediaId = null, tab = null } = {}) {
    const mediaId = Number(seoMediaId ?? 0);
    if (mediaId <= 0) {
        return null;
    }

    const target = new URL(MEDIA_IMAGE_EDITOR_PATH, window.location.origin);
    target.searchParams.set('media', String(mediaId));
    if (tab) {
        target.searchParams.set('tab', String(tab));
    }

    return target.toString();
}

/**
 * @deprecated Dùng buildMediaImageEditorUrl({ seoMediaId, tab: 'splitter' })
 */
export function buildImageSplitterUrl({ seoMediaId = null } = {}) {
    return buildMediaImageEditorUrl({
        seoMediaId,
        tab: 'splitter',
    });
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

let activeClipboardUpload = null;
let lastClipboardPasteAt = 0;

function dispatchEditorNotify(title, body, status = 'success') {
    const payload = { title, body, status };
    if (typeof Livewire !== 'undefined') {
        Livewire.dispatch('seo-article-editor-notify', payload);
        return;
    }
    window.dispatchEvent(
        new CustomEvent('seo-article-editor-notify', {
            detail: payload,
        }),
    );
}

function parseUploadError(response, data) {
    if (response.status === 419) {
        return 'Phiên đăng nhập hết hạn — tải lại trang rồi dán lại.';
    }
    if (response.status === 403) {
        return 'Bạn không có quyền upload ảnh cho bài này.';
    }
    if (response.status === 422) {
        return (
            data.message ??
            data.errors?.url?.[0] ??
            data.errors?.image?.[0] ??
            'File ảnh không hợp lệ.'
        );
    }

    return data.message ?? 'Không thể upload ảnh.';
}

/**
 * @param {File|Blob} file
 * @param {{ articleId?: number|null, siteId?: number|null, source?: string }} options
 */
export async function uploadSeoMediaFromFile(file, { articleId = null, siteId = null, source = 'clipboard' } = {}) {
    if (activeClipboardUpload) {
        return activeClipboardUpload;
    }

    const run = async () => {
        const formData = new FormData();
        formData.append('image', file);
        formData.append('source', source);

        if (articleId != null && !Number.isNaN(Number(articleId))) {
            formData.append('article_id', String(articleId));
        }
        if (siteId != null && !Number.isNaN(Number(siteId))) {
            formData.append('site_id', String(siteId));
        }

        const token = csrfToken();
        const response = await fetch(UPLOAD_URL, {
            method: 'POST',
            body: formData,
            headers: {
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.success) {
            throw new Error(parseUploadError(response, data));
        }

        if (data.url) {
            data.url = normalizeSeoMediaUrl(data.url);
        }

        return data;
    };

    activeClipboardUpload = run().finally(() => {
        activeClipboardUpload = null;
    });

    return activeClipboardUpload;
}

/**
 * Tải ảnh từ URL bên ngoài, tối ưu theo cấu hình site và lưu thư viện.
 * @param {string} remoteUrl
 * @param {{ articleId?: number|null, siteId?: number|null }} options
 */
export async function importSeoMediaFromUrl(remoteUrl, { articleId = null, siteId = null } = {}) {
    const url = String(remoteUrl ?? '').trim();
    if (!url) {
        throw new Error('Vui lòng nhập URL ảnh.');
    }

    const response = await fetch(IMPORT_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            url,
            ...(articleId != null && !Number.isNaN(Number(articleId))
                ? { article_id: Number(articleId) }
                : {}),
            ...(siteId != null && !Number.isNaN(Number(siteId)) ? { site_id: Number(siteId) } : {}),
        }),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        throw new Error(parseUploadError(response, data));
    }

    if (data.url) {
        data.url = normalizeSeoMediaUrl(data.url);
    }

    return data;
}

/**
 * @param {number} mediaId
 * @param {Blob} blob
 */
export async function saveEditedSeoMedia(mediaId, blob) {
    const url = SAVE_EDITED_URL_TEMPLATE.replace('{id}', String(mediaId));
    const formData = new FormData();
    formData.append('image', blob, 'edited-image.png');

    const response = await fetch(url, {
        method: 'POST',
        body: formData,
        headers: {
            ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        const message =
            response.status === 419
                ? 'Phiên đăng nhập hết hạn — tải lại trang rồi thử lại.'
                : (data.message ?? 'Lỗi lưu ảnh!');
        throw new Error(message);
    }

    if (data.url) {
        const [path, query] = data.url.split('?');
        const normalized = normalizeSeoMediaUrl(path);
        data.url = query ? `${normalized}?${query}` : normalized;
    }

    return data;
}

/**
 * @param {{ siteId: number, seoMediaId?: number|null, wpAttachmentId?: number|null, url?: string, slug?: string }} params
 */
export async function prepareImageEditorUrl({
    siteId,
    seoMediaId = null,
    wpAttachmentId = null,
    url = '',
    slug = '',
}) {
    const response = await fetch(PREPARE_EDITOR_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            site_id: siteId,
            seo_media_id: seoMediaId ?? undefined,
            wp_attachment_id: wpAttachmentId ?? undefined,
            url: url || undefined,
            slug: slug || undefined,
        }),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        const message =
            response.status === 419
                ? 'Phiên đăng nhập hết hạn — tải lại trang rồi thử lại.'
                : (data.message ?? 'Không mở được trình chỉnh sửa.');
        throw new Error(message);
    }

    return data;
}

/**
 * @param {{ siteId: number, seoMediaId?: number|null, wpAttachmentId?: number|null, url?: string, slug?: string }} params
 */
export async function applyWatermarkToImage({
    siteId,
    seoMediaId = null,
    wpAttachmentId = null,
    url = '',
    slug = '',
}) {
    const response = await fetch(APPLY_WATERMARK_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            site_id: siteId,
            seo_media_id: seoMediaId ?? undefined,
            wp_attachment_id: wpAttachmentId ?? undefined,
            url: url || undefined,
            slug: slug || undefined,
        }),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        const message =
            response.status === 419
                ? 'Phiên đăng nhập hết hạn — tải lại trang rồi thử lại.'
                : (data.message ?? 'Không áp dụng được đóng dấu.');
        throw new Error(message);
    }

    if (data.url) {
        const [path, query] = String(data.url).split('?');
        const normalized = normalizeSeoMediaUrl(path);
        data.url = query ? `${normalized}?${query}` : normalized;
    }

    return data;
}

export async function renameSeoMedia(mediaId, newSlug) {
    const url = RENAME_URL_TEMPLATE.replace('{id}', String(mediaId));
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ new_slug: newSlug }),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        const message = data.message ?? data.errors?.new_slug?.[0] ?? 'Không thể đổi tên ảnh.';
        throw new Error(message);
    }

    if (data.url) {
        data.url = normalizeSeoMediaUrl(data.url);
    }

    return data;
}

export async function renameSeoMediaByUrl(mediaUrl, newSlug) {
    const response = await fetch(RENAME_BY_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ url: mediaUrl, new_slug: newSlug }),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        const message = data.message ?? data.errors?.new_slug?.[0] ?? data.errors?.url?.[0] ?? 'Không thể đổi tên ảnh.';
        throw new Error(message);
    }

    if (data.url) {
        data.url = normalizeSeoMediaUrl(data.url);
    }

    return data;
}

/**
 * Xử lý paste ảnh từ clipboard (dùng cho Tiptap và khối ảnh trống).
 * @returns {boolean} true nếu đã xử lý (cần preventDefault ở caller nếu sync)
 */
export function processClipboardImagePaste(event, options = {}) {
    const {
        articleId = null,
        siteId = null,
        source = 'clipboard',
        onUploaded,
        onError,
        notifyOnSuccess = true,
        notifyOnError = true,
    } = options;

    const clipboard = event.clipboardData;
    const items = clipboard?.items;
    if (!items?.length) {
        return false;
    }

    const pastedHtml = clipboard.getData?.('text/html') ?? '';
    if (/data:image\/[^;]+;base64,/i.test(pastedHtml)) {
        event.preventDefault();
        if (notifyOnError) {
            dispatchEditorNotify(
                'Không dán ảnh base64',
                'Hãy dán file ảnh (Ctrl+V) để hệ thống tự upload lên server.',
                'warning',
            );
        }

        return true;
    }

    let imageFile = null;
    for (const item of items) {
        if (item.type.indexOf('image') === 0) {
            imageFile = item.getAsFile();
            if (imageFile) {
                break;
            }
        }
    }

    if (!imageFile) {
        return false;
    }

    const now = Date.now();
    if (now - lastClipboardPasteAt < 600) {
        event.preventDefault();
        return true;
    }
    lastClipboardPasteAt = now;

    event.preventDefault();

    uploadSeoMediaFromFile(imageFile, { articleId, siteId, source })
        .then((data) => {
            if (!data?.url) {
                throw new Error('Server không trả URL ảnh.');
            }
            onUploaded?.(data);
            if (notifyOnSuccess) {
                dispatchEditorNotify(
                    'Đã dán và lưu ảnh',
                    'Ảnh đã lưu trên máy chủ và chèn vào nội dung.',
                    'success',
                );
            }
        })
        .catch((error) => {
            console.error('Lỗi dán ảnh:', error);
            onError?.(error);
            if (notifyOnError) {
                dispatchEditorNotify(
                    'Không thể dán ảnh',
                    error?.message ?? 'Upload từ clipboard thất bại, vui lòng thử lại.',
                    'danger',
                );
            }
        });

    return true;
}

/**
 * Tiptap editorProps.handlePaste — chặn base64, upload blob lên Laravel.
 * @param {{ articleId?: number|null, siteId?: number|null, defaultAltTitle?: string }} context
 */
export async function fetchArticleAiMediaJobs(articleId) {
    if (!articleId) {
        return [];
    }

    const url = ARTICLE_AI_JOBS_URL_TEMPLATE.replace('{articleId}', String(articleId));
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        throw new Error(data.message ?? 'Không tải được danh sách job AI.');
    }

    return Array.isArray(data.items) ? data.items : [];
}

export async function fetchSeoMediaStatus(seoMediaId) {
    const url = MEDIA_STATUS_URL_TEMPLATE.replace('{id}', String(seoMediaId));
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        throw new Error(data.message ?? 'Không kiểm tra được trạng thái media.');
    }

    if (data.url) {
        data.url = normalizeSeoMediaUrl(data.url);
    }

    return data;
}

export async function retryAiMediaGeneration(seoMediaId, retryInput = '') {
    const url = MEDIA_RETRY_URL_TEMPLATE.replace('{id}', String(seoMediaId));
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({
            retry_input: String(retryInput || '').trim(),
        }),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        throw new Error(data.message ?? 'Không thử lại được job AI.');
    }

    if (data.url) {
        data.url = normalizeSeoMediaUrl(data.url);
    }

    return data;
}

export async function deleteAiMediaJob(seoMediaId) {
    const url = MEDIA_DELETE_AI_JOB_URL_TEMPLATE.replace('{id}', String(seoMediaId));
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        throw new Error(data.message ?? 'Không xóa được job AI.');
    }

    return data;
}

export function createClipboardPasteHandler(context = {}) {
    return function handlePaste(view, event) {
        return processClipboardImagePaste(event, {
            articleId: context.articleId,
            siteId: context.siteId,
            source: 'clipboard',
            onUploaded: (data) => {
                const imageType = view.state.schema.nodes.image;
                if (!imageType || !data?.url) {
                    return;
                }

                const mainKeyword = String(context.defaultAltTitle ?? '').trim();
                const altTitle = mainKeyword || (data.slug ?? '');
                const node = imageType.create({
                    src: data.url,
                    alt: altTitle,
                    title: altTitle,
                    'data-seo-media-id': data.id != null ? String(data.id) : null,
                });
                const transaction = view.state.tr.replaceSelectionWith(node);
                view.dispatch(transaction);
            },
        });
    };
}
