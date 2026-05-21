<x-filament-panels::page>
    <div
        x-data
        x-on:editor-html-collected.window="
            if ($event.detail.target === 'sync') {
                $wire.syncArticleToWordPress($event.detail.html);
            } else {
                $wire.persistArticleLocal($event.detail.html);
            }
        "
        x-on:seo-rename-attachment-slugs.window="$wire.renameAttachmentSlugsOnWordPress($event.detail.items ?? [])"
        @seo-attachment-slugs-rename-finished.window="window.dispatchEvent(new CustomEvent('seo-attachment-slugs-rename-finished', { detail: $event.detail }))"
        x-on:seo-analyze-draft.window="$wire.analyzeSeoDraft($event.detail.html)"
        @seo-analyze-result.window="window.dispatchEvent(new CustomEvent('seo-editor-analyze-result', { detail: $event.detail }))"
        x-on:save-article-faqs.window="$wire.saveArticleFaqs($event.detail.faqs)"
        x-on:extract-article-faqs-with-context.window="$wire.extractFaqsFromSelection($event.detail.html ?? '', $event.detail.articleHtml ?? '')"
        x-on:renew-article-faq.window="$wire.renewArticleFaq($event.detail.index, $event.detail.question, $event.detail.answer)"
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
                <script type="application/json" id="seo-article-meta">@json(['id' => $record->id, 'title' => $articleTitle])</script>
                <script type="application/json" id="seo-article-initial-faqs">@json($this->getEditorFaqsPayload())</script>
                <script type="application/json" id="seo-article-faq-extract-debug">@json($this->getFaqExtractDebugPayload())</script>

                <div wire:ignore id="seo-article-editor-root" class="w-full seo-article-editor-compact"></div>

                <div wire:ignore id="seo-article-faq-root" class="w-full mt-4"></div>
            </div>

            {{-- Sidebar widgets (ẩn khi chọn text → hiện Chat AI) --}}
            <aside
                class="wp-article-edit-sidebar"
                x-data="{ aiChatOpen: false }"
                x-on:seo-editor-text-selection.window="aiChatOpen = Boolean($event.detail?.hasSelection)"
            >
                <div x-show="!aiChatOpen" x-cloak class="space-y-4">
                {{-- Xuất bản --}}
                <div class="wp-postbox">
                    <div class="wp-postbox-header">
                        <h2>Xuất bản</h2>
                        <a
                            href="{{ $this->getArticlePreviewUrl() }}"
                            target="_blank"
                            rel="noopener"
                            class="text-xs text-sky-600 hover:underline"
                        >
                            Xem trước (Laravel)
                        </a>
                    </div>
                    <div class="wp-postbox-inside space-y-3 text-sm">
                        <div class="flex justify-between gap-2">
                            <span class="text-gray-500 dark:text-gray-400">Trạng thái:</span>
                            <select
                                wire:model.live="articleStatus"
                                class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1"
                            >
                                <option value="draft">Bản nháp</option>
                                <option value="published">Đã xuất bản</option>
                                <option value="scheduled">Hẹn giờ</option>
                                <option value="private">Riêng tư</option>
                            </select>
                        </div>
                        @if ($this->getPublishedAtLabel())
                            <div class="flex justify-between gap-2">
                                <span class="text-gray-500 dark:text-gray-400">Xuất bản:</span>
                                <span>{{ $this->getPublishedAtLabel() }}</span>
                            </div>
                        @endif
                        @if ($record->wp_post_id)
                            <div class="text-xs text-gray-500">WordPress ID: {{ $record->wp_post_id }}</div>
                        @endif
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Nháp editor lưu cục bộ trong trình duyệt. «Lưu» ghi vào hệ thống SEO; «Đồng bộ» đẩy lên WordPress.
                        </p>
                        <div class="seo-article-actions flex flex-col gap-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                            <button
                                type="button"
                                wire:click="requestSaveArticle"
                                wire:loading.attr="disabled"
                                wire:target="requestSaveArticle,persistArticleLocal"
                                class="seo-wp-btn-primary w-full"
                            >
                                <span wire:loading.remove wire:target="requestSaveArticle,persistArticleLocal">Lưu</span>
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
                        @if ($featuredImageUrl)
                            <img
                                src="{{ $featuredImageUrl }}"
                                alt="Ảnh đại diện"
                                class="mx-auto max-h-40 rounded border border-gray-200 dark:border-gray-700 object-cover"
                            />
                            <p class="mt-2 text-xs text-gray-500">Đồng bộ từ WordPress</p>
                        @else
                            <div class="py-6 text-gray-400 text-sm">
                                <svg class="mx-auto h-12 w-12 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <button type="button" class="mt-2 text-sky-600 hover:underline" disabled>
                                    Đặt ảnh đại diện
                                </button>
                                <p class="mt-1 text-xs">Chưa có ảnh trên WordPress</p>
                            </div>
                        @endif
                    </div>
                </div>

                @if ($this->isProduct())
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
                                class="wp-product-gallery-add mt-2"
                                disabled
                                title="Quản lý album trên WordPress / WooCommerce"
                            >
                                Thêm ảnh thư viện sản phẩm
                            </button>
                            <p class="mt-1 text-xs text-gray-500">
                                @if (count($productGallery) > 0)
                                    {{ count($productGallery) }} ảnh · đồng bộ từ WordPress
                                @else
                                    Chưa có album trên WordPress
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
    </div>

    @push('scripts')
        @viteReactRefresh
        @vite('app/Addons/SeoContentAi/resources/js/article-editor.jsx')
    @endpush
</x-filament-panels::page>
