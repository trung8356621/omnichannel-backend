<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Trạng thái kết nối -->
        <x-filament::section class="md:col-span-2">
            <x-slot name="heading">Tình trạng kết nối CMS</x-slot>

            <div
                class="flex items-center justify-between p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl">
                <div class="flex items-center gap-3">
                    <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
                    <span class="font-medium text-green-700 dark:text-green-400">Đã kết nối với WP API</span>
                </div>
                <x-filament::button color="gray" size="sm">Kiểm tra lại</x-filament::button>
            </div>

            <div class="mt-6 space-y-4">
                <p class="text-sm text-gray-500">Thông tin đồng bộ gần nhất:</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-3 bg-gray-100 dark:bg-gray-800 rounded-lg">
                        <div class="text-xs text-gray-400">Bài viết</div>
                        <div class="text-xl font-bold">1,240</div>
                    </div>
                    <div class="p-3 bg-gray-100 dark:bg-gray-800 rounded-lg">
                        <div class="text-xs text-gray-400">Danh mục</div>
                        <div class="text-xl font-bold">12</div>
                    </div>
                </div>
            </div>
        </x-filament::section>

        <!-- Thao tác nhanh -->
        <x-filament::section>
            <x-slot name="heading">Thao tác nhanh</x-slot>
            <div class="space-y-3">
                <x-filament::button icon="heroicon-m-arrow-path" class="w-full">
                    Đồng bộ ngay
                </x-filament::button>
                <x-filament::button icon="heroicon-m-globe-alt" color="gray" class="w-full">
                    Xem Web thực tế
                </x-filament::button>
                <hr class="border-gray-200 dark:border-gray-800">
                <x-filament::button icon="heroicon-m-cog-6-tooth" color="danger" variant="outline" class="w-full">
                    Cấu hình API
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>