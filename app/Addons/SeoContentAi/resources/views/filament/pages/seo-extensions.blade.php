<x-filament-panels::page>
    <div
        x-data="{ detailsOpen: false }"
        class="space-y-4"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('seo-content-ai::filament.extensions.subtitle') }}
            </p>
            <button
                type="button"
                wire:click="refreshHealth"
                wire:loading.attr="disabled"
                wire:target="refreshHealth"
                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
            >
                <svg wire:loading wire:target="refreshHealth" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <span wire:loading.remove wire:target="refreshHealth">{{ __('seo-content-ai::filament.extensions.refresh_health') }}</span>
                <span wire:loading wire:target="refreshHealth">{{ __('seo-content-ai::filament.common.saving') }}</span>
            </button>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('seo-content-ai::filament.extensions.col_id') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('seo-content-ai::filament.extensions.col_name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('seo-content-ai::filament.extensions.col_version') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('seo-content-ai::filament.extensions.col_sdk') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('seo-content-ai::filament.extensions.col_status') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('seo-content-ai::filament.extensions.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-950">
                    @forelse ($rows as $row)
                        <tr wire:key="extension-row-{{ $row['id'] }}">
                            <td class="px-4 py-3 text-sm font-mono text-gray-900 dark:text-gray-100">{{ $row['id'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ $row['name'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $row['version'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $row['sdk'] }}</td>
                            <td class="px-4 py-3 text-sm">
                                @php
                                    $status = (string) ($row['status'] ?? 'healthy');
                                    $badge = match ($status) {
                                        'healthy' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
                                        'error' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
                                        'disabled' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                                        'needs_update' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                                        default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                                    };
                                @endphp
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }}">
                                    {{ __('seo-content-ai::filament.extensions.status_'.$status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-sm">
                                <div class="inline-flex items-center gap-2">
                                    @if ($row['enabled'])
                                        <button
                                            type="button"
                                            wire:click="disableExtension('{{ $row['id'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="disableExtension"
                                            class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-200"
                                        >
                                            <span wire:loading.remove wire:target="disableExtension">{{ __('seo-content-ai::filament.extensions.disable') }}</span>
                                            <span wire:loading wire:target="disableExtension">{{ __('seo-content-ai::filament.common.saving') }}</span>
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="enableExtension('{{ $row['id'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="enableExtension"
                                            class="rounded-lg bg-primary-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-primary-500 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="enableExtension">{{ __('seo-content-ai::filament.extensions.enable') }}</span>
                                            <span wire:loading wire:target="enableExtension">{{ __('seo-content-ai::filament.common.saving') }}</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.extensions.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <button
            type="button"
            @click="detailsOpen = !detailsOpen"
            class="text-xs text-gray-500 underline dark:text-gray-400"
        >
            <span x-show="!detailsOpen">{{ __('seo-content-ai::filament.extensions.show_details') }}</span>
            <span x-show="detailsOpen" x-cloak>{{ __('seo-content-ai::filament.extensions.hide_details') }}</span>
        </button>

        <div x-show="detailsOpen" x-cloak class="rounded-lg border border-dashed border-gray-300 p-4 text-xs text-gray-600 dark:border-gray-600 dark:text-gray-400">
            {{ __('seo-content-ai::filament.extensions.details_hint') }}
        </div>
    </div>
</x-filament-panels::page>
