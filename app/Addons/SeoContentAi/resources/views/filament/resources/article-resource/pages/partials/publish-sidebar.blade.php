@php
    $publishBoxLabels = [
        'postType' => [
            'article' => __('seo-content-ai::filament.article_list.post_type_article'),
            'product' => __('seo-content-ai::filament.article_list.post_type_product'),
            'category' => __('seo-content-ai::filament.article_list.post_type_category'),
            'product_category' => __('seo-content-ai::filament.article_list.post_type_product_category'),
        ],
        'status' => [
            'draft' => 'Draft',
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'private' => 'Private',
        ],
        'visibility' => [
            'public' => 'Public',
            'private' => 'Private',
        ],
    ];
    $publishBoxInitial = [
        'postType' => \App\Addons\SeoContentAi\Models\SeoProjectTask::normalizePostType(
            \App\Addons\SeoContentAi\Support\ArticlePostTypeResolver::resolve($this->record),
        ),
        'status' => $articleStatus,
        'visibility' => $visibility,
        'publishDay' => $publishDay,
        'publishMonth' => $publishMonth,
        'publishYear' => $publishYear,
        'publishHour' => $publishHour,
        'publishMinute' => $publishMinute,
        'publishWhenLabel' => $this->getPublishWhenLabel(),
    ];
    $articleRevisionCount = app(\App\Addons\SeoContentAi\Services\SeoArticleRevisionService::class)
        ->countForArticle((int) $record->getKey());
@endphp

