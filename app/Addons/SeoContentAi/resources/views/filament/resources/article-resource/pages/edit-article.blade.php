<x-filament-panels::page>
    <div
        x-data="{
            mediaModalOpen: @entangle('mediaPickerOpen').live,
            mediaModalMode: 'featured',
            pickerLoading: @entangle('mediaPickerLoading').live,
            openArticleMediaModal(mode, blockId = null) {
                this.mediaModalMode = mode;
                this.mediaModalOpen = true;
                this.pickerLoading = true;
                if (mode === 'editor-block') {
                    $wire.prepareMediaPicker('editor-block', blockId);
                } else {
                    this.mediaModalMode = mode === 'gallery' ? 'gallery' : 'featured';
                    $wire.prepareMediaPicker(mode);
                }
            },
            closeArticleMediaModal() {
                $wire.closeMediaPicker();
            },
        }"
        x-on:close-article-media-modal.window="closeArticleMediaModal()"
        x-on:seo-open-article-media-picker.window="openArticleMediaModal('editor-block', $event.detail?.blockId ?? null)"
        x-on:seo-article-editor-notify.window="
            const payload = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            $wire.handleEditorNotify(payload);
        "
        x-on:open-article-media-modal.window="mediaModalMode = $wire.mediaPickerMode || 'featured'"
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
            } else {
                $wire.persistArticleLocal(detail.html ?? '');
            }
        "
        x-on:seo-rename-attachment-slugs.window="$wire.renameAttachmentSlugsOnWordPress($event.detail.items ?? [])"
        @seo-attachment-slugs-rename-finished.window="window.dispatchEvent(new CustomEvent('seo-attachment-slugs-rename-finished', { detail: $event.detail }))"
        x-on:seo-analyze-draft.window="$wire.analyzeSeoDraft($event.detail.html)"
        @seo-analyze-result.window="window.dispatchEvent(new CustomEvent('seo-editor-analyze-result', { detail: $event.detail }))"
        x-on:save-article-faqs.window="$wire.saveArticleFaqs($event.detail.faqs ?? [])"
        x-on:dismiss-faq-extract-debug.window="$wire.clearFaqExtractDebug()"
        @article-faq-extract-debug-cleared.window="window.dispatchEvent(new CustomEvent('article-faq-extract-debug-cleared'))"
        x-on:extract-article-faqs-with-context.window="$wire.extractFaqsFromSelection($event.detail.html ?? '', $event.detail.articleHtml ?? '')"
        x-on:renew-article-faq.window="$wire.renewArticleFaq($event.detail.index, $event.detail.question, $event.detail.answer)"
        x-on:generate-article-image.window="$wire.generateArticleImageFromEditor($event.detail.selectionText ?? '', $event.detail.selectionHtml ?? '', $event.detail.userBrief ?? '', $event.detail.activeBlockId ?? '')"
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
        class="wp-article-edit -mx-4 max-w-none"
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

                    <div class="wp-permalink mt-3 text-sm text-gray-600 dark:text-gray-400">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Đường dẫn:</span>
                        @if ($editingSlug)
                            <span class="text-gray-500">{{ $this->getPermalinkBase() }}/</span>
                            <input
                                type="text"
                                wire:model.blur="articleSlug"
                                class="inline-block w-auto max-w-[200px] rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-0.5 text-sm"
                            />
                            <button
                                type="button"
                                wire:click="$set('editingSlug', false)"
                                class="ml-2 text-primary-600 hover:underline text-xs"
                            >
                                OK
                            </button>
                        @else
                            @php($articlePermalink = $this->getArticlePermalink())
                            <a
                                href="{{ $articlePermalink !== '' ? $articlePermalink : '#' }}"
                                target="_blank"
                                rel="noopener"
                                class="text-sky-600 dark:text-sky-400 hover:underline break-all"
                            >
                                {{ $articlePermalink !== '' ? $articlePermalink : $this->getPermalinkBase() . '/' . $this->getDisplaySlug() }}
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
                </div>

                <script type="application/json" id="seo-article-initial-html">@json($editorHtml)</script>
                <script type="application/json" id="seo-article-initial-outline">@json($this->getEditorOutlineMarkdown())</script>
                <script type="application/json" id="seo-article-initial-seo">@json($this->getEditorSeoPayload())</script>
                <script type="application/json" id="seo-article-initial-images">@json($this->getEditorImagesPayload())</script>
                <script type="application/json" id="seo-article-editor-settings">@json($this->getEditorSettingsPayload())</script>
                <script type="application/json" id="seo-article-meta">@json($this->getEditorMetaPayload())</script>
                <script type="application/json" id="seo-article-initial-faqs">@json($this->getEditorFaqsPayload())</script>
                <script type="application/json" id="seo-article-faq-extract-debug">@json($this->getFaqExtractDebugPayload())</script>

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

            {{-- Sidebar: widgets thường hoặc Chat AI (mở từ nút tròn góc màn hình) --}}
            <aside
                class="wp-article-edit-sidebar"
                x-data="{ aiChatOpen: false }"
                x-on:seo-article-ai-chat-open.window="aiChatOpen = true"
                x-on:seo-article-ai-chat-close.window="aiChatOpen = false"
            >
                <div x-show="!aiChatOpen" x-cloak class="space-y-4">
                <div wire:ignore id="seo-article-links-root"></div>

                {{-- Xuất bản --}}
                <div class="wp-postbox">
                    <div class="wp-postbox-header">
                        <h2>Xuất bản</h2>
                    </div>
                    <div class="wp-postbox-inside space-y-3 text-sm">
                        <div class="flex justify-end">
                            <a
                                href="{{ $this->getArticlePreviewUrl() }}"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center rounded border border-sky-600 px-3 py-1 text-xs font-medium text-sky-700 hover:bg-sky-50 dark:border-sky-500 dark:text-sky-300 dark:hover:bg-sky-950/50"
                            >
                                Xem trước
                            </a>
                        </div>

                        <div class="space-y-3 border-y border-gray-200 py-3 dark:border-gray-700">
                            <div class="text-xs">
                                <span class="text-gray-500 dark:text-gray-400">Trạng thái:</span>
                                <strong class="text-gray-800 dark:text-gray-100">{{ $this->getStatusLabelForPublishBox() }}</strong>
                                <button
                                    type="button"
                                    wire:click="startStatusEdit"
                                    class="ml-1 text-sky-600 hover:underline"
                                >
                                    Chỉnh sửa
                                </button>
                                @if ($editingStatus)
                                    <div class="mt-2 flex items-center gap-2">
                                        <select
                                            wire:model.live="articleStatus"
                                            class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1"
                                        >
                                            <option value="draft">Bản nháp</option>
                                            <option value="published">Đã xuất bản</option>
                                            <option value="scheduled">Đã lên lịch</option>
                                            <option value="private">Riêng tư</option>
                                        </select>
                                        <button type="button" wire:click="applyStatusEdit" class="text-sky-600 hover:underline">Đồng ý</button>
                                        <button type="button" wire:click="cancelStatusEdit" class="text-sky-600 hover:underline">Hủy</button>
                                    </div>
                                @endif
                            </div>

                            <div class="text-xs">
                                <span class="text-gray-500 dark:text-gray-400">Hiển thị:</span>
                                <strong class="text-gray-800 dark:text-gray-100">{{ $this->getVisibilityLabel() }}</strong>
                                <button
                                    type="button"
                                    wire:click="startVisibilityEdit"
                                    class="ml-1 text-sky-600 hover:underline"
                                >
                                    Chỉnh sửa
                                </button>
                                @if ($editingVisibility)
                                    <div class="mt-2 flex items-center gap-2">
                                        <select
                                            wire:model.live="visibility"
                                            class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1"
                                        >
                                            <option value="public">Công khai</option>
                                            <option value="private">Riêng tư</option>
                                        </select>
                                        <button type="button" wire:click="applyVisibilityEdit" class="text-sky-600 hover:underline">Đồng ý</button>
                                        <button type="button" wire:click="cancelVisibilityEdit" class="text-sky-600 hover:underline">Hủy</button>
                                    </div>
                                @endif
                            </div>

                            <div class="text-xs">
                                <span class="text-gray-500 dark:text-gray-400">Bài lên lịch:</span>
                                <strong class="text-gray-800 dark:text-gray-100">{{ $this->getPublishWhenLabel() }}</strong>
                                <button
                                    type="button"
                                    wire:click="startPublishAtEdit"
                                    class="ml-1 text-sky-600 hover:underline"
                                >
                                    Chỉnh sửa
                                </button>
                                @if ($editingPublishAt)
                                    <div
                                        class="mt-2 space-y-2"
                                        x-data="{
                                            day: @entangle('publishDay').live,
                                            month: @entangle('publishMonth').live,
                                            year: @entangle('publishYear').live,
                                            hour: @entangle('publishHour').live,
                                            minute: @entangle('publishMinute').live,
                                            iso: '',
                                            init() {
                                                this.rebuildIso();
                                                this.$watch('day', () => this.rebuildIso());
                                                this.$watch('month', () => this.rebuildIso());
                                                this.$watch('year', () => this.rebuildIso());
                                                this.$watch('hour', () => this.rebuildIso());
                                                this.$watch('minute', () => this.rebuildIso());
                                            },
                                            pad(v) {
                                                const n = Number(v || 0);
                                                if (Number.isNaN(n)) return '00';
                                                return String(n).padStart(2, '0');
                                            },
                                            rebuildIso() {
                                                const y = String(this.year || '').padStart(4, '0');
                                                const m = this.pad(this.month);
                                                const d = this.pad(this.day);
                                                const h = this.pad(this.hour);
                                                const i = this.pad(this.minute);
                                                this.iso = `${y}-${m}-${d}T${h}:${i}`;
                                            },
                                            applyIso() {
                                                if (!this.iso || !this.iso.includes('T')) return;
                                                const [datePart, timePart] = this.iso.split('T');
                                                const [y, m, d] = datePart.split('-');
                                                const [h, i] = timePart.split(':');
                                                this.year = y || this.year;
                                                this.month = m || this.month;
                                                this.day = d || this.day;
                                                this.hour = h || this.hour;
                                                this.minute = i || this.minute;
                                            }
                                        }"
                                    >
                                        <input
                                            x-model="iso"
                                            x-on:change="applyIso()"
                                            type="datetime-local"
                                            step="60"
                                            class="seo-publish-datetime-input rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1.5 px-2"
                                        />
                                    </div>
                                    <div class="mt-1 flex items-center gap-2">
                                        <button type="button" wire:click="applyPublishAtEdit" class="text-sky-600 hover:underline">Đồng ý</button>
                                        <button type="button" wire:click="cancelPublishAtEdit" class="text-sky-600 hover:underline">Hủy</button>
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($record->wp_post_id)
                            <div class="text-xs text-gray-500">WordPress ID: {{ $record->wp_post_id }}</div>
                        @endif
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Nháp editor lưu cục bộ trong trình duyệt. «Lưu» ghi vào hệ thống SEO; «Đồng bộ» đẩy lên WordPress.
                        </p>
                        <div class="seo-article-actions flex flex-col gap-2 pt-2">
                            <button
                                type="button"
                                wire:click="requestSaveArticle"
                                wire:loading.attr="disabled"
                                wire:target="requestSaveArticle,persistArticleLocal"
                                class="seo-wp-btn-primary w-full"
                            >
                                <span wire:loading.remove wire:target="requestSaveArticle,persistArticleLocal">
                                    {{ $articleStatus === 'scheduled' ? 'Cập nhật lịch' : 'Cập nhật' }}
                                </span>
                                <span wire:loading wire:target="requestSaveArticle,persistArticleLocal">Đang lưu…</span>
                            </button>
                            <button
                                type="button"
                                wire:click="requestSyncToWordPress"
                                wire:loading.attr="disabled"
                                wire:target="requestSyncToWordPress,syncArticleToWordPress"
                                class="seo-wp-btn-secondary w-full"
                                @if (! $record->wp_post_id) disabled title="Chưa liên kết WordPress" @endif
                            >
                                <span wire:loading.remove wire:target="requestSyncToWordPress,syncArticleToWordPress">Đồng bộ WordPress</span>
                                <span wire:loading wire:target="requestSyncToWordPress,syncArticleToWordPress">Đang đồng bộ…</span>
                            </button>
                            @if ($record->wp_post_id)
                                @php($wpPermalink = $this->getArticlePermalink())
                                @if ($wpPermalink !== '')
                                    <a
                                        href="{{ $wpPermalink }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="seo-wp-btn-outline w-full text-center"
                                    >
                                        Xem trên WordPress
                                    </a>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>

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

                @if ($this->supportsProductGallery())
                    {{-- Album hình ảnh sản phẩm (WooCommerce) --}}
                    <div class="wp-postbox">
                        <div class="wp-postbox-header">
                            <h2>Album hình ảnh sản phẩm</h2>
                        </div>
                        <div class="wp-postbox-inside">
                            @if (count($productGallery) > 0)
                                <div class="wp-product-gallery-grid" role="list">
                                    @foreach ($productGallery as $image)
                                        <a
                                            href="{{ $image['url'] }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="wp-product-gallery-thumb-wrap"
                                            role="listitem"
                                        >
                                            <img
                                                src="{{ $image['url'] }}"
                                                alt=""
                                                class="wp-product-gallery-thumb"
                                            />
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                            <button
                                type="button"
                                x-on:click="openArticleMediaModal('gallery')"
                                class="wp-product-gallery-add mt-2"
                                title="Thêm ảnh từ thư viện WordPress"
                            >
                                Thêm ảnh thư viện sản phẩm
                            </button>
                            <p class="mt-1 text-xs text-gray-500">
                                @if (count($productGallery) > 0)
                                    {{ count($productGallery) }} ảnh · «Đồng bộ» để đẩy lên WordPress
                                @else
                                    Chưa có ảnh trong album
                                @endif
                            </p>
                        </div>
                    </div>
                @endif

                </div>

                <div
                    x-show="aiChatOpen"
                    x-cloak
                    wire:ignore
                    id="seo-article-ai-chat-root"
                    class="wp-sidebar-ai-chat"
                ></div>
            </aside>
        </div>

        <div
            x-show="mediaModalOpen"
            x-cloak
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
                        <span x-text="mediaModalMode === 'gallery' ? 'Chọn ảnh cho album sản phẩm' : (mediaModalMode === 'editor-block' ? 'Chọn ảnh từ thư viện' : 'Chọn ảnh đại diện')"></span>
                    </h2>
                    <button type="button" class="seo-article-media-modal__close" x-on:click="closeArticleMediaModal()" aria-label="Đóng">
                        ×
                    </button>
                </div>

                <div class="seo-article-media-modal__tabs">
                    <button
                        type="button"
                        wire:click="setMediaPickerTab('original')"
                        class="seo-article-media-modal__tab {{ $mediaPickerTab === 'original' ? 'is-active' : '' }}"
                    >
                        Gốc (WP)
                    </button>
                    <button
                        type="button"
                        wire:click="setMediaPickerTab('local')"
                        class="seo-article-media-modal__tab {{ $mediaPickerTab === 'local' ? 'is-active' : '' }}"
                    >
                        Nội bộ (Laravel)
                    </button>
                </div>

                <div class="seo-article-media-modal__toolbar">
                    <input
                        type="search"
                        wire:model.live.debounce.400ms="mediaPickerSearch"
                        class="seo-article-media-modal__search"
                        placeholder="{{ $mediaPickerTab === 'local' ? 'Tìm slug, alt, tên file (Laravel)…' : 'Tìm slug, alt, caption (WP search)…' }}"
                        autocomplete="off"
                        x-on:keydown.escape="closeArticleMediaModal()"
                    />
                </div>

                @if ($mediaPickerError)
                    <p class="seo-article-media-modal__error">{{ $mediaPickerError }}</p>
                @endif

                <div class="seo-article-media-modal__body">
                    <div
                        x-show="pickerLoading"
                        x-cloak
                        class="seo-article-media-modal__skeleton-grid"
                        aria-busy="true"
                        aria-label="Đang tải thư viện ảnh"
                    >
                        @for ($i = 0; $i < 12; $i++)
                            <div class="seo-article-media-modal__skeleton"></div>
                        @endfor
                    </div>

                    <div x-show="!pickerLoading" x-cloak>
                        @if (empty($mediaPickerImages) && ! $mediaPickerError)
                            <p class="seo-article-media-modal__empty">Không có ảnh trong thư viện.</p>
                        @elseif (! empty($mediaPickerImages))
                            <div class="seo-article-media-modal__grid">
                                @foreach ($mediaPickerImages as $image)
                                    <button
                                        type="button"
                                        class="seo-article-media-modal__item"
                                        wire:click="selectMediaFromPicker({{ (int) ($image['wp_attachment_id'] ?? 0) }}, @js($image['url'] ?? ''), @js($image['alt'] ?? ''), @js($image['slug'] ?? ''), {{ (int) ($image['seo_media_id'] ?? ($mediaPickerTab === 'local' ? ($image['id'] ?? 0) : 0)) }})"
                                        wire:key="picker-media-{{ $mediaPickerTab }}-{{ $mediaPickerPage }}-{{ $image['id'] }}"
                                    >
                                        <img
                                            src="{{ $image['url'] }}"
                                            alt="{{ $image['alt'] ?? $image['slug'] }}"
                                            loading="lazy"
                                            class="seo-article-media-modal__thumb"
                                        />
                                        @if (filled($image['slug'] ?? ''))
                                            <span class="seo-article-media-modal__slug">{{ $image['slug'] }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                @if ($mediaPickerTotalPages > 1)
                    <div class="seo-article-media-modal__pagination">
                        <button
                            type="button"
                            class="seo-article-media-modal__page-btn"
                            wire:click="mediaPickerPreviousPage"
                            @disabled($mediaPickerPage <= 1)
                        >
                            Trang trước
                        </button>
                        <span>{{ $mediaPickerPage }} / {{ $mediaPickerTotalPages }}</span>
                        <button
                            type="button"
                            class="seo-article-media-modal__page-btn"
                            wire:click="mediaPickerNextPage"
                            @disabled($mediaPickerPage >= $mediaPickerTotalPages)
                        >
                            Trang sau
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        @viteReactRefresh
        @vite('app/Addons/SeoContentAi/resources/js/article-editor.jsx')
    @endpush
</x-filament-panels::page>
