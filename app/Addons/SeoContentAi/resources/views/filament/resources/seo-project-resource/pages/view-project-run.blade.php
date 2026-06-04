<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_mode') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                    {{ $this->run->isTestMode()
                        ? __('seo-content-ai::filament.projects.run_mode_test')
                        : __('seo-content-ai::filament.projects.run_mode_full') }}
                </p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_total') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ (int) $this->run->total }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_succeeded') }}</p>
                <p class="mt-1 text-lg font-semibold text-success-600 dark:text-success-400">{{ (int) $this->run->succeeded }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_failed_count') }}</p>
                <p class="mt-1 text-lg font-semibold text-danger-600 dark:text-danger-400">{{ (int) $this->run->failed }}</p>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">{{ __('seo-content-ai::filament.projects.run_items_heading') }}</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">#</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.article_type') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.article_list.post_type') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.keyword') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_status') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_article') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_message') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($this->getResultItems() as $index => $item)
                            <tr class="align-top">
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $index + 1 }}</td>
                                <td class="px-3 py-3">
                                    {{ ($item['type'] ?? '') === 'rewrite'
                                        ? __('seo-content-ai::filament.projects.run_type_rewrite')
                                        : __('seo-content-ai::filament.projects.run_type_new') }}
                                </td>
                                <td class="px-3 py-3">{{ $this->postTypeLabel($item['post_type'] ?? null) }}</td>
                                <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">
                                    {{ $item['source_content'] ?? '—' }}
                                </td>
                                <td class="px-3 py-3">
                                    @if (($item['status'] ?? '') === 'success')
                                        <span class="inline-flex rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                            OK
                                        </span>
                                    @else
                                        <span class="inline-flex rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                                            {{ __('seo-content-ai::filament.projects.run_item_failed') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    @if (! empty($item['article_edit_url']))
                                        <a
                                            href="{{ $item['article_edit_url'] }}"
                                            class="text-primary-600 hover:underline dark:text-primary-400"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            #{{ (int) ($item['article_id'] ?? 0) }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">
                                    {{ $item['message'] ?? '' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.projects.run_items_empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