@once
    <script>
        window.seoPublishBoxData = function seoPublishBoxData(initial, labels) {
            const minutePool = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];

            return {
                postType: initial.postType ?? 'article',
                status: initial.status ?? 'draft',
                visibility: initial.visibility ?? 'public',
                publishDay: initial.publishDay ?? '',
                publishMonth: initial.publishMonth ?? '',
                publishYear: initial.publishYear ?? '',
                publishHour: initial.publishHour ?? '',
                publishMinute: initial.publishMinute ?? '',
                publishWhenLabel: initial.publishWhenLabel ?? 'Not scheduled',
                publishIso: '',
                labels,
                editingPostType: false,
                editingStatus: false,
                editingVisibility: false,
                editingPublishAt: false,
                _backup: null,

                init() {
                    this.publishIso = this.buildIso();
                    window.__seoPublishBoxPush = () => this.pushToWire();
                },

                postTypeLabel() {
                    return this.labels.postType[this.postType] ?? this.labels.postType.article;
                },

                statusLabel() {
                    return this.labels.status[this.status] ?? this.labels.status.draft;
                },

                visibilityLabel() {
                    return this.labels.visibility[this.visibility] ?? this.labels.visibility.public;
                },

                saveButtonTitle() {
                    return (this.status === 'scheduled' ? 'Cập nhật lịch' : 'Cập nhật') + ' (Ctrl+S)';
                },

                pad(value) {
                    const n = Number(value || 0);
                    if (Number.isNaN(n)) {
                        return '00';
                    }

                    return String(n).padStart(2, '0');
                },

                buildIso() {
                    const y = String(this.publishYear || '').padStart(4, '0');
                    const m = this.pad(this.publishMonth);
                    const d = this.pad(this.publishDay);
                    const h = this.pad(this.publishHour);
                    const i = this.pad(this.publishMinute);

                    return `${y}-${m}-${d}T${h}:${i}`;
                },

                formatScheduleLabel(date) {
                    const weekdayMap = ['CN', 'Th2', 'Th3', 'Th4', 'Th5', 'Th6', 'Th7'];
                    const weekday = weekdayMap[date.getDay()] ?? 'Th';

                    return `${weekday} ${date.getDate()}, ${date.getFullYear()} at ${this.pad(date.getHours())}:${this.pad(date.getMinutes())}`;
                },

                snapshot() {
                    return {
                        postType: this.postType,
                        status: this.status,
                        visibility: this.visibility,
                        publishDay: this.publishDay,
                        publishMonth: this.publishMonth,
                        publishYear: this.publishYear,
                        publishHour: this.publishHour,
                        publishMinute: this.publishMinute,
                        publishWhenLabel: this.publishWhenLabel,
                    };
                },

                restoreSnapshot(snapshot) {
                    if (!snapshot) {
                        return;
                    }

                    Object.assign(this, snapshot);
                    this.publishIso = this.buildIso();
                },

                beginEdit(field) {
                    this._backup = this.snapshot();
                    this[`editing${field}`] = true;
                },

                cancelEdit(field) {
                    this.restoreSnapshot(this._backup);
                    this._backup = null;
                    this[`editing${field}`] = false;
                },

                applyPostType() {
                    this.editingPostType = false;
                    this._backup = null;
                    window.dispatchEvent(new CustomEvent('seo-publish-post-type-changed', {
                        detail: { postType: this.postType },
                    }));
                },

                applyStatus() {
                    this.visibility = this.status === 'private' ? 'private' : 'public';
                    this.editingStatus = false;
                    this._backup = null;
                },

                applyVisibility() {
                    if (this.visibility === 'private') {
                        this.status = 'private';
                    } else if (this.status === 'private') {
                        this.status = 'draft';
                    }

                    this.editingVisibility = false;
                    this._backup = null;
                },

                randomizePublishAtFuture() {
                    const base = new Date();
                    base.setHours(base.getHours() + Math.floor(Math.random() * 8) + 1);
                    base.setMinutes(minutePool[Math.floor(Math.random() * minutePool.length)]);
                    base.setSeconds(0);
                    base.setMilliseconds(0);

                    this.publishYear = String(base.getFullYear());
                    this.publishMonth = String(base.getMonth() + 1).padStart(2, '0');
                    this.publishDay = String(base.getDate()).padStart(2, '0');
                    this.publishHour = String(base.getHours()).padStart(2, '0');
                    this.publishMinute = String(base.getMinutes()).padStart(2, '0');
                    this.publishIso = this.buildIso();

                    if (this.visibility !== 'private') {
                        this.status = 'scheduled';
                    }
                },

                beginPublishAtEdit() {
                    this.beginEdit('PublishAt');
                    this.randomizePublishAtFuture();
                },

                applyPublishIso() {
                    if (!this.publishIso || !this.publishIso.includes('T')) {
                        return;
                    }

                    const [datePart, timePart] = this.publishIso.split('T');
                    const [y, m, d] = datePart.split('-');
                    const [h, i] = timePart.split(':');
                    this.publishYear = y || this.publishYear;
                    this.publishMonth = m || this.publishMonth;
                    this.publishDay = d || this.publishDay;
                    this.publishHour = h || this.publishHour;
                    this.publishMinute = i || this.publishMinute;
                },

                applyPublishAt() {
                    this.applyPublishIso();

                    const dt = new Date(this.publishIso);
                    if (Number.isNaN(dt.getTime())) {
                        return;
                    }

                    this.publishWhenLabel = this.formatScheduleLabel(dt);

                    if (this.visibility !== 'private') {
                        this.status = dt > new Date() ? 'scheduled' : 'published';
                    }

                    this.editingPublishAt = false;
                    this._backup = null;
                },

                pushToWire() {
                    return this.$wire.applyPublishBoxFromClient(
                        this.postType,
                        this.status,
                        this.visibility,
                        this.publishDay,
                        this.publishMonth,
                        this.publishYear,
                        this.publishHour,
                        this.publishMinute,
                    );
                },

                async requestSave() {
                    if (this.$wire.articleHeavyActionBusy) {
                        return;
                    }

                    window.dispatchEvent(new CustomEvent('article-wordpress-sync-lock', {
                        detail: { action: 'save' },
                    }));

                    try {
                        await this.pushToWire();
                        if (typeof window.__seoPushPublishCategoriesToWire === 'function') {
                            await window.__seoPushPublishCategoriesToWire();
                        }
                        await this.$wire.requestSaveArticle();
                        window.__seoResetPublishTabPrimed?.();
                    } catch (error) {
                        window.dispatchEvent(new CustomEvent('article-wordpress-sync-unlock'));
                    }
                },

                async requestSync() {
                    if (this.$wire.articleHeavyActionBusy) {
                        return;
                    }

                    try {
                        if (typeof window.__seoEnsureCategoriesBeforeSync === 'function') {
                            const allowed = await window.__seoEnsureCategoriesBeforeSync();
                            if (! allowed) {
                                return;
                            }
                        }

                        window.dispatchEvent(new CustomEvent('article-wordpress-sync-lock', {
                            detail: { action: 'sync' },
                        }));

                        await this.pushToWire();
                        if (typeof window.__seoPushPublishCategoriesToWire === 'function') {
                            await window.__seoPushPublishCategoriesToWire();
                        }
                        await this.$wire.requestSyncToWordPress();
                        window.__seoResetPublishTabPrimed?.();
                    } catch (error) {
                        console.warn('Đồng bộ WordPress thất bại ở client', error);
                        window.dispatchEvent(new CustomEvent('article-wordpress-sync-unlock'));
                    }
                },
            };
        };
    </script>
