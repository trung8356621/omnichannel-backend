<x-filament-panels::page>
    <div wire:poll.10s="refreshCounters" class="space-y-6">
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Automation operations</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Executions monitor — failed, stale, dead letter</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-filament::button
                        type="button"
                        color="gray"
                        icon="heroicon-o-arrow-path"
                        wire:click="refreshCounters"
                        wire:loading.attr="disabled"
                        wire:target="refreshCounters"
                    >
                        Refresh
                    </x-filament::button>
                    @if (\App\Addons\SeoContentAi\Support\SeoAccessControl::canRetryAutomationExecution())
                        <x-filament::button
                            type="button"
                            color="warning"
                            icon="heroicon-o-wrench"
                            wire:click="recoverStale"
                            wire:loading.attr="disabled"
                            wire:target="recoverStale"
                        >
                            Recover stale
                        </x-filament::button>
                    @endif
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'all' => 'All',
                    'failed' => 'Failed',
                    'stale' => 'Stale',
                    'dead_letter' => 'Dead letter',
                    'partial' => 'Partial',
                    'processing' => 'Processing',
                    'cancelled' => 'Cancelled',
                ] as $key => $label)
                    <button
                        type="button"
                        wire:click="setFilter('{{ $key }}')"
                        class="rounded-lg border px-4 py-3 text-left transition {{ $filter === $key ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/30' : 'border-gray-200 dark:border-gray-700' }}"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $counters[$key] ?? 0 }}</p>
                    </button>
                @endforeach
            </div>
        </section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
