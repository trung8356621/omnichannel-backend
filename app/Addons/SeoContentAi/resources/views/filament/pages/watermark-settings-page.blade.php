<x-filament-panels::page>
    <div class="space-y-6">
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <label class="text-sm font-semibold text-gray-700 dark:text-gray-300" for="wm-auto-site">
                    Tên miền (domain):
                </label>
                <select
                    id="wm-auto-site"
                    wire:model.live="siteId"
                    class="text-sm rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                >
                    <option value="">-- Chọn tên miền --</option>
                    @foreach ($this->sites as $site)
                        <option value="{{ $site->id }}">{{ $site->domain }}</option>
                    @endforeach
                </select>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                Dùng trang «Thiết kế đóng dấu» để chỉnh canvas kéo thả; trang này quản lý quy tắc tự động khi upload và áp dụng hàng loạt.
            </p>
            @if ($siteId)
                <div class="mt-4 rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-3">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Áp dụng hàng loạt</p>
                    <label class="flex items-start gap-2 text-sm cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model.live="batchApplyWatermark"
                            class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                        />
                        <span>
                            <strong>Watermark</strong> — đóng dấu bản quyền theo thiết kế đã lưu
                        </span>
                    </label>
                    <p class="text-xs text-gray-500 pl-6">
                        Luôn <strong>tối ưu ảnh</strong> (resize, chuyển WebP theo trang «Tối ưu hình ảnh»).
                        @if (! $batchApplyWatermark)
                            Chỉ tối ưu file <strong>không phải .webp</strong>.
                        @else
                            Tối ưu sau khi đóng dấu (file .webp chỉ đóng dấu, không convert lại).
                        @endif
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::button
                            type="button"
                            color="warning"
                            icon="heroicon-o-photo"
                            wire:click="applyBatchToCurrentSite"
                            wire:confirm="Xử lý toàn bộ ảnh nội bộ và WordPress của domain này? Quá trình có thể mất vài phút."
                        >
                            Áp dụng toàn bộ ảnh
                        </x-filament::button>
                        <a
                            href="{{ \App\Addons\SeoContentAi\Filament\Pages\ImageOptimizationSettings::getUrl(['siteId' => $siteId]) }}"
                            class="text-xs text-primary-600 hover:underline"
                        >
                            Cấu hình tối ưu WebP
                        </a>
                    </div>
                </div>
            @endif
        </div>

        <form wire:submit.prevent="save" class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" size="md">
                    Lưu cấu hình
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
