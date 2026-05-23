<x-filament-panels::page>
    @viteReactRefresh
    @vite('app/Addons/SeoContentAi/resources/js/watermark-editor-page.jsx')

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4 mb-4">
        <div class="flex flex-wrap items-center gap-3">
            <label class="text-sm font-semibold text-gray-700 dark:text-gray-300" for="wm-design-site">
                Tên miền (watermark thuộc domain):
            </label>
            <select
                id="wm-design-site"
                wire:model.live="siteId"
                class="text-sm rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white min-w-[220px]"
            >
                <option value="">-- Chọn tên miền --</option>
                @foreach ($this->sites as $site)
                    <option value="{{ $site->id }}">{{ $site->domain }}</option>
                @endforeach
            </select>
            @if ($siteId)
                <span class="text-xs text-gray-500">
                    Cấu hình thiết kế lưu theo domain ·
                    <a
                        href="{{ \App\Addons\SeoContentAi\Filament\Pages\WatermarkSettingsPage::getUrl(['siteId' => $siteId]) }}"
                        class="text-primary-600 hover:underline"
                    >
                        Cấu hình tự động
                    </a>
                </span>
            @endif
        </div>
        @unless ($siteId)
            <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                Chọn tên miền để tải mẫu thiết kế và lưu watermark đúng domain.
            </p>
        @endunless
    </div>

    <div
        id="seo-watermark-editor-root"
        class="seo-watermark-editor-host"
        data-site-id="{{ $siteId ?? '' }}"
        data-site-domain="{{ $siteId ? ($this->sites->firstWhere('id', (int) $siteId)?->domain ?? '') : '' }}"
        data-image-url="{{ $imageUrl ?? '' }}"
        data-image-id="{{ $imageId ?? '' }}"
        data-back-url="{{ \App\Addons\SeoContentAi\Filament\Pages\WatermarkSettingsPage::getUrl(['siteId' => $siteId]) }}"
        data-initial-config='@json($this->getInitialDesignConfig())'
        data-media-samples='@json($this->getMediaSamples())'
    ></div>
</x-filament-panels::page>
