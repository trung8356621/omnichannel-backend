<x-filament-panels::page>
    @vite('app/Addons/SeoContentAi/resources/css/media-library.css')

    <div class="seo-media-library">
        <div class="seo-media-library-tabs-bar">
            <button
                type="button"
                wire:click="$set('activeTab', 'original')"
                class="seo-media-library-tab {{ $activeTab === 'original' ? 'is-active' : '' }}"
            >
                Gốc (WP)
            </button>
            <button
                type="button"
                wire:click="$set('activeTab', 'local')"
                class="seo-media-library-tab {{ in_array($activeTab, ['local', 'generated'], true) ? 'is-active' : '' }}"
            >
                Nội bộ (Laravel)
            </button>
        </div>

        <div class="seo-media-library-filters-card">
            <div class="seo-media-library-filters">
                <div class="seo-media-library-field">
                    <label class="seo-media-library-label" for="media-library-site">Tên miền</label>
                    <select id="media-library-site" wire:model.live="siteId" class="seo-media-library-select">
                        <option value="">-- Chọn tên miền --</option>
                        @foreach ($this->sites as $site)
                            <option value="{{ $site->id }}">{{ $site->domain }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="seo-media-library-field seo-media-library-field-search">
                    <label class="seo-media-library-label" for="media-library-search">Tìm kiếm</label>
                    <div class="seo-media-library-search-row">
                        <input
                            id="media-library-search"
                            type="search"
                            wire:model.live.debounce.400ms="filterSearch"
                            class="seo-media-library-search"
                            placeholder="{{ $activeTab === 'original' ? 'Slug, alt, caption (WP search)…' : 'Slug, alt, tiêu đề…' }}"
                            autocomplete="off"
                        />
                        @if (filled($filterSearch))
                            <button type="button" wire:click="clearSearchFilter" class="seo-media-library-clear-search">
                                Xóa
                            </button>
                        @endif
                    </div>
                </div>

                <div class="seo-media-library-field">
                    <label class="seo-media-library-label" for="media-library-month">Tháng đăng (tùy chọn)</label>
                    <div class="seo-media-library-month-row">
                        <input
                            id="media-library-month"
                            type="month"
                            wire:model.live="filterMonth"
                            class="seo-media-library-month"
                            placeholder="mm/yyyy"
                        />
                        @if (filled($filterMonth))
                            <button type="button" wire:click="clearMonthFilter" class="seo-media-library-clear-month">
                                Bỏ lọc tháng
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if ($activeTab !== 'watermark-config')
        <div class="seo-media-library-meta" wire:loading.remove wire:target="activeTab,siteId,filterMonth,filterSearch,page,loadImages,previousPage,nextPage,clearMonthFilter,clearSearchFilter,previewApplyWatermark,previewOptimize">
            @if ($total > 0)
                {{ $total }} ảnh · Trang {{ $page }}/{{ $totalPages }}
                @if (filled($filterSearch))
                    · Tìm: “{{ $filterSearch }}”
                @endif
                @if (filled($filterMonth))
                    · Tháng {{ $this->filterMonthLabel() }}
                @else
                    · Tất cả tháng
                @endif
                @if ($activeTab === 'original')
                    · WordPress Media API
                @elseif (in_array($activeTab, ['local', 'generated'], true))
                    · seo_media + seo_generated_images
                @endif
            @elseif ($siteId)
                Chưa có ảnh{{ filled($filterMonth) ? ' trong tháng đã chọn' : '' }}.
            @endif
        </div>

        <div wire:loading wire:target="activeTab,siteId,filterMonth,page,loadImages,previousPage,nextPage,clearMonthFilter,previewApplyWatermark,previewOptimize" class="seo-media-library-meta">
            Đang tải hình ảnh…
        </div>
        @endif

        @if ($loadError)
            <div class="seo-media-library-alert is-error">
                {{ $loadError }}
            </div>
        @elseif (in_array($activeTab, ['local', 'generated'], true))
            <div class="seo-media-library-alert">
                Ảnh upload/dán và đợ Gen AI. Click ảnh để phóng to, đóng dấu hoặc tối ưu (bỏ qua .webp khi chỉ tối ưu).
            </div>
        @else
            <div class="seo-media-library-alert">
                Tab Gốc từ WordPress. Click ảnh để xem, đóng dấu hoặc tối ưu tại chỗ.
            </div>
        @endif

        @if (empty($images) && ! $loadError && $siteId)
            <div class="seo-media-library-empty">
                @if (filled($filterSearch))
                    Không có hình ảnh khớp “{{ $filterSearch }}”.
                @elseif (filled($filterMonth))
                    Không có hình ảnh trong tháng {{ $this->filterMonthLabel() }}.
                @else
                    Không có hình ảnh cho domain này.
                @endif
            </div>
        @elseif (! $siteId)
            <div class="seo-media-library-empty">
                Chọn tên miền để xem thư viện ảnh.
            </div>
        @elseif (! empty($images))
            <div class="seo-media-library-grid">
                @foreach ($images as $image)
                    @php
                        $itemKind = (string) ($image['kind'] ?? 'local');
                        $editKey = $itemKind . '-' . $image['id'];
                        $wpAttachmentId = (int) ($image['wp_attachment_id'] ?? 0);
                        $seoMediaId = (int) ($image['seo_media_id'] ?? 0);
                        $articleId = (int) ($image['article_id'] ?? 0);
                        $articleEditUrl = $image['article_edit_url'] ?? null;
                    @endphp
                    <article class="seo-media-library-card" wire:key="media-{{ $itemKind }}-{{ $image['id'] }}">
                        <button
                            type="button"
                            class="seo-media-library-thumb-wrap seo-media-library-thumb-btn"
                            wire:click="openImagePreview(@js($image))"
                            title="Xem và xử lý ảnh"
                        >
                            <img
                                src="{{ $image['url'] }}"
                                alt="{{ $image['alt'] ?: $image['slug'] }}"
                                class="seo-media-library-thumb"
                                loading="lazy"
                                onerror="this.src='https://placehold.co/400x400?text=No+Image'"
                            />
                        </button>

                        <div class="seo-media-library-body">
                            <div class="seo-media-library-slug-wrap">
                                @if ($editingKey === $editKey)
                                    <div
                                        class="seo-media-library-slug-edit"
                                        x-data
                                        x-on:click.outside="$wire.cancelSlugEdit()"
                                    >
                                        <input
                                            type="text"
                                            wire:model="editingSlug"
                                            wire:keydown.enter="saveSlugEdit"
                                            wire:keydown.escape="cancelSlugEdit"
                                            class="seo-media-library-slug-input"
                                            x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                                        />
                                        <button
                                            type="button"
                                            wire:click="saveSlugEdit"
                                            class="seo-media-library-slug-save-btn"
                                            title="Lưu slug"
                                            aria-label="Lưu slug"
                                        >
                                            <svg
                                                class="seo-media-library-slug-save-icon"
                                                xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path fill-rule="evenodd" d="M14.279 2.152a1.126 1.126 0 0 1 1.516 1.52l.854.845-9.083 9.035-3.719-.47 1.016-3.77.854-.845 9.132-9.115Zm1.72 4.888-1.72-1.704-8.699 8.66-1.016 3.771 3.718-.47 8.717-8.657Z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                    </div>
                                @else
                                    <p
                                        class="seo-media-library-slug is-editable"
                                        title="Double-click để sửa slug"
                                        wire:dblclick="beginSlugEdit(@js($editKey), @js($image['slug'] ?? ''), {{ (int) $image['id'] }}, @js($image['url'] ?? ''), {{ $wpAttachmentId }}, @js($itemKind), {{ $seoMediaId }})"
                                    >
                                        {{ $image['slug'] ?: '—' }}
                                    </p>
                                @endif
                            </div>
                            @if (filled($image['alt'] ?? ''))
                                <p class="seo-media-library-alt" title="{{ $image['alt'] }}">
                                    {{ $image['alt'] }}
                                </p>
                            @endif

                            <div class="seo-media-library-card-footer">
                                @if ($articleId > 0 && filled($articleEditUrl))
                                    <a
                                        href="{{ $articleEditUrl }}"
                                        class="seo-media-library-article-link"
                                        title="Mở bài viết trong editor"
                                    >
                                        Bài viết #{{ $articleId }}
                                    </a>
                                @else
                                    <span class="seo-media-library-article-muted">Chưa gắn bài viết</span>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($totalPages > 1)
                <div class="seo-media-library-pagination">
                    <button
                        type="button"
                        class="seo-media-library-page-btn"
                        wire:click="previousPage"
                        @disabled($page <= 1)
                    >
                        Trang trước
                    </button>
                    <span class="seo-media-library-meta">{{ $page }} / {{ $totalPages }}</span>
                    <button
                        type="button"
                        class="seo-media-library-page-btn"
                        wire:click="nextPage"
                        @disabled($page >= $totalPages)
                    >
                        Trang sau
                    </button>
                </div>
            @endif
        @endif
    </div>

    @if ($previewOpen && $previewImage)
        <div
            class="seo-media-preview-backdrop"
            wire:click="closeImagePreview"
            wire:keydown.escape.window="closeImagePreview"
        >
            <div
                class="seo-media-preview-modal"
                role="dialog"
                aria-modal="true"
                aria-label="Xem ảnh"
                wire:click.stop
            >
                <div class="seo-media-preview-modal__head">
                    <div>
                        <h3 class="seo-media-preview-modal__title">{{ $previewImage['slug'] ?? 'Ảnh' }}</h3>
                        @if (! empty($previewImage['article_id']) && ! empty($previewImage['article_edit_url']))
                            <a
                                href="{{ $previewImage['article_edit_url'] }}"
                                class="seo-media-preview-modal__article"
                                target="_blank"
                                rel="noopener"
                            >
                                Bài viết #{{ $previewImage['article_id'] }} → Editor
                            </a>
                        @endif
                    </div>
                    <button type="button" class="seo-media-preview-modal__close" wire:click="closeImagePreview" aria-label="Đóng">
                        ×
                    </button>
                </div>

                <div class="seo-media-preview-modal__body">
                    <img
                        src="{{ $previewImage['url'] }}"
                        alt=""
                        class="seo-media-preview-modal__img"
                        wire:loading.class="is-loading"
                        wire:target="previewApplyWatermark,previewOptimize,previewRestore"
                    />
                </div>

                @if ($previewMessage)
                    <p class="seo-media-preview-modal__msg is-{{ $previewMessageType ?? 'info' }}">
                        {{ $previewMessage }}
                    </p>
                @endif

                <div class="seo-media-preview-modal__actions">
                    @if ($previewCanRestore)
                        <button
                            type="button"
                            class="seo-media-preview-btn is-restore"
                            wire:click="previewRestore"
                            wire:loading.attr="disabled"
                            wire:target="previewRestore"
                        >
                            <span wire:loading.remove wire:target="previewRestore">Khôi phục ảnh gốc</span>
                            <span wire:loading wire:target="previewRestore">Đang khôi phục…</span>
                        </button>
                    @else
                        <button
                            type="button"
                            class="seo-media-preview-btn is-primary"
                            wire:click="previewApplyWatermark"
                            wire:loading.attr="disabled"
                            wire:target="previewApplyWatermark"
                            @if (($previewImage['kind'] ?? '') === 'generated') disabled @endif
                        >
                            <span wire:loading.remove wire:target="previewApplyWatermark">Áp dụng đóng dấu</span>
                            <span wire:loading wire:target="previewApplyWatermark">Đang xử lý…</span>
                        </button>
                    @endif
                    @if ($previewCanOptimize)
                        <button
                            type="button"
                            class="seo-media-preview-btn"
                            wire:click="previewOptimize"
                            wire:loading.attr="disabled"
                            wire:target="previewOptimize"
                            @if (($previewImage['kind'] ?? '') === 'generated') disabled @endif
                        >
                            <span wire:loading.remove wire:target="previewOptimize">Tối ưu ảnh</span>
                            <span wire:loading wire:target="previewOptimize">Đang tối ưu…</span>
                        </button>
                    @endif
                </div>
                @if ($previewProcessingStatus && $previewProcessingStatus !== 'original')
                    <p class="seo-media-preview-modal__hint">
                        Trạng thái:
                        @if ($previewProcessingStatus === 'watermarked') đã đóng dấu
                        @elseif ($previewProcessingStatus === 'optimized') đã tối ưu
                        @elseif ($previewProcessingStatus === 'restored') đã khôi phục gốc
                        @endif
                    </p>
                @endif
                @if (($previewImage['kind'] ?? '') === 'generated')
                    <p class="seo-media-preview-modal__hint">Ảnh Gen AI: chỉ xem — tải vào thư viện nội bộ để xử lý.</p>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
