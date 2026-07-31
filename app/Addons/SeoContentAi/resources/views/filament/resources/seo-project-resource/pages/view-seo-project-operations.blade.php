@php
    /** @var \App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject $this */
    $payload = $this->operationsPayload;
    $stats = $payload['stats'] ?? [];
    $rows = $payload['rows'] ?? [];
    $paginator = $payload['paginator'] ?? null;
    $project = $this->project;
    $totalItems = (int) ($stats['total_items'] ?? 0);
    $activeCard = $this->activeSummaryCard;
    $selectedCount = $this->selectedCount;
    $hasActiveFilters = $this->hasActiveFilters;
@endphp

<x-filament-panels::page>
    <div
        class="space-y-4"
        x-data="{ detailsOpen: @entangle('executionDetailsOpen'), autoOpen: false }"
        x-on:open-auto-schedule.window="autoOpen = true"
    >
        @if ($this->settingsOpen)
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-gray-600 dark:text-gray-300">{{ $project?->description ?: '—' }}</p>
                @if ($payload['last_execution_at'] ?? null)
                    <p class="mt-2 text-xs text-gray-500">
                        Last execution: {{ $payload['last_execution_at'] }}
                        @if ($payload['last_execution_status'] ?? null)
                            · {{ $payload['last_execution_status'] }}
                        @endif
                    </p>
                @endif
            </div>
        @endif

        {{-- KPI grid — scoped CSS (Tailwind grid-cols purged in Filament build) --}}
        <div
            class="cp-ops-kpi-grid"
            role="group"
            aria-label="Project summary"
            wire:loading.class="opacity-60"
            wire:target="applySummaryFilter,clearFilters,search,generationFilter,lifecycleFilter,queueFilter,scheduledFilter,failedOnly"
        >
            @foreach ([
                ['key' => 'total_items', 'card' => 'total', 'label' => __('seo-content-ai::filament.projects.ops_total')],
                ['key' => 'pending', 'card' => 'pending', 'label' => __('seo-content-ai::filament.projects.ops_pending')],
                ['key' => 'running', 'card' => 'running', 'label' => __('seo-content-ai::filament.projects.ops_running')],
                ['key' => 'failed', 'card' => 'failed', 'label' => __('seo-content-ai::filament.projects.ops_failed')],
                ['key' => 'waiting_review', 'card' => 'review', 'label' => __('seo-content-ai::filament.projects.ops_in_review')],
                ['key' => 'approved', 'card' => 'approved', 'label' => __('seo-content-ai::filament.projects.ops_approved')],
                ['key' => 'waiting_publish', 'card' => 'scheduled', 'label' => __('seo-content-ai::filament.projects.ops_scheduled')],
                ['key' => 'published', 'card' => 'published', 'label' => __('seo-content-ai::filament.projects.ops_published_only')],
            ] as $card)
                <x-seo-content-ai::content-project-summary-card
                    :card="$card['card']"
                    :label="$card['label']"
                    :value="(int) ($stats[$card['key']] ?? 0)"
                    :active="$activeCard === $card['card']"
                    wire:click="applySummaryFilter('{{ $card['card'] }}')"
                />
            @endforeach
        </div>

        {{-- Filter toolbar --}}
        <div @class(['opacity-50 pointer-events-none' => $this->bulkRunning])>
            <x-seo-content-ai::content-project-filter-toolbar />
            <x-seo-content-ai::content-project-bulk-selection-toolbar :selected-count="$selectedCount" />
        </div>

        {{-- Auto schedule modal — Alpine first --}}
        <div
            x-show="autoOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @keydown.escape.window="autoOpen = false"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" @click.outside="autoOpen = false">
                <h3 class="mb-3 text-base font-semibold">{{ __('seo-content-ai::filament.projects.auto_schedule') }}</h3>
                <div class="space-y-3 text-sm">
                    <label class="block">
                        <span class="text-xs text-gray-500">Mode</span>
                        <x-select wire:model="autoMode" class="mt-1 !w-full">
                            <option value="interval">interval</option>
                            <option value="daily">daily</option>
                        </x-select>
                    </label>
                    <label class="block">
                        <span class="text-xs text-gray-500">Start at</span>
                        <input type="datetime-local" wire:model="autoStartAt" class="fi-input mt-1 block w-full rounded-lg text-sm" />
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="text-xs text-gray-500">Interval (min)</span>
                            <input type="number" wire:model="autoIntervalMinutes" class="fi-input mt-1 block w-full rounded-lg text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs text-gray-500">Per day</span>
                            <input type="number" wire:model="autoPerDay" class="fi-input mt-1 block w-full rounded-lg text-sm" />
                        </label>
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="autoOpen = false" class="fi-btn fi-btn-color-gray fi-size-sm">{{ __('seo-content-ai::filament.projects.archive_cancel') }}</button>
                    <button type="button" wire:click="runAutoSchedule" @click="autoOpen = false" class="fi-btn fi-btn-color-primary fi-size-sm">
                        {{ __('seo-content-ai::filament.projects.auto_schedule_submit') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Loading skeleton --}}
        <div wire:loading.delay.shortest wire:target="applySummaryFilter,clearFilters,search,generationFilter,lifecycleFilter,queueFilter,scheduledFilter,failedOnly,gotoPage,previousPage,nextPage" class="space-y-2">
            @foreach (range(1, 4) as $_)
                <div class="h-14 animate-pulse rounded-lg bg-gray-100 dark:bg-gray-800"></div>
            @endforeach
        </div>

        {{-- Canonical table (desktop) + cards (mobile) --}}
        <div wire:loading.remove.delay.shortest wire:target="applySummaryFilter,clearFilters,search,generationFilter,lifecycleFilter,queueFilter,scheduledFilter,failedOnly">
            @if ($totalItems === 0)
                <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center dark:border-gray-600 dark:bg-gray-900">
                    <x-filament::icon icon="heroicon-o-inbox" class="mx-auto h-8 w-8 text-gray-400" />
                    <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('seo-content-ai::filament.projects.item_empty') }}</p>
                    <p class="mt-1 text-xs text-gray-500">Add items in Edit project, then Generate pending items.</p>
                </div>
            @elseif (count($rows) === 0)
                <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center dark:border-gray-600 dark:bg-gray-900">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="mx-auto h-8 w-8 text-gray-400" />
                    <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">No items match filters</p>
                    @if ($hasActiveFilters)
                        <button type="button" wire:click="clearFilters" class="mt-3 text-sm font-semibold text-primary-600 hover:underline">Clear filters</button>
                    @endif
                </div>
            @else
                {{-- Mobile card list --}}
                <div class="cp-ops-mobile-list">
                    @foreach ($rows as $row)
                        @php $tid = (int) $row['task_id']; @endphp
                        <div wire:key="item-m-{{ $tid }}" class="cp-ops-mobile-card">
                            <div class="cp-ops-mobile-card__row">
                                <input
                                    type="checkbox"
                                    class="mt-1 rounded"
                                    value="{{ $tid }}"
                                    wire:model.live="selectedTaskIds"
                                    aria-label="Select item {{ $tid }}"
                                />
                                <div class="cp-ops-mobile-card__body">
                                    <x-seo-content-ai::content-project-item-meta :row="$row" />
                                    <div class="cp-ops-mobile-card__badges">
                                        <x-seo-content-ai::content-project-status-badge :badge="$row['generation_badge']" />
                                        <x-seo-content-ai::content-project-status-badge :badge="$row['lifecycle_badge']" />
                                        <x-seo-content-ai::content-project-status-badge :badge="$row['queue_badge']" />
                                    </div>
                                    <div class="cp-ops-mobile-card__meta">
                                        {{ $row['scheduled_at'] ?: '—' }} · {{ $row['last_activity'] }}
                                    </div>
                                </div>
                                <x-seo-content-ai::content-project-item-actions-menu :row="$row" />
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
                                    <th class="cp-ops-col-check" scope="col">
                                        <span class="sr-only">Select</span>
                                    </th>
                                    <th class="cp-ops-col-item" scope="col">Item</th>
                                    <th class="cp-ops-col-gen" scope="col">Generation</th>
                                    <th class="cp-ops-col-life" scope="col">Lifecycle</th>
                                    <th class="cp-ops-col-sched" scope="col">Schedule</th>
                                    <th class="cp-ops-col-queue" scope="col">Queue</th>
                                    <th class="cp-ops-col-activity" scope="col">Last activity</th>
                                    <th class="cp-ops-col-actions" scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    @php $tid = (int) $row['task_id']; @endphp
                                    <tr
                                        wire:key="item-{{ $tid }}"
                                        @class(['is-even' => $loop->even])
                                    >
                                        <td>
                                            <input
                                                type="checkbox"
                                                class="rounded"
                                                value="{{ $tid }}"
                                                wire:model.live="selectedTaskIds"
                                                aria-label="Select item {{ $tid }}"
                                            />
                                        </td>
                                        <td>
                                            <x-seo-content-ai::content-project-item-meta :row="$row" />
                                        </td>
                                        <td>
                                            <x-seo-content-ai::content-project-status-badge :badge="$row['generation_badge']" />
                                            @if (! empty($row['current_step']) && in_array($row['generation_badge']['key'] ?? '', ['running', 'failed'], true))
                                                <div class="cp-ops-step" title="{{ $row['current_step'] }}">{{ $row['current_step'] }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <x-seo-content-ai::content-project-status-badge :badge="$row['lifecycle_badge']" />
                                        </td>
                                        <td class="cp-ops-col-sched">
                                            {{ $row['scheduled_at'] ?: '—' }}
                                        </td>
                                        <td class="cp-ops-col-queue">
                                            <x-seo-content-ai::content-project-status-badge :badge="$row['queue_badge']" />
                                        </td>
                                        <td class="cp-ops-muted" title="{{ $row['last_activity_full'] ?? '' }}">
                                            {{ $row['last_activity'] }}
                                        </td>
                                        <td>
                                            <x-seo-content-ai::content-project-item-actions-menu :row="$row" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        @if ($paginator && count($rows) > 0)
            <div class="mt-2">{{ $paginator->links() }}</div>
        @endif

        {{-- Execution details drawer --}}
        <div
            x-show="detailsOpen"
            x-cloak
            class="fixed inset-0 z-50 flex justify-end"
            x-on:keydown.escape.window="detailsOpen = false; $wire.closeExecutionDetails()"
        >
            <div class="absolute inset-0 bg-black/40" @click="detailsOpen = false; $wire.closeExecutionDetails()"></div>
            <div class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <h3 class="font-semibold">{{ __('seo-content-ai::filament.projects.item_execution_details_heading') }}</h3>
                    <button type="button" @click="detailsOpen = false; $wire.closeExecutionDetails()" class="text-sm text-gray-500" aria-label="Close details">✕</button>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    @forelse ($this->executionDetailsRows as $exec)
                        <div class="mb-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                            <div class="font-medium">Run #{{ $exec['run_id'] }} · #{{ $exec['id'] }} · {{ $exec['status'] }}</div>
                            <div class="text-gray-500">{{ $exec['action'] }} · {{ $exec['started_at'] ?? '—' }} → {{ $exec['finished_at'] ?? '—' }}</div>
                            @if ($exec['error'] !== '')
                                <div class="mt-1 text-danger-600">{{ $exec['error'] }}</div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.projects.item_execution_empty') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @once
        <style>
            .cp-ops-kpi-grid {
                display: grid;
                gap: 0.5rem;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            @media (min-width: 640px) {
                .cp-ops-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            }
            @media (min-width: 1280px) {
                .cp-ops-kpi-grid { grid-template-columns: repeat(8, minmax(0, 1fr)); }
            }

            .cp-ops-kpi-card {
                display: flex;
                min-height: 4.25rem;
                flex-direction: column;
                justify-content: space-between;
                border: 1px solid rgb(229 231 235);
                border-left-width: 4px;
                border-radius: 0.75rem;
                background: #fff;
                padding: 0.625rem 0.75rem;
                text-align: left;
                box-shadow: 0 1px 2px rgb(0 0 0 / 0.04);
                cursor: pointer;
                transition: background-color .15s, box-shadow .15s;
            }
            .dark .cp-ops-kpi-card {
                border-color: rgb(55 65 81);
                background: rgb(17 24 39);
            }
            .cp-ops-kpi-card:hover { background: rgb(249 250 251); }
            .dark .cp-ops-kpi-card:hover { background: rgb(31 41 55); }
            .cp-ops-kpi-card:focus-visible {
                outline: 2px solid rgb(59 130 246);
                outline-offset: 2px;
            }
            .cp-ops-kpi-card.is-active {
                box-shadow: 0 0 0 2px rgb(59 130 246 / 0.45);
            }
            .cp-ops-kpi-card.accent-total { border-left-color: rgb(107 114 128); }
            .cp-ops-kpi-card.accent-pending { border-left-color: rgb(148 163 184); }
            .cp-ops-kpi-card.accent-running { border-left-color: rgb(59 130 246); }
            .cp-ops-kpi-card.accent-failed { border-left-color: rgb(239 68 68); }
            .cp-ops-kpi-card.accent-review { border-left-color: rgb(245 158 11); }
            .cp-ops-kpi-card.accent-approved { border-left-color: rgb(34 197 94); }
            .cp-ops-kpi-card.accent-scheduled { border-left-color: rgb(139 92 246); }
            .cp-ops-kpi-card.accent-published { border-left-color: rgb(13 148 136); }

            .cp-ops-kpi-card__top {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.25rem;
            }
            .cp-ops-kpi-card__label {
                font-size: 0.6875rem;
                font-weight: 600;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: rgb(107 114 128);
            }
            .dark .cp-ops-kpi-card__label { color: rgb(156 163 175); }
            .cp-ops-kpi-card__icon {
                width: 1rem;
                height: 1rem;
                flex-shrink: 0;
                opacity: 0.85;
            }
            .cp-ops-kpi-card.accent-running .cp-ops-kpi-card__icon,
            .cp-ops-kpi-card.accent-running .cp-ops-kpi-card__value { color: rgb(37 99 235); }
            .cp-ops-kpi-card.accent-failed .cp-ops-kpi-card__icon,
            .cp-ops-kpi-card.accent-failed .cp-ops-kpi-card__value { color: rgb(220 38 38); }
            .cp-ops-kpi-card.accent-review .cp-ops-kpi-card__icon,
            .cp-ops-kpi-card.accent-review .cp-ops-kpi-card__value { color: rgb(217 119 6); }
            .cp-ops-kpi-card.accent-approved .cp-ops-kpi-card__icon,
            .cp-ops-kpi-card.accent-approved .cp-ops-kpi-card__value { color: rgb(22 163 74); }
            .cp-ops-kpi-card.accent-scheduled .cp-ops-kpi-card__icon,
            .cp-ops-kpi-card.accent-scheduled .cp-ops-kpi-card__value { color: rgb(124 58 237); }
            .cp-ops-kpi-card.accent-published .cp-ops-kpi-card__icon,
            .cp-ops-kpi-card.accent-published .cp-ops-kpi-card__value { color: rgb(15 118 110); }
            .cp-ops-kpi-card__value {
                margin-top: 0.25rem;
                font-size: 1.35rem;
                font-weight: 700;
                font-variant-numeric: tabular-nums;
                line-height: 1.1;
                color: rgb(17 24 39);
            }
            .dark .cp-ops-kpi-card__value { color: #fff; }
            @media (min-width: 640px) {
                .cp-ops-kpi-card__value { font-size: 1.5rem; }
            }

            .cp-ops-toolbar {
                border: 1px solid rgb(229 231 235);
                border-radius: 0.75rem;
                background: #fff;
                padding: 0.75rem;
            }
            .dark .cp-ops-toolbar {
                border-color: rgb(55 65 81);
                background: rgb(17 24 39);
            }
            .cp-ops-toolbar__row {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.5rem;
            }
            .cp-ops-toolbar__search {
                flex: 1 1 14rem;
                min-width: 12rem;
                max-width: 100%;
                border-radius: 0.5rem;
                font-size: 0.875rem;
            }
            .cp-ops-toolbar__filters {
                display: none;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.5rem;
            }
            @media (min-width: 768px) {
                .cp-ops-toolbar__filters { display: flex; }
                .cp-ops-toolbar__filters-btn { display: none !important; }
            }
            .cp-ops-toolbar .cp-ops-select,
            .cp-ops-toolbar .x-select-wrap.cp-ops-select {
                display: inline-block;
                width: 9.5rem;
                max-width: 9.5rem;
                flex: 0 0 9.5rem;
            }
            .cp-ops-toolbar .cp-ops-select .x-select,
            .cp-ops-toolbar .cp-ops-select select {
                width: 100%;
            }
            @media (max-width: 1023px) {
                .cp-ops-toolbar .cp-ops-select--wide-only { display: none !important; }
            }
            .cp-ops-toolbar__check {
                display: inline-flex;
                align-items: center;
                gap: 0.375rem;
                font-size: 0.75rem;
                font-weight: 500;
                color: rgb(75 85 99);
                white-space: nowrap;
            }
            .dark .cp-ops-toolbar__check { color: rgb(209 213 219); }
            .cp-ops-toolbar__link {
                font-size: 0.75rem;
                font-weight: 600;
                color: rgb(37 99 235);
                background: none;
                border: 0;
                cursor: pointer;
                white-space: nowrap;
            }
            .cp-ops-toolbar__filters-btn {
                display: inline-flex;
                align-items: center;
                gap: 0.25rem;
                border-radius: 0.5rem;
                padding: 0.5rem 0.75rem;
                font-size: 0.75rem;
                font-weight: 600;
                border: 1px solid rgb(209 213 219);
                background: #fff;
            }
            .cp-ops-toolbar__select-page { margin-left: auto; }

            .cp-ops-filters-drawer {
                position: fixed;
                inset: 0;
                z-index: 50;
            }
            @media (min-width: 768px) {
                .cp-ops-filters-drawer { display: none !important; }
            }
            .cp-ops-filters-drawer__backdrop {
                position: absolute;
                inset: 0;
                background: rgb(0 0 0 / 0.4);
            }
            .cp-ops-filters-drawer__panel {
                position: absolute;
                left: 0;
                right: 0;
                bottom: 0;
                max-height: 80vh;
                overflow: auto;
                border-radius: 1rem 1rem 0 0;
                background: #fff;
                padding: 1rem;
                box-shadow: 0 -8px 24px rgb(0 0 0 / 0.15);
            }
            .dark .cp-ops-filters-drawer__panel { background: rgb(17 24 39); }
            .cp-ops-filters-drawer__head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 0.75rem;
            }
            .cp-ops-filters-drawer__head h3 {
                margin: 0;
                font-size: 0.875rem;
                font-weight: 600;
            }
            .cp-ops-filters-drawer__body {
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
            }
            .cp-ops-filters-drawer__actions {
                display: flex;
                gap: 0.5rem;
                padding-top: 0.5rem;
            }
            .cp-ops-filters-drawer__actions .fi-btn { flex: 1; }

            .cp-ops-mobile-list { display: flex; flex-direction: column; gap: 0.5rem; }
            .cp-ops-mobile-card {
                border: 1px solid rgb(229 231 235);
                border-radius: 0.75rem;
                background: #fff;
                padding: 0.75rem;
            }
            .dark .cp-ops-mobile-card {
                border-color: rgb(55 65 81);
                background: rgb(17 24 39);
            }
            .cp-ops-mobile-card__row { display: flex; align-items: flex-start; gap: 0.5rem; }
            .cp-ops-mobile-card__body { min-width: 0; flex: 1; }
            .cp-ops-mobile-card__badges {
                display: flex;
                flex-wrap: wrap;
                gap: 0.375rem;
                margin-top: 0.5rem;
            }
            .cp-ops-mobile-card__meta {
                margin-top: 0.25rem;
                font-size: 0.6875rem;
                color: rgb(107 114 128);
            }

            .cp-ops-table-wrap {
                display: none;
                overflow-x: auto;
                overflow-y: visible;
                max-height: none;
                border: 1px solid rgb(229 231 235);
                border-radius: 0.75rem;
                background: #fff;
            }
            .dark .cp-ops-table-wrap {
                border-color: rgb(55 65 81);
                background: rgb(17 24 39);
            }
            @media (min-width: 768px) {
                .cp-ops-mobile-list { display: none; }
                .cp-ops-table-wrap { display: block; }
            }
            .cp-ops-table-scroll {
                max-height: none;
                overflow-x: auto;
                overflow-y: visible;
            }
            .cp-ops-table {
                width: 100%;
                table-layout: fixed;
                border-collapse: collapse;
                font-size: 0.875rem;
            }
            .cp-ops-table thead {
                z-index: 10;
                background: rgb(249 250 251);
            }
            .dark .cp-ops-table thead { background: rgb(31 41 55); }
            .cp-ops-table th {
                padding: 0.5rem;
                text-align: left;
                font-size: 0.6875rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: rgb(107 114 128);
                border-bottom: 1px solid rgb(229 231 235);
            }
            .dark .cp-ops-table th {
                color: rgb(156 163 175);
                border-bottom-color: rgb(55 65 81);
            }
            .cp-ops-table td {
                padding: 0.5rem;
                vertical-align: top;
                border-bottom: 1px solid rgb(243 244 246);
            }
            .dark .cp-ops-table td { border-bottom-color: rgb(31 41 55); }
            .cp-ops-table tbody tr:hover { background: rgb(249 250 251 / 0.9); }
            .dark .cp-ops-table tbody tr:hover { background: rgb(31 41 55 / 0.45); }
            .cp-ops-table tbody tr.is-even { background: rgb(249 250 251 / 0.45); }
            .dark .cp-ops-table tbody tr.is-even { background: rgb(31 41 55 / 0.25); }
            .cp-ops-col-check { width: 2.5rem; }
            .cp-ops-col-item { width: 30%; }
            .cp-ops-col-gen, .cp-ops-col-life { width: 11%; }
            .cp-ops-col-sched { width: 12%; }
            .cp-ops-col-queue { width: 10%; }
            .cp-ops-col-activity { width: 12%; }
            .cp-ops-col-actions { width: 10%; }
            @media (max-width: 1023px) {
                .cp-ops-table .cp-ops-col-sched,
                .cp-ops-table .cp-ops-col-queue { display: none; }
            }
            .cp-ops-muted {
                font-size: 0.75rem;
                color: rgb(75 85 99);
            }
            .dark .cp-ops-muted { color: rgb(209 213 219); }
            .cp-ops-step {
                margin-top: 0.25rem;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 0.6875rem;
                color: rgb(107 114 128);
            }
        </style>
    @endonce
</x-filament-panels::page>
