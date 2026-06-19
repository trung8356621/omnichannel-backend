@props([
    'showTest' => false,
])

@php
    $progressTemplate = __('seo-content-ai::filament.domain.sync_incremental_progress', [
        'done' => '__DONE__',
        'total' => '__TOTAL__',
    ]);
@endphp

<div
    class="seo-sync-actions space-y-2"
    x-data="{
        done: @js($incrementalSyncProgress),
        total: @js($incrementalSyncTotal),
        running: @js($incrementalSyncRunning),
        template: @js($progressTemplate),
        progressLabel() {
            return this.template
                .replace('__DONE__', String(this.done))
                .replace('__TOTAL__', String(this.total));
        },
    }"
    x-on:incremental-sync-progress.window="
        done = $event.detail.done ?? 0;
        total = $event.detail.total ?? 0;
        running = $event.detail.running ?? false;
    "
>
    <p
        x-show="total > 0"
        x-cloak
        class="text-sm font-medium text-primary-600 dark:text-primary-400"
        x-text="progressLabel()"
    ></p>

    <p
        x-show="running"
        x-cloak
        class="text-xs text-gray-500 dark:text-gray-400"
    >
        {{ __('seo-content-ai::filament.domain.sync_incremental_background_hint') }}
    </p>

    <div class="flex flex-wrap gap-2">
        <x-filament::button
            type="button"
            color="success"
            icon="heroicon-o-arrow-down-tray"
            wire:click="runIncrementalSyncAction"
            wire:loading.attr="disabled"
            wire:target="runIncrementalSyncAction"
            :disabled="$incrementalSyncRunning"
        >
            <span wire:loading.remove wire:target="runIncrementalSyncAction">
                @if ($incrementalSyncRunning)
                    {{ __('seo-content-ai::filament.domain.sync_incremental_running') }}
                @elseif ($incrementalSyncResumable)
                    {{ __('seo-content-ai::filament.domain.sync_incremental_resume') }}
                @else
                    {{ __('seo-content-ai::filament.domain.sync_incremental') }}
                @endif
            </span>
            <span wire:loading wire:target="runIncrementalSyncAction">
                {{ __('seo-content-ai::filament.domain.sync_incremental_preparing') }}
            </span>
        </x-filament::button>

        <x-filament::button
            type="button"
            color="danger"
            icon="heroicon-o-arrow-path"
            wire:click="mountAction('resync_keywords')"
            wire:loading.attr="disabled"
            wire:target="mountAction('resync_keywords')"
            :disabled="$incrementalSyncRunning"
        >
            {{ __('seo-content-ai::filament.keyword.resync_linked') }}
        </x-filament::button>

        @if ($showTest)
            <x-filament::button
                type="button"
                color="gray"
                icon="heroicon-o-bug-ant"
                wire:click="mountAction('test_sync_data')"
                wire:loading.attr="disabled"
                wire:target="runIncrementalSyncAction, mountAction('test_sync_data')"
                :disabled="$incrementalSyncRunning"
            >
                {{ __('seo-content-ai::filament.domain.test_sync_debug') }}
            </x-filament::button>
        @endif
    </div>
</div>
