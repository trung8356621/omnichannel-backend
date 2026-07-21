/**
 * Shared Article Editor operation lock + polling (WordPress sync, media slug fix).
 */

import { setArticleAutosaveLock } from './articleAutosaveLock';

const POLL_MS = 2500;

/** @type {ReturnType<typeof setInterval>|null} */
let pollTimer = null;
/** @type {number} */
let trackedArticleId = 0;
/** @type {boolean} */
let reloadScheduled = false;

function csrfToken() {
    return (
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || window.Livewire?.find?.(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__)?.csrf
        || ''
    );
}

/**
 * @param {'queued'|'processing'|'success'|'failed'|string} status
 * @param {'wordpress_sync'|'media_slug_fix'|string} operation
 * @param {{ stage?: string, error_message?: string }} [extra]
 */
export function showArticleOperationOverlay(status, operation = 'wordpress_sync', extra = {}) {
    const overlay = window.__seoArticleHeavyActionOverlay;
    if (!overlay?.show) {
        return;
    }

    const copy = operationCopy(operation, status, extra);
    overlay.show(operation === 'media_slug_fix' ? 'sync' : 'sync', {
        persistUntilUnload: status === 'queued' || status === 'processing' || status === 'success',
        title: copy.title,
        message: copy.message,
    });

    // Prefer custom title/message when overlay supports options.
    const el = document.getElementById(overlay.id);
    const titleEl = el?.querySelector?.('[data-heavy-action-title]');
    const messageEl = el?.querySelector?.('[data-heavy-action-message]');
    if (titleEl && copy.title) {
        titleEl.textContent = copy.title;
    }
    if (messageEl && copy.message) {
        messageEl.textContent = copy.message;
    }

    setArticleAutosaveLock('article-operation', true);
    window.dispatchEvent(
        new CustomEvent('article-wordpress-sync-lock', {
            detail: { action: operation, status },
        }),
    );
}

/**
 * @param {'wordpress_sync'|'media_slug_fix'|string} operation
 * @param {string} status
 * @param {{ stage?: string, error_message?: string }} extra
 */
function operationCopy(operation, status, extra = {}) {
    if (operation === 'media_slug_fix') {
        if (status === 'success') {
            return {
                title: 'Đã cập nhật slug ảnh',
                message: 'Đang tải lại dữ liệu…',
            };
        }
        if (status === 'failed') {
            return {
                title: 'Không cập nhật được slug ảnh',
                message: String(extra.error_message || 'Vui lòng tải lại trang.'),
            };
        }

        return {
            title: 'Đang sửa slug ảnh',
            message: 'Vui lòng không chỉnh sửa bài viết trong lúc đổi slug.',
        };
    }

    if (status === 'queued') {
        return {
            title: 'Đang chờ đồng bộ WordPress',
            message: 'Yêu cầu đã được đưa vào hàng đợi. Vui lòng không chỉnh sửa bài viết trong lúc đồng bộ.',
        };
    }
    if (status === 'processing') {
        const stage = String(extra.stage || '').trim();
        return {
            title: 'Đang đồng bộ bài viết lên WordPress',
            message: stage !== '' && stage !== 'processing' && stage !== 'queued'
                ? stage
                : 'Hệ thống đang xử lý nội dung và hình ảnh. Trang sẽ tự tải lại khi hoàn tất.',
        };
    }
    if (status === 'success') {
        return {
            title: 'Đồng bộ WordPress thành công',
            message: 'Đang tải lại dữ liệu…',
        };
    }
    if (status === 'failed') {
        return {
            title: 'Đồng bộ WordPress thất bại',
            message: String(extra.error_message || 'Đang tải lại trang để đọc trạng thái mới nhất…'),
        };
    }

    return {
        title: 'Đang đồng bộ với WordPress',
        message: 'Vui lòng chờ — không chỉnh sửa cho đến khi hoàn tất.',
    };
}

export function stopArticleOperationPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

/**
 * @param {number} articleId
 * @returns {Promise<{success: boolean, operation?: object|null, has_active_operation?: boolean}>}
 */
