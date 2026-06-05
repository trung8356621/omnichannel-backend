<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_mode') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                    {{ $this->projectRun->isTestMode()
                        ? __('seo-content-ai::filament.projects.run_mode_test')
                        : __('seo-content-ai::filament.projects.run_mode_full') }}
                </p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_total') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ (int) $this->projectRun->total }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_succeeded') }}</p>
                <p class="mt-1 text-lg font-semibold text-success-600 dark:text-success-400">{{ (int) $this->projectRun->succeeded }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_failed_count') }}</p>
                <p class="mt-1 text-lg font-semibold text-danger-600 dark:text-danger-400">{{ (int) $this->projectRun->failed }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_pending_count') }}</p>
                <p class="mt-1 text-lg font-semibold text-warning-600 dark:text-warning-400">{{ $this->getPendingCount() }}</p>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">{{ __('seo-content-ai::filament.projects.run_items_heading') }}</x-slot>
            <x-slot name="description">{{ __('seo-content-ai::filament.projects.run_items_description') }}</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">#</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.article_type') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.article_list.post_type') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.keyword') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_status') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_message') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($this->getAllItems() as $index => $item)
                            @php
                                $taskId = (int) ($item['task_id'] ?? 0);
                                $itemStatus = (string) ($item['status'] ?? '');
                            @endphp
                            <tr
                                class="align-top {{ $itemStatus === 'pending' ? 'bg-warning-50/40 dark:bg-warning-500/5' : '' }}"
                                wire:key="run-row-{{ $taskId > 0 ? $taskId : $index }}"
                            >
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $index + 1 }}</td>
                                <td class="px-3 py-3">
                                    {{ ($item['type'] ?? '') === 'rewrite'
                                        ? __('seo-content-ai::filament.projects.run_type_rewrite')
                                        : __('seo-content-ai::filament.projects.run_type_new') }}
                                </td>
                                <td class="px-3 py-3">{{ $this->postTypeLabel($item['post_type'] ?? null) }}</td>
                                <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">
                                    @if ($editUrl = $this->itemKeywordEditUrl($item))
                                        <a
                                            href="{{ $editUrl }}"
                                            class="text-primary-600 hover:underline dark:text-primary-400"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            {{ $this->itemKeywordLabel($item) }}
                                        </a>
                                    @else
                                        {{ $this->itemKeywordLabel($item) }}
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    @if ($itemStatus === 'success')
                                        <span class="inline-flex rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                            OK
                                        </span>
                                    @elseif ($itemStatus === 'pending')
                                        <span class="inline-flex rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                                            {{ __('seo-content-ai::filament.projects.run_item_pending') }}
                                        </span>
                                    @else
                                        <span class="inline-flex rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                                            {{ __('seo-content-ai::filament.projects.run_item_failed') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">
                                    @if ($itemStatus === 'failed')
                                        <p class="font-medium text-danger-600 dark:text-danger-400">
                                            {{ $this->displayItemError($item) }}
                                        </p>
                                        @if ($this->isDebugMode() && ! empty($item['error_class']))
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ $item['error_class'] }}
                                            </p>
                                        @endif
                                        @if ($this->isDebugMode() && (! empty($item['failed_step']['title']) || ! empty($item['failed_step']['prompt_name'])))
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ __('seo-content-ai::filament.projects.run_failed_step', [
                                                    'step' => trim(implode(' — ', array_filter([
                                                        $item['failed_step']['title'] ?? null,
                                                        $item['failed_step']['prompt_name'] ?? null,
                                                    ]))),
                                                ]) }}
                                            </p>
                                        @endif
                                        @if ($this->isDebugMode() && ! empty($item['error_trace']))
                                            <pre class="mt-2 max-h-48 overflow-auto rounded-md bg-gray-950 p-2 text-xs text-gray-100">{{ $item['error_trace'] }}</pre>
                                        @endif
                                    @elseif ($itemStatus === 'pending')
                                        <span class="text-warning-700 dark:text-warning-400">
                                            {{ __('seo-content-ai::filament.projects.run_item_pending_hint') }}
                                        </span>
                                    @else
                                        {{ $item['message'] ?? '' }}
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    @if ($taskId > 0 && in_array($itemStatus, ['failed', 'pending'], true))
                                        <x-filament::button
                                            size="xs"
                                            color="{{ $itemStatus === 'pending' ? 'success' : 'warning' }}"
                                            wire:click="runItem({{ $taskId }})"
                                            wire:loading.attr="disabled"
                                            wire:target="runItem({{ $taskId }})"
                                        >
                                            <span wire:loading.remove wire:target="runItem({{ $taskId }})">
                                                {{ $itemStatus === 'pending'
                                                    ? __('seo-content-ai::filament.projects.run_run_item')
                                                    : __('seo-content-ai::filament.projects.run_retry_item') }}
                                            </span>
                                            <span wire:loading wire:target="runItem({{ $taskId }})">
                                                {{ __('seo-content-ai::filament.projects.run_retry_running') }}
                                            </span>
                                        </x-filament::button>
                                    @else
                                        —
                                    @endif
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
