@props([])

<div
    {{ $attributes->class(['cp-ops-toolbar']) }}
    x-data="{ filtersOpen: false }"
>
    <div class="cp-ops-toolbar__row">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('seo-content-ai::filament.projects.queue_search') }}"
            class="fi-input cp-ops-toolbar__search"
            aria-label="{{ __('seo-content-ai::filament.projects.queue_search') }}"
        />

        <div class="cp-ops-toolbar__filters">
            <x-select wire:model.live="generationFilter" wrapClass="cp-ops-select" aria-label="Generation filter">
                <option value="">Generation</option>
                <option value="pending">pending</option>
                <option value="running">running</option>
                <option value="success">success</option>
                <option value="failed">failed</option>
            </x-select>
            <x-select wire:model.live="lifecycleFilter" wrapClass="cp-ops-select" aria-label="Lifecycle filter">
                <option value="">Lifecycle</option>
                <option value="draft">draft</option>
                <option value="review">review</option>
                <option value="approved">approved</option>
                <option value="waiting_publish">scheduled</option>
                <option value="published">published</option>
                <option value="failed">failed</option>
            </x-select>
            <x-select wire:model.live="queueFilter" wrapClass="cp-ops-select cp-ops-select--wide-only" aria-label="Queue filter">
                <option value="">Queue</option>
                <option value="none">none</option>
                <option value="waiting">waiting</option>
                <option value="processing">processing</option>
                <option value="retrying">retrying</option>
                <option value="failed">failed</option>
                <option value="published">published</option>
            </x-select>
            <x-select wire:model.live="scheduledFilter" wrapClass="cp-ops-select cp-ops-select--wide-only" aria-label="Schedule filter">
                <option value="">Schedule</option>
                <option value="yes">scheduled</option>
                <option value="no">unscheduled</option>
            </x-select>
            <label class="cp-ops-toolbar__check">
                <input type="checkbox" wire:model.live="failedOnly" class="rounded" />
                Failed only
            </label>
            <button type="button" wire:click="clearFilters" class="cp-ops-toolbar__link">
                Clear filters
            </button>
        </div>

        <button
            type="button"
            class="cp-ops-toolbar__filters-btn"
            @click="filtersOpen = true"
            aria-label="Open filters"
        >
            <x-filament::icon icon="heroicon-o-funnel" class="h-4 w-4" />
            Filters
        </button>

        <button
            type="button"
            wire:click="selectPage"
            class="fi-btn fi-btn-color-gray fi-size-sm cp-ops-toolbar__select-page"
            aria-label="{{ __('seo-content-ai::filament.projects.queue_select_page') }}"
        >
            {{ __('seo-content-ai::filament.projects.queue_select_page') }}
        </button>
    </div>

    <div
        x-show="filtersOpen"
        x-cloak
        class="cp-ops-filters-drawer"
        @keydown.escape.window="filtersOpen = false"
    >
        <div class="cp-ops-filters-drawer__backdrop" @click="filtersOpen = false"></div>
        <div class="cp-ops-filters-drawer__panel">
            <div class="cp-ops-filters-drawer__head">
                <h3>Filters</h3>
                <button type="button" @click="filtersOpen = false" aria-label="Close filters">✕</button>
            </div>
            <div class="cp-ops-filters-drawer__body">
                <x-select wire:model.live="typeFilter" class="!w-full" aria-label="Type filter">
                    <option value="">Type</option>
                    <option value="create">new</option>
                    <option value="rewrite">rewrite</option>
                    <option value="improve">improve</option>
                </x-select>
                <x-select wire:model.live="generationFilter" class="!w-full">
                    <option value="">Generation</option>
                    <option value="pending">pending</option>
                    <option value="running">running</option>
                    <option value="success">success</option>
                    <option value="failed">failed</option>
                </x-select>
                <x-select wire:model.live="lifecycleFilter" class="!w-full">
                    <option value="">Lifecycle</option>
                    <option value="draft">draft</option>
                    <option value="review">review</option>
                    <option value="approved">approved</option>
                    <option value="waiting_publish">scheduled</option>
                    <option value="published">published</option>
                    <option value="failed">failed</option>
                </x-select>
                <x-select wire:model.live="queueFilter" class="!w-full">
                    <option value="">Queue</option>
                    <option value="none">none</option>
                    <option value="waiting">waiting</option>
                    <option value="processing">processing</option>
                    <option value="retrying">retrying</option>
                    <option value="failed">failed</option>
                    <option value="published">published</option>
                </x-select>
                <x-select wire:model.live="scheduledFilter" class="!w-full">
                    <option value="">Schedule</option>
                    <option value="yes">scheduled</option>
                    <option value="no">unscheduled</option>
                </x-select>
                <label class="cp-ops-toolbar__check">
                    <input type="checkbox" wire:model.live="failedOnly" class="rounded" />
                    Failed only
                </label>
                <div class="cp-ops-filters-drawer__actions">
                    <button type="button" wire:click="clearFilters" @click="filtersOpen = false" class="fi-btn fi-btn-color-gray fi-size-sm">Clear filters</button>
                    <button type="button" @click="filtersOpen = false" class="fi-btn fi-btn-color-primary fi-size-sm">Done</button>
                </div>
            </div>
        </div>
    </div>
</div>