@endonce

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
    <div
        class="wp-postbox-inside wp-publish-box__inside"
        x-data="seoPublishBoxData(@js($publishBoxInitial), @js($publishBoxLabels))"
    >
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
                <span class="text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.article_list.post_type') }}:</span>
                <strong class="text-gray-800 dark:text-gray-100" x-text="postTypeLabel()"></strong>
                <button
                    type="button"
                    x-on:click="beginEdit('PostType')"
                    class="ml-1 text-sky-600 hover:underline"
                >
                    Chỉnh sửa
                </button>
                <div class="mt-2 flex items-center gap-2" x-show="editingPostType" x-cloak>
                    <select
                        x-model="postType"
                        class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1"
                    >
                        <option value="article">{{ __('seo-content-ai::filament.article_list.post_type_article') }}</option>
                        <option value="product">{{ __('seo-content-ai::filament.article_list.post_type_product') }}</option>
                        @if ($this->isTaxonomyArticle())
                            <option value="category">{{ __('seo-content-ai::filament.article_list.post_type_category') }}</option>
                            <option value="product_category">{{ __('seo-content-ai::filament.article_list.post_type_product_category') }}</option>
                        @endif
                    </select>
                    <button type="button" x-on:click="applyPostType()" class="text-sky-600 hover:underline">Đồng ý</button>
                    <button type="button" x-on:click="cancelEdit('PostType')" class="text-sky-600 hover:underline">Hủy</button>
                </div>
            </div>

            <div class="text-xs">
                <span class="text-gray-500 dark:text-gray-400">Trạng thái:</span>
                <strong class="text-gray-800 dark:text-gray-100" x-text="statusLabel()"></strong>
                <button
                    type="button"
                    x-on:click="beginEdit('Status')"
                    class="ml-1 text-sky-600 hover:underline"
                >
                    Chỉnh sửa
                </button>
                <div class="mt-2 flex items-center gap-2" x-show="editingStatus" x-cloak>
                    <select
                        x-model="status"
                        class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1"
                    >
                        <option value="draft">Bản nháp</option>
                        <option value="published">Đã xuất bản</option>
                        <option value="scheduled">Đã lên lịch</option>
                        <option value="private">Riêng tư</option>
                    </select>
                    <button type="button" x-on:click="applyStatus()" class="text-sky-600 hover:underline">Đồng ý</button>
                    <button type="button" x-on:click="cancelEdit('Status')" class="text-sky-600 hover:underline">Hủy</button>
                </div>
            </div>

            <div class="text-xs">
                <span class="text-gray-500 dark:text-gray-400">Hiển thị:</span>
                <strong class="text-gray-800 dark:text-gray-100" x-text="visibilityLabel()"></strong>
                <button
                    type="button"
                    x-on:click="beginEdit('Visibility')"
                    class="ml-1 text-sky-600 hover:underline"
                >
                    Chỉnh sửa
                </button>
                <div class="mt-2 flex items-center gap-2" x-show="editingVisibility" x-cloak>
                    <select
                        x-model="visibility"
                        class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1"
                    >
                        <option value="public">Công khai</option>
                        <option value="private">Riêng tư</option>
                    </select>
                    <button type="button" x-on:click="applyVisibility()" class="text-sky-600 hover:underline">Đồng ý</button>
                    <button type="button" x-on:click="cancelEdit('Visibility')" class="text-sky-600 hover:underline">Hủy</button>
                </div>
            </div>

            <div class="text-xs">
                <span class="text-gray-500 dark:text-gray-400">Bài lên lịch:</span>
                <strong class="text-gray-800 dark:text-gray-100" x-text="publishWhenLabel"></strong>
                <button
                    type="button"
                    x-on:click="beginPublishAtEdit()"
                    class="ml-1 text-sky-600 hover:underline"
                >
                    Chỉnh sửa
                </button>
                <div class="mt-2 space-y-2" x-show="editingPublishAt" x-cloak>
                    <input
                        x-model="publishIso"
                        x-on:change="applyPublishIso()"
                        type="datetime-local"
                        step="60"
                        class="seo-publish-datetime-input rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1.5 px-2"
                    />
                    <div class="flex items-center gap-2">
                        <button type="button" x-on:click="applyPublishAt()" class="text-sky-600 hover:underline">Đồng ý</button>
                        <button type="button" x-on:click="cancelEdit('PublishAt')" class="text-sky-600 hover:underline">Hủy</button>
                    </div>
                </div>
            </div>

            @if (! \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager())
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
            @endif

            <div class="text-xs">
                <a
                    href="{{ route('seo.articles.revisions.compare', ['article' => $record->getKey()]) }}"
                    class="text-sky-600 hover:underline"
                    title="Xem và so sánh các phiên bản đã lưu"
                    target="_blank"
                >
                    📋 Lịch sử chỉnh sửa ({{ $articleRevisionCount }})
                </a>
            </div>
        </div>

        <div class="seo-publish-icon-actions">
            <button
                type="button"
                x-on:click="requestSave()"
                wire:loading.attr="disabled"
                wire:target="requestSaveArticle,requestSyncToWordPress,applyPublishBoxFromClient,persistProductAlbumFromClient,persistArticleLocal,syncArticleToWordPress,saveArticleFaqs,finalizePendingEditorCollect"
                @disabled($articleHeavyActionBusy)
                class="seo-publish-icon-btn is-primary @if ($articleHeavyActionBusy && $articleHeavyAction === 'save') is-busy @endif"
                x-bind:title="saveButtonTitle()"
                x-bind:aria-label="saveButtonTitle()"
                @if ($articleHeavyActionBusy) aria-busy="true" @endif
            >
                @if ($articleHeavyActionBusy && $articleHeavyAction === 'save')
                    <span class="seo-publish-icon-btn__spinner" aria-hidden="true"></span>
                @else
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M17 21v-8H7v8M7 3v5h8" />
                    </svg>
                @endif
            </button>

            @if (! \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager())
                <button
                    type="button"
                    x-on:click="requestSync()"
                    wire:loading.attr="disabled"
                    wire:target="requestSaveArticle,requestSyncToWordPress,applyPublishBoxFromClient,persistProductAlbumFromClient,persistArticleLocal,syncArticleToWordPress,saveArticleFaqs,finalizePendingEditorCollect"
                    @disabled($articleHeavyActionBusy)
                    class="seo-publish-icon-btn @if ($articleHeavyActionBusy && $articleHeavyAction === 'sync') is-busy @endif"
                    title="{{ $record->wp_post_id ? 'Đồng bộ WordPress (Ctrl+Shift+S)' : 'Đăng bài viết mới lên WordPress (Ctrl+Shift+S)' }}"
                    aria-label="{{ $record->wp_post_id ? 'Đồng bộ WordPress (Ctrl+Shift+S)' : 'Đăng bài viết mới lên WordPress (Ctrl+Shift+S)' }}"
                    @if ($articleHeavyActionBusy) aria-busy="true" @endif
                >
                    @if ($articleHeavyActionBusy && $articleHeavyAction === 'sync')
                        <span class="seo-publish-icon-btn__spinner" aria-hidden="true"></span>
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 3v5h5M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 16h5v5" />
                        </svg>
                    @endif
                </button>
            @endif

            @php
                $approvedToggleTitle = \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager()
                    ? 'Đánh dấu project đã duyệt'
                    : __('seo-content-ai::filament.article_list.mark_reviewed');
            @endphp

            <button
                type="button"
                wire:click="toggleArticleReview"
                @if (! $record->is_reviewed)
                    wire:confirm="{{ __('seo-content-ai::filament.article_list.review_article_description') }}"
                @endif
                wire:loading.attr="disabled"
                wire:target="toggleArticleReview,approveArticle"
                @disabled(! $this->canToggleArticleReview())
                class="seo-publish-icon-btn is-success @if ($record->is_reviewed) is-active @endif"
                title="{{ $record->is_reviewed ? __('seo-content-ai::filament.article_list.reviewed') : $approvedToggleTitle }}"
                aria-label="{{ __('seo-content-ai::filament.article_list.reviewed') }}"
            >
                <span wire:loading.remove wire:target="toggleArticleReview,approveArticle">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
                <span wire:loading wire:target="toggleArticleReview,approveArticle" class="seo-publish-icon-btn__spinner" aria-hidden="true"></span>
            </button>

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
