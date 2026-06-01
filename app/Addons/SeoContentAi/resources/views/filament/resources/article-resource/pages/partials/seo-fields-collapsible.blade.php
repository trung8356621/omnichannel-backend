@php($seoTitlePreview = trim($seoTitle !== '' ? $seoTitle : $articleTitle))

<div
    class="wp-seo-fields mt-4 border-t border-gray-200 pt-3 dark:border-gray-700"
    x-data="{ seoFieldsOpen: false, shortcutsOpen: false }"
    x-on:article-editor-toggle-seo-fields.window="seoFieldsOpen = !seoFieldsOpen"
>
    <div class="wp-seo-fields-toolbar">
        <button
            type="button"
            x-on:click="seoFieldsOpen = !seoFieldsOpen"
            class="inline-flex items-center gap-1.5 text-xs font-medium text-sky-600 hover:text-sky-700 hover:underline dark:text-sky-400 dark:hover:text-sky-300"
            x-bind:aria-expanded="seoFieldsOpen"
        >
            <svg
                class="h-3.5 w-3.5 transition-transform"
                x-bind:class="seoFieldsOpen ? 'rotate-90' : ''"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
            <span x-text="seoFieldsOpen ? 'Ẩn mô tả SEO' : 'Chỉnh sửa mô tả SEO'"></span>
        </button>

        <div class="relative">
            <button
                type="button"
                x-on:click="shortcutsOpen = !shortcutsOpen"
                class="article-editor-shortcuts-trigger"
                title="Phím tắt"
                aria-label="Phím tắt"
                x-bind:aria-expanded="shortcutsOpen"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <rect x="2" y="6" width="20" height="12" rx="2" stroke-width="1.75" />
                    <path stroke-linecap="round" stroke-width="1.75" d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8" />
                </svg>
            </button>

            <div
                x-show="shortcutsOpen"
                x-cloak
                x-on:click.outside="shortcutsOpen = false"
                x-on:keydown.escape.window="shortcutsOpen = false"
                class="article-editor-shortcuts-popover"
                role="dialog"
                aria-label="Danh sách phím tắt"
            >
                @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-shortcuts-panel')
            </div>
        </div>
    </div>

    <div x-show="seoFieldsOpen" x-cloak class="mt-3 space-y-3">
        <div>
            <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                <label class="font-medium text-gray-700 dark:text-gray-200">Từ khóa chính</label>
                <span>{{ mb_strlen(trim((string) $focusKeyword)) }} ký tự</span>
            </div>
            <input
                type="text"
                wire:model.live.debounce.300ms="focusKeyword"
                placeholder="Nhập từ khóa chính cho bài viết..."
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900"
            />
        </div>

        <div>
            <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                <label class="font-medium text-gray-700 dark:text-gray-200">Tiêu đề SEO</label>
                <span>{{ mb_strlen($seoTitlePreview) }} / 60</span>
            </div>
            <input
                type="text"
                wire:model.live.debounce.300ms="seoTitle"
                placeholder="%title% %sep% %sitename%"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900"
            />
            <div class="mt-1 h-1.5 w-full overflow-hidden rounded bg-gray-200 dark:bg-gray-700">
                @php($titleRatio = min(100, (int) round((mb_strlen($seoTitlePreview) / 60) * 100)))
                <div
                    class="h-full {{ mb_strlen($seoTitlePreview) > 60 ? 'bg-red-500' : (mb_strlen($seoTitlePreview) >= 45 ? 'bg-green-500' : 'bg-amber-500') }}"
                    style="width: {{ $titleRatio }}%;"
                ></div>
            </div>
        </div>

        <div>
            <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                <label class="font-medium text-gray-700 dark:text-gray-200">Liên kết cố định</label>
                <span>{{ mb_strlen(trim((string) $articleSlug)) }} / 75</span>
            </div>
            <input
                type="text"
                value="{{ trim((string) $articleSlug) }}"
                readonly
                class="w-full rounded border border-gray-300 bg-gray-100 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800"
            />
            <div class="mt-1 h-1.5 w-full overflow-hidden rounded bg-gray-200 dark:bg-gray-700">
                @php($slugLength = mb_strlen(trim((string) $articleSlug)))
                @php($slugRatio = min(100, (int) round(($slugLength / 75) * 100)))
                <div
                    class="h-full {{ $slugLength > 75 ? 'bg-red-500' : ($slugLength >= 35 ? 'bg-green-500' : 'bg-amber-500') }}"
                    style="width: {{ $slugRatio }}%;"
                ></div>
            </div>
        </div>

        <div>
            <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                <label class="font-medium text-gray-700 dark:text-gray-200">Thẻ mô tả</label>
                <span>{{ mb_strlen(trim((string) $seoMetaDescription)) }} / 160</span>
            </div>
            <textarea
                wire:model.live.debounce.300ms="seoMetaDescription"
                rows="3"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900"
                placeholder="Mô tả ngắn để hiển thị trên kết quả tìm kiếm..."
            ></textarea>
            <div class="mt-1 h-1.5 w-full overflow-hidden rounded bg-gray-200 dark:bg-gray-700">
                @php($descLength = mb_strlen(trim((string) $seoMetaDescription)))
                @php($descRatio = min(100, (int) round(($descLength / 160) * 100)))
                <div
                    class="h-full {{ $descLength > 160 ? 'bg-red-500' : ($descLength >= 120 ? 'bg-green-500' : 'bg-amber-500') }}"
                    style="width: {{ $descRatio }}%;"
                ></div>
            </div>
        </div>
    </div>
</div>
