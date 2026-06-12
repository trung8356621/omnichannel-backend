@php
    /**
     * Tab Publish — bộ chọn danh mục kiểu WordPress.
     * - Danh sách checkbox, hỗ trợ chọn nhiều, lưu mảng category_ids qua Livewire.
     * - post_type = post  → taxonomy `category`
     * - post_type = product → taxonomy `product_category` (product_cat)
     */
    $publishCategoriesInitial = [
        'selectedIds' => $this->articleCategoryIds,
        'postType' => \App\Addons\SeoContentAi\Models\SeoProjectTask::normalizePostType($articlePostType),
        'options' => $this->getPublishCategoryOptions(),
    ];
@endphp

@once
    <script>
        window.seoPublishCategoriesData = function seoPublishCategoriesData(initial) {
            return {
                selectedIds: Array.isArray(initial.selectedIds) ? initial.selectedIds.map(Number) : [],
                postType: initial.postType ?? 'article',
                optionsByTaxonomy: initial.options ?? { category: [], product_category: [] },
                searchQuery: '',
                // Bật viền đỏ cảnh báo khi user bấm Đồng bộ mà chưa chọn danh mục.
                highlightError: false,
                _saveTimer: null,

                init() {
                    // Guard toàn cục cho nút Đồng bộ (publish-sidebar + phím tắt Ctrl+Shift+S).
                    // Trả về true nếu hợp lệ (đã chọn danh mục hoặc post type không cần danh mục).
                    window.__seoEnsureCategoriesBeforeSync = () => this.ensureBeforeSync();
                },

                // Chỉ bắt buộc danh mục với bài viết / sản phẩm; taxonomy record thì bỏ qua.
                requiresCategories() {
                    return this.postType === 'article' || this.postType === 'product';
                },

                taxonomy() {
                    return this.postType === 'product' ? 'product_category' : 'category';
                },

                taxonomyLabel() {
                    return this.postType === 'product' ? 'Danh mục sản phẩm (product_cat)' : 'Chuyên mục (category)';
                },

                allOptions() {
                    return this.optionsByTaxonomy[this.taxonomy()] ?? [];
                },

                filteredOptions() {
                    const q = this.searchQuery.trim().toLowerCase();
                    if (q === '') {
                        return this.allOptions();
                    }

                    return this.allOptions().filter((opt) => String(opt.label).toLowerCase().includes(q));
                },

                isChecked(id) {
                    return this.selectedIds.includes(Number(id));
                },

                toggle(id) {
                    id = Number(id);
                    this.selectedIds = this.isChecked(id)
                        ? this.selectedIds.filter((v) => v !== id)
                        : [...this.selectedIds, id];

                    if (this.selectedIds.length > 0) {
                        this.highlightError = false;
                    }

                    this.queueSave();
                },

                // Debounce lưu meta category_ids để không spam request khi tick nhiều ô liên tiếp.
                queueSave() {
                    clearTimeout(this._saveTimer);
                    this._saveTimer = setTimeout(() => {
                        this.$wire.applyArticleCategoriesFromClient(this.selectedIds);
                    }, 400);
                },

                onPostTypeChanged(event) {
                    const next = event.detail?.postType;
                    if (typeof next === 'string' && next !== '') {
                        this.postType = next;
                    }
                },

                /**
                 * Validation trước khi Đồng bộ lên WordPress:
                 * - Chưa chọn danh mục → mở Tab Publish, bật viền đỏ, toast cảnh báo, chặn sync.
                 * - Đã chọn (hoặc không áp dụng) → cho phép sync tiếp tục.
                 */
                ensureBeforeSync() {
                    if (!this.requiresCategories() || this.selectedIds.length > 0) {
                        return true;
                    }

                    this.highlightError = true;
                    window.dispatchEvent(new CustomEvent('seo-sidebar-open-publish-tab'));
                    window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Chưa chọn danh mục',
                            body: 'Chọn ít nhất 1 danh mục trong tab Publish trước khi đăng bài lên WordPress.',
                            status: 'warning',
                        },
                    }));

                    return false;
                },
            };
        };
    </script>
@endonce

<div
    class="wp-postbox"
    x-data="seoPublishCategoriesData(@js($publishCategoriesInitial))"
    x-on:seo-publish-post-type-changed.window="onPostTypeChanged($event)"
>
    <div class="wp-postbox-header">
        <h2 x-text="taxonomyLabel()"></h2>
    </div>
    <div class="wp-postbox-inside">
        <template x-if="!requiresCategories()">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Loại bài hiện tại không cần chọn danh mục.
            </p>
        </template>

        <template x-if="requiresCategories()">
            <div class="space-y-2">
                <input
                    type="search"
                    x-model="searchQuery"
                    placeholder="Tìm danh mục..."
                    class="w-full rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-800 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                />

                {{-- Khung danh sách checkbox kiểu WordPress; viền đỏ khi thiếu danh mục lúc đồng bộ --}}
                <div
                    class="max-h-52 space-y-0.5 overflow-y-auto rounded border bg-white p-2 transition-colors dark:bg-gray-900"
                    x-bind:class="highlightError
                        ? 'border-red-500 ring-2 ring-red-300 dark:ring-red-800'
                        : 'border-gray-300 dark:border-gray-600'"
                >
                    <template x-if="allOptions().length === 0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Chưa có danh mục đồng bộ cho tên miền này. Đồng bộ nội dung domain trước.
                        </p>
                    </template>

                    <template x-for="option in filteredOptions()" :key="option.id">
                        <label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-xs text-gray-800 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-800">
                            <input
                                type="checkbox"
                                class="h-3.5 w-3.5 rounded border-gray-300 text-sky-600 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-900"
                                x-bind:checked="isChecked(option.id)"
                                x-on:change="toggle(option.id)"
                            />
                            <span x-text="option.label"></span>
                        </label>
                    </template>

                    <template x-if="allOptions().length > 0 && filteredOptions().length === 0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Không có danh mục khớp từ khóa.</p>
                    </template>
                </div>

                <p class="text-xs" x-bind:class="highlightError ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'">
                    <span x-show="highlightError" x-cloak>Chọn ít nhất 1 danh mục trước khi đăng lên WordPress.</span>
                    <span x-show="!highlightError" x-text="`Đã chọn ${selectedIds.length} danh mục`"></span>
                </p>
            </div>
        </template>
    </div>
</div>
