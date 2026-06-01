@php
    $meta = $metadata ?? [];
    $sections = is_array($meta['sections'] ?? null) ? $meta['sections'] : [];
    $description = (string) ($sections['description'] ?? '');
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('seo-content-ai::filament.wp_plugin.heading')"
        :description="__('seo-content-ai::filament.wp_plugin.description')"
        icon="heroicon-o-puzzle-piece"
    >
        <div class="space-y-4">
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.wp_plugin.name') }}
                    </dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $meta['name'] ?? 'omi-seo-ai-bridge' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.wp_plugin.version') }}
                    </dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                        @if ($latest)
                            <span class="inline-flex items-center rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/30">
                                v{{ $latest['version'] }}
                            </span>
                        @else
                            <span class="text-gray-500 dark:text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.wp_plugin.requires') }}
                    </dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        WP {{ $meta['requires'] ?? '—' }}
                        @if (filled($meta['tested'] ?? null))
                            · {{ __('seo-content-ai::filament.wp_plugin.tested') }} {{ $meta['tested'] }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.wp_plugin.php') }}
                    </dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $meta['requires_php'] ?? '—' }}
                    </dd>
                </div>
            </dl>

            @if ($description !== '')
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $description }}
                </p>
            @endif

            @if (filled($meta['last_updated'] ?? null))
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.wp_plugin.last_updated') }}: {{ $meta['last_updated'] }}
                </p>
            @endif

            @if ($has_packages && $latest)
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button
                        tag="a"
                        :href="route('seo.wp-plugin.download', ['version' => $latest['version']])"
                        icon="heroicon-o-arrow-down-tray"
                        color="primary"
                    >
                        {{ __('seo-content-ai::filament.wp_plugin.download_latest', ['version' => $latest['version']]) }}
                    </x-filament::button>

                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $latest['size_label'] }}
                        @if (filled($latest['modified_at'] ?? null))
                            · {{ $latest['modified_at'] }}
                        @endif
                    </span>

                    @if (count($older) > 0)
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            :icon="$showOlderVersions ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down'"
                            wire:click="$toggle('showOlderVersions')"
                        >
                            {{ $showOlderVersions
                                ? __('seo-content-ai::filament.wp_plugin.hide_older')
                                : trans_choice('seo-content-ai::filament.wp_plugin.show_older', count($older), ['count' => count($older)]) }}
                        </x-filament::button>
                    @endif
                </div>

                @if ($showOlderVersions && count($older) > 0)
                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.wp_plugin.version') }}
                                    </th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.wp_plugin.file') }}
                                    </th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.wp_plugin.size') }}
                                    </th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.wp_plugin.action') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-gray-900">
                                @foreach ($older as $release)
                                    <tr wire:key="wp-plugin-{{ $release['version'] }}">
                                        <td class="whitespace-nowrap px-4 py-2 text-sm font-medium text-gray-950 dark:text-white">
                                            v{{ $release['version'] }}
                                        </td>
                                        <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">
                                            <code class="text-xs">{{ $release['filename'] }}</code>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-sm text-gray-600 dark:text-gray-300">
                                            {{ $release['size_label'] }}
                                            @if (filled($release['modified_at'] ?? null))
                                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $release['modified_at'] }}</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right">
                                            <x-filament::button
                                                tag="a"
                                                size="sm"
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
                <x-filament::section
                    compact
                    class="border-dashed"
                >
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('seo-content-ai::filament.wp_plugin.no_packages') }}
                    </p>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.wp_plugin.no_packages_hint') }}
                    </p>
                </x-filament::section>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
