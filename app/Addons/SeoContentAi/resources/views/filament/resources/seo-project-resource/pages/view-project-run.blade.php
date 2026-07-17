@php
    $queueBootstrap = $this->getQueueBootstrapData();
    $runStats = $this->getRunStatsPayload();
@endphp

<x-filament-panels::page>
    @push('styles')
        @vite('app/Addons/SeoContentAi/resources/css/project-run-queue.css')
        <style>
            .seo-run-items-wrap,
            .seo-run-items-wrap table,
            .seo-run-items-wrap thead,
            .seo-run-items-wrap tbody,
            .seo-run-items-wrap tr,
            .seo-run-items-wrap th,
            .seo-run-items-wrap td {
                overflow: visible !important;
            }
            .seo-run-row-actions {
                overflow: visible !important;
                white-space: nowrap;
                position: relative;
                z-index: 1;
                padding-right: 2.75rem;
            }
            .seo-run-actions-menu {
                position: relative;
                display: inline-block;
            }
            .seo-run-actions-dropdown {
                position: absolute;
                bottom: calc(100% + 0.25rem);
                top: auto;
                right: 0;
                z-index: 60;
                min-width: 11rem;
                overflow: hidden;
                border-radius: 0.5rem;
                background: #fff;
                padding: 0.25rem 0;
                text-align: left;
                box-shadow: 0 10px 15px -3px rgba(15, 23, 42, 0.12), 0 4px 6px -4px rgba(15, 23, 42, 0.08);
                border: 1px solid #e5e7eb;
            }
            .dark .seo-run-actions-dropdown { background: rgb(17 24 39); border-color: rgb(55 65 81); }
            .seo-run-actions-dropdown__item {
                display: block;
                width: 100%;
                padding: 0.5rem 0.75rem;
                text-align: left;
                font-size: 0.875rem;
                line-height: 1.25rem;
                color: #374151;
                white-space: nowrap;
                background: transparent;
                border: 0;
                cursor: pointer;
            }
            .seo-run-actions-dropdown__item:hover { background: #f9fafb; }
            .seo-run-actions-dropdown__item:disabled { opacity: 0.5; cursor: not-allowed; }
            .dark .seo-run-actions-dropdown__item { color: #e5e7eb; }
            .dark .seo-run-actions-dropdown__item:hover { background: rgb(31 41 55); }
            .seo-run-actions-dropdown__item--warning { color: #b45309; }
            .seo-run-actions-dropdown__item--warning:hover { background: #fffbeb; }
            .dark .seo-run-actions-dropdown__item--warning { color: #fbbf24; }
            .dark .seo-run-actions-dropdown__item--warning:hover { background: rgba(245, 158, 11, 0.1); }
            a.seo-run-actions-dropdown__item { text-decoration: none; }
            .seo-run-retry-badge {
                position: absolute;
                top: -0.35rem;
                right: -0.35rem;
                min-width: 1.1rem;
                height: 1.1rem;
                padding: 0 0.25rem;
                border-radius: 9999px;
                background: #2563eb;
                color: #fff;
                font-size: 0.68rem;
                font-weight: 700;
                line-height: 1.1rem;
                text-align: center;
                box-shadow: 0 0 0 2px #fff;
            }
            .dark .seo-run-retry-badge { box-shadow: 0 0 0 2px rgb(17 24 39); }
        </style>
    @endpush

    <div
        class="space-y-6"
        data-seo-run-queue
        x-data="seoProjectRunQueue(@js($queueBootstrap))"
        @seo-run-archive="archiveTaskRow($event.detail)"
        @seo-run-mark-running="markRowRunning($event.detail)"
        @seo-run-start-queue="handleStartQueue($event.detail)"
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
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white" data-run-stat="total">{{ (int) $runStats['total'] }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_succeeded') }}</p>
                <p class="mt-1 text-lg font-semibold text-success-600 dark:text-success-400" data-run-stat="succeeded">{{ (int) $runStats['succeeded'] }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_failed_count') }}</p>
                <p class="mt-1 text-lg font-semibold text-danger-600 dark:text-danger-400" data-run-stat="failed">{{ (int) $runStats['failed'] }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_pending_count') }}</p>
                <p class="mt-1 text-lg font-semibold text-warning-600 dark:text-warning-400" data-run-stat="pending">{{ (int) $runStats['pending'] }}</p>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">
                <div class="flex w-full flex-wrap items-center justify-between gap-3">
                    <span>{{ __('seo-content-ai::filament.projects.run_items_heading') }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($this->canRerunAllItems())
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-primary-500 dark:hover:bg-primary-400"
                                x-show="!$store.seoRunQueue.isRunning"
                                x-on:click="startRerunAllQueue()"
                            >
                                <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" />
                                <span>{{ __('seo-content-ai::filament.projects.run_rerun_all') }}</span>
                            </button>
                        @endif
                        <button
                            type="button"
                            class="seo-run-stop-button inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-60"
                            x-cloak
                            x-show="$store.seoRunQueue.isRunning"
                            x-on:click="$store.seoRunQueue.requestStop()"
                            x-bind:disabled="$store.seoRunQueue.stopRequested"
                        >
                            <x-filament::icon icon="heroicon-o-stop" class="h-4 w-4" />
                            <span x-show="!$store.seoRunQueue.stopRequested">{{ __('seo-content-ai::filament.projects.run_stop') }}</span>
                            <span x-show="$store.seoRunQueue.stopRequested">{{ __('seo-content-ai::filament.projects.run_stopping') }}</span>
                        </button>
                    </div>
                </div>
            </x-slot>
            <x-slot name="description">{{ __('seo-content-ai::filament.projects.run_items_description') }}</x-slot>

            <div class="seo-run-items-wrap overflow-visible">
                <table class="w-full table-fixed text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr>
                            <th class="w-10 px-3 py-2">#</th>
                            <th class="w-36 px-3 py-2">{{ __('seo-content-ai::filament.projects.article_type') }}</th>
                            <th class="w-28 px-3 py-2">{{ __('seo-content-ai::filament.article_list.post_type') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.keyword') }}</th>
                            <th class="w-28 px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_status') }}</th>
                            <th class="w-48 px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_message') }}</th>
                            <th class="w-28 px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_date') }}</th>
                            <th class="w-36 px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_last_run') }}</th>
                            <th class="w-24 px-2 py-2 text-right">{{ __('seo-content-ai::filament.projects.run_item_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($this->getAllItems() as $index => $item)
                            @php
                                $taskId = (int) ($item['task_id'] ?? 0);
                                $itemStatus = (string) ($item['status'] ?? '');
                                $articleId = (int) ($item['article_id'] ?? 0);
                                $retryCount = (int) ($item['retry_count'] ?? 0);
                                $isReviewed = (bool) ($item['article_is_reviewed'] ?? false);
                                $canRetry = \App\Addons\SeoContentAi\Support\SeoAccessControl::canRetryProjectRunItem($this->projectRun?->project);
                            @endphp
                            <tr
                                class="align-top {{ in_array($itemStatus, ['pending', 'manual'], true) ? 'bg-warning-50/40 dark:bg-warning-500/5' : '' }}"
                                wire:key="run-row-{{ $taskId > 0 ? $taskId : $index }}"
                                @if ($taskId > 0)
                                    data-run-task-id="{{ $taskId }}"
                                    x-show="isRowVisible({{ $taskId }})"
                                    x-cloak
                                @endif
                                data-run-item-status="{{ $itemStatus }}"
                            >
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $index + 1 }}</td>
                                <td class="px-3 py-3">
                                    {{ $this->itemTypeLabel($item) }}
                                </td>
                                <td class="px-3 py-3">{{ $this->postTypeLabel($item['post_type'] ?? null) }}</td>
                                <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">
                                    <div class="min-w-0 wrap-break-word">
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
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300 wrap-break-word" data-run-message>
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
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300" data-run-last-run>
                                    {{ $this->itemLastRunAt($item) }}
                                </td>
                                <td class="relative w-24 px-2 py-3 text-right seo-run-row-actions" data-run-actions>
                                    @php
                                        $canArchiveItem = $taskId > 0 && $this->canArchiveRunItem($item);
                                        $stepsUrl = $this->itemStepsUrl($item);
                                        $showRetrySuccess = $canRetry && ! $isReviewed && $itemStatus === 'success'
                                            && ! $this->itemIsImproveType($item);
                                        $showRetryOther = $canRetry && ! $isReviewed
                                            && in_array($itemStatus, ['failed', 'pending'], true)
                                            && ! $this->itemIsImproveType($item);
                                        $showMarkFixed = $itemStatus === 'failed' && $articleId > 0
                                            && ! $this->itemIsImproveType($item);
                                        $hasRowActions = $canArchiveItem || filled($stepsUrl) || $showRetrySuccess || $showRetryOther || $showMarkFixed;
                                    @endphp

                                    @if ($taskId > 0 && $hasRowActions)
                                        <div
                                            class="seo-run-actions-menu relative inline-block text-left"
                                            x-data="{ open: false }"
                                            x-bind:class="open ? 'z-20' : ''"
                                            @keydown.escape.window="open = false"
                                            @scroll.window="open = false"
                                        >
                                            <button
                                                type="button"
                                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-500 ring-1 ring-gray-300 transition hover:bg-gray-50 hover:text-gray-700 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-800"
                                                @click="open = ! open"
                                                :aria-expanded="open"
                                                title="{{ __('seo-content-ai::filament.projects.more_actions') }}"
                                            >
                                                <x-filament::icon icon="heroicon-m-ellipsis-vertical" class="h-4 w-4" />
                                                <span
                                                    class="seo-run-retry-badge"
                                                    data-run-retry-badge
                                                    @if ($retryCount > 0)
                                                        title="{{ __('seo-content-ai::filament.projects.run_item_rerun_badge_tooltip', ['count' => $retryCount]) }}"
                                                    @else
                                                        style="display: none;"
                                                    @endif
                                                >{{ $retryCount }}</span>
                                            </button>

                                            <div
                                                x-show="open"
                                                x-cloak
                                                x-transition
                                                class="seo-run-actions-dropdown"
                                                @click.outside="open = false"
                                            >
                                                @if ($canArchiveItem)
                                                    <button
                                                        type="button"
                                                        class="seo-run-actions-dropdown__item seo-run-actions-dropdown__item--warning"
                                                        @click="
                                                            open = false;
                                                            const root = $el.closest('[data-seo-run-queue]');
                                                            const queue = root ? Alpine.$data(root) : null;
                                                            if (queue && typeof queue.archiveTaskRow === 'function') {
                                                                queue.archiveTaskRow({{ $taskId }});
                                                            }
                                                        "
                                                    >
                                                        {{ __('seo-content-ai::filament.projects.archive_item') }}
                                                    </button>
                                                @endif

                                                @if ($stepsUrl)
                                                    <a
                                                        href="{{ $stepsUrl }}"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="seo-run-actions-dropdown__item"
                                                        @click="open = false"
                                                    >
                                                        {{ __('seo-content-ai::filament.projects.run_view_steps') }}
                                                    </a>
                                                @endif

                                                @if ($showRetrySuccess)
                                                    <button
                                                        type="button"
                                                        class="seo-run-actions-dropdown__item"
                                                        @click="
                                                            open = false;
                                                            const root = $el.closest('[data-seo-run-queue]');
                                                            const queue = root ? Alpine.$data(root) : null;
                                                            if (! queue || typeof queue.runSingleTask !== 'function') {
                                                                window.alert('Queue UI chưa sẵn sàng — Ctrl+F5 rồi thử lại.');
                                                                return;
                                                            }
                                                            queue.runSingleTask({{ $taskId }}, {
                                                                confirm: @js(__('seo-content-ai::filament.projects.run_retry_item_confirm')),
                                                            });
                                                        "
                                                    >
                                                        {{ __('seo-content-ai::filament.projects.run_retry_item') }}
                                                    </button>
                                                @elseif ($showRetryOther)
                                                    <button
                                                        type="button"
                                                        class="seo-run-actions-dropdown__item"
                                                        @click="
                                                            open = false;
                                                            const root = $el.closest('[data-seo-run-queue]');
                                                            const queue = root ? Alpine.$data(root) : null;
                                                            if (! queue || typeof queue.runSingleTask !== 'function') {
                                                                window.alert('Queue UI chưa sẵn sàng — Ctrl+F5 rồi thử lại.');
                                                                return;
                                                            }
                                                            queue.runSingleTask({{ $taskId }});
                                                        "
                                                    >
                                                        {{ $itemStatus === 'pending'
                                                            ? __('seo-content-ai::filament.projects.run_run_item')
                                                            : __('seo-content-ai::filament.projects.run_retry_item') }}
                                                    </button>
                                                @endif

                                                @if ($showMarkFixed)
                                                    <button
                                                        type="button"
                                                        class="seo-run-actions-dropdown__item"
                                                        wire:click="markItemFixed({{ $taskId }}, {{ $articleId }})"
                                                        wire:confirm="Xác nhận bài viết đã được sửa lỗi thủ công?"
                                                        wire:loading.attr="disabled"
                                                        wire:target="markItemFixed({{ $taskId }}, {{ $articleId }})"
                                                        @click="open = false"
                                                    >
                                                        {{ __('seo-content-ai::filament.projects.run_mark_fixed') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
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
