@push('styles')
    @vite([
        'app/Addons/SeoContentAi/resources/js/article-media-picker-cache-bootstrap.js',
        'app/Addons/SeoContentAi/resources/css/article-edit-page.css',
    ])
@endpush

<x-filament-panels::page @class(['seo-article-edit-page'])>
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
@once
<script>
        window.__seoArticleHeavyActionOverlay = {
            id: 'seo-article-heavy-action-overlay',
            locked: false,
            persistUntilUnload: false,
            action: null,
            guardTimer: null,
            show(action = 'sync', options = {}) {
                this.locked = true;
                this.persistUntilUnload = Boolean(options.persistUntilUnload);
                this.action = action === 'save' ? 'save' : 'sync';
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
                    message.textContent = 'Vui lòng chờ — không chỉnh sửa cho đến khi trang tải lại xong.';

                    const skeleton = document.createElement('div');
                    skeleton.className = 'seo-article-sync-overlay__skeleton';
                    skeleton.setAttribute('aria-hidden', 'true');
                    for (let index = 0; index < 3; index += 1) {
                        skeleton.appendChild(document.createElement('i'));
                    }

                    panel.append(spinner, title, message, skeleton);
                    overlay.appendChild(panel);
                    document.body.appendChild(overlay);
                }

                const title = overlay.querySelector('[data-heavy-action-title]');
                if (title) {
                    title.textContent = this.action === 'save'
                        ? 'Đang cập nhật bài viết'
                        : 'Đang đồng bộ với WordPress';
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
            const normalized = action === 'save' ? 'save' : 'sync';
            window.__seoArticleHeavyActionOverlay?.show(normalized);
            window.__seoArticleAutosaveLock?.set('article-heavy-action', true);
            window.dispatchEvent(new CustomEvent('article-wordpress-sync-lock', {
                detail: { action: normalized },
            }));

            return normalized;
        };

        window.__seoEndArticleHeavyActionClient = function endArticleHeavyActionClient() {
            if (window.__seoArticleHeavyActionOverlay?.persistUntilUnload) {
                return;
            }

            window.__seoArticleHeavyActionOverlay?.hide();
            window.__seoArticleAutosaveLock?.set('article-heavy-action', false);
            window.dispatchEvent(new CustomEvent('article-wordpress-sync-unlock'));
        };

        window.__seoYieldForHeavyActionPaint = function yieldForHeavyActionPaint() {
            return new Promise((resolve) => {
                requestAnimationFrame(() => requestAnimationFrame(resolve));
            });
        };
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
            init() {
                this.syncFeaturedImageDraft();
                if (window.__seoArticleHeavyActionOverlay?.locked) {
                    this.syncPageLocked = true;
                    this.heavyPageAction = window.__seoArticleHeavyActionOverlay.action ?? 'sync';
                    return;
                }
            },
            syncFeaturedImageDraft() {
                const stored = window.__seoFeaturedImageStorage?.load?.(this.articleId);
                if (stored) {
                    this.featuredImageDraft = stored;
                }
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
                        this.unlockPageAfterHeavyActionFailure();
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
                window.__seoEndArticleHeavyActionClient?.();
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

                return 'Tìm slug, alt, caption (WP search)…';
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

                if (this.pickerTab === 'original' && !this.pickerWordPressLinked()) {
                    this.pickerImages = [];
                    this.pickerCatalog = [];
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
                    url.searchParams.set('tab', this.pickerTab);
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

                    this.applyPickerPayload(detail);
                    this.persistPickerCacheFromFetch(detail);
                } catch (error) {
                    this.pickerImages = [];
                    this.pickerError = error?.message || 'Không tải được thư viện media.';
                } finally {
                    this.pickerLoading = false;
                    this.pickerSearching = false;
                }
            },
            isPickerCacheableTab(tab) {
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
                this.savePickerSession();
            },
            tryHydratePickerFromCache(tab, page) {
                if (!this.isPickerCacheableTab(tab)) {
                    return false;
                }

                if (this.pickerSearchQuery.trim() !== '') {
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
                if (this.pickerSearchQuery.trim() !== '') {
                    return;
                }

                const tab = detail?.tab ?? this.pickerTab;
                if (!this.isPickerCacheableTab(tab)) {
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

                this.pickerTab = tab;
                this.pickerSearchQuery = '';
                this.pickerCatalog = [];
                this.pickerImages = [];
                this.pickerPage = 1;
                this.pickerSearching = false;
                this.clearGalleryPickerSelection();

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
                if (this.pickerSearchQuery.trim() === '' && this.tryHydratePickerFromCache(this.pickerTab, prevPage)) {
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
                if (this.pickerSearchQuery.trim() === '' && this.tryHydratePickerFromCache(this.pickerTab, nextPage)) {
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

                    const count = uploaded?.length ?? 0;
                    const titleMany = String(i18n.upload_success_many || '');
                    $wire.handleEditorNotify({
                        title: count === 1
                            ? (i18n.upload_success_one || 'Đã upload ảnh')
                            : titleMany.replace(':count', String(count)),
                        body: i18n.upload_success_body || '',
                        status: 'success',
                    });
                } catch (error) {
                    $wire.handleEditorNotify({
                        title: i18n.upload_failed || 'Upload thất bại',
                        body: error?.message ?? (i18n.upload_failed_body || ''),
                        status: 'danger',
                    });
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
        x-on:seo-featured-image-cleared.window="onFeaturedImageCleared($event)"
        x-on:article-wordpress-sync-lock.window="lockPageForHeavyAction($event.detail?.action ?? 'sync')"
        x-on:article-wordpress-sync-unlock.window="unlockPageAfterHeavyActionFailure()"
        x-on:seo-open-article-media-picker.window="openArticleMediaModal('editor-block', $event.detail?.blockId ?? null)"
        x-on:seo-article-editor-notify.window="
            const payload = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            $wire.handleEditorNotify(payload);
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
            setTimeout(() => {
                if ($wire.pendingEditorCollectTarget) {
                    $wire.finalizePendingEditorCollect();
                }
            }, 1200);
        "
        x-on:editor-html-collected.window="
            const detail = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            const heavyAction = detail.target === 'sync' ? 'sync' : (detail.target === 'save' || !detail.target ? 'save' : null);
            if (heavyAction && !window.__seoArticleHeavyActionOverlay?.locked) {
                window.__seoBeginArticleHeavyActionClient?.(heavyAction);
            }
            const articleId = @js((int) $record->getKey());
            const persistAlbum = window.__seoPersistProductAlbumDraft;
            const persistFeatured = window.__seoPersistFeaturedImageDraft;
            const runAfterMediaDrafts = async (fn) => {
                if (typeof persistFeatured === 'function' && articleId) {
                    await persistFeatured(articleId, $wire);
                }
                if (typeof persistAlbum === 'function' && articleId) {
                    await persistAlbum(articleId, $wire);
                }
                fn(detail);
            };
            if (detail.target === 'sync') {
                runAfterMediaDrafts((d) => $wire.syncArticleToWordPress(d.html ?? '', d.seoAnalysis ?? null));
            } else if (detail.target === 'generate-faq') {
                runAfterMediaDrafts((d) => $wire.generateArticleFaqs(d.html ?? ''));
            } else if (detail.target === 'quick-translate') {
                runAfterMediaDrafts((d) => $wire.quickTranslateLinkedArticle(d.html ?? ''));
            } else {
                runAfterMediaDrafts((d) => $wire.persistArticleLocal(d.html ?? '', d.seoAnalysis ?? null));
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
                if (!lockPageForHeavyAction('save')) {
                    return;
                }
                const runSave = async () => {
                    if (typeof window.__seoExecuteHeavyArticleAction === 'function') {
                        await window.__seoExecuteHeavyArticleAction('save', $wire);
                    } else {
                        await $wire.requestSaveArticle();
                    }
                    window.__seoResetPublishTabPrimed?.();
                };
                runSave().catch(() => unlockPageAfterHeavyActionFailure());
            } else if (action === 'sync') {
                @if ($record->wp_post_id && ! \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager())
                    if (!lockPageForHeavyAction('sync')) {
                        return;
                    }
                    const runSync = async () => {
                        if (typeof window.__seoEnsureCategoriesBeforeSync === 'function') {
                            const allowed = await window.__seoEnsureCategoriesBeforeSync();
                            if (! allowed) {
                                unlockPageAfterHeavyActionFailure();
                                return;
                            }
                        }
                        if (typeof window.__seoExecuteHeavyArticleAction === 'function') {
                            await window.__seoExecuteHeavyArticleAction('sync', $wire);
                        } else {
                            await $wire.requestSyncToWordPress();
                        }
                        window.__seoResetPublishTabPrimed?.();
                    };
                    runSync().catch(() => unlockPageAfterHeavyActionFailure());
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
        x-on:seo-rename-attachment-slugs.window="$wire.renameAttachmentSlugsOnWordPress($event.detail.items ?? [])"
        @seo-attachment-slugs-rename-finished.window="window.dispatchEvent(new CustomEvent('seo-attachment-slugs-rename-finished', { detail: $event.detail }))"
        x-on:seo-update-attachment-meta.window="$wire.updateAttachmentMetaOnWordPress($event.detail.items ?? [])"
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

        <div class="wp-article-edit-layout">
            {{-- Cột chính (giống WP post editor) --}}
            <div class="wp-article-edit-main space-y-4">
                <div class="seo-article-edit-back">
                    <a
                        href="{{ \App\Addons\SeoContentAi\Filament\Resources\ArticleResource::getUrl('index') }}"
                        class="seo-article-edit-back-link"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                        </svg>
                        {{ __('seo-content-ai::filament.article_list.back_to_articles') }}
                    </a>
                </div>

                <div class="wp-postbox">
                    <div class="wp-postbox-title-toolbar">
                        <input
                            type="text"
                            wire:model.blur="articleTitle"
                            placeholder="Thêm tiêu đề bài viết"
                            class="wp-title-input"
                        />
                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-shortcuts-slot')
                    </div>

                    <div class="wp-permalink mt-3 flex flex-wrap items-baseline gap-x-1 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Đường dẫn:</span>
                        @php($displayPermalink = trim($this->getDisplayPermalink()))
                        @if($displayPermalink !== '' && (int) ($record->wp_post_id ?? 0) > 0)
                            <a
                                href="{{ $displayPermalink }}"
                                target="_blank"
                                rel="noopener"
                                class="text-sky-600 dark:text-sky-400 hover:underline break-all"
                            >{{ $displayPermalink }}</a>
                        @else
                            <span
                                class="break-all text-gray-500 dark:text-gray-400"
                                title="URL dự kiến, chưa tồn tại trên WordPress"
                            >{{ $displayPermalink !== '' ? $displayPermalink : (trim($this->getPermalinkBase()) !== '' ? rtrim($this->getPermalinkBase(), '/') . '/' . $this->getDisplaySlug() : '#') }}</span>
                        @endif
                    </div>
                </div>

                <script type="application/json" id="seo-article-initial-html">@json($editorHtml)</script>
                <script type="application/json" id="seo-article-initial-seo">@json($this->getEditorSeoPayload())</script>
                <script type="application/json" id="seo-article-initial-images">@json($this->getEditorImagesPayload())</script>
                <script type="application/json" id="seo-article-editor-settings">@json($this->getEditorSettingsPayload())</script>
                <script type="application/json" id="seo-article-meta">@json($this->getEditorMetaPayload())</script>
                <script type="application/json" id="seo-article-initial-faqs">@json($this->getEditorFaqsPayload())</script>
                <script type="application/json" id="seo-article-faq-config">@json(['can_generate_faq' => $this->canGenerateArticleFaqs(), 'can_import_markdown_faq' => \App\Addons\SeoContentAi\Support\SeoAccessControl::canAccessManagerFeatures()])</script>
                <script type="application/json" id="seo-article-faq-extract-debug">@json($this->getFaqExtractDebugPayload())</script>
                <script>
                    window.__SEO_I18N_LOCALE__ = @js(app()->getLocale());
                    window.__SEO_ARTICLE_MEDIA_PICKER__ = @json($this->getArticleMediaPickerPayload());
                    window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ = @js($this->getId());
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
                x-data="{ aiChatOpen: false, sidebarTab: 'seo' }"
                x-on:seo-article-ai-chat-open.window="aiChatOpen = true"
                x-on:seo-article-ai-chat-close.window="aiChatOpen = false"
                x-on:seo-sidebar-open-publish-tab.window="sidebarTab = 'publish'; aiChatOpen = false"
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
                            <div x-show="!aiChatOpen" x-cloak class="wp-article-edit-sidebar-scroll space-y-4">
                                {{-- Tab switcher: SEO (nội dung sidebar hiện tại) / Publish (chọn danh mục WordPress) --}}
                                <div class="flex border-b border-gray-200 dark:border-gray-700" role="tablist">
                                    <button
                                        type="button"
                                        role="tab"
                                        x-on:click="sidebarTab = 'seo'"
                                        x-bind:aria-selected="sidebarTab === 'seo'"
                                        class="flex-1 px-3 py-2 text-xs font-semibold transition-colors"
                                        x-bind:class="sidebarTab === 'seo'
                                            ? 'border-b-2 border-sky-600 text-sky-600 dark:text-sky-400'
                                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                    >
                                        SEO
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        x-on:click="sidebarTab = 'publish'"
                                        x-bind:aria-selected="sidebarTab === 'publish'"
                                        class="flex-1 px-3 py-2 text-xs font-semibold transition-colors"
                                        x-bind:class="sidebarTab === 'publish'
                                            ? 'border-b-2 border-sky-600 text-sky-600 dark:text-sky-400'
                                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                    >
                                        Publish
                                    </button>
                                </div>

                                {{-- Tab Publish: bộ chọn danh mục kiểu WordPress --}}
                                <div x-show="sidebarTab === 'publish'" x-cloak class="space-y-4">
                                    @include('seo-content-ai::filament.resources.article-resource.pages.partials.publish-categories')
                                </div>

                                {{-- Tab SEO: toàn bộ nội dung sidebar hiện tại (Links, widgets, ảnh đại diện / album) --}}
                                <div x-show="sidebarTab === 'seo'" class="space-y-4">
                                @if (! \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager())
                                    <div wire:ignore id="seo-article-links-root" style="margin: 0;"></div>

                                    <div wire:ignore id="seo-article-domain-widgets-root" style="margin: 0;"></div>
                                @endif

                                @if (! $this->supportsProductGallery())
                    {{-- Ảnh đại diện --}}
                    <div class="wp-postbox">
                        <div class="wp-postbox-header">
                            <h2>Ảnh đại diện</h2>
                        </div>
                        <div class="wp-postbox-inside text-center">
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
                                class="mt-2 text-xs text-gray-500"
                                x-text="featuredImageDraft?.url
                                    ? 'Đã lưu nháp cục bộ · bấm Lưu hoặc Đồng bộ để ghi database'
                                    : 'Bấm để chọn từ thư viện Media'"
                            ></p>
                        </div>
                    </div>
                @endif

                @if ($this->supportsProductGallery())
                    {{-- Album hình ảnh sản phẩm (WooCommerce) — chỉnh sửa qua localStorage, lưu DB khi Lưu/Đồng bộ --}}
                    <div class="wp-postbox">
                        <div class="wp-postbox-header">
                            <h2>Album hình ảnh sản phẩm</h2>
                        </div>
                        <div
                            class="wp-postbox-inside"
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
                            <p class="mt-1 text-xs text-gray-500" x-text="albumCountLabel()"></p>
                        </div>
                    </div>
                @endif
                                </div>{{-- /Tab SEO --}}

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

                    <div x-show="!aiChatOpen" x-cloak class="wp-article-edit-rail-bottom">
                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.publish-sidebar')
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
            <div class="seo-article-media-modal__panel">
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
                        x-show="pickerLoading && pickerImages.length === 0"
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
                        x-show="!pickerLoading || pickerImages.length > 0"
                        x-cloak
                        x-bind:class="{ 'is-busy': pickerSearching || (pickerLoading && pickerImages.length > 0) }"
                    >
                        <div
                            x-show="pickerSearching || (pickerLoading && pickerImages.length > 0)"
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
                            x-show="!pickerSearching && !pickerLoading && pickerImages.length === 0 && !pickerError"
                            class="seo-article-media-modal__empty"
                            x-text="pickerTab === 'article'
                                ? 'Chưa có media trong nội dung bài viết.'
                                : 'Không có media trong thư viện.'"
                        ></p>

                        <div class="seo-article-media-modal__grid" x-show="pickerImages.length > 0">
                            <template x-for="image in pickerImages" :key="image.picker_key">
                                <button
                                    type="button"
                                    class="seo-article-media-modal__item"
                                    x-bind:data-picker-key="image.picker_key"
                                    x-bind:data-picker-wp="image.wp_attachment_id"
                                    x-bind:data-picker-seo="image.seo_media_id"
                                    x-bind:data-picker-url="image.url"
                                    x-bind:data-picker-alt="image.alt"
                                    x-bind:data-picker-slug="image.slug"
                                    x-bind:data-picker-media-type="image.media_type"
                                    x-bind:class="{ 'is-selected': mediaModalMode === 'gallery' && isGalleryPickerSelected(image.picker_key) }"
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
                                        class="seo-article-media-modal__slug"
                                        x-show="image.slug"
                                        x-text="image.slug"
                                    ></span>
                                </button>
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
        </div>
    </div>

    @if (\App\Addons\SeoContentAi\Support\SeoAccessControl::canAccessManagerFeatures())
        <div
            class="seo-debug-markdown-import"
            x-data="{
                open: false,
                markdown: '',
                openModal() {
                    this.open = true;
                    this.$nextTick(() => this.$refs.debugMarkdownInput?.focus());
                },
                closeModal() {
                    this.open = false;
                },
                async importMarkdown() {
                    const text = (this.markdown || '').trim();
                    if (text === '') {
                        return;
                    }
                    await $wire.importMarkdownDebug(text);
                    this.markdown = '';
                    this.open = false;
                },
            }"
            x-on:open-debug-markdown-import.window="openModal()"
            x-on:keydown.escape.window="if (open) closeModal()"
        >
            <div
                x-show="open"
                x-cloak
                class="seo-debug-markdown-import__backdrop"
                x-on:click.self="closeModal()"
                role="dialog"
                aria-modal="true"
                aria-labelledby="seo-debug-markdown-import-title"
            >
                <div class="seo-debug-markdown-import__panel">
                    <h3 id="seo-debug-markdown-import-title" class="seo-debug-markdown-import__title">
                        Debug: import nội dung markdown
                    </h3>
                    <p class="seo-debug-markdown-import__desc">
                        Dán markdown AI để convert sang HTML editor. Chỉ gửi request khi bấm Import.
                    </p>
                    <textarea
                        x-ref="debugMarkdownInput"
                        x-model="markdown"
                        rows="14"
                        class="seo-debug-markdown-import__textarea"
                        placeholder="Nội dung markdown…"
                    ></textarea>
                    <div class="seo-debug-markdown-import__actions">
                        <button
                            type="button"
                            class="fi-btn fi-btn-size-md fi-color-gray"
                            x-on:click="closeModal()"
                        >
                            Hủy
                        </button>
                        <button
                            type="button"
                            class="fi-btn fi-btn-size-md fi-color-primary"
                            x-on:click="importMarkdown()"
                            wire:loading.attr="disabled"
                            wire:target="importMarkdownDebug"
                        >
                            <span wire:loading.remove wire:target="importMarkdownDebug">Import</span>
                            <span wire:loading wire:target="importMarkdownDebug">Đang import…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @push('scripts')
        @viteReactRefresh
        @vite('app/Addons/SeoContentAi/resources/js/article-editor.jsx')
        @if (\App\Addons\SeoContentAi\Support\SeoAccessControl::canAccessManagerFeatures())
            <script>
                (function () {
                    function mountDebugMarkdownHeaderButton() {
                        const slot = document.querySelector('[data-seo-page-actions-slot]');
                        if (!slot || slot.querySelector('[data-seo-debug-md-import]')) {
                            return;
                        }

                        const button = document.createElement('button');
                        button.type = 'button';
                        button.setAttribute('data-seo-debug-md-import', '1');
                        button.className = 'fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 fi-btn-size-md fi-color-gray gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-white text-gray-950 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20';
                        button.innerHTML = '<span class="fi-btn-label">Debug import Markdown</span>';
                        button.addEventListener('click', function (event) {
                            event.preventDefault();
                            window.dispatchEvent(new CustomEvent('open-debug-markdown-import'));
                        });

                        slot.insertBefore(button, slot.firstChild);
                        window.dispatchEvent(new CustomEvent('seo-article-editor-toolbar-refresh'));
                    }

                    document.addEventListener('DOMContentLoaded', mountDebugMarkdownHeaderButton);
                    document.addEventListener('livewire:navigated', mountDebugMarkdownHeaderButton);
                    document.addEventListener('seo-article-editor-header-actions-mounted', mountDebugMarkdownHeaderButton);
                })();
            </script>
        @endif
    @endpush
@endif
</x-filament-panels::page>