export async function fetchArticleOperationStatus(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, operation: null, has_active_operation: false };
    }

    const response = await fetch(`/api/seo/articles/${id}/operation-status`, {
        method: 'GET',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        return { success: false, operation: null, has_active_operation: false };
    }

    return data;
}

/**
 * @param {number} articleId
 * @param {{ delayMs?: number }} [options]
 */
export function scheduleArticleEditorReload(articleId, options = {}) {
    if (reloadScheduled) {
        return;
    }
    reloadScheduled = true;
    trackedArticleId = Number(articleId) || trackedArticleId;
    const delay = Number(options.delayMs ?? 500);

    window.__seoArticleHeavyActionOverlay && (window.__seoArticleHeavyActionOverlay.persistUntilUnload = true);
    setTimeout(() => {
        window.location.reload();
    }, delay);
}

/**
 * @param {number} articleId
 * @param {object|null|undefined} operation
 * @param {{ allowTerminalReload?: boolean }} [options]
 */
export function applyArticleOperationState(articleId, operation, options = {}) {
    const op = operation && typeof operation === 'object' ? operation : null;
    if (!op) {
        return;
    }

    const status = String(op.status || op.raw_status || '').trim();
    const type = String(op.type || op.operation || 'wordpress_sync').trim() || 'wordpress_sync';
    const publicStatus =
        status === 'pending' ? 'queued'
            : status === 'completed' ? 'success'
                : status;
    const allowTerminalReload = options.allowTerminalReload !== false;

    if (publicStatus === 'queued' || publicStatus === 'processing') {
        showArticleOperationOverlay(publicStatus, type, {
            stage: String(op.stage || ''),
            error_message: String(op.error_message || ''),
        });
        startArticleOperationPolling(articleId);

        return;
    }

    if (!allowTerminalReload) {
        return;
    }

    if (publicStatus === 'success') {
        showArticleOperationOverlay('success', type);
        scheduleArticleEditorReload(articleId, { delayMs: 600 });

        return;
    }

    if (publicStatus === 'failed') {
        showArticleOperationOverlay('failed', type, {
            error_message: String(op.error_message || ''),
        });
        scheduleArticleEditorReload(articleId, { delayMs: 2500 });
    }
}

/**
 * @param {number} articleId
 */
export function startArticleOperationPolling(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return;
    }

    trackedArticleId = id;
    stopArticleOperationPolling();

    const tick = async () => {
        if (reloadScheduled || trackedArticleId !== id) {
            stopArticleOperationPolling();

            return;
        }

        try {
            const data = await fetchArticleOperationStatus(id);
            const op = data.operation ?? null;
            if (!op) {
                return;
            }

            const status = String(op.status || '').trim();
            if (status === 'queued' || status === 'processing') {
                showArticleOperationOverlay(status, String(op.type || 'wordpress_sync'), {
                    stage: String(op.stage || ''),
                    error_message: String(op.error_message || ''),
                });

                return;
            }

            stopArticleOperationPolling();
            applyArticleOperationState(id, op);
        } catch {
            // keep polling; transient network errors
        }
    };

    void tick();
    pollTimer = setInterval(() => {
        void tick();
    }, POLL_MS);
}

/**
 * Bootstrap on Edit Article page load — restore overlay after F5.
 * @param {number} articleId
 */
export async function bootstrapArticleOperationLock(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return;
    }

    try {
        const data = await fetchArticleOperationStatus(id);
        if (!data.has_active_operation || !data.operation) {
            return;
        }

        applyArticleOperationState(id, data.operation);
    } catch {
        // ignore bootstrap failures
    }
}

export function installArticleOperationTracker() {
    window.__seoArticleOperationTracker = {
        show: showArticleOperationOverlay,
        poll: startArticleOperationPolling,
        stop: stopArticleOperationPolling,
        bootstrap: bootstrapArticleOperationLock,
        apply: applyArticleOperationState,
        reload: scheduleArticleEditorReload,
        fetchStatus: fetchArticleOperationStatus,
    };
}
