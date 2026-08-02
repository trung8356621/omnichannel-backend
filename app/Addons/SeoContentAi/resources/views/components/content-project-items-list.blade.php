@props([
    'rows' => [],
    'variant' => 'content_project',
    'hasActiveFilters' => false,
    'showCheckbox' => true,
    'emptyClearWire' => 'clearFilters',
    'useRowVisibility' => null,
])

@php
    $isPublishingQueue = $variant === 'publishing_queue';
    // CP ops has client-side optimistic row exit (isRowVisible() in the page's Alpine
    // root); the Publishing Queue hub does not implement that state. Callers may pass
    // `useRowVisibility` explicitly; otherwise it defaults by variant.
    $useRowVisibility = $useRowVisibility ?? ! $isPublishingQueue;
@endphp

@if (count($rows) === 0)
    <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center dark:border-gray-600 dark:bg-gray-900">
        <x-filament::icon icon="heroicon-o-magnifying-glass" class="mx-auto h-8 w-8 text-gray-400" />
        <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">No items match filters</p>
        @if ($hasActiveFilters)
            <button type="button" wire:click="{{ $emptyClearWire }}" class="mt-3 text-sm font-semibold text-primary-600 hover:underline">Clear filters</button>
        @endif
    </div>
@else
    {{-- Mobile card list --}}
    <div class="cp-ops-mobile-list">
        @foreach ($rows as $row)
            @php $tid = (int) ($row['task_id'] ?? 0); @endphp
            <div
                wire:key="item-m-{{ $tid }}"
                data-ops-row="{{ $tid }}"
                class="cp-ops-mobile-card"
                @if ($useRowVisibility)
                    x-show="isRowVisible({{ $tid }})"
                    x-cloak
                @endif
            >
                <div class="cp-ops-mobile-card__row">
                    <x-seo-content-ai::content-project-item-thumbnail :row="$row" class="mt-0.5" />
                    @if ($showCheckbox)
                        <input
                            type="checkbox"
                            class="mt-1 rounded"
                            value="{{ $tid }}"
                            wire:model.live="selectedTaskIds"
                            aria-label="Select item {{ $tid }}"
                        />
                    @endif
                    <div class="cp-ops-mobile-card__body">
                        <x-seo-content-ai::content-project-item-meta :row="$row" />
                        <div class="cp-ops-mobile-card__badges">
                            @if ($isPublishingQueue)
                                <x-seo-content-ai::content-project-status-badge :badge="$row['publish_badge'] ?? null" />
                            @else
                                <x-seo-content-ai::content-project-status-badge :badge="$row['generation_badge']" />
                                <x-seo-content-ai::content-project-status-badge :badge="$row['workflow_badge'] ?? $row['lifecycle_badge']" />
                            @endif
                        </div>
                        <div class="cp-ops-mobile-card__meta">
                            {{ $row['last_activity'] ?? '' }}
                        </div>
                    </div>
                    @if ($isPublishingQueue)
                        <x-seo-content-ai::publishing-queue-item-actions-menu :row="$row" />
                    @else
                        <x-seo-content-ai::content-project-item-actions-menu :row="$row" />
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Desktop table --}}
    <div class="cp-ops-table-wrap">
        <div class="cp-ops-table-scroll">
            <table class="cp-ops-table">
                <thead>
                    <tr>
                        @if ($showCheckbox)
                            <th class="cp-ops-col-check" scope="col">
                                <span class="sr-only">Select</span>
                            </th>
                        @endif
                        <th class="cp-ops-col-thumb" scope="col">
                            <span class="sr-only">{{ __('seo-content-ai::filament.projects.ops_col_thumbnail') }}</span>
                        </th>
                        <th class="cp-ops-col-item" scope="col">Item</th>
                        @if ($isPublishingQueue)
                            <th class="cp-ops-col-gen" scope="col">Publish state</th>
                            <th class="cp-ops-col-life" scope="col">Schedule</th>
                        @else
                            <th class="cp-ops-col-gen" scope="col">{{ __('seo-content-ai::filament.projects.ops_col_generation') }}</th>
                            <th class="cp-ops-col-life" scope="col">{{ __('seo-content-ai::filament.projects.ops_col_workflow') }}</th>
                        @endif
                        <th class="cp-ops-col-activity" scope="col">Last activity</th>
                        <th class="cp-ops-col-actions" scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $tid = (int) ($row['task_id'] ?? 0); @endphp
                        <tr
                            wire:key="item-{{ $tid }}"
                            data-ops-row="{{ $tid }}"
                            @class(['is-even' => $loop->even])
                            @if ($useRowVisibility)
                                x-show="isRowVisible({{ $tid }})"
                            @endif
                        >
                            @if ($showCheckbox)
                                <td>
                                    <input
                                        type="checkbox"
                                        class="rounded"
                                        value="{{ $tid }}"
                                        wire:model.live="selectedTaskIds"
                                        aria-label="Select item {{ $tid }}"
                                    />
                                </td>
                            @endif
                            <td>
                                <x-seo-content-ai::content-project-item-thumbnail :row="$row" />
                            </td>
                            <td>
                                <x-seo-content-ai::content-project-item-meta :row="$row" />
                            </td>
                            @if ($isPublishingQueue)
                                <td>
                                    <x-seo-content-ai::content-project-status-badge :badge="$row['publish_badge'] ?? null" />
                                    @if (! empty($row['last_publish_error']))
                                        <div class="cp-ops-step" title="{{ $row['last_publish_error'] }}">{{ \Illuminate\Support\Str::limit((string) $row['last_publish_error'], 60) }}</div>
                                    @endif
                                </td>
                                <td class="cp-ops-muted">{{ $row['scheduled_at'] ?? '—' }}</td>
                            @else
                                <td>
                                    <div class="flex flex-col items-start gap-1">
                                        <x-seo-content-ai::content-project-status-badge :badge="$row['generation_badge']" />
                                    </div>
                                    @if (! empty($row['current_step']) && in_array($row['generation_badge']['key'] ?? '', ['running', 'failed'], true))
                                        <div class="cp-ops-step" title="{{ $row['current_step'] }}">{{ $row['current_step'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if (! empty($row['workflow_badge']))
                                        <x-seo-content-ai::content-project-status-badge :badge="$row['workflow_badge']" />
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
                                    @endif
                                </td>
                            @endif
                            <td class="cp-ops-muted" title="{{ $row['last_activity_full'] ?? '' }}">
                                {{ $row['last_activity'] ?? '' }}
                            </td>
                            <td>
                                @if ($isPublishingQueue)
                                    <x-seo-content-ai::publishing-queue-item-actions-menu :row="$row" />
                                @else
                                    <x-seo-content-ai::content-project-item-actions-menu :row="$row" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
