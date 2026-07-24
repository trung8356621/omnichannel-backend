@push('styles')
    @vite([
        'app/Addons/SeoContentAi/resources/js/article-media-picker-cache-bootstrap.js',
        'app/Addons/SeoContentAi/resources/css/article-edit-page.css',
    ])
    {{-- Inline fallback: topbar hide không phụ thuộc hashed CSS nếu Vite stale --}}
    <style id="article-editor-ui-revision-style">
        body.article-editor-page .fi-topbar,
        html.article-editor-page .fi-topbar,
        body:has(.article-editor-page) .fi-topbar,
        body:has([data-article-editor-runtime-marker="sticky-help-v1"]) .fi-topbar {
            display: block !important;
            position: fixed !important;
            top: 0;
            right: 0;
            left: 0;
            width: 100%;
            height: 0;
            min-height: 0;
            padding: 0;
            overflow: visible !important;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            z-index: 55;
            pointer-events: none;
        }
        body.article-editor-page .fi-topbar *,
        html.article-editor-page .fi-topbar *,
        body:has(.article-editor-page) .fi-topbar * {
            visibility: hidden !important;
            pointer-events: none !important;
        }
        body.article-editor-page .fi-topbar .global-help-topbar-host,
        body.article-editor-page .fi-topbar .global-help-topbar-host *,
        html.article-editor-page .fi-topbar .global-help-topbar-host,
        html.article-editor-page .fi-topbar .global-help-topbar-host * {
            /* Help nằm cạnh nút More trong toolbar — ẩn host fixed trên topbar */
            display: none !important;
            visibility: hidden !important;
            pointer-events: none !important;
        }
        body.article-editor-page .seo-article-editor-sticky-header {
            padding-right: 0.75rem;
        }
        body.article-editor-page .fi-main,
        body:has(.article-editor-page) .fi-main,
        body:has(.seo-article-edit-page) .fi-main {
            padding-inline: 0 !important;
            padding-top: 0 !important;
        }
        .seo-article-editor-sticky-header {
            position: sticky;
            top: 0;
            z-index: 40;
            width: 100%;
            margin: 0;
            border-radius: 0;
            border: 0;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.55rem 1rem;
            box-sizing: border-box;
            background: rgb(255 255 255 / 97%);
        }
        .wp-article-edit-layout {
            padding-inline: 0.75rem;
            padding-top: 0.75rem;
            box-sizing: border-box;
        }
        .seo-article-editor-sticky-header__title,
        .seo-article-editor-sticky-header__meta {
            display: none !important;
        }
    </style>
@endpush

@push('scripts')
<script>
    (function () {
        document.body.classList.add('article-editor-page');
        document.documentElement.classList.add('article-editor-page');
        window.__ARTICLE_EDITOR_UI_REVISION__ = 'sticky-help-v1';
        window.__ARTICLE_EDITOR_HELP__ = window.__ARTICLE_EDITOR_HELP__ || { revision: 'sticky-help-v1' };
        document.addEventListener('livewire:navigated', function () {
            if (!document.querySelector('[data-article-editor-runtime-marker="sticky-help-v1"], .seo-article-edit-page')) {
                document.body.classList.remove('article-editor-page');
                document.documentElement.classList.remove('article-editor-page');
            }
        });
    })();
</script>
@endpush

<x-filament-panels::page @class(['seo-article-edit-page', 'article-editor-page']) data-article-editor-page>
{{-- Runtime revision marker: sticky-help-v1 — single root: phải nằm TRONG page component --}}
<meta name="article-editor-ui-revision" content="sticky-help-v1">
<div
    data-article-editor-runtime-marker="sticky-help-v1"
    style="display:none"
    aria-hidden="true"
></div>
@if ($this->editorPreparing)
    <div class="mx-auto flex max-w-xl flex-col items-center gap-4 py-20 text-center" wire:poll.3s="pollEditorReadiness">
        <x-filament::loading-indicator class="h-10 w-10" />
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
            {{ __('seo-content-ai::filament.projects.article_editor_preparing_title') }}
        </h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ $this->editorPreparingMessage }}
        </p>
    </div>
@else
@php
    $seoActiveArticleOperation = app(\App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService::class)
        ->activeOperation($record);
    $seoHasActiveArticleOperation = is_array($seoActiveArticleOperation)
        && in_array((string) ($seoActiveArticleOperation['raw_status'] ?? ''), [
            \App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService::STATUS_PENDING,
            \App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService::STATUS_PROCESSING,
        ], true);
@endphp
<script>
    window.__SEO_ACTIVE_ARTICLE_OPERATION__ = @js($seoHasActiveArticleOperation ? $seoActiveArticleOperation : null);
</script>
@once
<script>
        window.__seoArticleHeavyActionOverlay = {
            id: 'seo-article-heavy-action-overlay',
            locked: false,
            persistUntilUnload: false,
            action: null,
            guardTimer: null,
            copyForAction(action) {
                const map = {
                    save: {
                        title: 'Đang cập nhật bài viết',
                        message: 'Đang lưu nội dung — vui lòng chờ…',
                    },
                    sync: {
                        title: 'Đang đưa vào hàng đợi WordPress',
                        message: 'Đang lưu và xếp hàng đồng bộ — tab sẽ tự đóng khi xong…',
                    },
                    restore: {
                        title: 'Đang đồng bộ từ WordPress',
                        message: 'Đang ghi đè bài viết bằng bản WordPress — vui lòng chờ…',
                    },
                    delete: {
                        title: 'Đang xóa bài viết',
                        message: 'Đang xóa bài viết — vui lòng chờ…',
                    },
                };

                return map[action] ?? map.sync;
            },
            show(action = 'sync', options = {}) {
                const allowed = ['save', 'sync', 'restore', 'delete'];
                const normalized = allowed.includes(action) ? action : 'sync';
                this.locked = true;
                this.persistUntilUnload = Boolean(options.persistUntilUnload);
                this.action = normalized;
                let overlay = document.getElementById(this.id);

                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.id = this.id;
                    overlay.className = 'seo-article-sync-overlay';
                    overlay.setAttribute('role', 'alert');
                    overlay.setAttribute('aria-live', 'assertive');
                    overlay.setAttribute('aria-busy', 'true');

                    const panel = document.createElement('div');
                    panel.className = 'seo-article-sync-overlay__panel';

                    const spinner = document.createElement('div');
                    spinner.className = 'seo-article-sync-overlay__spinner';
                    spinner.setAttribute('aria-hidden', 'true');

                    const title = document.createElement('strong');
                    title.setAttribute('data-heavy-action-title', '');

                    const message = document.createElement('span');
                    message.setAttribute('data-heavy-action-message', '');
                    message.textContent = 'Vui lòng chờ — không chỉnh sửa cho đến khi hoàn tất.';

                    panel.append(spinner, title, message);
                    overlay.appendChild(panel);
                    document.body.appendChild(overlay);
                }

                const copy = this.copyForAction(normalized);
                const title = overlay.querySelector('[data-heavy-action-title]');
                if (title) {
                    title.textContent = String(options.title || copy.title);
                }

                const message = overlay.querySelector('[data-heavy-action-message]');
                if (message) {
                    message.textContent = String(options.message || copy.message);
                }

                document.documentElement.classList.add('seo-article-sync-locked');
                document.querySelectorAll('body > *').forEach((element) => {
                    if (element.id !== this.id && !element.hasAttribute('inert')) {
                        element.setAttribute('data-seo-heavy-action-inert', '1');
                        element.setAttribute('inert', '');
                    }
                });

                if (!window.__seoArticleHeavyActionKeyBlocker) {
                    window.__seoArticleHeavyActionKeyBlocker = (event) => {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    };
                    window.addEventListener('keydown', window.__seoArticleHeavyActionKeyBlocker, true);
                }

                if (!this.guardTimer) {
                    this.guardTimer = window.setInterval(() => {
                        if (!this.locked) {
                            return;
                        }

                        if (
                            !document.getElementById(this.id)
                            || !document.documentElement.classList.contains('seo-article-sync-locked')
                        ) {
                            this.show(this.action ?? 'sync');
                        }
                    }, 150);
                }
            },
            setStatusMessage(text) {
                const overlay = document.getElementById(this.id);
                const message = overlay?.querySelector('[data-heavy-action-message]');
                if (message && text) {
                    message.textContent = String(text);
                }
            },
            hide() {
                if (this.persistUntilUnload) {
                    return;
                }

                this.locked = false;
                this.action = null;
                if (this.guardTimer) {
                    window.clearInterval(this.guardTimer);
                    this.guardTimer = null;
                }

                document.getElementById(this.id)?.remove();
                document.documentElement.classList.remove('seo-article-sync-locked');
                document.querySelectorAll('[data-seo-heavy-action-inert]').forEach((element) => {
                    element.removeAttribute('inert');
                    element.removeAttribute('data-seo-heavy-action-inert');
                });

                if (window.__seoArticleHeavyActionKeyBlocker) {
                    window.removeEventListener('keydown', window.__seoArticleHeavyActionKeyBlocker, true);
                    delete window.__seoArticleHeavyActionKeyBlocker;
                }
            },
        };

        window.__seoBeginArticleHeavyActionClient = function beginArticleHeavyActionClient(action = 'sync') {
            const allowed = ['save', 'sync', 'restore', 'delete'];
            const normalized = allowed.includes(action) ? action : 'sync';
            window.__seoArticleHeavyActionOverlay?.show(normalized);
            window.__seoArticleAutosaveLock?.set('article-heavy-action', true);
            window.dispatchEvent(new CustomEvent('article-wordpress-sync-lock', {
                detail: { action: normalized },
            }));

            return normalized;
        };

        window.__seoEndArticleHeavyActionClient = function endArticleHeavyActionClient() {
            if (window.__seoArticleHeavyActionEnding) {
                return;
            }

            if (window.__seoArticleHeavyActionOverlay?.persistUntilUnload) {
                return;
            }

            window.__seoArticleHeavyActionEnding = true;
            try {
                window.__seoArticleHeavyActionOverlay?.hide();
                window.__seoArticleAutosaveLock?.set('article-heavy-action', false);
                window.dispatchEvent(new CustomEvent('article-wordpress-sync-unlock'));
            } finally {
                window.__seoArticleHeavyActionEnding = false;
            }
        };

        window.__seoYieldForHeavyActionPaint = function yieldForHeavyActionPaint() {
            return new Promise((resolve) => {
                requestAnimationFrame(() => requestAnimationFrame(resolve));
            });
        };

        window.__seoRunWordPressPhasedSync = async function runWordPressSync(wire, payload = {}) {
            const articleId = @js((int) $record->getKey());
            await window.__seoRunArticleEditorApiAction?.('sync', wire, {
                html: payload.html ?? '',
                seoAnalysis: payload.seoAnalysis ?? null,
                articleId,
            });
        };

        (function bootstrapActiveArticleOperationOverlay() {
            const op = window.__SEO_ACTIVE_ARTICLE_OPERATION__;
            if (!op || typeof op !== 'object') {
                return;
            }
            const status = String(op.status || '');
            if (status !== 'queued' && status !== 'processing') {
                return;
            }
            // WP sync đang chạy — không khóa editor chờ; chuyển Sync Queue.
            window.__SEO_EDITOR_EXITING__ = true;
            window.__seoArticleOperationTracker?.stop?.();
            const url = typeof window.__SEO_ARTICLES_SYNC_QUEUE_URL__ === 'string'
                && window.__SEO_ARTICLES_SYNC_QUEUE_URL__.trim() !== ''
                ? window.__SEO_ARTICLES_SYNC_QUEUE_URL__.trim()
                : '/seo/articles?tab=queue';
            window.location.replace(url);
        })();
