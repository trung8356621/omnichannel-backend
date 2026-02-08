<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($services as $service)
            <x-filament::section>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <x-filament::icon 
                            icon="{{ $service['config']['icon'] ?? 'heroicon-o-cube' }}" 
                            class="w-10 h-10 text-primary-500" 
                        />
                        <div>
                            <h3 class="text-lg font-bold">{{ $service['name'] }}</h3>
                            <span class="text-xs text-gray-400">v{{ $service['config']['version'] ?? '1.0.0' }}</span>
                        </div>
                    </div>
                    <x-filament::badge :color="$service['is_active'] ? 'success' : 'gray'">
                        {{ $service['is_active'] ? 'Đang bật' : 'Đang tắt' }}
                    </x-filament::badge>
                </div>
                <p class="text-sm text-gray-500 mb-6 h-12 line-clamp-2">
                    {{ $service['config']['description'] ?? 'Không có mô tả.' }}
                </p>
                <div class="flex gap-2">
                    <x-filament::button 
                        wire:click="toggleService({{ $service['id'] }})" 
                        :color="$service['is_active'] ? 'danger' : 'success'" 
                        class="flex-1"
                    >
                        {{ $service['is_active'] ? 'Hủy kích hoạt' : 'Kích hoạt' }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>