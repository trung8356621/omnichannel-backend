<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Content Project Operation Center</h2>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Observability dashboard chạy trên SEO panel (cần connection <code>omi_seo_ai</code>).
        </p>
        <div class="mt-4">
            <x-filament::button tag="a" :href="$this->getSeoOperationsUrl()" color="primary">
                Mở Content Operations (SEO panel)
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
