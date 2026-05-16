<x-filament-panels::page>
    @php
        $synced = $this->isSiteSynced();
    @endphp

    @if(! $synced)
        <div
            class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-1 dark:border-amber-500/40 dark:bg-amber-500/10"
        >
        <x-filament::section class="!shadow-none border-0 bg-transparent">
            <x-slot name="heading">
                {{ __('Website này chưa được đồng bộ dữ liệu') }}
            </x-slot>

            <x-slot name="description">
                {{ __('Chưa có bản ghi nào trong kho nội dung SEO cho tên miền này. Hãy chạy đồng bộ từ WordPress.') }}
            </x-slot>

            <x-filament::button
                type="button"
                color="warning"
                icon="heroicon-o-arrow-path"
                wire:click="mountAction('sync_data')"
            >
                {{ __('Đồng bộ dữ liệu') }}
            </x-filament::button>
        </x-filament::section>
        </div>
    @else
        @php
            $stats = $this->getSyncStatistics();
            $scoring = $this->getScoringStatistics();
        @endphp

        <x-filament::section class="mb-6">
            <x-slot name="heading">
                {{ __('Chấm điểm SEO') }}
            </x-slot>

            <x-slot name="description">
                {{ __('Điểm tính sau mỗi lần đồng bộ (Rank Math / Yoast + rule nội bộ).') }}
            </x-slot>

            @if($scoring['scored'] === 0)
                <p class="text-sm text-amber-700 dark:text-amber-300">
                    {{ __('Chưa có bài được chấm điểm. Chạy lại đồng bộ; cần Focus Keyword trên WordPress (Rank Math hoặc Yoast).') }}
                </p>
            @else
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <p>
                        <span class="font-semibold">{{ __('Đã chấm') }}:</span>
                        {{ $scoring['scored'] }} {{ __('bài') }}
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('Điểm TB') }}:</span>
                        {{ $scoring['avg_score'] }}/100
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('Thấp nhất') }}:</span>
                        {{ $scoring['min_score'] }}
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('Cao nhất') }}:</span>
                        {{ $scoring['max_score'] }}
                    </p>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('Thống kê đồng bộ') }}
            </x-slot>

            <x-slot name="description">
                {{ __('Dữ liệu theo trường type trên bảng nội dung SEO.') }}
            </x-slot>

            <div class="grid gap-3 text-sm text-gray-700 dark:text-gray-200 sm:grid-cols-2">
                <p>
                    <span class="font-semibold">{{ __('Bài viết') }}:</span>
                    {{ $stats['articles'] }}
                </p>
                <p>
                    <span class="font-semibold">{{ __('Sản phẩm') }}:</span>
                    {{ $stats['products'] }}
                </p>
                <p>
                    <span class="font-semibold">{{ __('Danh mục') }}:</span>
                    {{ $stats['categories'] }}
                </p>
                <p>
                    <span class="font-semibold">{{ __('Danh mục sản phẩm') }}:</span>
                    {{ $stats['product_categories'] }}
                </p>
                @if($stats['other'] > 0)
                    <p class="sm:col-span-2">
                        <span class="font-semibold">{{ __('Khác') }}:</span>
                        {{ $stats['other'] }}
                    </p>
                @endif
            </div>

            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Đang có :a bài viết, :b sản phẩm (và :c danh mục, :d danh mục SP).', [
                    'a' => $stats['articles'],
                    'b' => $stats['products'],
                    'c' => $stats['categories'],
                    'd' => $stats['product_categories'],
                ]) }}
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
