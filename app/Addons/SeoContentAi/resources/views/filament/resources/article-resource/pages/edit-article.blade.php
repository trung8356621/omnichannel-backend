@push('styles')
    @vite('app/Addons/SeoContentAi/resources/css/article-edit-page.css')
@endpush

<x-filament-panels::page @class(['seo-article-edit-page'])>
    <div
        x-data="{
            mediaModalOpen: false,
            mediaModalMode: 'featured',
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
            get galleryPickerSelectedCount() {
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
            visibleGalleryPickerElements() {
                return Array.from(document.querySelectorAll('.seo-article-media-modal__item[data-picker-key]'));
            },
            toggleGalleryPickerItem(event, key, el) {
                if (this.mediaModalMode !== 'gallery' || !key) {
                    return;
                }

                const shiftKey = event?.shiftKey === true;
                if (shiftKey && this.galleryPickerAnchorKey) {
                    const nodes = this.visibleGalleryPickerElements();
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
                if (this.galleryPickerSelectedCount === 0) {
                    return;
                }

                const items = this.galleryPickerSelectedKeys
                    .map((key) => this.galleryPickerSelectedItems[key])
                    .filter((item) => item && String(item.url || '').trim() !== '');

                if (items.length === 0) {
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
            },
            loadArticleTabFromEditor() {
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
                        this.pickerPage = 1;
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
                this.pickerSaveCacheAfterLoad = false;

                try {
                    await $wire.searchMediaPicker(this.pickerSearchQuery);
                } catch (error) {
                    this.pickerSearching = false;
                }
            },
            async switchPickerTab(tab) {
                if (this.pickerTab === tab) {
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

                    return;
                }

                const hydrated = this.tryHydratePickerFromCache(tab, 1);
                this.pickerSuppressLoadingOverlay = hydrated;
                if (!hydrated) {
                    this.pickerLoading = true;
                }

                await $wire.setMediaPickerTab(tab);
            },
            pickerPrevPage() {
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
                    this.pickerSuppressLoadingOverlay = true;
                    $wire.goToMediaPickerPage(prevPage);

                    return;
                }

                this.pickerSuppressLoadingOverlay = false;
                this.pickerLoading = true;
                $wire.mediaPickerPreviousPage();
            },
            pickerNextPage() {
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
                    this.pickerSuppressLoadingOverlay = true;
                    $wire.goToMediaPickerPage(nextPage);

                    return;
                }

                this.pickerSuppressLoadingOverlay = false;
                this.pickerLoading = true;
                $wire.mediaPickerNextPage();
            },
            reloadPickerImages() {
                if (this.pickerTab === 'article') {
                    this.loadArticleTabFromEditor();

                    return;
                }

                this.pickerSuppressLoadingOverlay = false;
                this.pickerLoading = true;
                $wire.reloadMediaPickerImages();
            },
            openArticleMediaModal(mode, blockId = null) {
                this.clearGalleryPickerSelection();
                this.mediaModalMode = mode;
                this.mediaModalOpen = true;
                this.pickerSearchQuery = '';
                this.pickerCatalog = [];
                this.pickerImages = [];
                this.pickerLoading = true;
                this.pickerSearching = false;

                if (mode === 'editor-block') {
                    this.pickerTab = 'article';
                    $wire.armEditorBlockMediaPicker(blockId ?? '');
                    this.loadArticleTabFromEditor();

                    return;
                }

                this.mediaModalMode = mode === 'gallery' ? 'gallery' : 'featured';
                const hydrated = this.tryHydratePickerFromCache('original', 1);
                this.pickerSuppressLoadingOverlay = hydrated;
                if (hydrated) {
                    this.pickerLoading = false;
                }

                $wire.prepareMediaPicker(mode);
            },
            closeArticleMediaModal() {
                this.clearGalleryPickerSelection();
                this.mediaModalOpen = false;
                this.pickerImages = [];
                this.pickerCatalog = [];
                this.pickerSearchQuery = '';
                this.pickerError = null;
                this.pickerLoading = false;
                this.pickerSearching = false;
                $wire.closeMediaPicker();
            },
            selectPickerImage(image) {
                if (!image || !String(image.url || '').trim()) {
                    return;
                }

                if (this.mediaModalMode === 'gallery') {
                    return;
                }

                $wire
                    .selectMediaFromPicker(
                        Number(image.wp_attachment_id || 0),
                        String(image.url || ''),
                        String(image.alt || ''),
                        String(image.slug || ''),
                        Number(image.seo_media_id || 0),
                        String(image.media_type || 'image'),
                    )
                    .then(() => {
                        if (this.mediaModalMode === 'editor-block') {
                            this.closeArticleMediaModal();
                        }
                    });
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

                    await $wire.reloadMediaPickerImages();

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
                    this.pickerLoading = false;
                    if (input) {
                        input.value = '';
                    }
                }
            },
        }"
        x-on:close-article-media-modal.window="closeArticleMediaModal()"
        x-on:seo-open-article-media-picker.window="openArticleMediaModal('editor-block', $event.detail?.blockId ?? null)"
        x-on:seo-article-editor-notify.window="
            const payload = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            $wire.handleEditorNotify(payload);
        "
        x-on:open-article-media-modal.window="
            mediaModalMode = $wire.mediaPickerMode || 'featured';
            mediaModalOpen = true;
        "
        x-on:article-media-picker-loading.window="
            if (!pickerSuppressLoadingOverlay) {
                pickerLoading = true;
            }
        "
        x-on:article-media-picker-loaded.window="
            pickerSuppressLoadingOverlay = false;
            applyPickerPayload($event.detail);
            pickerLoading = false;
            pickerSearching = false;
            persistPickerCacheFromFetch($event.detail);
        "
        x-on:flush-article-faqs.window="
            setTimeout(() => {
                if ($wire.pendingEditorCollectTarget) {
                    $wire.finalizePendingEditorCollect();
                }
            }, 2500);
        "
        x-on:editor-html-collected.window="
            const detail = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            if (detail.target === 'sync') {
                $wire.syncArticleToWordPress(detail.html ?? '');
            } else if (detail.target === 'generate-faq') {
                $wire.generateArticleFaqs(detail.html ?? '');
            } else {
                $wire.persistArticleLocal(detail.html ?? '');
            }
        "
        x-on:generate-article-faqs.window="$wire.requestGenerateArticleFaqs()"
        x-on:article-editor-shortcut.window="
            const action = $event.detail?.action;
            if (action === 'save') {
                $wire.requestSaveArticle();
            } else if (action === 'sync') {
                @if ($record->wp_post_id)
                    $wire.requestSyncToWordPress();
                @endif
            } else if (action === 'preview') {
                const url = @js($this->getArticlePreviewUrl());
                if (url) {
                    window.open(url, '_blank', 'noopener');
                }
            } else if (action === 'toggle-seo') {
                window.dispatchEvent(new CustomEvent('article-editor-toggle-seo-fields'));
            }
        "
        x-on:seo-rename-attachment-slugs.window="$wire.renameAttachmentSlugsOnWordPress($event.detail.items ?? [])"
        @seo-attachment-slugs-rename-finished.window="window.dispatchEvent(new CustomEvent('seo-attachment-slugs-rename-finished', { detail: $event.detail }))"
        x-on:seo-update-attachment-meta.window="$wire.updateAttachmentMetaOnWordPress($event.detail.items ?? [])"
        x-on:seo-analyze-draft.window="$wire.analyzeSeoDraft($event.detail.html)"
        x-on:seo-rewrite-outline.window="
            const detail = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            $wire.rewriteOutlineFromWorkflow(
                detail.mode ?? 'title',
                detail.title ?? '',
                detail.html ?? '',
            ).then((result) => {
                window.dispatchEvent(new CustomEvent('seo-outline-rewritten', { detail: result || {} }));
            });
        "
        @seo-analyze-result.window="window.dispatchEvent(new CustomEvent('seo-editor-analyze-result', { detail: $event.detail }))"
        x-on:save-article-faqs.window="$wire.saveArticleFaqs($event.detail.faqs ?? [])"
        x-on:dismiss-faq-extract-debug.window="$wire.clearFaqExtractDebug()"
        @article-faq-extract-debug-cleared.window="window.dispatchEvent(new CustomEvent('article-faq-extract-debug-cleared'))"
        x-on:extract-article-faqs-with-context.window="$wire.extractFaqsFromSelection($event.detail.html ?? '', $event.detail.articleHtml ?? '')"
        x-on:renew-article-faq.window="$wire.renewArticleFaq($event.detail.index, $event.detail.question, $event.detail.answer)"
        x-on:generate-article-image.window="$wire.generateArticleImageFromEditor($event.detail.selectionText ?? '', $event.detail.selectionHtml ?? '', $event.detail.userBrief ?? '', $event.detail.activeBlockId ?? '', $event.detail.target ?? 'editor', $event.detail.loaiSanPhamCategoryArticleId ?? 0, $event.detail.loaiSanPhamCustom ?? '')"
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
                <div class="wp-postbox">
                    <input
                        type="text"
                        wire:model.blur="articleTitle"
                        placeholder="Thêm tiêu đề bài viết"
                        class="wp-title-input"
                    />

                    <div
                        class="wp-permalink mt-3 flex flex-wrap items-baseline gap-x-1 gap-y-1 text-sm text-gray-600 dark:text-gray-400"
                        wire:key="article-permalink-{{ md5($articleSlug . '|' . $this->getArticlePermalink()) }}"
                    >
                        <span class="font-medium text-gray-700 dark:text-gray-300">Đường dẫn:</span>
                        @if ($editingSlug)
                            <span class="text-gray-500">{{ $this->getPermalinkBase() }}/</span>
                            <input
                                type="text"
                                wire:model.live.debounce.250ms="articleSlug"
                                wire:keydown.enter.prevent="confirmArticleSlug"
                                class="inline-block min-w-[12rem] max-w-full flex-1 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-0.5 text-sm"
                            />
                            @if ($this->getPermalinkSuffix() !== '')
                                <span class="text-gray-500">{{ $this->getPermalinkSuffix() }}</span>
                            @endif
                            <button
                                type="button"
                                wire:click="confirmArticleSlug"
                                wire:loading.attr="disabled"
                                wire:target="confirmArticleSlug"
                                class="ml-2 text-primary-600 hover:underline text-xs disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="confirmArticleSlug">OK</span>
                                <span wire:loading wire:target="confirmArticleSlug">…</span>
                            </button>
                        @else
                            @php($previewPermalink = $this->getDisplayPermalink())
                            <a
                                href="{{ $previewPermalink !== '' ? $previewPermalink : '#' }}"
                                target="_blank"
                                rel="noopener"
                                class="text-sky-600 dark:text-sky-400 hover:underline break-all"
                            >
                                {{ $previewPermalink !== '' ? $previewPermalink : ($this->getPermalinkBase() !== '' ? $this->getPermalinkBase() . '/sample-post' : '#') }}
                            </a>
                            <button
                                type="button"
                                wire:click="$set('editingSlug', true)"
                                class="ml-2 text-xs text-gray-500 hover:text-primary-600 hover:underline"
                            >
                                Chỉnh sửa
                            </button>
                        @endif
                    </div>

                    @include('seo-content-ai::filament.resources.article-resource.pages.partials.seo-fields-collapsible')
                </div>

                <script type="application/json" id="seo-article-initial-html">@json($editorHtml)</script>
                <script type="application/json" id="seo-article-initial-outline">@json($this->getEditorOutlineMarkdown())</script>
                <script type="application/json" id="seo-article-initial-seo">@json($this->getEditorSeoPayload())</script>
                <script type="application/json" id="seo-article-initial-images">@json($this->getEditorImagesPayload())</script>
                <script type="application/json" id="seo-article-editor-settings">@json($this->getEditorSettingsPayload())</script>
                <script type="application/json" id="seo-article-meta">@json($this->getEditorMetaPayload())</script>
                <script type="application/json" id="seo-article-initial-faqs">@json($this->getEditorFaqsPayload())</script>
                <script type="application/json" id="seo-article-faq-config">@json(['can_generate_faq' => $this->canGenerateArticleFaqs()])</script>
                <script type="application/json" id="seo-article-faq-extract-debug">@json($this->getFaqExtractDebugPayload())</script>
                <script>
                    window.__SEO_I18N_LOCALE__ = @js(app()->getLocale());
                    window.__SEO_ARTICLE_MEDIA_PICKER__ = @json($this->getArticleMediaPickerPayload());
                </script>

                <div wire:ignore id="seo-article-editor-root" class="w-full seo-article-editor-compact"></div>

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
                x-data="{ aiChatOpen: false }"
                x-on:seo-article-ai-chat-open.window="aiChatOpen = true"
                x-on:seo-article-ai-chat-close.window="aiChatOpen = false"
            >
                <div class="wp-article-edit-rail">
                    <div x-show="!aiChatOpen" x-cloak class="wp-article-edit-rail-top">
                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.seo-snippet-sidebar')
                    </div>

                    <div
                        class="wp-article-edit-rail-center"
                        x-bind:class="{ 'is-chat': aiChatOpen }"
                    >
                        <div class="wp-article-edit-sidebar-window">
                            <div x-show="!aiChatOpen" x-cloak class="wp-article-edit-sidebar-scroll space-y-4">
                                <div wire:ignore id="seo-article-links-root" style="margin: 0;"></div>

                                <div wire:ignore id="seo-article-domain-widgets-root" style="margin: 0;"></div>

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
                                @if ($featuredImageUrl)
                                    <img
                                        src="{{ $featuredImageUrl }}"
                                        alt="Ảnh đại diện"
                                        class="wp-featured-image-picker__img"
                                    />
                                @else
                                    <span class="wp-featured-image-picker__empty">
                                        <svg class="mx-auto h-12 w-12 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <span class="wp-featured-image-picker__label">Đặt ảnh đại diện</span>
                                    </span>
                                @endif
                            </button>
                            <p class="mt-2 text-xs text-gray-500">
                                @if ($featuredImageUrl)
                                    Đã lưu cục bộ · «Đồng bộ» để đẩy lên WordPress
                                @else
                                    Bấm để chọn từ thư viện Media
                                @endif
                            </p>
                        </div>
                    </div>
                @endif

                @if ($this->supportsProductGallery())
                    {{-- Album hình ảnh sản phẩm (WooCommerce) --}}
                    <div class="wp-postbox">
                        <div class="wp-postbox-header">
                            <h2>Album hình ảnh sản phẩm</h2>
                        </div>
                        <div
                            class="wp-postbox-inside"
                            x-data="{
                                dragUrl: null,
                                startDrag(event) {
                                    this.dragUrl = event.currentTarget.dataset.galleryUrl || null;
                                },
                                onDrop(event) {
                                    event.preventDefault();
                                    if (!this.dragUrl) return;
                                    const target = event.currentTarget.closest('[data-gallery-url]');
                                    const targetUrl = target?.dataset?.galleryUrl || null;
                                    if (!targetUrl || targetUrl === this.dragUrl) return;

                                    const list = Array.from($el.querySelectorAll('[data-gallery-url]'));
                                    const dragNode = list.find((node) => node.dataset.galleryUrl === this.dragUrl);
                                    const targetNode = list.find((node) => node.dataset.galleryUrl === targetUrl);
                                    if (!dragNode || !targetNode) return;

                                    const parent = dragNode.parentNode;
                                    parent.insertBefore(dragNode, targetNode);
                                    const ordered = Array.from(parent.querySelectorAll('[data-gallery-url]'))
                                        .map((node) => node.dataset.galleryUrl)
                                        .filter(Boolean);
                                    $wire.reorderProductGallery(ordered);
                                },
                                allowDrop(event) {
                                    event.preventDefault();
                                },
                                finishDrag() {
                                    this.dragUrl = null;
                                },
                            }"
                        >
                            @if (count($productGallery) > 0)
                                <div class="wp-product-gallery-grid" role="list">
                                    @foreach ($productGallery as $idx => $image)
                                        <div
                                            class="wp-product-gallery-thumb-wrap"
                                            role="listitem"
                                            draggable="true"
                                            data-gallery-url="{{ $image['url'] }}"
                                            x-on:dragstart="startDrag($event)"
                                            x-on:dragover="allowDrop($event)"
                                            x-on:drop="onDrop($event)"
                                            x-on:dragend="finishDrag()"
                                        >
                                            <a
                                                href="{{ $image['url'] }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="wp-product-gallery-thumb-link"
                                            >
                                                <img
                                                    src="{{ $image['url'] }}"
                                                    alt=""
                                                    class="wp-product-gallery-thumb"
                                                />
                                            </a>
                                            @if ($idx === 0)
                                                <span class="wp-product-gallery-badge">Đại diện</span>
                                            @endif
                                            <button
                                                type="button"
                                                class="wp-product-gallery-remove"
                                                wire:click="removeProductGalleryImage(@js($image['url']))"
                                                title="Xóa ảnh khỏi album"
                                                aria-label="Xóa ảnh khỏi album"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <button
                                    type="button"
                                    class="wp-product-gallery-generate mt-2"
                                    title="Tạo ảnh AI cho album sản phẩm"
                                    x-on:click="window.dispatchEvent(new CustomEvent('seo-open-generate-image-modal', { detail: { target: 'product-gallery' } }))"
                                >
                                    Tạo ảnh
                                </button>
                            @endif
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
                            <p class="mt-1 text-xs text-gray-500">
                                @if (count($productGallery) > 0)
                                    {{ count($productGallery) }} ảnh · Ảnh đầu là đại diện · Kéo thả để đổi vị trí
                                @else
                                    Chưa có ảnh trong album
                                @endif
                            </p>
                            <script>
                                try {
                                    window.localStorage.setItem(
                                        'seo_product_album_list_{{ (int) $record->getKey() }}',
                                        JSON.stringify(@json($productGallery))
                                    );
                                } catch (error) {
                                    // ignore localStorage failures
                                }
                            </script>
                        </div>
                    </div>
                @endif

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
                    >
                        <span x-show="!localMediaUploading">{{ __('seo-content-ai::filament.media_tools.upload') }}</span>
                        <span x-show="localMediaUploading" x-cloak>{{ __('seo-content-ai::filament.media_tools.uploading') }}</span>
                    </button>
                    <button
                        type="button"
                        class="seo-article-media-modal__reload"
                        x-on:click="reloadPickerImages()"
                    >
                        Reload
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
                                    x-on:click="mediaModalMode === 'gallery'
                                        ? toggleGalleryPickerItem($event, image.picker_key, $el)
                                        : selectPickerImage(image)"
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
                            Đã chọn: <strong x-text="galleryPickerSelectedCount">0</strong>
                        </span>
                        <button
                            type="button"
                            class="seo-article-media-modal__select-bar-clear"
                            x-on:click="clearGalleryPickerSelection()"
                            x-show="galleryPickerSelectedCount > 0"
                            x-cloak
                        >
                            Bỏ chọn
                        </button>
                    </div>
                    <button
                        type="button"
                        class="seo-article-media-modal__select-bar-submit"
                        x-on:click="confirmGalleryPickerSelection()"
                        x-bind:disabled="galleryPickerSelectedCount === 0"
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

    @push('scripts')
        @viteReactRefresh
        @vite('app/Addons/SeoContentAi/resources/js/article-editor.jsx')
    @endpush
</x-filament-panels::page>