</script>
@endonce

    <div
        x-data="{
            syncPageLocked: false,
            heavyPageAction: null,
            mediaModalOpen: false,
            mediaModalMode: 'featured',
            mediaModalTargetBlockId: null,
            articleId: @js((int) $record->getKey()),
            featuredImageDraft: @js($featuredImageUrl ? [
                'url' => $featuredImageUrl,
                'wp_attachment_id' => (int) ($record->articleMetas->firstWhere('meta_key', \App\Addons\SeoContentAi\Services\ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0),
                'seo_media_id' => 0,
                'alt' => '',
                'slug' => '',
            ] : null),
            pickerLoading: false,
            pickerSearching: false,
            pickerSearchQuery: '',
            pickerTab: @js($mediaPickerTab),
            pickerImages: [],
            pickerCatalog: [],
            pickerPage: 1,
            pickerTotalPages: 1,
            pickerPerPage: 24,
            pickerError: null,
            _pickerSearchTimer: null,
            pickerSuppressLoadingOverlay: false,
            galleryPickerSelectedKeys: [],
            galleryPickerSelectedItems: {},
            galleryPickerAnchorKey: null,
            pickerWasOpened: false,
            pickerCustomTabs: [],
            pickerDisplayImages: [],
            pickerMoveMenu: { open: false, x: 0, y: 0, image: null },
            pickerAddTabMenu: { open: false, x: 0, y: 0 },
            pickerAddTabSearchKeyword: '',
            pickerCustomTabRename: { tabId: null, draft: '' },
            init() {
                this.syncFeaturedImageDraft();
                this.loadPickerCustomTabs();
                if (window.__SEO_EDITOR_EXITING__) {
                    return;
                }
                const activeOp = window.__SEO_ACTIVE_ARTICLE_OPERATION__;
                if (activeOp && typeof activeOp === 'object') {
                    // Tracker sẽ redirect Sync Queue cho WP sync queued/processing.
                    window.__seoArticleOperationTracker?.apply?.(this.articleId, activeOp);
                    return;
                }
                if (window.__seoArticleHeavyActionOverlay?.locked) {
                    this.syncPageLocked = true;
                    this.heavyPageAction = window.__seoArticleHeavyActionOverlay.action ?? 'sync';
                    return;
                }
                window.__seoArticleOperationTracker?.bootstrap?.(this.articleId);
            },
            syncFeaturedImageDraft() {
                const stored = window.__seoFeaturedImageStorage?.load?.(this.articleId);
                if (stored) {
                    this.featuredImageDraft = stored;
                }
            },
            onFeaturedImageUpdated(event) {
                const detail = event?.detail ?? {};
                const aid = Number(detail.articleId ?? detail.article_id ?? 0);
                if (aid > 0 && aid !== this.articleId) {
                    return;
                }

                const item = detail.item ?? detail;
                const url = String(item?.url ?? item?.src ?? '').trim();
                if (url === '') {
                    this.syncFeaturedImageDraft();

                    return;
                }

                this.featuredImageDraft = {
                    url,
                    wp_attachment_id: Number(item?.wp_attachment_id ?? item?.wpAttachmentId ?? 0) || 0,
                    seo_media_id: Number(item?.seo_media_id ?? item?.seoMediaId ?? 0) || 0,
                    alt: String(item?.alt ?? '').trim(),
                    slug: String(item?.slug ?? '').trim(),
                };
            },
            onFeaturedImageCleared(event) {
                const detail = event?.detail ?? {};
                const aid = Number(detail.articleId ?? detail.article_id ?? 0);
                if (aid !== this.articleId) {
                    return;
                }

                this.featuredImageDraft = null;
            },
            lockPageForHeavyAction(action = 'sync') {
                if (this.syncPageLocked || document.getElementById('seo-article-heavy-action-overlay')) {
                    return false;
                }

                this.syncPageLocked = true;
                this.heavyPageAction = window.__seoBeginArticleHeavyActionClient?.(action) ?? (action === 'save' ? 'save' : 'sync');

                clearTimeout(this._heavyActionUnlockTimer);
                this._heavyActionUnlockTimer = setTimeout(() => {
                    if (window.__seoArticleHeavyActionOverlay?.persistUntilUnload) {
                        return;
                    }

                    if (!this.$wire?.articleHeavyActionBusy) {
                        window.__seoEndArticleHeavyActionClient?.();
                    }
                }, 120000);

                return true;
            },
            unlockPageAfterHeavyActionFailure() {
                if (window.__seoArticleHeavyActionOverlay?.persistUntilUnload) {
                    return;
                }

                clearTimeout(this._heavyActionUnlockTimer);
                this.syncPageLocked = false;
                this.heavyPageAction = null;
            },
            galleryPickerSelectedCount() {
                return this.galleryPickerSelectedKeys.length;
            },
            clearGalleryPickerSelection() {
                this.galleryPickerSelectedKeys = [];
                this.galleryPickerSelectedItems = {};
                this.galleryPickerAnchorKey = null;
            },
            isGalleryPickerSelected(key) {
                return this.galleryPickerSelectedKeys.includes(key);
            },
            readGalleryPickerItemFromEl(el) {
                return {
                    wp_attachment_id: Number(el?.dataset?.pickerWp || 0),
                    seo_media_id: Number(el?.dataset?.pickerSeo || 0),
                    url: String(el?.dataset?.pickerUrl || ''),
                    alt: String(el?.dataset?.pickerAlt || ''),
                    slug: String(el?.dataset?.pickerSlug || ''),
                    media_type: String(el?.dataset?.pickerMediaType || 'image'),
                };
            },
            visibleGalleryPickerElements(el) {
                const grid = el?.closest('.seo-article-media-modal__grid');
                if (!grid) {
                    return [];
                }

                return Array.from(grid.querySelectorAll('.seo-article-media-modal__item[data-picker-key]'))
                    .filter((node) => node.offsetParent !== null);
            },
            toggleGalleryPickerItem(event, key, el) {
                if (this.mediaModalMode !== 'gallery' || !key) {
                    return;
                }

                const shiftKey = event?.shiftKey === true;
                if (shiftKey && this.galleryPickerAnchorKey) {
                    const nodes = this.visibleGalleryPickerElements(el);
                    const keys = nodes.map((node) => node.dataset.pickerKey);
                    const fromIndex = keys.indexOf(this.galleryPickerAnchorKey);
                    const toIndex = keys.indexOf(key);
                    if (fromIndex !== -1 && toIndex !== -1) {
                        const start = Math.min(fromIndex, toIndex);
                        const end = Math.max(fromIndex, toIndex);
                        for (let i = start; i <= end; i += 1) {
                            const node = nodes[i];
                            const itemKey = keys[i];
                            if (!itemKey) {
                                continue;
                            }
                            this.galleryPickerSelectedItems[itemKey] = this.readGalleryPickerItemFromEl(node);
                            if (!this.galleryPickerSelectedKeys.includes(itemKey)) {
                                this.galleryPickerSelectedKeys.push(itemKey);
                            }
                        }
                        this.galleryPickerAnchorKey = key;
                        return;
                    }
                }

                if (this.isGalleryPickerSelected(key)) {
                    this.galleryPickerSelectedKeys = this.galleryPickerSelectedKeys.filter((item) => item !== key);
                    delete this.galleryPickerSelectedItems[key];
                } else {
                    this.galleryPickerSelectedKeys.push(key);
                    this.galleryPickerSelectedItems[key] = this.readGalleryPickerItemFromEl(el);
                }
                this.galleryPickerAnchorKey = key;
            },
            confirmGalleryPickerSelection() {
                if (this.galleryPickerSelectedCount() === 0) {
                    return;
                }

                const items = this.galleryPickerSelectedKeys
                    .map((key) => this.galleryPickerSelectedItems[key])
                    .filter((item) => item && String(item.url || '').trim() !== '');

                if (items.length === 0) {
                    return;
                }

                const articleId = @js((int) $record->getKey());
                const appendAlbum = window.__seoProductAlbumStorage?.append;
                if (typeof appendAlbum === 'function' && articleId) {
                    appendAlbum(articleId, items);
                    this.clearGalleryPickerSelection();
                    this.closeArticleMediaModal();
                    return;
                }

                $wire.confirmGallerySelectionFromPicker({ items }).then(() => {
                    this.clearGalleryPickerSelection();
                });
            },
            pickerSearchPlaceholder() {
                if (this.pickerTab === 'article') {
                    return 'Tìm slug, alt trong bài…';
                }
                if (this.pickerTab === 'local') {
                    return 'Tìm slug, alt, tên file (Laravel)…';
                }
                if (this.isCustomPickerTab()) {
                    if (this.isBlankCustomTab()) {
                        return 'Lọc ảnh đã chuyển trong nhóm trống…';
                    }

                    return 'Tìm slug, alt, caption (WP search tab)…';
                }

                return 'Tìm slug, alt, caption (WP search)…';
            },
            customTabsApi() {
                return window.__seoArticleMediaPickerCustomTabs || {};
            },
            articleDomain() {
                const picker = window.__SEO_ARTICLE_MEDIA_PICKER__ || {};
                const api = this.customTabsApi();
                const raw = String(picker.articleDomain || '').trim();
                if (raw === '') {
                    return '';
                }

                return api.normalizeDomain ? api.normalizeDomain(raw) : raw.toLowerCase();
            },
            articleDomainReady() {
                return this.articleDomain() !== '';
            },
            isCustomPickerTab(tab = this.pickerTab) {
                const api = this.customTabsApi();

                return api.isCustomTab ? api.isCustomTab(tab) : String(tab || '').startsWith('custom:');
            },
            customTabIdFromPickerTab(tab = this.pickerTab) {
                const api = this.customTabsApi();

                return api.customTabIdFromPickerTab
                    ? api.customTabIdFromPickerTab(tab)
                    : String(tab || '').replace(/^custom:/, '');
            },
            resolveCustomTab(tabId = this.customTabIdFromPickerTab()) {
                const id = String(tabId || '').trim();
                if (id === '') {
                    return null;
                }

                return (Array.isArray(this.pickerCustomTabs) ? this.pickerCustomTabs : [])
                    .find((row) => String(row?.id || '') === id) || null;
            },
            loadPickerCustomTabs() {
                const api = this.customTabsApi();
                const domain = this.articleDomain();
                if (domain === '') {
                    this.pickerCustomTabs = [];

                    return;
                }

                this.pickerCustomTabs = api.loadTabs?.(domain, this.articleId) ?? [];
            },
            defaultPickerSearchKeyword() {
                const picker = window.__SEO_ARTICLE_MEDIA_PICKER__ || {};

                return String(picker.defaultSearchKeyword || '').trim();
            },
            stagedCountForTab(tabId) {
                const api = this.customTabsApi();

                return api.countStagedImages?.(this.articleId, tabId) ?? 0;
            },
            refreshPickerDisplayImages() {
                if (!this.isCustomPickerTab()) {
                    this.pickerDisplayImages = Array.isArray(this.pickerImages) ? [...this.pickerImages] : [];

                    return;
                }

                const tabId = this.customTabIdFromPickerTab();
                let staged = this.customTabsApi().loadStagedImages?.(this.articleId, tabId) ?? [];

                if (this.isBlankCustomTab(tabId)) {
                    const q = this.pickerSearchQuery.trim().toLowerCase();
                    if (q !== '') {
                        staged = staged.filter((row) => {
                            const haystack = [row.slug, row.alt, row.url]
                                .filter((part) => part)
                                .join(' ')
                                .toLowerCase();

                            return haystack.includes(q);
                        });
                    }

                    this.pickerDisplayImages = staged;

                    return;
                }

                const stagedKeys = new Set(staged.map((row) => String(row.picker_key || '')));
                const fetched = (Array.isArray(this.pickerImages) ? this.pickerImages : [])
                    .filter((row) => !stagedKeys.has(String(row.picker_key || '')));

                this.pickerDisplayImages = [...staged, ...fetched];
            },
            isBlankCustomTab(tabId = this.customTabIdFromPickerTab()) {
                const customTab = this.resolveCustomTab(tabId);

                return customTab?.blank === true;
            },
            clampPickerFloatingMenuPosition(left, top, menuWidth = 208, menuHeight = 120) {
                const pad = 8;
                const maxLeft = Math.max(pad, window.innerWidth - menuWidth - pad);
                const maxTop = Math.max(pad, window.innerHeight - menuHeight - pad);

                return {
                    x: Math.max(pad, Math.min(left, maxLeft)),
                    y: Math.max(pad, Math.min(top, maxTop)),
                };
            },
            pickerFloatingMenuStyle(menu) {
                return `left:${Number(menu?.x || 0)}px;top:${Number(menu?.y || 0)}px;`;
            },
            togglePickerAddTabMenu(event) {
                if (!this.articleDomainReady()) {
                    this.pickerError = 'Chưa xác định được domain của bài viết. Không thể thêm tab tìm kiếm.';

                    return;
                }

                if (!this.pickerWordPressLinked()) {
                    this.pickerError = 'Bài viết chưa được liên kết WordPress. Hãy đồng bộ bài viết trước khi tạo tab tìm kiếm.';

                    return;
                }

                this.closePickerMoveMenu();

                if (this.pickerAddTabMenu.open) {
                    this.closePickerAddTabMenu();

                    return;
                }

                const rect = event?.currentTarget?.getBoundingClientRect?.();
                if (!rect) {
                    return;
                }

                const position = this.clampPickerFloatingMenuPosition(rect.left, rect.bottom + 6, 208, 132);
                this.pickerAddTabMenu = {
                    open: true,
                    x: position.x,
                    y: position.y,
                };
                this.$nextTick(() => {
                    this.$refs.pickerAddTabSearchInput?.focus?.();
                });
            },
            closePickerAddTabMenu() {
                this.pickerAddTabMenu = { open: false, x: 0, y: 0 };
                this.pickerAddTabSearchKeyword = '';
            },
            createCustomPickerTab(keyword, { blank = false } = {}) {
                if (!this.articleDomainReady()) {
                    this.pickerError = 'Chưa xác định được domain của bài viết. Không thể thêm tab tìm kiếm.';

                    return;
                }

                if (!this.pickerWordPressLinked()) {
                    this.pickerError = 'Bài viết chưa được liên kết WordPress. Hãy đồng bộ bài viết trước khi tạo tab tìm kiếm.';

                    return;
                }

                const normalized = String(keyword || '').trim();
                if (!blank && normalized === '') {
                    return;
                }

                const api = this.customTabsApi();
                const created = api.addTab?.(this.articleDomain(), normalized, {
                    blank,
                    articleId: this.articleId,
                });
                if (!created?.id) {
                    return;
                }

                this.closePickerAddTabMenu();
                this.loadPickerCustomTabs();
                this.switchPickerTab(`custom:${created.id}`);
            },
            addBlankCustomPickerTab() {
                this.createCustomPickerTab('', { blank: true });
            },
            addSearchCustomPickerTabFromInput() {
                const keyword = String(this.pickerAddTabSearchKeyword || '').trim();
                if (keyword === '') {
                    return;
                }

                this.createCustomPickerTab(keyword);
            },
            async removeCustomPickerTab(tabId) {
                const id = String(tabId || '').trim();
                if (id === '') {
                    return;
                }

                if (!this.articleDomainReady()) {
                    this.pickerError = 'Chưa xác định được domain của bài viết. Không thể xóa tab tìm kiếm.';

                    return;
                }

                if (this.pickerCustomTabRename.tabId === id) {
                    this.cancelRenameCustomPickerTab();
                }

                if (!window.confirm('Xóa tab tìm kiếm này và ảnh đã chuyển tạm trong tab?')) {
                    return;
                }

                const activeCustomId = this.isCustomPickerTab() ? this.customTabIdFromPickerTab() : '';
                this.customTabsApi().removeTab?.(this.articleDomain(), id, this.articleId);
                this.loadPickerCustomTabs();

                if (activeCustomId === id) {
                    await this.switchPickerTab('original');
                }
            },
            startRenameCustomPickerTab(customTab) {
                if (!customTab?.blank || !customTab?.id || !this.articleDomainReady()) {
                    return;
                }

                this.pickerCustomTabRename = {
                    tabId: String(customTab.id),
                    draft: String(customTab.label || 'Nhóm trống'),
                };
                this.$nextTick(() => {
                    const input = this.$refs.mediaModalPanel?.querySelector?.(
                        `[data-picker-tab-rename='${String(customTab.id)}']`,
                    );
                    input?.focus?.();
                    input?.select?.();
                });
            },
            cancelRenameCustomPickerTab() {
                this.pickerCustomTabRename = { tabId: null, draft: '' };
            },
            commitRenameCustomPickerTab() {
                const tabId = String(this.pickerCustomTabRename?.tabId || '').trim();
                const draft = String(this.pickerCustomTabRename?.draft || '').trim();
                if (tabId === '' || draft === '' || !this.articleDomainReady()) {
                    this.cancelRenameCustomPickerTab();

                    return;
                }

                this.customTabsApi().renameTab?.(this.articleId, tabId, draft);
                this.loadPickerCustomTabs();
                this.cancelRenameCustomPickerTab();
            },
            removeStagedPickerImage(image) {
                if (!this.isCustomPickerTab() || image?.staged !== true) {
                    return;
                }

                const tabId = this.customTabIdFromPickerTab();
                const pickerKey = String(image?.picker_key || '').trim();
                if (tabId === '' || pickerKey === '') {
                    return;
                }

                this.customTabsApi().unstageImage?.(this.articleId, tabId, pickerKey);
                this.loadPickerCustomTabs();
                this.refreshPickerDisplayImages();
            },
            openPickerMoveMenu(image, event) {
                if (!image || this.pickerTab !== 'original') {
                    return;
                }

                if ((Array.isArray(this.pickerCustomTabs) ? this.pickerCustomTabs : []).length === 0) {
                    window.alert('Chưa có tab tìm kiếm. Nhấn + sau tab Gốc (WP) để tạo tab.');

                    return;
                }

                const button = event?.currentTarget;
                const rect = button?.getBoundingClientRect?.();
                if (!rect) {
                    return;
                }

                this.closePickerAddTabMenu();
                const position = this.clampPickerFloatingMenuPosition(rect.left, rect.bottom + 6, 176, 160);
                this.pickerMoveMenu = {
                    open: true,
                    x: position.x,
                    y: position.y,
                    image,
                };
            },
            closePickerMoveMenu() {
                this.pickerMoveMenu = { open: false, x: 0, y: 0, image: null };
            },
            movePickerImageToCustomTab(tabId) {
                const image = this.pickerMoveMenu?.image;
                const id = String(tabId || '').trim();
                if (!image || id === '') {
                    this.closePickerMoveMenu();

                    return;
                }

                this.customTabsApi().stageImage?.(this.articleId, id, image);
                this.loadPickerCustomTabs();

                if (this.isCustomPickerTab() && this.customTabIdFromPickerTab() === id) {
                    this.refreshPickerDisplayImages();
                }

                this.closePickerMoveMenu();
            },
            pickerMoveMenuStyle() {
                return this.pickerFloatingMenuStyle(this.pickerMoveMenu);
            },
            pickerAddTabMenuStyle() {
                return this.pickerFloatingMenuStyle(this.pickerAddTabMenu);
            },
            schedulePickerSearch() {
                this.pickerSearching = true;
                clearTimeout(this._pickerSearchTimer);
                this._pickerSearchTimer = setTimeout(() => this.runPickerSearch(), 400);
            },
            pickerSiteId() {
                const picker = window.__SEO_ARTICLE_MEDIA_PICKER__ || {};

                return Number(picker.siteId || 0);
            },
            pickerWordPressLinked() {
                return Boolean(window.__SEO_ARTICLE_MEDIA_PICKER__?.wordPressLinked);
            },
            async fetchPickerImages({ resetPage = false, skipCache = false } = {}) {
                if (this.pickerTab === 'article') {
                    await this.loadArticleTabFromEditor();

                    return;
                }

                const isCustomTab = this.isCustomPickerTab();
                const apiTab = isCustomTab ? 'original' : this.pickerTab;

                if (isCustomTab && this.isBlankCustomTab()) {
                    this.pickerImages = [];
                    this.pickerTotalPages = 1;
                    this.pickerError = null;
                    this.refreshPickerDisplayImages();
                    this.pickerLoading = false;
                    this.pickerSearching = false;

                    return;
                }

                if (apiTab === 'original' && !this.pickerWordPressLinked()) {
                    this.pickerImages = [];
                    this.pickerCatalog = [];
                    this.pickerDisplayImages = [];
                    this.pickerError = 'Bài viết chưa được liên kết WordPress. Hãy đồng bộ bài viết trước khi chọn ảnh WordPress.';
                    this.pickerLoading = false;
                    this.pickerSearching = false;

                    return;
                }

                if (resetPage) {
                    this.pickerPage = 1;
                }

                if (
                    !skipCache
                    && this.pickerSearchQuery.trim() !== ''
                    && this.tryHydratePickerFromCache(this.pickerTab, this.pickerPage || 1)
                ) {
                    return;
                }

                if (
                    !skipCache
                    && !isCustomTab
                    && this.pickerSearchQuery.trim() === ''
                    && this.tryHydratePickerFromCache(this.pickerTab, this.pickerPage || 1)
                ) {
                    return;
                }

                this.pickerLoading = true;
                this.pickerError = null;

                try {
                    const picker = window.__SEO_ARTICLE_MEDIA_PICKER__ || {};
                    const endpoint = String(picker.endpoint || window.__SEO_ARTICLE_MEDIA_PICKER_ENDPOINT__ || '').trim();
                    if (!endpoint) {
                        throw new Error('Media picker endpoint is unavailable');
                    }

                    const url = new URL(endpoint, window.location.origin);
                    url.searchParams.set('tab', apiTab);
                    url.searchParams.set('page', String(this.pickerPage || 1));
                    if (this.pickerSearchQuery.trim() !== '') {
                        url.searchParams.set('search', this.pickerSearchQuery.trim());
                    }

                    const response = await fetch(url.toString(), {
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const detail = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        throw new Error(detail?.error || `Media picker failed with status ${response.status}`);
                    }

                    if (isCustomTab) {
                        detail.tab = this.pickerTab;
                    }

                    this.applyPickerPayload(detail);
                    this.persistPickerCacheFromFetch(detail);
                } catch (error) {
                    this.pickerImages = [];
                    this.pickerDisplayImages = [];
                    this.pickerError = error?.message || 'Không tải được thư viện media.';
                } finally {
                    this.pickerLoading = false;
                    this.pickerSearching = false;
                }
            },
            isPickerCacheableTab(tab) {
                if (this.isCustomPickerTab(tab)) {
                    return true;
                }

                const cacheApi = window.__seoArticleMediaPickerCache;

                if (cacheApi?.isCacheableTab) {
                    return cacheApi.isCacheableTab(tab);
                }

                return tab === 'original' || tab === 'local';
            },
            applyPickerPayload(detail) {
                if (!detail || typeof detail !== 'object') {
                    return;
                }

                this.pickerTab = detail.tab ?? this.pickerTab;
                this.pickerPage = Number(detail.page || 1);
                this.pickerTotalPages = Number(detail.totalPages || 1);
                this.pickerError = detail.error ? String(detail.error) : null;
                if (Array.isArray(detail.catalog) && detail.catalog.length) {
                    this.pickerCatalog = detail.catalog;
                } else if (this.pickerTab === 'article') {
                    this.pickerCatalog = Array.isArray(detail.images) ? detail.images : [];
                }
                this.pickerImages = Array.isArray(detail.images) ? detail.images : [];
                this.refreshPickerDisplayImages();
                this.savePickerSession();
            },
            tryHydratePickerFromCache(tab, page) {
                if (!this.isPickerCacheableTab(tab)) {
                    return false;
                }

                const search = this.pickerSearchQuery.trim();
                if (this.isCustomPickerTab(tab)) {
                    if (search === '') {
                        return false;
                    }
                } else if (search !== '') {
                    return false;
                }

                if (this.isCustomPickerTab(tab)) {
                    const tabId = this.customTabIdFromPickerTab(tab);
                    const cached = this.customTabsApi().readFetchCache?.(
                        this.articleId,
                        tabId,
                        page,
                        search,
                    );
                    if (!cached) {
                        return false;
                    }

                    this.applyPickerPayload(cached);
                    this.pickerLoading = false;
                    this.pickerSearching = false;

                    return true;
                }

                if (search !== '') {
                    return false;
                }

                const siteId = this.pickerSiteId();
                if (siteId <= 0) {
                    return false;
                }

                const cacheApi = window.__seoArticleMediaPickerCache;
                if (!cacheApi?.read) {
                    return false;
                }

                const cached = cacheApi.read(siteId, tab, page);
                if (!cached) {
                    return false;
                }

                this.applyPickerPayload(cached);
                this.pickerLoading = false;
                this.pickerSearching = false;

                return true;
            },
            persistPickerCacheFromFetch(detail) {
                const tab = detail?.tab ?? this.pickerTab;
                if (!this.isPickerCacheableTab(tab)) {
                    return;
                }

                const search = this.pickerSearchQuery.trim();
                if (this.isCustomPickerTab(tab)) {
                    if (search === '') {
                        return;
                    }

                    const tabId = this.customTabIdFromPickerTab(tab);
                    this.customTabsApi().writeFetchCache?.(
                        this.articleId,
                        tabId,
                        Number(detail?.page || this.pickerPage || 1),
                        search,
                        detail,
                    );

                    return;
                }

                if (search !== '') {
                    return;
                }

                const siteId = this.pickerSiteId();
                if (siteId <= 0) {
                    return;
                }

                const cacheApi = window.__seoArticleMediaPickerCache;
                if (!cacheApi?.write) {
                    return;
                }

                cacheApi.write(siteId, tab, Number(detail?.page || this.pickerPage || 1), detail);
            },
            applyPickerView() {
                const q = this.pickerSearchQuery.trim().toLowerCase();
                let rows = Array.isArray(this.pickerCatalog) ? [...this.pickerCatalog] : [];
                if (q !== '') {
                    rows = rows.filter((row) => {
                        const haystack = [row.slug, row.alt, row.url]
                            .filter((part) => part)
                            .join(' ')
                            .toLowerCase();

                        return haystack.includes(q);
                    });
                }

                const perPage = this.pickerPerPage;
                const totalPages = Math.max(1, Math.ceil(rows.length / perPage) || 1);
                this.pickerTotalPages = totalPages;
                if (this.pickerPage > totalPages) {
                    this.pickerPage = totalPages;
                }
                if (this.pickerPage < 1) {
                    this.pickerPage = 1;
                }

                const offset = (this.pickerPage - 1) * perPage;
                this.pickerImages = rows.slice(offset, offset + perPage);
                this.refreshPickerDisplayImages();
                this.savePickerSession();
            },
            loadArticleTabFromEditor({ preservePage = false } = {}) {
                return new Promise((resolve) => {
                    this.pickerLoading = true;
                    this.pickerSearching = false;
                    const timeout = setTimeout(() => {
                        cleanup();
                        this.pickerCatalog = [];
                        this.pickerImages = [];
                        this.pickerLoading = false;
                        resolve([]);
                    }, 8000);
                    const onCatalog = (event) => {
                        cleanup();
                        const images = Array.isArray(event.detail?.images) ? event.detail.images : [];
                        this.pickerCatalog = images;
                        this.pickerTab = 'article';
                        if (!preservePage) {
                            this.pickerPage = 1;
                        }
                        this.pickerError = null;
                        this.applyPickerView();
                        this.pickerLoading = false;
                        this.pickerSearching = false;
                        resolve(images);
                    };
                    const cleanup = () => {
                        clearTimeout(timeout);
                        window.removeEventListener('seo-editor-images-catalog', onCatalog);
                    };

                    window.addEventListener('seo-editor-images-catalog', onCatalog);
                    window.dispatchEvent(new CustomEvent('seo-request-editor-images-catalog'));
                });
            },
            async runPickerSearch() {
                if (this.pickerTab === 'article') {
                    this.pickerPage = 1;
                    this.applyPickerView();
                    this.pickerSearching = false;

                    return;
                }

                if (this.isCustomPickerTab() && this.isBlankCustomTab()) {
                    this.refreshPickerDisplayImages();
                    this.pickerSearching = false;

                    return;
                }

                this.pickerSuppressLoadingOverlay = false;
                await this.fetchPickerImages({ resetPage: true });
            },
            async switchPickerTab(tab) {
                if (this.pickerTab === tab) {
                    return;
                }

                if (tab === 'original' && !this.pickerWordPressLinked()) {
                    this.pickerError = 'Bài viết chưa được liên kết WordPress. Hãy đồng bộ bài viết trước khi chọn ảnh WordPress.';

                    return;
                }

                if (this.isCustomPickerTab(tab) && !this.pickerWordPressLinked()) {
                    this.pickerError = 'Bài viết chưa được liên kết WordPress. Hãy đồng bộ bài viết trước khi dùng tab tìm kiếm.';

                    return;
                }

                this.pickerTab = tab;
                this.pickerCatalog = [];
                this.pickerImages = [];
                this.pickerDisplayImages = [];
                this.pickerPage = 1;
                this.pickerSearching = false;
                this.clearGalleryPickerSelection();
                this.closePickerMoveMenu();
                this.closePickerAddTabMenu();

                if (this.isCustomPickerTab(tab)) {
                    const customTab = this.resolveCustomTab(this.customTabIdFromPickerTab(tab));
                    if (!customTab) {
                        await this.switchPickerTab('original');

                        return;
                    }

                    this.pickerSearchQuery = customTab.blank === true
                        ? ''
                        : String(customTab.keyword || '').trim();
                } else {
                    this.pickerSearchQuery = '';
                }

                if (tab === 'article') {
                    await this.loadArticleTabFromEditor();
                    this.savePickerSession();

                    return;
                }

                const hydrated = this.tryHydratePickerFromCache(tab, 1);
                if (hydrated) {
                    this.savePickerSession();

                    return;
                }

                if (this.isCustomPickerTab(tab) && this.isBlankCustomTab(this.customTabIdFromPickerTab(tab))) {
                    this.pickerImages = [];
                    this.pickerTotalPages = 1;
                    this.pickerError = null;
                    this.refreshPickerDisplayImages();
                    this.savePickerSession();

                    return;
                }

                this.pickerLoading = true;
                await this.fetchPickerImages({ resetPage: true });
                this.savePickerSession();
            },
            async pickerPrevPage() {
                if (this.pickerPage <= 1) {
                    return;
                }

                if (this.pickerTab === 'article') {
                    this.pickerPage -= 1;
                    this.applyPickerView();

                    return;
                }

                const prevPage = this.pickerPage - 1;
                if (this.tryHydratePickerFromCache(this.pickerTab, prevPage)) {
                    return;
                }

                this.pickerSuppressLoadingOverlay = false;
                this.pickerPage = prevPage;
                await this.fetchPickerImages();
            },
            async pickerNextPage() {
                if (this.pickerPage >= this.pickerTotalPages) {
                    return;
                }

                if (this.pickerTab === 'article') {
                    this.pickerPage += 1;
                    this.applyPickerView();

                    return;
                }

                const nextPage = this.pickerPage + 1;
                if (this.tryHydratePickerFromCache(this.pickerTab, nextPage)) {
                    return;
                }

                this.pickerSuppressLoadingOverlay = false;
                this.pickerPage = nextPage;
                await this.fetchPickerImages();
            },
            async reloadPickerImages() {
                await this.fetchPickerImages({ skipCache: true });
            },
            pickerSessionStorageKey() {
                return `seo-article-picker-ui:${this.articleId}`;
            },
            savePickerSession() {
                if (!this.articleId) {
                    return;
                }

                try {
                    sessionStorage.setItem(
                        this.pickerSessionStorageKey(),
                        JSON.stringify({
                            tab: this.pickerTab,
                            page: Math.max(1, Number(this.pickerPage) || 1),
                            search: String(this.pickerSearchQuery ?? ''),
                            totalPages: Math.max(1, Number(this.pickerTotalPages) || 1),
                            touched: true,
                        }),
                    );
                } catch {
                    // ignore quota / private mode
                }
            },
            restorePickerSession() {
                if (!this.articleId) {
                    return false;
                }

                try {
                    const raw = sessionStorage.getItem(this.pickerSessionStorageKey());
                    if (!raw) {
                        return false;
                    }

                    const session = JSON.parse(raw);
                    if (!session?.touched) {
                        return false;
                    }

                    if (session.tab) {
                        this.pickerTab = session.tab;
                    }

                    this.pickerPage = Math.max(1, Number(session.page) || 1);
                    this.pickerSearchQuery = String(session.search ?? '');
                    if (this.isCustomPickerTab() && this.pickerSearchQuery.trim() === '') {
                        const customTab = this.resolveCustomTab(this.customTabIdFromPickerTab());
                        if (customTab?.keyword) {
                            this.pickerSearchQuery = String(customTab.keyword);
                        }
                    }
                    this.pickerTotalPages = Math.max(1, Number(session.totalPages) || 1);

                    return true;
                } catch {
                    return false;
                }
            },
            pickerHasSession() {
                return (Array.isArray(this.pickerCatalog) && this.pickerCatalog.length > 0)
                    || (Array.isArray(this.pickerImages) && this.pickerImages.length > 0);
            },
            shouldRestorePickerSession() {
                return this.restorePickerSession() || this.pickerHasSession();
            },
            async openArticleMediaModal(mode, blockId = null) {
                this.mediaModalMode = mode === 'gallery' ? 'gallery' : (mode === 'editor-block' ? 'editor-block' : 'featured');
                this.mediaModalTargetBlockId = blockId;

                if (this.pickerWasOpened || (Array.isArray(this.pickerImages) && this.pickerImages.length > 0)) {
                    this.clearGalleryPickerSelection();
                    this.refreshPickerDisplayImages();
                    this.mediaModalOpen = true;
                    window.__seoArticleAutosaveLock?.set('media-picker-modal', true);

                    return;
                }

                this.clearGalleryPickerSelection();
                this.mediaModalOpen = true;
                window.__seoArticleAutosaveLock?.set('media-picker-modal', true);

                if (mode === 'editor-block') {
                    this.pickerTab = 'article';
                    await this.loadArticleTabFromEditor();
                    this.pickerWasOpened = true;

                    return;
                }

                this.pickerTab = this.pickerWordPressLinked() ? 'original' : 'local';
                this.pickerLoading = true;
                if (this.tryHydratePickerFromCache(this.pickerTab, 1)) {
                    this.pickerWasOpened = true;

                    return;
                }

                await this.fetchPickerImages({ resetPage: true });
                this.pickerWasOpened = true;
            },
            closeArticleMediaModal() {
                this.mediaModalOpen = false;
                this.closePickerMoveMenu();
                this.closePickerAddTabMenu();
                window.__seoArticleAutosaveLock?.set('media-picker-modal', false);
            },
            handlePickerImageClick(event, image, el) {
                if (!image || !String(image.url || '').trim()) {
                    return;
                }

                if (this.mediaModalMode === 'gallery') {
                    this.toggleGalleryPickerItem(event, image.picker_key, el);

                    return;
                }

                this.selectPickerImage(image);
            },
            selectPickerImage(image) {
                if (!image || !String(image.url || '').trim()) {
                    return;
                }

                if (this.mediaModalMode === 'gallery') {
                    return;
                }

                const mediaType = String(image.media_type || 'image').toLowerCase() === 'video'
                    ? 'video'
                    : 'image';
                const payload = {
                    url: String(image.url || '').trim(),
                    alt: String(image.alt || '').trim(),
                    slug: String(image.slug || '').trim(),
                    wpAttachmentId: Number(image.wp_attachment_id || 0),
                    seoMediaId: Number(image.seo_media_id || 0),
                    mediaType,
                };

                if (this.mediaModalMode === 'editor-block') {
                    window.dispatchEvent(new CustomEvent('editor-block-image-selected', {
                        detail: {
                            ...payload,
                            blockId: String(this.mediaModalTargetBlockId || ''),
                            attachmentId: payload.wpAttachmentId,
                            pickerTab: this.pickerTab,
                        },
                    }));
                    this.closeArticleMediaModal();

                    return;
                }

                if (mediaType !== 'image') {
                    return;
                }

                const featured = window.__seoFeaturedImageStorage?.save?.(this.articleId, payload) ?? {
                    ...payload,
                    wp_attachment_id: payload.wpAttachmentId,
                    seo_media_id: payload.seoMediaId,
                };
                this.featuredImageDraft = featured;
                window.dispatchEvent(new CustomEvent('article-media-selected', {
                    detail: {
                        ...payload,
                        mode: 'featured',
                        pickerTab: this.pickerTab,
                    },
                }));
                this.closeArticleMediaModal();
            },
            localMediaUploading: false,
            openLocalMediaUploadPicker() {
                if (this.localMediaUploading) {
                    return;
                }
                this.$refs.localMediaFileInput?.click();
            },
            async onLocalMediaFilesSelected(event) {
                const input = event?.target;
                const files = input?.files;
                if (!files?.length || this.localMediaUploading) {
                    return;
                }

                if (this.pickerTab !== 'local') {
                    await this.switchPickerTab('local');
                }

                this.localMediaUploading = true;
                window.__seoArticleAutosaveLock?.set('media-upload', true);
                this.pickerLoading = true;

                const picker = window.__SEO_ARTICLE_MEDIA_PICKER__ || {};
                const i18n = picker.i18n || {};

                try {
                    const uploadFn = window.seoUploadLocalMediaFiles;
                    if (typeof uploadFn !== 'function') {
                        throw new Error('Upload chưa sẵn sàng — tải lại trang.');
                    }

                    const uploaded = await uploadFn(files, {
                        articleId: picker.articleId ?? null,
                        siteId: picker.siteId ?? null,
                        source: 'library',
                    });

                    await this.reloadPickerImages();
                } catch (error) {
                    const failTitle = String(i18n.upload_failed || 'Upload thất bại').trim();
                    const failBody = String(error?.message ?? (i18n.upload_failed_body || '')).trim();
                    if (typeof window.__seoShowArticleEditorToast === 'function') {
                        window.__seoShowArticleEditorToast({
                            title: failTitle,
                            body: failBody,
                            status: 'danger',
                        });
                    } else if (typeof FilamentNotification !== 'undefined' && (failTitle !== '' || failBody !== '')) {
                        const toast = new FilamentNotification();
                        if (failTitle !== '') {
                            toast.title(failTitle);
                        }
                        if (failBody !== '') {
                            toast.body(failBody);
                        }
                        toast.danger().send();
                    }
                } finally {
                    this.localMediaUploading = false;
                    window.__seoArticleAutosaveLock?.set('media-upload', false);
                    this.pickerLoading = false;
                    if (input) {
                        input.value = '';
                    }
                }
            },
        }"
        x-on:close-article-media-modal.window="closeArticleMediaModal()"
        x-on:seo-featured-image-storage-ready.window="syncFeaturedImageDraft()"
        x-on:seo-featured-image-updated.window="onFeaturedImageUpdated($event)"
        x-on:seo-featured-image-cleared.window="onFeaturedImageCleared($event)"
        x-on:article-wordpress-sync-lock.window="lockPageForHeavyAction($event.detail?.action ?? 'sync')"
        x-on:article-wordpress-sync-unlock.window="unlockPageAfterHeavyActionFailure()"
        x-on:seo-open-article-media-picker.window="openArticleMediaModal('editor-block', $event.detail?.blockId ?? null)"
        x-on:seo-article-editor-notify.window="
            const payload = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            if (typeof window.__seoShowArticleEditorToast === 'function') {
                window.__seoShowArticleEditorToast(payload);
                return;
            }
            const title = String(payload.title ?? '').trim();
            const body = String(payload.body ?? '').trim();
            if (title === '' && body === '') {
                return;
            }
            if (typeof FilamentNotification === 'undefined') {
                return;
            }
            const toast = new FilamentNotification();
            if (title !== '') {
                toast.title(title);
            }
            if (body !== '') {
                toast.body(body);
            }
            const status = String(payload.status ?? 'success');
            if (status === 'danger' || status === 'error') {
                toast.danger();
            } else if (status === 'warning') {
                toast.warning();
            } else if (status === 'info') {
                toast.info();
            } else {
                toast.success();
            }
            toast.send();
        "
        x-on:open-article-media-modal.window="
            const wireMode = $wire.mediaPickerMode || 'featured';
            const mode = wireMode === 'editor-block'
                ? 'editor-block'
                : (wireMode === 'gallery' ? 'gallery' : 'featured');
            openArticleMediaModal(mode);
        "
        x-on:article-media-picker-loading.window="
            if (!mediaModalOpen) {
                return;
            }
            if (!pickerSuppressLoadingOverlay) {
                pickerLoading = true;
            }
        "
        x-on:article-media-picker-loaded.window="
            if (!mediaModalOpen) {
                return;
            }
            pickerSuppressLoadingOverlay = false;
            applyPickerPayload($event.detail);
            pickerLoading = false;
            pickerSearching = false;
            persistPickerCacheFromFetch($event.detail);
        "
        x-on:seo-editor-images-catalog.window="
            if (!$event.detail?.autoSync) {
                return;
            }
            const images = Array.isArray($event.detail?.images) ? $event.detail.images : [];
            if (images.length === 0) {
                return;
            }
            pickerCatalog = images;
            if (mediaModalOpen && pickerTab === 'article') {
                applyPickerView();
            }
        "
        x-on:flush-article-faqs.window="
            if (this._faqFlushFinalizeTimer) {
                clearTimeout(this._faqFlushFinalizeTimer);
            }
            const targetAtFlush = $wire.pendingEditorCollectTarget;
            this._faqFlushFinalizeTimer = setTimeout(() => {
                this._faqFlushFinalizeTimer = null;
                // Chỉ fallback khi saveArticleFaqs chưa clear pending (tránh double collect → double generate FAQ).
                if ($wire.pendingEditorCollectTarget && $wire.pendingEditorCollectTarget === targetAtFlush) {
                    $wire.finalizePendingEditorCollect();
                }
            }, 1200);
        "
        x-on:editor-html-collected.window="
            const detail = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            const articleId = @js((int) $record->getKey());
            if (detail.target === 'sync') {
                if (!window.__seoArticleHeavyActionOverlay?.locked) {
                    window.__seoBeginArticleHeavyActionClient?.('sync');
                }
                (async () => {
                    try {
                        await window.__seoRunArticleEditorApiAction?.('sync', $wire, {
                            html: detail.html ?? '',
                            seoAnalysis: detail.seoAnalysis ?? null,
                            articleId,
                        });
                    } catch (error) {
                        window.__seoEndArticleHeavyActionClient?.();
                        if (!error?.notificationShown && typeof FilamentNotification !== 'undefined') {
                            new FilamentNotification()
                                .title(@js(__('seo-content-ai::filament.automation.wp_sync_blocked_title')))
                                .body(error?.message ?? @js(__('seo-content-ai::filament.automation.wp_sync_blocked_body')))
                                .danger()
                                .send();
                        }
                    }
                })();
            } else if (detail.target === 'generate-faq') {
                $wire.generateArticleFaqs(detail.html ?? '');
            } else if (detail.target === 'quick-translate') {
                $wire.quickTranslateLinkedArticle(detail.html ?? '');
            } else if (detail.target === 'save' || !detail.target) {
                (async () => {
                    try {
                        await window.__seoRunArticleEditorApiAction?.('save', null, {
                            html: detail.html ?? '',
                            seoAnalysis: detail.seoAnalysis ?? null,
                            articleId,
                        });
                    } catch (error) {
                        window.__seoEndArticleHeavyActionClient?.();
                        window.dispatchEvent(new CustomEvent('article-editor-save-finished'));
                    }
                })();
            }
        "
        x-on:generate-article-faqs.window="$wire.requestGenerateArticleFaqs()"
        x-on:import-markdown-faq-debug.window="$wire.importMarkdownFaqDebug($event.detail?.markdown ?? '')"
        x-on:article-editor-shortcut.window="
            const action = $event.detail?.action;
            if (syncPageLocked || $wire.articleHeavyActionBusy) {
                return;
            }
            if (action === 'save') {
                const runSave = async () => {
                    if (typeof window.__seoExecuteHeavyArticleAction !== 'function') {
                        throw new Error('Editor chưa sẵn sàng — tải lại trang rồi thử lại.');
                    }

                    await window.__seoExecuteHeavyArticleAction('save', null);
                    window.__seoResetPublishTabPrimed?.();
                };
                runSave().catch((error) => {
                    window.__seoEndArticleHeavyActionClient?.();
                    window.dispatchEvent(new CustomEvent('article-editor-save-finished'));
                    if (typeof FilamentNotification !== 'undefined') {
                        new FilamentNotification()
                            .title('Không lưu được bài viết')
                            .body(error?.message ?? 'Lưu thất bại.')
                            .danger()
                            .send();
                    }
                });
            } else if (action === 'sync') {
                @if (! \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager())
                    window.dispatchEvent(new CustomEvent('seo-publish-tab-request-sync'));
                @endif
            } else if (action === 'preview') {
                const url = @js($this->getArticlePreviewUrl());
                if (url) {
                    window.open(url, '_blank', 'noopener');
                }
            } else if (action === 'toggle-seo') {
                window.dispatchEvent(new CustomEvent('google-serp-preview-open-edit'));
            }
        "
        x-on:seo-rename-attachment-slugs.window="$wire.renameAttachmentSlugsOnWordPress($event.detail.items ?? [], !!($event.detail.silent ?? false))"
        x-on:seo-update-attachment-meta.window="$wire.updateAttachmentMetaOnWordPress($event.detail.items ?? [], !!($event.detail.silent ?? false))"
        @seo-analyze-result.window="window.dispatchEvent(new CustomEvent('seo-editor-analyze-result', { detail: $event.detail }))"
        x-on:save-article-faqs.window="$wire.saveArticleFaqs($event.detail.faqs ?? [])"
        x-on:dismiss-faq-extract-debug.window="$wire.clearFaqExtractDebug()"
        x-on:extract-article-faqs-with-context.window="$wire.extractFaqsFromSelection($event.detail.html ?? '', $event.detail.articleHtml ?? '')"
        x-on:renew-article-faq.window="$wire.renewArticleFaq($event.detail.index, $event.detail.question, $event.detail.answer)"
        x-on:preview-generate-article-image-prompt.window="
            $wire.previewGenerateArticleImagePrompt(
                $event.detail.userBrief ?? '',
                $event.detail.target ?? 'editor',
                $event.detail.loaiSanPhamCategoryArticleId ?? 0,
                $event.detail.loaiSanPhamCustom ?? ''
            ).then((result) => {
                window.dispatchEvent(new CustomEvent('article-generate-image-prompt-preview', { detail: result ?? {} }));
            });
        "
        x-on:generate-article-video.window="$wire.generateArticleVideoFromEditor($event.detail.selectionText ?? '', $event.detail.selectionHtml ?? '', $event.detail.userBrief ?? '', $event.detail.activeBlockId ?? '')"
        x-on:check-faq-question.window="
            $wire.checkFaqQuestionDuplicate($event.detail.question, $event.detail.faqId).then((result) => {
                window.dispatchEvent(new CustomEvent('faq-duplicate-checked', {
                    detail: {
                        index: $event.detail.index,
                        duplicate: result?.duplicate ?? false,
                        duplicate_scope: result?.duplicate_scope ?? null,
                    },
                }));
            });
        "
        class="wp-article-edit seo-article-edit-content max-w-none"
    >
        <div wire:ignore id="seo-article-ai-launcher-root"></div>

        @if ($this->hasWpDataOutOfSync())
            <div
                class="mb-4 rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-600 dark:bg-danger-950/40 dark:text-danger-200"
                role="alert"
            >
                Dữ liệu không đồng bộ, vui lòng xem lại.
            </div>
        @endif

        <div
            class="seo-article-edit-back seo-article-editor-sticky-header"
            data-seo-sticky-editor-header
            data-article-editor-ui-revision="sticky-help-v1"
        >
            <div class="seo-article-editor-sticky-header__left">
                <a
                    href="{{ \App\Addons\SeoContentAi\Filament\Resources\ArticleResource::getUrl('index') }}"
                    class="seo-article-edit-back-link seo-article-edit-back-link--icon"
                    title="{{ __('seo-content-ai::filament.article_list.back_to_articles') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.back_to_articles') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </a>
                <span
                    class="seo-article-editor-sticky-header__save-status"
                    data-seo-sticky-save-status
                    data-status="saved"
                    aria-live="polite"
                ></span>
                <button
                    type="button"
                    class="seo-article-editor-sticky-header__draft-alert"
                    data-seo-sticky-draft-alert
                    hidden
                    title="Có nháp chưa lưu — bấm để chọn lại"
                    aria-label="Có nháp chưa lưu — bấm để chọn lại"
                >
                    !
                </button>
            </div>
            <div class="seo-article-editor-sticky-header__right">
                @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-shortcuts-slot')
            </div>
        </div>

        <div class="wp-article-edit-layout">
            {{-- Cột chính (giống WP post editor) --}}
            <div class="wp-article-edit-main space-y-4">
                <div class="wp-postbox">
                    <div class="wp-postbox-title-toolbar">
                        <input
                            type="text"
                            wire:model.blur="articleTitle"
                            placeholder="Thêm tiêu đề bài viết"
                            class="wp-title-input"
                        />
                    </div>

                    <div
                        class="wp-permalink mt-3 flex flex-wrap items-baseline gap-x-1 gap-y-1 text-sm text-gray-600 dark:text-gray-400"
                        data-seo-permalink-root
                        data-permalink-base="{{ rtrim($this->getPermalinkBase(), '/') }}"
                        data-permalink-suffix="{{ $this->getPermalinkSuffix() }}"
                        data-article-slug="{{ trim($this->articleSlug) }}"
                    >
                        <span class="font-medium text-gray-700 dark:text-gray-300">Đường dẫn:</span>
                        @php($displayPermalink = trim($this->getDisplayPermalink()))
                        @if($displayPermalink !== '' && (int) ($record->wp_post_id ?? 0) > 0)
                            <a
                                href="{{ $displayPermalink }}"
                                target="_blank"
                                rel="noopener"
                                data-seo-permalink-url
                                class="text-sky-600 dark:text-sky-400 hover:underline break-all"
                            >{{ $displayPermalink }}</a>
                        @else
                            <span
                                data-seo-permalink-url
                                class="break-all text-gray-500 dark:text-gray-400"
                                title="URL dự kiến, chưa tồn tại trên WordPress"
                            >{{ $displayPermalink !== '' ? $displayPermalink : (trim($this->getPermalinkBase()) !== '' ? rtrim($this->getPermalinkBase(), '/') . '/' . $this->getDisplaySlug() : '#') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Phase 2: single core bootstrap only (identity + content + endpoints + minimal settings). --}}
                <script type="application/json" id="seo-article-core-bootstrap">@json($this->getEditorCoreBootstrap())</script>
                <script>
                    window.__SEO_I18N_LOCALE__ = @js(app()->getLocale());
                    window.__SEO_ARTICLES_LIST_URL__ = @js(\App\Addons\SeoContentAi\Filament\Resources\ArticleResource::getUrl('index'));
                    window.__SEO_ARTICLES_SYNC_QUEUE_URL__ = @js(\App\Addons\SeoContentAi\Filament\Resources\ArticleResource::getUrl('index').'?tab=queue');
                    {{-- Minimal picker config — no focus-keyword DB resolve, no i18n bulk from server beyond static keys in JS fallback --}}
                    window.__SEO_ARTICLE_MEDIA_PICKER__ = @json($this->getArticleMediaPickerMinimalPayload());
                    window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ = @js($this->getId());
                    window.__SEO_ARTICLE_EDITOR_PERF_DEBUG__ = @js((bool) config('seo-content-ai.article_editor_perf_debug', false));
                </script>

                <div wire:ignore id="seo-article-editor-root" class="w-full seo-article-editor-compact min-w-0"></div>

                <button
                    type="button"
                    id="seo-faq-debug-dismiss-wire"
                    class="hidden"
                    wire:click="clearFaqExtractDebug"
                    wire:loading.attr="disabled"
                    tabindex="-1"
                    aria-hidden="true"
                ></button>

                <div wire:ignore id="seo-article-faq-root" class="w-full mt-4"></div>
            </div>

            {{-- Cột phải: preview + sidebar giữa + xuất bản --}}
            <aside
                class="wp-article-edit-sidebar"
                x-data="{ aiChatOpen: false, syncOpen: false }"
                x-on:seo-article-ai-chat-open.window="aiChatOpen = true"
                x-on:seo-article-ai-chat-close.window="aiChatOpen = false"
                x-on:seo-assistant-open-publishing.window="aiChatOpen = false; syncOpen = true"
                x-on:seo-sidebar-open-publish-tab.window="aiChatOpen = false; syncOpen = true"
                x-on:seo-publish-tab-request-sync.window="aiChatOpen = false; syncOpen = true; $nextTick(() => window.__seoPublishTabRequestSync?.())"
            >
                <div class="wp-article-edit-rail">
                    <div x-show="!aiChatOpen" x-cloak class="wp-article-edit-rail-top">
                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.seo-polylang-widget')
                    </div>

                    <div
                        class="wp-article-edit-rail-center"
                        x-bind:class="{ 'is-chat': aiChatOpen }"
                    >
                        <div class="wp-article-edit-sidebar-window">
                            <div
                                x-show="!aiChatOpen"
                                x-cloak
                                class="wp-article-edit-sidebar-scroll seo-assistant-host seo-assistant-sidebar space-y-3"
                                x-data="seoAssistantNavigator(@js([
                                    'postType' => \App\Addons\SeoContentAi\Models\SeoProjectTask::normalizePostType($this->articlePostType),
                                    'supportsProductGallery' => $this->supportsProductGallery(),
                                ]))"
                                x-init="initWorkspace()"
                                x-bind:class="{ 'is-panel-filter': panelFilterActive }"
                            >
                                <div
                                    x-ref="dock"
                                    class="seo-assistant-dock"
                                    role="navigation"
                                    aria-label="Assistant Dock"
                                >
                                    <div class="seo-assistant-dock__search-wrap">
                                        <label class="sr-only" for="seo-assistant-dock-search">Search assistants</label>
                                        <div class="seo-assistant-dock__search">
                                            <svg class="seo-assistant-dock__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Z" />
                                            </svg>
                                            <input
                                                id="seo-assistant-dock-search"
                                                type="search"
                                                class="seo-assistant-dock__search-input"
                                                placeholder="Search assistants..."
                                                autocomplete="off"
                                                x-model="searchQuery"
                                                x-on:input="onSearchInput()"
                                                x-on:focus="openSearch()"
                                                x-on:keydown="onSearchKeydown($event)"
                                                x-on:keydown.escape.prevent="searchQuery = ''; closeSearch()"
                                            />
                                        </div>
                                        <div
                                            class="seo-assistant-dock__dropdown"
                                            x-show="searchOpen && filteredSearchResults.length > 0"
                                            x-cloak
                                            x-on:click.outside="closeSearch()"
                                        >
                                            <template x-for="(item, index) in filteredSearchResults" :key="item.label + '-' + index">
                                                <button
                                                    type="button"
                                                    class="seo-assistant-dock__dropdown-item"
                                                    x-bind:class="{ 'is-active': searchHighlightIndex === index }"
                                                    x-on:click="selectSearchResult(index)"
                                                >
                                                    <span class="seo-assistant-dock__dropdown-label" x-text="item.label"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="seo-assistant-dock__tabs" role="tablist" aria-label="Assistant panels">
                                        <template x-for="chip in chips" :key="chip.id">
                                            <button
                                                type="button"
                                                class="seo-assistant-dock__tab"
                                                role="tab"
                                                x-bind:class="{ 'is-active': panelFilterActive && activePanel === chip.id }"
                                                x-bind:aria-selected="panelFilterActive && activePanel === chip.id ? 'true' : 'false'"
                                                x-on:click="selectChip(chip.id)"
                                            >
                                                <span x-text="chip.label"></span>
                                                <span
                                                    class="seo-assistant-dock__tab-badge"
                                                    x-show="chipBadge(chip.id)"
                                                    x-text="chipBadge(chip.id)"
                                                ></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                <div class="seo-assistant-widget-layer space-y-3">
                                    <div
                                        class="seo-assistant-panel-slot"
                                        data-assistant-widget
                                        data-assistant-widget-id="seo"
                                        data-assistant-tab-label="SEO"
                                        data-assistant-widget-label="SEO Assistant"
                                        data-assistant-search-keywords="seo,score,keyword,violation,check,focus"
                                        x-show="isWidgetVisible('seo')"
                                        x-bind:class="{ 'is-active': panelFilterActive && activePanel === 'seo' }"
                                    >
                                        <div wire:ignore id="seo-article-seo-assistant-root"></div>
                                    </div>

                                        {{-- Both panels always in DOM; Alpine toggles by live post_type (no WP sync). --}}
                                        <div
                                            class="seo-assistant-panel-slot"
                                            data-assistant-widget
                                            data-assistant-widget-id="featured"
                                            data-assistant-tab-label="Featured"
                                            data-assistant-widget-label="Featured Image"
                                            data-assistant-search-keywords="featured,thumbnail,cover,đại diện"
                                            data-assistant-requires-non-product="1"
                                            x-show="!supportsProductGalleryUi && isWidgetVisible('featured')"
                                            x-cloak
                                            x-bind:class="{ 'is-active': panelFilterActive && activePanel === 'featured' }"
                                        >
                                            <section class="seo-assistant-widget seo-assistant-widget--featured-image seo-assistant-widget--static">
                        <header class="seo-assistant-widget__header seo-assistant-widget__header--static">
                            <div class="seo-assistant-widget__toggle seo-assistant-widget__toggle--static">
                                <svg class="seo-assistant-widget__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m2 7 4.41-4.41A2 2 0 0 1 7.17 2h9.66a2 2 0 0 1 1.42.59L22 7"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 22v-4.172a2 2 0 0 0-.586-1.414L12 15l-2.414 2.414A2 2 0 0 0 9 18.828V22"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2 7h20"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 2v5"/></svg>
                                <span class="seo-assistant-widget__title">Ảnh đại diện</span>
                            </div>
                        </header>
                        <div class="seo-assistant-widget__body text-center">
                            <button
                                type="button"
                                x-on:click="openArticleMediaModal('featured')"
                                class="wp-featured-image-picker"
                                title="Chọn ảnh từ thư viện WordPress"
                            >
                                <template x-if="featuredImageDraft?.url">
                                    <img
                                        x-bind:src="featuredImageDraft.url"
                                        alt="Ảnh đại diện"
                                        class="wp-featured-image-picker__img"
                                    />
                                </template>
                                <template x-if="!featuredImageDraft?.url">
                                    <span class="wp-featured-image-picker__empty">
                                        <svg class="mx-auto h-12 w-12 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <span class="wp-featured-image-picker__label">Đặt ảnh đại diện</span>
                                    </span>
                                </template>
                            </button>
                            <p
                                class="mt-2 text-xs text-gray-500 dark:text-gray-400"
                                x-text="featuredImageDraft?.url
                                    ? 'Đã lưu nháp cục bộ · bấm Lưu hoặc Đồng bộ để ghi database'
                                    : 'Bấm để chọn từ thư viện Media'"
                            ></p>
                        </div>
                    </section>
                                        </div>

                                        <div
                                            class="seo-assistant-panel-slot"
                                            data-assistant-widget
                                            data-assistant-widget-id="featured"
                                            data-assistant-tab-label="Featured"
                                            data-assistant-widget-label="Product Album"
                                            data-assistant-search-keywords="album,gallery,product,featured,sản phẩm"
                                            data-assistant-requires-product="1"
                                            x-show="supportsProductGalleryUi && isWidgetVisible('featured')"
                                            x-cloak
                                            x-bind:class="{ 'is-active': panelFilterActive && activePanel === 'featured' }"
                                        >
                    <section class="seo-assistant-widget seo-assistant-widget--product-album seo-assistant-widget--static">
                        <header class="seo-assistant-widget__header seo-assistant-widget__header--static">
                            <div class="seo-assistant-widget__toggle seo-assistant-widget__toggle--static">
                                <svg class="seo-assistant-widget__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m2 7 4.41-4.41A2 2 0 0 1 7.17 2h9.66a2 2 0 0 1 1.42.59L22 7"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 22v-4.172a2 2 0 0 0-.586-1.414L12 15l-2.414 2.414A2 2 0 0 0 9 18.828V22"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2 7h20"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 2v5"/></svg>
                                <span class="seo-assistant-widget__title">Album hình ảnh sản phẩm</span>
                            </div>
                        </header>
                        <div
                            class="seo-assistant-widget__body"
                            x-data="seoProductAlbumBoxData(@js((int) $record->getKey()))"
                        >
                            <template x-if="albumItems.length > 0">
                                <div class="wp-product-gallery-grid" role="list">
                                    <template x-for="(image, idx) in albumItems" :key="image.url">
                                        <div
                                            class="wp-product-gallery-thumb-wrap"
                                            role="listitem"
                                            draggable="true"
                                            x-bind:data-gallery-url="image.url"
                                            x-bind:data-gallery-id="image.id"
                                            x-on:dragstart="startDrag($event)"
                                            x-on:dragover="allowDrop($event)"
                                            x-on:drop="onDrop($event)"
                                            x-on:dragend="finishDrag()"
                                        >
                                            <a
                                                x-bind:href="image.url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="wp-product-gallery-thumb-link"
                                            >
                                                <img
                                                    x-bind:src="image.url"
                                                    alt=""
                                                    class="wp-product-gallery-thumb"
                                                />
                                            </a>
                                            <span
                                                x-show="idx === 0"
                                                class="wp-product-gallery-badge"
                                            >
                                                Đại diện
                                            </span>
                                            <button
                                                type="button"
                                                class="wp-product-gallery-remove"
                                                x-on:click="removeItem(image.url)"
                                                title="Xóa ảnh khỏi album"
                                                aria-label="Xóa ảnh khỏi album"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <button
                                type="button"
                                class="wp-product-gallery-generate mt-2"
                                title="Tạo ảnh AI hoặc tách lưới album sản phẩm"
                                x-on:click="window.dispatchEvent(new CustomEvent('seo-open-generate-image-modal', { detail: { target: 'product-gallery' } }))"
                            >
                                <span x-text="albumItems.length > 0 ? 'Tạo / tách ảnh' : 'Tạo ảnh'"></span>
                            </button>
                            <button
                                type="button"
                                x-on:click="openArticleMediaModal('gallery')"
                                class="wp-product-gallery-add mt-2"
                                title="Thêm ảnh từ thư viện WordPress"
                            >
                                Thêm ảnh thư viện sản phẩm
                            </button>
                            <button
                                type="button"
                                class="wp-product-gallery-distribute mt-2"
                                title="Chèn ảnh album vào các section chưa có hình"
                                x-on:click="window.dispatchEvent(new CustomEvent('seo-editor-distribute-product-gallery'))"
                            >
                                Rải ảnh vào các section
                            </button>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-text="albumCountLabel()"></p>
                        </div>
                    </section>
                                        </div>

                                    <div
                                        class="seo-assistant-panel-slot"
                                        data-assistant-widget
                                        data-assistant-widget-id="images"
                                        data-assistant-tab-label="Images"
                                        data-assistant-widget-label="Image Assistant"
                                        data-assistant-search-keywords="image,images,alt,photo,picture,generate,fix"
                                        x-show="isWidgetVisible('images')"
                                        x-bind:class="{ 'is-active': panelFilterActive && activePanel === 'images' }"
                                    >
                                <div wire:ignore id="seo-article-image-assistant-root"></div>
                                    </div>

                                    <div
                                        class="seo-assistant-panel-slot"
                                        data-assistant-widget
                                        data-assistant-widget-id="reviews"
                                        data-assistant-tab-label="Reviews"
                                        data-assistant-widget-label="Reviews Assistant"
                                        data-assistant-search-keywords="reviews,rating,comment"
                                        data-assistant-requires-product="1"
                                        x-show="supportsProductGalleryUi && isWidgetVisible('reviews')"
                                        x-cloak
                                        x-bind:class="{ 'is-active': panelFilterActive && activePanel === 'reviews' }"
                                    >
                                <div wire:ignore id="seo-article-reviews-assistant-root"></div>
                                    </div>

                                    <div
                                        class="seo-assistant-panel-slot"
                                        data-assistant-widget
                                        data-assistant-widget-id="links"
                                        data-assistant-tab-label="Links"
                                        data-assistant-widget-label="Link Assistant"
                                        data-assistant-search-keywords="link,links,internal,external,href"
                                        x-show="isWidgetVisible('links')"
                                        x-bind:class="{ 'is-active': panelFilterActive && activePanel === 'links' }"
                                    >
                                        <div wire:ignore id="seo-article-links-root"></div>
                                    </div>

                                            <div
                                                class="seo-assistant-panel-slot"
                                                data-assistant-widget
                                                data-assistant-widget-id="publishing"
                                                data-assistant-tab-label="Publishing"
                                                data-assistant-widget-label="Publishing Assistant"
                                                data-assistant-search-keywords="publish,publishing,sync,wordpress,schedule"
                                                x-show="isWidgetVisible('publishing')"
                                                x-bind:class="{ 'is-active': panelFilterActive && activePanel === 'publishing' }"
                                            >
                                                <section
                                                    id="seo-publishing-assistant"
                                                    class="seo-assistant-widget seo-assistant-widget--publishing seo-assistant-widget--static"
                                                >
                                                    <header class="seo-assistant-widget__header seo-assistant-widget__header--static">
                                                        <div class="seo-assistant-widget__toggle seo-assistant-widget__toggle--static">
                                                            <svg class="seo-assistant-widget__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 16V4m0 12-4-4m4 4 4-4M4 20h16"/></svg>
                                                            <span class="seo-assistant-widget__title">Publishing Assistant</span>
                                                        </div>
                                                    </header>
                                                    <div class="seo-assistant-widget__body space-y-3">
                                                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.publish-categories')
                                                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.publish-sync-panel')
                                                    </div>
                                                </section>
                                            </div>

                                            <div
                                                class="seo-assistant-panel-slot"
                                                data-assistant-widget
                                                data-assistant-widget-id="article"
                                                data-assistant-tab-label="Article"
                                                data-assistant-widget-label="Article Information"
                                                data-assistant-search-keywords="article,info,slug,status,schedule,author,tác giả"
                                                x-show="isWidgetVisible('article')"
                                                x-bind:class="{ 'is-active': panelFilterActive && activePanel === 'article' }"
                                            >
                                                <section class="seo-assistant-widget seo-assistant-widget--article-info seo-assistant-widget--static">
                                                    <header class="seo-assistant-widget__header seo-assistant-widget__header--static">
                                                        <div class="seo-assistant-widget__toggle seo-assistant-widget__toggle--static">
                                                            <svg class="seo-assistant-widget__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                                                            <span class="seo-assistant-widget__title">Article Information</span>
                                                        </div>
                                                    </header>
                                                    <div class="seo-assistant-widget__body space-y-3">
                                                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.publish-sidebar')
                                                    </div>
                                                </section>
                                            </div>
                                </div>
                            </div>

                            <div
                                x-show="aiChatOpen"
                                x-cloak
                                wire:ignore
                                id="seo-article-ai-chat-root"
                                class="wp-sidebar-ai-chat wp-article-edit-sidebar-scroll wp-article-edit-sidebar-scroll--chat"
                            ></div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <div
            x-show="mediaModalOpen"
            x-cloak
            wire:ignore
            class="seo-article-media-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="seo-article-media-modal-title"
        >
            <button
                type="button"
                class="seo-article-media-modal__backdrop"
                x-on:click="closeArticleMediaModal()"
                aria-label="Đóng"
            ></button>
            <div class="seo-article-media-modal__panel" x-ref="mediaModalPanel">
                <div class="seo-article-media-modal__header">
                    <h2 id="seo-article-media-modal-title" class="seo-article-media-modal__title">
                        <span x-text="mediaModalMode === 'gallery' ? 'Chọn media cho album sản phẩm' : (mediaModalMode === 'editor-block' ? 'Chọn image/video từ thư viện' : 'Chọn ảnh đại diện')"></span>
                    </h2>
                    <button type="button" class="seo-article-media-modal__close" x-on:click="closeArticleMediaModal()" aria-label="Đóng">
                        ×
                    </button>
                </div>

                <div class="seo-article-media-modal__tabs">
                    <button
                        type="button"
                        class="seo-article-media-modal__tab"
                        x-bind:class="{ 'is-active': pickerTab === 'article' }"
                        x-on:click="switchPickerTab('article')"
                    >
                        Trong bài
                    </button>
                    <button
                        type="button"
                        class="seo-article-media-modal__tab"
                        x-bind:class="{ 'is-active': pickerTab === 'original' }"
                        x-on:click="switchPickerTab('original')"
                        x-bind:disabled="!pickerWordPressLinked()"
                        x-bind:title="pickerWordPressLinked() ? 'Thư viện WordPress' : 'Đồng bộ bài viết với WordPress để sử dụng thư viện này'"
                    >
                        Gốc (WP)
                    </button>
                    <div
                        class="seo-article-media-modal__tab-add-wrap"
                        x-show="pickerWordPressLinked() && articleDomainReady()"
                        x-cloak
                    >
                        <button
                            type="button"
                            class="seo-article-media-modal__tab seo-article-media-modal__tab--add"
                            x-ref="pickerAddTabButton"
                            x-on:click.stop="togglePickerAddTabMenu($event)"
                            title="Thêm tab tìm kiếm WordPress"
                            aria-label="Thêm tab tìm kiếm WordPress"
                            x-bind:aria-expanded="pickerAddTabMenu.open"
                            x-bind:disabled="!articleDomainReady()"
                        >+</button>
                    </div>
                    <template x-for="customTab in pickerCustomTabs" x-bind:key="customTab.id">
                        <button
                            type="button"
                            class="seo-article-media-modal__tab seo-article-media-modal__tab--custom"
                            x-bind:class="{ 'is-active': pickerTab === ('custom:' + customTab.id) }"
                            x-on:click="switchPickerTab('custom:' + customTab.id)"
                            x-bind:title="customTab.blank ? 'Double-click để đổi tên nhóm trống' : customTab.keyword"
                        >
                            <input
                                x-show="pickerCustomTabRename.tabId === customTab.id && customTab.blank"
                                x-cloak
                                type="text"
                                class="seo-article-media-modal__tab-rename-input"
                                x-bind:data-picker-tab-rename="customTab.id"
                                x-model="pickerCustomTabRename.draft"
                                x-on:click.stop
                                x-on:keydown.enter.prevent="commitRenameCustomPickerTab()"
                                x-on:keydown.escape.prevent="cancelRenameCustomPickerTab()"
                                x-on:blur="commitRenameCustomPickerTab()"
                            />
                            <span
                                class="seo-article-media-modal__tab-label"
                                x-bind:class="{ 'seo-article-media-modal__tab-label--editable': customTab.blank }"
                                x-show="pickerCustomTabRename.tabId !== customTab.id || !customTab.blank"
                                x-text="customTab.label || customTab.keyword"
                                x-on:dblclick.stop.prevent="startRenameCustomPickerTab(customTab)"
                            ></span>
                            <span
                                class="seo-article-media-modal__tab-badge"
                                x-show="stagedCountForTab(customTab.id) > 0"
                                x-text="stagedCountForTab(customTab.id)"
                            ></span>
                            <span
                                type="button"
                                class="seo-article-media-modal__tab-remove"
                                x-on:click.stop="removeCustomPickerTab(customTab.id)"
                                title="Xóa tab"
                                aria-label="Xóa tab"
                            >×</span>
                        </button>
                    </template>
                    <button
                        type="button"
                        class="seo-article-media-modal__tab"
                        x-bind:class="{ 'is-active': pickerTab === 'local' }"
                        x-on:click="switchPickerTab('local')"
                    >
                        Nội bộ (Laravel)
                    </button>
                </div>

                <div class="seo-article-media-modal__toolbar">
                    <div class="seo-article-media-modal__search-wrap">
                        <input
                            type="search"
                            x-model="pickerSearchQuery"
                            x-on:input="schedulePickerSearch()"
                            class="seo-article-media-modal__search"
                            x-bind:placeholder="pickerSearchPlaceholder()"
                            autocomplete="off"
                            x-on:keydown.escape="closeArticleMediaModal()"
                        />
                        <span
                            x-show="pickerSearching"
                            x-cloak
                            class="seo-article-media-modal__search-spinner"
                            aria-hidden="true"
                        ></span>
                    </div>
                    <input
                        type="file"
                        x-ref="localMediaFileInput"
                        class="seo-article-media-modal__upload-input"
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        multiple
                        x-on:change="onLocalMediaFilesSelected($event)"
                    />
                    <button
                        type="button"
                        class="seo-article-media-modal__upload"
                        x-show="pickerTab === 'local'"
                        x-cloak
                        x-on:click="openLocalMediaUploadPicker()"
                        x-bind:disabled="localMediaUploading"
                        title="{{ __('seo-content-ai::filament.media_tools.upload') }}"
                        aria-label="{{ __('seo-content-ai::filament.media_tools.upload') }}"
                    >
                        <span x-show="!localMediaUploading">
                            <x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                        </span>
                        <span x-show="localMediaUploading" x-cloak class="seo-article-media-modal__button-spinner"></span>
                    </button>
                    <button
                        type="button"
                        class="seo-article-media-modal__reload"
                        x-on:click="reloadPickerImages()"
                        x-bind:disabled="pickerLoading"
                        title="Tải lại thư viện"
                        aria-label="Tải lại thư viện"
                    >
                        <span x-show="!pickerLoading">
                            <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" />
                        </span>
                        <span x-show="pickerLoading" x-cloak class="seo-article-media-modal__button-spinner"></span>
                    </button>
                </div>

                <p
                    x-show="mediaModalMode === 'gallery'"
                    x-cloak
                    class="seo-article-media-modal__hint"
                >
                    Click / Shift+click để chọn nhiều media, rồi bấm <strong>Thêm vào album</strong> ở thanh bên dưới.
                </p>

                <p
                    x-show="pickerError"
                    x-cloak
                    class="seo-article-media-modal__error"
                    x-text="pickerError"
                ></p>

                <div class="seo-article-media-modal__body">
                    <div
                        x-show="pickerLoading && pickerDisplayImages.length === 0"
                        x-cloak
                        class="seo-article-media-modal__skeleton-grid"
                        aria-busy="true"
                        aria-label="Đang tải thư viện ảnh"
                    >
                        @for ($i = 0; $i < 12; $i++)
                            <div class="seo-article-media-modal__skeleton"></div>
                        @endfor
                    </div>

                    <div
                        class="seo-article-media-modal__results"
                        x-show="!pickerLoading || pickerDisplayImages.length > 0"
                        x-cloak
                        x-bind:class="{ 'is-busy': pickerSearching || (pickerLoading && pickerDisplayImages.length > 0) }"
                    >
                        <div
                            x-show="pickerSearching || (pickerLoading && pickerDisplayImages.length > 0)"
                            x-cloak
                            class="seo-article-media-modal__overlay"
                            aria-busy="true"
                            aria-live="polite"
                        >
                            <div class="seo-article-media-modal__skeleton-grid">
                                @for ($i = 0; $i < 12; $i++)
                                    <div class="seo-article-media-modal__skeleton"></div>
                                @endfor
                            </div>
                            <p class="seo-article-media-modal__overlay-label">Đang tìm ảnh…</p>
                        </div>

                        <p
                            x-show="!pickerSearching && !pickerLoading && pickerDisplayImages.length === 0 && !pickerError"
                            class="seo-article-media-modal__empty"
                            x-text="pickerTab === 'article'
                                ? 'Chưa có media trong nội dung bài viết.'
                                : (isCustomPickerTab()
                                    ? (isBlankCustomTab()
                                        ? 'Chưa có ảnh trong nhóm trống. Chuyển ảnh từ tab Gốc (WP).'
                                        : 'Không có ảnh cho từ khóa này.')
                                    : 'Không có media trong thư viện.')"
                        ></p>

                        <div class="seo-article-media-modal__grid" x-show="pickerDisplayImages.length > 0">
                            <template x-for="image in pickerDisplayImages" x-bind:key="image.picker_key">
                                <div class="seo-article-media-modal__item-wrap">
                                    <button
                                        type="button"
                                        class="seo-article-media-modal__item"
                                        x-bind:class="{
                                            'is-selected': mediaModalMode === 'gallery' && isGalleryPickerSelected(image.picker_key),
                                            'is-staged': image.staged === true,
                                        }"
                                        x-bind:data-picker-key="image.picker_key"
                                        x-bind:data-picker-wp="image.wp_attachment_id"
                                        x-bind:data-picker-seo="image.seo_media_id"
                                        x-bind:data-picker-url="image.url"
                                        x-bind:data-picker-alt="image.alt"
                                        x-bind:data-picker-slug="image.slug"
                                        x-bind:data-picker-media-type="image.media_type"
                                        x-on:mousedown.shift.prevent
                                        x-on:click="handlePickerImageClick($event, image, $el)"
                                        x-on:dragstart.prevent
                                        style="user-select: none;"
                                    >
                                        <span
                                            class="seo-article-media-modal__thumb seo-article-media-modal__thumb--video"
                                            x-show="image.media_type === 'video'"
                                            aria-hidden="true"
                                        >▶</span>
                                        <img
                                            x-show="image.media_type !== 'video'"
                                            class="seo-article-media-modal__thumb"
                                            x-bind:src="image.thumb_url || image.url"
                                            x-bind:alt="image.alt || image.slug || ''"
                                            loading="lazy"
                                            decoding="async"
                                            draggable="false"
                                            width="300"
                                            height="300"
                                        />
                                        <span
                                            class="seo-article-media-modal__staged-badge"
                                            x-show="image.staged === true"
                                        >Đã chuyển</span>
                                        <span
                                            class="seo-article-media-modal__slug"
                                            x-show="image.slug"
                                            x-text="image.slug"
                                        ></span>
                                    </button>
                                    <button
                                        type="button"
                                        class="seo-article-media-modal__item-move"
                                        x-show="pickerTab === 'original' && image.staged !== true"
                                        x-on:click.stop="openPickerMoveMenu(image, $event)"
                                        title="Chuyển vào tab tìm kiếm"
                                        aria-label="Chuyển vào tab tìm kiếm"
                                    >↗</button>
                                    <button
                                        type="button"
                                        class="seo-article-media-modal__item-remove"
                                        x-show="isCustomPickerTab() && image.staged === true"
                                        x-on:click.stop="removeStagedPickerImage(image)"
                                        title="Loại khỏi nhóm"
                                        aria-label="Loại khỏi nhóm"
                                    >×</button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="seo-article-media-modal__pagination" x-show="pickerTotalPages > 1" x-cloak>
                    <button
                        type="button"
                        class="seo-article-media-modal__page-btn"
                        x-on:click="pickerPrevPage()"
                        x-bind:disabled="pickerPage <= 1 || pickerSearching || pickerLoading"
                    >
                        Trang trước
                    </button>
                    <span x-text="pickerPage + ' / ' + pickerTotalPages"></span>
                    <button
                        type="button"
                        class="seo-article-media-modal__page-btn"
                        x-on:click="pickerNextPage()"
                        x-bind:disabled="pickerPage >= pickerTotalPages || pickerSearching || pickerLoading"
                    >
                        Trang sau
                    </button>
                </div>

                <div
                    x-show="mediaModalMode === 'gallery'"
                    x-cloak
                    class="seo-article-media-modal__select-bar"
                >
                    <div class="seo-article-media-modal__select-bar-left">
                        <span>
                            Đã chọn: <strong x-text="galleryPickerSelectedCount()">0</strong>
                        </span>
                        <button
                            type="button"
                            class="seo-article-media-modal__select-bar-clear"
                            x-on:click="clearGalleryPickerSelection()"
                            x-show="galleryPickerSelectedCount() > 0"
                            x-cloak
                        >
                            Bỏ chọn
                        </button>
                    </div>
                    <button
                        type="button"
                        class="seo-article-media-modal__select-bar-submit"
                        x-on:click="confirmGalleryPickerSelection()"
                        x-bind:disabled="galleryPickerSelectedCount() === 0"
                        wire:loading.attr="disabled"
                        wire:target="confirmGallerySelectionFromPicker"
                    >
                        <span wire:loading.remove wire:target="confirmGallerySelectionFromPicker">Thêm vào album</span>
                        <span wire:loading wire:target="confirmGallerySelectionFromPicker">Đang thêm…</span>
                    </button>
                </div>
            </div>

            <template x-if="pickerAddTabMenu.open">
                <div
                    class="seo-article-media-modal__add-tab-menu"
                    x-bind:style="pickerAddTabMenuStyle()"
                    x-on:click.outside="if (!$refs.pickerAddTabButton?.contains($event.target)) closePickerAddTabMenu()"
                >
                    <button
                        type="button"
                        class="seo-article-media-modal__add-tab-option"
                        x-on:click="addBlankCustomPickerTab()"
                    >
                        Group blank
                    </button>
                    <div class="seo-article-media-modal__add-tab-search">
                        <span class="seo-article-media-modal__add-tab-search-label">Group with search</span>
                        <input
                            type="text"
                            x-ref="pickerAddTabSearchInput"
                            class="seo-article-media-modal__add-tab-search-input"
                            x-model="pickerAddTabSearchKeyword"
                            x-on:keydown.enter.prevent="addSearchCustomPickerTabFromInput()"
                            x-bind:placeholder="defaultPickerSearchKeyword() || 'Từ khóa WordPress…'"
                            autocomplete="off"
                        />
                    </div>
                </div>
            </template>

            <template x-if="pickerMoveMenu.open && pickerMoveMenu.image">
                <div
                    class="seo-article-media-modal__move-menu"
                    x-bind:style="pickerMoveMenuStyle()"
                    x-on:click.outside="closePickerMoveMenu()"
                >
                    <p class="seo-article-media-modal__move-menu-title">Chuyển vào tab</p>
                    <template x-for="customTab in pickerCustomTabs" x-bind:key="'move-' + customTab.id">
                        <button
                            type="button"
                            class="seo-article-media-modal__move-menu-item"
                            x-on:click="movePickerImageToCustomTab(customTab.id)"
                        >
                            <span x-text="customTab.label || customTab.keyword"></span>
                            <span
                                class="seo-article-media-modal__tab-badge"
                                x-show="stagedCountForTab(customTab.id) > 0"
                                x-text="stagedCountForTab(customTab.id)"
                            ></span>
                        </button>
                    </template>
                </div>
            </template>
        </div>
    </div>

    @if (\App\Addons\SeoContentAi\Support\SeoAccessControl::canAccessManagerFeatures())
        <div
            class="seo-pipeline-rerun"
            x-data="{
                open: false,
                from: 'outline',
                submitting: false,
                openModal() { if (this.submitting) { return; } this.open = true; },
                closeModal() { this.open = false; },
                async submit() {
                    if (this.submitting) { return; }
                    this.submitting = true;
                    try {
                        await $wire.queueArticlePipelineRerun(this.from);
                    } finally {
                        this.submitting = false;
                        this.open = false;
                    }
                },
            }"
            x-on:open-article-pipeline-rerun-modal.window="openModal()"
            x-on:close-article-pipeline-rerun-modal.window="closeModal()"
            x-on:keydown.escape.window="if (open) closeModal()"
        >
            <div
                x-show="$wire.pipelineRerunStatus"
                x-cloak
                class="seo-pipeline-rerun-status"
                style="margin: 0.5rem 0;"
            >
                <span>
                    {{ __('seo-content-ai::filament.article_pipeline_rerun.status_label') }}:
                    <strong
                        x-text="{
                            queued: @js(__('seo-content-ai::filament.article_pipeline_rerun.status_queued')),
                            running: @js(__('seo-content-ai::filament.article_pipeline_rerun.status_running')),
                            completed: @js(__('seo-content-ai::filament.article_pipeline_rerun.status_completed')),
                            failed: @js(__('seo-content-ai::filament.article_pipeline_rerun.status_failed')),
                        }[$wire.pipelineRerunStatus] || ($wire.pipelineRerunStatus || '')"
                    ></strong>
                </span>
                <template x-if="$wire.pipelineRerunUrl">
                    <a x-bind:href="$wire.pipelineRerunUrl" target="_blank" rel="noopener noreferrer">
                        {{ __('seo-content-ai::filament.article_pipeline_rerun.view_run') }}
                    </a>
                </template>
            </div>

            <div
                x-show="open"
                x-cloak
                class="seo-pipeline-rerun__backdrop"
                x-on:click.self="closeModal()"
                role="dialog"
                aria-modal="true"
                aria-labelledby="seo-pipeline-rerun-title"
            >
                <div class="seo-pipeline-rerun__panel">
                    <h3 id="seo-pipeline-rerun-title" class="seo-pipeline-rerun__title">
                        {{ __('seo-content-ai::filament.article_pipeline_rerun.modal_title') }}
                    </h3>
                    <p class="seo-pipeline-rerun__desc">
                        {{ __('seo-content-ai::filament.article_pipeline_rerun.modal_intro') }}
                    </p>
                    <div class="seo-pipeline-rerun__options" role="radiogroup">
                        <label class="seo-pipeline-rerun__option">
                            <input type="radio" name="pipeline-rerun-from" value="outline" x-model="from" />
                            <span>
                                <span class="seo-pipeline-rerun__option-title">{{ __('seo-content-ai::filament.article_pipeline_rerun.from_outline_title') }}</span>
                                <span class="seo-pipeline-rerun__option-desc">{{ __('seo-content-ai::filament.article_pipeline_rerun.from_outline_desc') }}</span>
                            </span>
                        </label>
                        <label class="seo-pipeline-rerun__option">
                            <input type="radio" name="pipeline-rerun-from" value="article" x-model="from" />
                            <span>
                                <span class="seo-pipeline-rerun__option-title">{{ __('seo-content-ai::filament.article_pipeline_rerun.from_article_title') }}</span>
                                <span class="seo-pipeline-rerun__option-desc">{{ __('seo-content-ai::filament.article_pipeline_rerun.from_article_desc') }}</span>
                            </span>
                        </label>
                    </div>
                    <p class="seo-pipeline-rerun__warn">
                        {{ __('seo-content-ai::filament.article_pipeline_rerun.warning') }}
                    </p>
                    <div class="seo-pipeline-rerun__actions">
                        <button type="button" class="fi-btn fi-btn-size-md fi-color-gray" x-on:click="closeModal()" x-bind:disabled="submitting">
                            {{ __('seo-content-ai::filament.article_pipeline_rerun.cancel') }}
                        </button>
                        <button
                            type="button"
                            class="fi-btn fi-btn-size-md fi-color-primary"
                            x-on:click="submit()"
                            wire:loading.attr="disabled"
                            wire:target="queueArticlePipelineRerun"
                            x-bind:disabled="submitting"
                        >
                            <span x-show="!submitting">
                                {{ __('seo-content-ai::filament.article_pipeline_rerun.queue') }}
                            </span>
                            <span x-show="submitting" x-cloak>
                                {{ __('seo-content-ai::filament.article_pipeline_rerun.queueing') }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @push('scripts')
        @viteReactRefresh
        @vite('app/Addons/SeoContentAi/resources/js/article-editor.jsx')
    @endpush

    @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-assign-content-project-modals', ['record' => $record])
@endif
</x-filament-panels::page>
