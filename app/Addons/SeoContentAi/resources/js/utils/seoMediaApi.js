const UPLOAD_URL = '/api/seo/media/upload';
const RENAME_URL_TEMPLATE = '/api/seo/media/{id}/rename';

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
        return data.message ?? data.errors?.image?.[0] ?? 'File ảnh không hợp lệ.';
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
 * @param {{ articleId?: number|null, siteId?: number|null }} context
 */
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

                const node = imageType.create({
                    src: data.url,
                    alt: data.slug ?? '',
                    title: data.slug ?? '',
                    'data-seo-media-id': data.id != null ? String(data.id) : null,
                });
                const transaction = view.state.tr.replaceSelectionWith(node);
                view.dispatch(transaction);
            },
        });
    };
}
