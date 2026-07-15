{{-- Keyboard shortcuts — mounted under Outline left rail --}}
<div
    class="seo-editor-shortcuts-rail"
    data-seo-shortcuts-rail
    x-data="{
        open: (() => {
            try {
                const raw = localStorage.getItem('seo.articleEditor.shortcutsOpen');
                if (raw === null) return false;
                return raw === '1';
            } catch (e) {
                return false;
            }
        })(),
        groupIndex: 0,
        groupCount: {{ \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager() ? 2 : 2 }},
        toggle() {
            this.open = !this.open;
            try {
                localStorage.setItem('seo.articleEditor.shortcutsOpen', this.open ? '1' : '0');
            } catch (e) {}
        },
        prevGroup() {
            if (this.groupCount <= 1) return;
            this.groupIndex = (this.groupIndex - 1 + this.groupCount) % this.groupCount;
        },
        nextGroup() {
            if (this.groupCount <= 1) return;
            this.groupIndex = (this.groupIndex + 1) % this.groupCount;
        },
    }"
>
    <div class="seo-editor-shortcuts-rail__header">
        <button
            type="button"
            class="seo-editor-shortcuts-rail__title-btn"
            x-on:click="toggle()"
            x-bind:aria-expanded="open"
            title="{{ __('seo-content-ai::filament.article_list.page_action_shortcuts') }}"
            aria-label="{{ __('seo-content-ai::filament.article_list.page_action_shortcuts') }}"
        >
            <svg
                class="seo-editor-shortcuts-rail__chevron"
                x-bind:class="open ? 'is-open' : ''"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z" clip-rule="evenodd" />
            </svg>
            <span>{{ __('seo-content-ai::filament.article_list.page_action_shortcuts') }}</span>
        </button>

        <div class="seo-editor-shortcuts-rail__nav">
            <button
                type="button"
                class="seo-editor-toolbar-btn"
                title="{{ __('seo-content-ai::filament.article_list.shortcuts_prev_group') }}"
                aria-label="{{ __('seo-content-ai::filament.article_list.shortcuts_prev_group') }}"
                x-on:click.stop="prevGroup()"
                x-bind:disabled="!open || groupCount <= 1"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
            </button>
            <button
                type="button"
                class="seo-editor-toolbar-btn"
                title="{{ __('seo-content-ai::filament.article_list.shortcuts_next_group') }}"
                aria-label="{{ __('seo-content-ai::filament.article_list.shortcuts_next_group') }}"
                x-on:click.stop="nextGroup()"
                x-bind:disabled="!open || groupCount <= 1"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
            </button>
        </div>
    </div>

    <div class="seo-editor-shortcuts-rail__body" x-show="open" x-cloak>
        <div class="article-editor-shortcuts-panel article-editor-shortcuts-panel--rail">
            <div class="article-editor-shortcuts-groups">
                <section class="article-editor-shortcuts-group" x-show="groupIndex === 0" x-cloak>
                    <h5 class="article-editor-shortcuts-group-label">Bài viết</h5>
                    <ul class="article-editor-shortcuts-list">
                        <li>
                            <span class="article-editor-shortcuts-keys">
                                <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>S</kbd>
                            </span>
                            <span class="article-editor-shortcuts-desc">Lưu bài viết</span>
                        </li>
                        @if (! \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager())
                            <li>
                                <span class="article-editor-shortcuts-keys">
                                    <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>S</kbd>
                                </span>
                                <span class="article-editor-shortcuts-desc">Đồng bộ WordPress</span>
                            </li>
                        @endif
                        <li>
                            <span class="article-editor-shortcuts-keys">
                                <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>P</kbd>
                            </span>
                            <span class="article-editor-shortcuts-desc">Xem trước bài viết</span>
                        </li>
                        <li>
                            <span class="article-editor-shortcuts-keys">
                                <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>E</kbd>
                            </span>
                            <span class="article-editor-shortcuts-desc">Mở / ẩn mô tả SEO</span>
                        </li>
                        <li>
                            <span class="article-editor-shortcuts-keys">
                                <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>A</kbd>
                            </span>
                            <span class="article-editor-shortcuts-desc">Phân tích SEO</span>
                        </li>
                    </ul>
                </section>
                <section class="article-editor-shortcuts-group" x-show="groupIndex === 1" x-cloak>
                    <h5 class="article-editor-shortcuts-group-label">Chỉnh sửa nội dung</h5>
                    <ul class="article-editor-shortcuts-list">
                        <li>
                            <span class="article-editor-shortcuts-keys">
                                <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Z</kbd>
                            </span>
                            <span class="article-editor-shortcuts-desc">Hoàn tác</span>
                        </li>
                        <li>
                            <span class="article-editor-shortcuts-keys">
                                <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Y</kbd>
                            </span>
                            <span class="article-editor-shortcuts-desc">Làm lại</span>
                        </li>
                        <li>
                            <span class="article-editor-shortcuts-keys">
                                <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Z</kbd>
                            </span>
                            <span class="article-editor-shortcuts-desc">Làm lại (thay thế)</span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</div>
