@php
    $meta = $metadata ?? [];
    $sections = is_array($meta['sections'] ?? null) ? $meta['sections'] : [];
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('seo-content-ai::filament.wp_plugin.heading')"
        :description="__('seo-content-ai::filament.dashboard.wp_plugin_compact_desc')"
        icon="heroicon-o-puzzle-piece"
        compact
    >
        @if ($has_packages && $latest)
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <dl class="grid min-w-0 flex-1 grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4">
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.wp_plugin.version') }}
                        </dt>
                        <dd class="mt-0.5 text-sm font-semibold text-gray-950 dark:text-white">v{{ $latest['version'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.wp_plugin.requires') }}
                        </dt>
                        <dd class="mt-0.5 text-sm text-gray-950 dark:text-white">WP {{ $meta['requires'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.wp_plugin.php') }}
                        </dt>
                        <dd class="mt-0.5 text-sm text-gray-950 dark:text-white">{{ $meta['requires_php'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.wp_plugin.size') }}
                        </dt>
                        <dd class="mt-0.5 text-sm text-gray-950 dark:text-white">{{ $latest['size_label'] ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <x-filament::button
                        tag="a"
                        size="sm"
                        color="primary"
                        :href="route('seo.wp-plugin.download', ['version' => $latest['version']])"
                        icon="heroicon-o-arrow-down-tray"
                    >
                        {{ __('seo-content-ai::filament.wp_plugin.download_latest', ['version' => $latest['version']]) }}
                    </x-filament::button>

                    @if (count($older) > 0)
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="gray"
                            :icon="$showOlderVersions ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down'"
                            wire:click="$toggle('showOlderVersions')"
                        >
                            {{ $showOlderVersions
                                ? __('seo-content-ai::filament.wp_plugin.hide_older')
                                : trans_choice('seo-content-ai::filament.wp_plugin.show_older', count($older), ['count' => count($older)]) }}
                        </x-filament::button>
                    @endif

                    <x-filament::button
                        tag="a"
                        size="sm"
                        color="gray"
                        :href="\App\Addons\SeoContentAi\Support\SeoConnectionContext::panelUrl('settings/wp-plugin-release')"
                        icon="heroicon-o-cog-6-tooth"
                    >
                        {{ __('seo-content-ai::filament.wp_plugin.manage_releases') }}
                    </x-filament::button>
                </div>
            </div>

            @if ($showOlderVersions && count($older) > 0)
                <div class="mt-3 overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-3 py-1.5 text-left text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.wp_plugin.version') }}
                                </th>
                                <th class="px-3 py-1.5 text-right text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.wp_plugin.action') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-gray-900">
                            @foreach ($older as $release)
                                <tr wire:key="dash-wp-plugin-{{ $release['version'] }}">
                                    <td class="whitespace-nowrap px-3 py-1.5 text-sm font-medium text-gray-950 dark:text-white">
                                        v{{ $release['version'] }}
                                        <span class="block text-[11px] font-normal text-gray-500 dark:text-gray-400">{{ $release['size_label'] ?? '' }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-1.5 text-right">
                                        <x-filament::button
                                            tag="a"
                                            size="xs"
                                            color="gray"
                                            :href="route('seo.wp-plugin.download', ['version' => $release['version']])"
                                            icon="heroicon-o-arrow-down-tray"
                                        >
                                            {{ __('seo-content-ai::filament.wp_plugin.download') }}
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('seo-content-ai::filament.wp_plugin.no_packages') }}
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
