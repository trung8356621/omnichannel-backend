@php
    $queueBootstrap = $this->getQueueBootstrapData();
@endphp

<x-filament-panels::page>
    @push('styles')
        @vite('app/Addons/SeoContentAi/resources/css/project-run-queue.css')
    @endpush

    <div
        class="space-y-6"
        x-data="seoProjectRunQueue(@js($queueBootstrap))"
    >
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
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white" data-run-stat="total">{{ (int) $this->projectRun->total }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_succeeded') }}</p>
                <p class="mt-1 text-lg font-semibold text-success-600 dark:text-success-400" data-run-stat="succeeded">{{ (int) $this->projectRun->succeeded }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_failed_count') }}</p>
                <p class="mt-1 text-lg font-semibold text-danger-600 dark:text-danger-400" data-run-stat="failed">{{ (int) $this->projectRun->failed }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_pending_count') }}</p>
                <p class="mt-1 text-lg font-semibold text-warning-600 dark:text-warning-400" data-run-stat="pending">{{ $this->getPendingCount() }}</p>
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
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_date') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($this->getAllItems() as $index => $item)
                            @php
                                $taskId = (int) ($item['task_id'] ?? 0);
                                $itemStatus = (string) ($item['status'] ?? '');
                                $articleId = (int) ($item['article_id'] ?? 0);
                                $isReviewed = (bool) ($item['article_is_reviewed'] ?? false);
                                $canRetry = \App\Addons\SeoContentAi\Support\SeoAccessControl::canRetryProjectRunItem($this->projectRun?->project);
                            @endphp
                            <tr
                                class="align-top {{ in_array($itemStatus, ['pending', 'manual'], true) ? 'bg-warning-50/40 dark:bg-warning-500/5' : '' }}"
                                wire:key="run-row-{{ $taskId > 0 ? $taskId : $index }}"
                                @if ($taskId > 0) data-run-task-id="{{ $taskId }}" @endif
                                data-run-item-status="{{ $itemStatus }}"
                            >
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $index + 1 }}</td>
                                <td class="px-3 py-3">
                                    {{ $this->itemTypeLabel($item) }}
                                </td>
                                <td class="px-3 py-3">{{ $this->postTypeLabel($item['post_type'] ?? null) }}</td>
                                <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">
                                    <div>
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
                                            <span>{{ $this->itemKeywordLabel($item) }}</span>
                                            @if ($itemStatus === 'success' && (int) ($item['article_id'] ?? 0) > 0 && ! (bool) ($item['article_editor_ready'] ?? true))
                                                <p
                                                    class="mt-1 text-xs font-normal text-warning-700 dark:text-warning-400"
                                                    data-run-article-preparing="{{ (int) ($item['article_id'] ?? 0) }}"
                                                >
                                                    {{ $item['article_editor_preparing_message'] ?? __('seo-content-ai::filament.projects.article_editor_preparing_body') }}
                                                </p>
                                            @endif
                                        @endif

                                        @if ($rewriteNotes = $this->itemRewriteNotes($item))
                                            <p class="mt-1 max-w-xl text-xs font-normal leading-5 text-gray-500 dark:text-gray-400">
                                                {{ __('seo-content-ai::filament.projects.rewrite_notes') }}:
                                                {{ $rewriteNotes }}
                                            </p>
                                        @endif

                                        @if (filled($item['loai_san_pham'] ?? null))
                                            <p class="mt-1 max-w-xl text-xs font-normal leading-5 text-gray-500 dark:text-gray-400">
                                                {{ __('seo-content-ai::filament.projects.loai_san_pham') }}:
                                                {{ $item['loai_san_pham'] }}
                                            </p>
                                        @endif

                                        @if (filled($item['gallery_description'] ?? null))
                                            <p class="mt-1 max-w-xl text-xs font-normal leading-5 text-gray-500 dark:text-gray-400">
                                                {{ $item['gallery_description'] }}
                                            </p>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3" data-run-status>
                                    @if ($itemStatus === 'success')
                                        <span class="inline-flex rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                            OK
                                        </span>
                                    @elseif ($itemStatus === 'pending')
                                        <span class="inline-flex rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                                            {{ __('seo-content-ai::filament.projects.run_item_pending') }}
                                        </span>
                                    @elseif ($itemStatus === 'manual')
                                        <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-500/10 dark:text-gray-300">
                                            {{ __('seo-content-ai::filament.projects.run_item_manual') }}
                                        </span>
                                    @else
                                        <span class="inline-flex rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                                            {{ __('seo-content-ai::filament.projects.run_item_failed') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300" data-run-message>
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
                                    @elseif ($itemStatus === 'manual')
                                        {{ $item['message'] ?? __('seo-content-ai::filament.projects.run_item_manual_hint') }}
                                    @else
                                        {{ $item['message'] ?? '' }}
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">
                                    {{ $this->itemRunDate($item) }}
                                </td>
                                <td class="px-3 py-3 seo-run-row-actions" data-run-actions>
                                    @if ($taskId > 0 && $this->itemIsImproveType($item))
                                        @if ($stepsUrl = $this->itemStepsUrl($item))
                                            <x-filament::button
                                                size="xs"
                                                color="gray"
                                                tag="a"
                                                href="{{ $stepsUrl }}"
                                                target="_blank"
                                            >
                                                Xem runs
                                            </x-filament::button>
                                        @else
                                            —
                                        @endif
                                    @elseif ($taskId > 0 && in_array($itemStatus, ['success', 'failed', 'pending'], true))
                                        <div class="flex flex-wrap gap-2">
                                            @if ($stepsUrl = $this->itemStepsUrl($item))
                                                <x-filament::button
                                                    size="xs"
                                                    color="gray"
                                                    tag="a"
                                                    href="{{ $stepsUrl }}"
                                                    target="_blank"
                                                >
                                                    Xem runs
                                                </x-filament::button>
                                            @endif

                                            @if ($canRetry && ! $isReviewed && $itemStatus === 'success')
                                                <x-filament::button
                                                    size="xs"
                                                    color="warning"
                                                    wire:click="runItem({{ $taskId }})"
                                                    wire:confirm="Chạy lại hạng mục đã OK? Thao tác này có thể sử dụng AI API."
                                                    wire:loading.attr="disabled"
                                                    wire:target="runItem({{ $taskId }})"
                                                >
                                                    <span wire:loading.remove wire:target="runItem({{ $taskId }})">
                                                        {{ __('seo-content-ai::filament.projects.run_retry_item') }}
                                                    </span>
                                                    <span wire:loading wire:target="runItem({{ $taskId }})">
                                                        {{ __('seo-content-ai::filament.projects.run_retry_running') }}
                                                    </span>
                                                </x-filament::button>
                                            @elseif ($canRetry && ! $isReviewed)
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
                                            @endif

                                            @if ($itemStatus === 'failed' && $articleId > 0)
                                                <x-filament::button
                                                    size="xs"
                                                    color="success"
                                                    wire:click="markItemFixed({{ $taskId }}, {{ $articleId }})"
                                                    wire:confirm="Xác nhận bài viết đã được sửa lỗi thủ công?"
                                                    wire:loading.attr="disabled"
                                                    wire:target="markItemFixed({{ $taskId }}, {{ $articleId }})"
                                                >
                                                    Đã fix
                                                </x-filament::button>
                                            @endif
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.projects.run_items_empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>

    @push('scripts')
        @vite('app/Addons/SeoContentAi/resources/js/project-run-queue.js')
    @endpush
</x-filament-panels::page>
