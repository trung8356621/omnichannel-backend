<div>
    <x-filament-panels::page>
        @php
            $meta = $overview['metadata'] ?? [];
            $sections = is_array($meta['sections'] ?? null) ? $meta['sections'] : [];
            $latest = $overview['latest'] ?? null;
            $older = $overview['older'] ?? [];
            $pluginSlug = $selectedPluginSlug ?? '';
        @endphp

        <div class="space-y-6">
            <header>
                <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                    {{ __('seo-content-ai::filament.wp_plugin_release.title') }}
                </h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.wp_plugin_release.upload_section_description') }}
                </p>
            </header>

            <x-filament::section
                :heading="__('seo-content-ai::filament.wp_plugin_release.current_release')"
                icon="heroicon-o-information-circle"
                compact
            >
                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.wp_plugin.version') }}
                        </dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                            v{{ $meta['version'] ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.wp_plugin.last_updated') }}
                        </dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                            {{ $meta['last_updated'] ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.wp_plugin.file') }}
                        </dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                            @if ($latest)
                                <code class="text-xs">{{ $latest['filename'] }}</code>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.wp_plugin.size') }}
                        </dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                            {{ $latest['size_label'] ?? '—' }}
                        </dd>
                    </div>
                </dl>

                @if (filled($sections['changelog'] ?? null))
                    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.wp_plugin_release.changelog') }}
                        </p>
                        <pre class="mt-2 whitespace-pre-wrap text-xs text-gray-700 dark:text-gray-300">{{ $sections['changelog'] }}</pre>
                    </div>
                @endif
            </x-filament::section>

            <x-filament-panels::form wire:submit="publish">
                {{ $this->form }}

                <div class="mt-6 flex justify-end">
                    <x-filament::button
                        type="submit"
                        icon="heroicon-o-cloud-arrow-up"
                        wire:loading.attr="disabled"
                        wire:target="publish"
                    >
                        {{ __('seo-content-ai::filament.wp_plugin_release.publish_button') }}
                    </x-filament::button>
                </div>
            </x-filament-panels::form>

            @if (count($older) > 0)
                <x-filament::section
                    :heading="__('seo-content-ai::filament.wp_plugin_release.older_versions')"
                    compact
                >
                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.wp_plugin.version') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.wp_plugin.file') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.wp_plugin.size') }}
                                    </th>
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.wp_plugin.action') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-gray-900">
                                @foreach ($older as $release)
                                    <tr wire:key="release-{{ $release['version'] }}">
                                        <td class="whitespace-nowrap px-3 py-2 text-sm font-medium text-gray-950 dark:text-white">
                                            v{{ $release['version'] }}
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-300">
                                            <code class="text-xs">{{ $release['filename'] }}</code>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-600 dark:text-gray-300">
                                            {{ $release['size_label'] }}
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right">
                                            <div class="inline-flex items-center gap-1">
                                                <x-filament::button
                                                    tag="a"
                                                    size="xs"
                                                    color="gray"
                                                    :href="route('external-plugin.download', ['slug' => $pluginSlug, 'version' => $release['version']])"
                                                    icon="heroicon-o-arrow-down-tray"
                                                >
                                                    {{ __('seo-content-ai::filament.wp_plugin.download') }}
                                                </x-filament::button>
                                                <x-filament::button
                                                    type="button"
                                                    size="xs"
                                                    color="danger"
                                                    icon="heroicon-o-trash"
                                                    wire:click="deleteRelease('{{ $release['version'] }}')"
                                                    wire:confirm="{{ __('seo-content-ai::filament.wp_plugin_release.delete_confirm', ['version' => $release['version']]) }}"
                                                    wire:loading.attr="disabled"
                                                    wire:target="deleteRelease('{{ $release['version'] }}')"
                                                >
                                                    {{ __('seo-content-ai::filament.wp_plugin_release.delete') }}
                                                </x-filament::button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        </div>
    </x-filament-panels::page>
</div>
