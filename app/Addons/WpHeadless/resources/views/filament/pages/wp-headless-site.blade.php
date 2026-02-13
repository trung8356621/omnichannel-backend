<x-filament-panels::page>
    @if(!$this->hasWpHeadlessSite())
        <x-filament::section>
            <x-slot name="heading">Chưa có dữ liệu WP Headless</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Site <strong>{{ $site->domain ?? $siteId }}</strong> chưa được đồng bộ với WP Headless. Bấm <strong>Kết nối</strong> để chạy đồng bộ dữ liệu (gọi sync-site-data).
            </p>
            <x-filament::button
                wire:click="syncSiteData"
                wire:loading.attr="disabled"
                icon="heroicon-m-link"
                color="primary"
            >
                <span wire:loading.remove wire:target="syncSiteData">Kết nối</span>
                <span wire:loading wire:target="syncSiteData">Đang đồng bộ...</span>
            </x-filament::button>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Thông tin site WP Headless</x-slot>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Domain</dt>
                    <dd class="mt-1 text-sm font-semibold">{{ $site->domain ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Type</dt>
                    <dd class="mt-1 text-sm font-semibold">{{ $wpHeadlessSite->type ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Public URL</dt>
                    <dd class="mt-1">
                        @if($wpHeadlessSite->public_url ?? null)
                            <a href="{{ $wpHeadlessSite->public_url }}" target="_blank" rel="noopener" class="text-primary-600 dark:text-primary-400 hover:underline">
                                {{ $wpHeadlessSite->public_url }}
                            </a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
            <div class="mt-4">
                <x-filament::button
                    wire:click="syncSiteData"
                    wire:loading.attr="disabled"
                    icon="heroicon-m-arrow-path"
                    color="gray"
                    size="sm"
                >
                    <span wire:loading.remove wire:target="syncSiteData">Đồng bộ lại</span>
                    <span wire:loading wire:target="syncSiteData">Đang đồng bộ...</span>
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
