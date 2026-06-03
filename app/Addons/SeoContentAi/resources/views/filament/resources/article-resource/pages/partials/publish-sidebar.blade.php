<div class="wp-postbox wp-publish-box">
    <div class="wp-postbox-header">
        <h2>Xuất bản</h2>
        <a
            href="{{ $this->getArticlePreviewUrl() }}"
            target="_blank"
            rel="noopener"
            class="wp-publish-header-preview"
            title="Xem trước bài viết (Ctrl+Shift+P)"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
                <circle cx="12" cy="12" r="3" stroke-width="1.75" />
            </svg>
            <span>Xem trước</span>
        </a>
    </div>
    <div class="wp-postbox-inside wp-publish-box__inside">
        <div class="wp-publish-meta space-y-2">
            @if (\App\Addons\SeoContentAi\Support\SeoAccessControl::canAccessManagerFeatures())
                <div class="text-xs" x-data="{ markdownImportOpen: false, markdownImportDraft: '' }">
                    <span class="text-gray-500 dark:text-gray-400">Markdown import:</span>
                    <button
                        type="button"
                        x-on:click="markdownImportOpen = !markdownImportOpen"
                        class="ml-1 text-sky-600 hover:underline"
                    >
                        <span x-show="!markdownImportOpen">Import nhanh</span>
                        <span x-show="markdownImportOpen" x-cloak>Ẩn</span>
                    </button>
                    <div class="mt-2 space-y-2" x-show="markdownImportOpen" x-cloak>
                            <textarea
                                x-model="markdownImportDraft"
                                rows="8"
                                class="w-full rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-800 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                placeholder="Dán markdown AI vào đây để convert sang HTML editor..."
                            ></textarea>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    x-on:click="$wire.submitMarkdownImportFromSidebar(markdownImportDraft).then(() => { markdownImportDraft = ''; markdownImportOpen = false; })"
                                    wire:loading.attr="disabled"
                                    wire:target="submitMarkdownImportFromSidebar"
                                    class="text-sky-600 hover:underline disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="submitMarkdownImportFromSidebar">Import markdown</span>
                                    <span wire:loading wire:target="submitMarkdownImportFromSidebar">Đang import…</span>
                                </button>
                                <button
                                    type="button"
                                    x-on:click="markdownImportOpen = false"
                                    class="text-gray-500 hover:underline"
                                >
                                    Hủy
                                </button>
                            </div>
                    </div>
                </div>
            @endif

            @if ($record->wp_post_id)
                <div class="text-xs">
                    <span class="text-gray-500 dark:text-gray-400">WP ID:</span>
                    <strong class="text-gray-800 dark:text-gray-100">{{ $record->wp_post_id }}</strong>
                </div>
            @endif
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

            <div class="text-xs">
                <span class="text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.article_list.review') }}:</span>
                <strong class="{{ $record->is_reviewed ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-800 dark:text-gray-100' }}">
                    {{ $this->getReviewStatusLabel() }}
                </strong>
                @if ($this->getReviewedAtLabel())
                    <span class="text-gray-500 dark:text-gray-400">({{ $this->getReviewedAtLabel() }})</span>
                @endif
                @if ($this->getVirtualCommentsCount() > 0)
                    <span class="text-gray-500 dark:text-gray-400">
                        · {{ __('seo-content-ai::filament.article_list.virtual_comments_count', ['count' => $this->getVirtualCommentsCount()]) }}
                    </span>
                @endif
                @if ($this->getVirtualCommentsCount() > 0)
                    <button
                        type="button"
                        wire:click.stop="syncVirtualReviewsToWordPress"
                        wire:loading.attr="disabled"
                        wire:target="syncVirtualReviewsToWordPress"
                        class="ml-1 text-sky-600 hover:underline disabled:opacity-50"
                        title="{{ __('seo-content-ai::filament.article_list.sync_reviews_to_wp_hint') }}"
                    >
                        <span wire:loading.remove wire:target="syncVirtualReviewsToWordPress">
                            {{ __('seo-content-ai::filament.article_list.sync_reviews_to_wp') }}
                        </span>
                        <span wire:loading wire:target="syncVirtualReviewsToWordPress">
                            {{ __('seo-content-ai::filament.article_list.quick_create_reviews_loading') }}
                        </span>
                    </button>
                @elseif ($this->shouldShowQuickCreateReviewsButton())
                    <button
                        type="button"
                        wire:click.stop="generateQuickPostReviews"
                        wire:loading.attr="disabled"
                        wire:target="generateQuickPostReviews"
                        class="ml-1 text-sky-600 hover:underline disabled:opacity-50"
                        title="{{ __('seo-content-ai::filament.article_list.quick_create_reviews_hint') }}"
                    >
                        <span wire:loading.remove wire:target="generateQuickPostReviews">
                            {{ __('seo-content-ai::filament.article_list.quick_create_reviews') }}
                        </span>
                        <span wire:loading wire:target="generateQuickPostReviews">
                            {{ __('seo-content-ai::filament.article_list.quick_create_reviews_loading') }}
                        </span>
                    </button>
                @elseif (! $this->canGenerateQuickPostReviews())
                    <a
                        href="{{ \App\Addons\SeoContentAi\Filament\Pages\SeoSettingsWorkflows::getUrl() }}"
                        class="ml-1 text-sky-600 hover:underline"
                        title="{{ __('seo-content-ai::filament.article_list.quick_create_reviews_configure_hint') }}"
                    >
                        {{ __('seo-content-ai::filament.article_list.quick_create_reviews_configure') }}
                    </a>
                @endif
            </div>
        </div>

        <div class="seo-publish-icon-actions">
            <button
                type="button"
                wire:click="requestSaveArticle"
                wire:loading.attr="disabled"
                wire:target="requestSaveArticle,persistArticleLocal"
                class="seo-publish-icon-btn is-primary"
                title="{{ ($articleStatus === 'scheduled' ? 'Cập nhật lịch' : 'Cập nhật') . ' (Ctrl+S)' }}"
                aria-label="{{ ($articleStatus === 'scheduled' ? 'Cập nhật lịch' : 'Cập nhật') . ' (Ctrl+S)' }}"
            >
                <span wire:loading.remove wire:target="requestSaveArticle,persistArticleLocal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M17 21v-8H7v8M7 3v5h8" />
                    </svg>
                </span>
                <span wire:loading wire:target="requestSaveArticle,persistArticleLocal" class="seo-publish-icon-btn__spinner" aria-hidden="true"></span>
            </button>

            <button
                type="button"
                wire:click="requestSyncToWordPress"
                wire:loading.attr="disabled"
                wire:target="requestSyncToWordPress,syncArticleToWordPress"
                class="seo-publish-icon-btn"
                title="Đồng bộ WordPress (Ctrl+Shift+S)"
                aria-label="Đồng bộ WordPress (Ctrl+Shift+S)"
                @if (! $record->wp_post_id) disabled @endif
            >
                <span wire:loading.remove wire:target="requestSyncToWordPress,syncArticleToWordPress">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 3v5h5M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 16h5v5" />
                    </svg>
                </span>
                <span wire:loading wire:target="requestSyncToWordPress,syncArticleToWordPress" class="seo-publish-icon-btn__spinner" aria-hidden="true"></span>
            </button>

            @if (! $record->is_reviewed)
                <button
                    type="button"
                    wire:click="approveArticle"
                    wire:confirm="{{ __('seo-content-ai::filament.article_list.review_article_description') }}"
                    wire:loading.attr="disabled"
                    wire:target="approveArticle"
                    class="seo-publish-icon-btn is-success"
                    title="{{ __('seo-content-ai::filament.article_list.mark_reviewed') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.mark_reviewed') }}"
                >
                    <span wire:loading.remove wire:target="approveArticle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </span>
                    <span wire:loading wire:target="approveArticle" class="seo-publish-icon-btn__spinner" aria-hidden="true"></span>
                </button>
            @else
                <button
                    type="button"
                    class="seo-publish-icon-btn is-success is-active"
                    disabled
                    title="{{ __('seo-content-ai::filament.article_list.already_reviewed') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.already_reviewed') }}"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </button>
            @endif

            @if ($record->wp_post_id)
                @php($wpPermalink = $this->getArticlePermalink())
                @if ($wpPermalink !== '')
                    <a
                        href="{{ $wpPermalink }}"
                        target="_blank"
                        rel="noopener"
                        class="seo-publish-icon-btn is-outline"
                        title="Xem trên WordPress"
                        aria-label="Xem trên WordPress"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                        </svg>
                    </a>
                @endif
            @endif
        </div>
    </div>
</div>
