<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Agent Workspace</h2>
        @if ($this->getSeoAgentUrl())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Agent Workspace chạy trên SEO panel (cần connection <code>omi_seo_ai</code>).
            </p>
            <div class="mt-4">
                <x-filament::button tag="a" :href="$this->getSeoAgentUrl()" color="primary">
                    Mở Agent Workspace (SEO panel)
                </x-filament::button>
            </div>
        @else
            <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                {{ $this->getMissingSiteMessage() }}
            </p>
            <div class="mt-4">
                <x-filament::button tag="a" href="{{ url('/seo') }}" color="gray">
                    Chọn website / mở SEO panel
                </x-filament::button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
